<?php
/**
 * ============================================================
 * CRON — Calcul automatique des pénalités de retard
 * À exécuter 1 fois par jour (ex: 02:00)
 * ============================================================
 * 
 * Windows (Task Scheduler) :
 *   php C:\xampp\htdocs\african-trust-fund\cron\calcul_penalites.php
 * 
 * Linux (crontab -e) :
 *   0 2 * * * php /var/www/african-trust-fund/cron/calcul_penalites.php
 * ============================================================
 */

declare(strict_types=1);

/* Sécurité : ce script ne doit être appelé qu'en CLI ou avec un token secret */
$is_cli = (php_sapi_name() === 'cli');
$secret = $_GET['secret'] ?? '';

if (!$is_cli) {
    // Appel HTTP : nécessite un secret
    $expected_secret = 'ATF_CRON_SECRET_2025_CHANGE_ME'; // À changer !
    if (!hash_equals($expected_secret, $secret)) {
        http_response_code(403);
        die("Accès refusé. Utilisez le secret correct.");
    }
    header('Content-Type: text/plain; charset=utf-8');
}

require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/prets.php';

$pdo = Database::getConnection();

echo "=================================================\n";
echo "🕐 CRON PÉNALITÉS — " . date('d/m/Y H:i:s') . "\n";
echo "=================================================\n\n";

/* ============================================================
   1. RÉCUPÉRER LES ÉCHÉANCES EN RETARD (non payées)
   ============================================================ */
$stmt = $pdo->prepare("
    SELECT e.*, p.devise, p.reference AS pret_ref, p.id_client,
           pr.penalite_retard_pct, pr.jours_grace
    FROM Echeances_Pret e
    JOIN Prets p ON p.id_pret = e.id_pret
    JOIN Produits_Pret pr ON pr.id_produit = p.id_produit
    WHERE e.statut IN ('en_attente','partiel','retard')
      AND e.date_echeance < CURDATE()
      AND p.statut IN ('decaisse','en_cours','en_retard')
    ORDER BY e.date_echeance ASC
");
$stmt->execute();
$echeances_retard = $stmt->fetchAll();

echo "📋 Échéances en retard trouvées : " . count($echeances_retard) . "\n\n";

$traitees = 0;
$total_penalites = 0;
$erreurs = 0;

foreach ($echeances_retard as $e) {
    try {
        $date_echeance = strtotime($e['date_echeance']);
        $jours_retard = (int)floor((time() - $date_echeance) / 86400);
        
        /* Appliquer le délai de grâce */
        $jours_grace = (int)($e['jours_grace'] ?? 5);
        if ($jours_retard <= $jours_grace) {
            continue;
        }
        
        $jours_penalises = $jours_retard - $jours_grace;
        
        /* Calculer la pénalité */
        $reste = (float)$e['montant_total'] - (float)$e['montant_paye'];
        $taux_mensuel = (float)($e['penalite_retard_pct'] ?? 0.05);
        $taux_journalier = $taux_mensuel / 30;
        
        $nouvelle_penalite = round($reste * $taux_journalier * $jours_penalises, 2);
        
        /* Ne recalculer que si la pénalité a changé */
        $ancienne_penalite = (float)$e['penalite'];
        if (abs($nouvelle_penalite - $ancienne_penalite) < 0.01) {
            continue;
        }
        
        $pdo->beginTransaction();
        
        /* Mettre à jour l'échéance */
        $pdo->prepare("
            UPDATE Echeances_Pret 
            SET penalite = ?, statut = 'retard'
            WHERE id_echeance = ?
        ")->execute([$nouvelle_penalite, (int)$e['id_echeance']]);
        
        /* Mettre à jour les pénalités cumulées du prêt */
        $stmt2 = $pdo->prepare("SELECT COALESCE(SUM(penalite), 0) FROM Echeances_Pret WHERE id_pret = ?");
        $stmt2->execute([(int)$e['id_pret']]);
        $total_pen_pret = (float)$stmt2->fetchColumn();
        
        $pdo->prepare("
            UPDATE Prets 
            SET penalites_cumulees = ?, statut = 'en_retard'
            WHERE id_pret = ? AND statut != 'contentieux'
        ")->execute([$total_pen_pret, (int)$e['id_pret']]);
        
        /* Log */
        logPret(
            $pdo, (int)$e['id_pret'],
            'PENALITE_APPLIQUEE',
            $e['statut'], 'retard',
            'system', 0,
            "Échéance #{$e['numero_echeance']} : {$jours_penalises}j × " . 
            number_format($taux_journalier * 100, 4) . "% = $nouvelle_penalite {$e['devise']}"
        );
        
        $pdo->commit();
        
        $traitees++;
        $total_penalites += $nouvelle_penalite;
        
        echo "✅ {$e['pret_ref']} éch. #{$e['numero_echeance']} : ";
        echo "{$jours_penalises}j de retard → +" . number_format($nouvelle_penalite, 2) . " {$e['devise']}\n";
        
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $erreurs++;
        echo "❌ Erreur échéance #{$e['id_echeance']} : " . $ex->getMessage() . "\n";
        error_log('[ATF CRON PENALITES] ' . $ex->getMessage());
    }
}

/* ============================================================
   2. PASSER LES PRÊTS EN CONTENTIEUX (>90 jours de retard)
   ============================================================ */
$stmt = $pdo->prepare("
    SELECT DISTINCT p.id_pret, p.reference, c.nom, c.prenom
    FROM Prets p
    JOIN Client c ON c.id_client = p.id_client
    JOIN Echeances_Pret e ON e.id_pret = p.id_pret
    WHERE p.statut = 'en_retard'
      AND e.date_echeance < DATE_SUB(CURDATE(), INTERVAL 90 DAY)
      AND e.statut IN ('en_attente','partiel','retard')
");
$stmt->execute();
$prets_contentieux = $stmt->fetchAll();

foreach ($prets_contentieux as $pc) {
    $pdo->prepare("UPDATE Prets SET statut = 'contentieux' WHERE id_pret = ?")->execute([(int)$pc['id_pret']]);
    logPret($pdo, (int)$pc['id_pret'], 'PASSAGE_CONTENTIEUX', 'en_retard', 'contentieux', 'system', 0, "Retard > 90 jours");
    echo "⚖️  Prêt {$pc['reference']} ({$pc['prenom']} {$pc['nom']}) → CONTENTIEUX\n";
}

/* ============================================================
   RÉSUMÉ
   ============================================================ */
echo "\n=================================================\n";
echo "📊 RÉSUMÉ :\n";
echo "   Échéances traitées : $traitees\n";
echo "   Total pénalités    : " . number_format($total_penalites, 2) . "\n";
echo "   Prêts en contentieux : " . count($prets_contentieux) . "\n";
echo "   Erreurs            : $erreurs\n";
echo "=================================================\n";
echo "✅ Terminé à " . date('H:i:s') . "\n";

/* Nettoyage : vieux tokens expirés */
echo "\n🧹 Nettoyage des tokens expirés...\n";
$tokens_clean = 0;
foreach ($_SESSION as $key => $val) {
    if (str_starts_with($key, 'tok_') && is_array($val)) {
        foreach ($val as $tk => $data) {
            if (isset($data['expires']) && $data['expires'] < time()) {
                unset($_SESSION[$key][$tk]);
                $tokens_clean++;
            }
        }
    }
}
echo "✅ $tokens_clean tokens nettoyés\n";

exit(0);