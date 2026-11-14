<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/interets.php';

/**
 * CRON QUOTIDIEN — Crédite les intérêts sur tous les placements actifs
 * À exécuter tous les jours à 1h du matin
 * 
 * Windows : Planificateur de tâches
 * Linux : crontab -e puis : 0 1 * * * php /var/www/atf/cron/calculer_interets.php
 */

if (PHP_SAPI !== 'cli' && !isset($_GET['cron_key'])) {
    die("Accès refusé. Utilisez CLI ou cron_key.");
}

$pdo = Database::getConnection();

$log_dir = __DIR__ . '/../logs';
if (!is_dir($log_dir)) mkdir($log_dir, 0755, true);

$log_file = $log_dir . '/cron_interets.log';

function writeLog(string $message): void {
    global $log_file;
    file_put_contents($log_file, '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n", FILE_APPEND);
}

writeLog('=== Démarrage du CRON ===');

try {
    /* Récupérer tous les placements actifs dont la date de dernier calcul est < aujourd'hui */
    $stmt = $pdo->query("
        SELECT id_placement, reference, montant_actuel, taux_applique,
               date_dernier_calcul, date_debut, devise
        FROM Placements
        WHERE statut = 'actif'
          AND (date_dernier_calcul IS NULL OR date_dernier_calcul < CURDATE())
    ");
    $placements = $stmt->fetchAll();

    writeLog("Placements à traiter : " . count($placements));

    $total_interets = 0;
    $succes = 0;
    $erreurs = 0;

    foreach ($placements as $p) {
        try {
            $date_dernier = $p['date_dernier_calcul'] ?? $p['date_debut'];
            $aujourdhui = date('Y-m-d');
            $jours = (int)((strtotime($aujourdhui) - strtotime($date_dernier)) / 86400);

            if ($jours < 1) continue;

            /* Calcul des intérêts journaliers */
            $taux_journalier = (float)$p['taux_applique'] / 365;
            $interets = round((float)$p['montant_actuel'] * $taux_journalier * $jours, 2);

            if ($interets <= 0) continue;

            $solde_avant = (float)$p['montant_actuel'];
            $solde_apres = $solde_avant + $interets;

            $pdo->beginTransaction();

            /* Mettre à jour le placement */
            $pdo->prepare("
                UPDATE Placements
                SET interets_cumules = interets_cumules + ?,
                    date_dernier_calcul = ?
                WHERE id_placement = ?
            ")->execute([$interets, $aujourdhui, (int)$p['id_placement']]);

            /* Enregistrer dans l'historique */
            $pdo->prepare("
                INSERT INTO Interets_Historique
                    (id_placement, montant_interet, solde_avant, solde_apres,
                     periode_debut, periode_fin, date_calcul)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ")->execute([
                (int)$p['id_placement'],
                $interets,
                $solde_avant,
                $solde_apres,
                $date_dernier,
                $aujourdhui
            ]);

            $pdo->commit();

            $total_interets += $interets;
            $succes++;

            writeLog("✅ Placement {$p['reference']} : +{$interets} {$p['devise']} ({$jours}j)");

        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $erreurs++;
            writeLog("❌ Erreur placement {$p['reference']} : " . $e->getMessage());
        }
    }

    writeLog("=== Résumé : $succes succès | $erreurs erreurs | Total : " . number_format($total_interets, 2) . " CDF ===");

    if (PHP_SAPI === 'cli') {
        echo "✅ CRON terminé : $succes placements | $erreurs erreurs | " . number_format($total_interets, 2) . " CDF\n";
    } else {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'succes'  => $succes,
            'erreurs' => $erreurs,
            'total'   => $total_interets,
        ]);
    }

} catch (Throwable $e) {
    writeLog('❌ ERREUR CRITIQUE : ' . $e->getMessage());
    if (PHP_SAPI === 'cli') {
        echo "❌ Erreur : " . $e->getMessage() . "\n";
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}