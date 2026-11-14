<?php
/**
 * ============================================================
 * ACTIONS SUR LES PLACEMENTS — ATF Admin
 * Modifier / Supprimer / Restituer / Voir détails
 * ============================================================
 */
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/interets.php';
requireRole('admin');

$pdo = Database::getConnection();
$admin_id = (int)$_SESSION['admin_id'];

$action = $_POST['action'] ?? $_GET['action'] ?? '';

/* ============================================================
   ACTION 1 : MODIFIER UN PLACEMENT
   ============================================================ */
if ($action === 'modifier' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) {
            throw new RuntimeException("Jeton de sécurité invalide.");
        }

        $id_placement = (int)($_POST['id_placement'] ?? 0);
        $nouveau_montant = (float)str_replace([' ', ','], ['', '.'], $_POST['montant_initial'] ?? '0');
        $nouveau_taux = (float)($_POST['taux_applique'] ?? 0) / 100;
        $nouvelle_duree = (int)($_POST['duree_mois'] ?? 12);
        $nouveau_statut = in_array($_POST['statut'] ?? '', ['actif','cloture','anticipe'])
            ? $_POST['statut'] : 'actif';
        $nouvelle_date_debut = $_POST['date_debut'] ?? date('Y-m-d');
        $nouvelle_date_fin = $_POST['date_fin'] ?? date('Y-m-d', strtotime("+$nouvelle_duree months"));
        $raison = cleanString($_POST['raison'] ?? '', 255);

        if ($id_placement <= 0) throw new RuntimeException("Placement invalide.");
        if ($nouveau_montant <= 0) throw new RuntimeException("Le montant doit être positif.");
        if ($nouveau_taux <= 0 || $nouveau_taux > 0.5) throw new RuntimeException("Taux invalide (0-50%).");
        if ($nouvelle_duree < 1 || $nouvelle_duree > 120) throw new RuntimeException("Durée invalide (1-120 mois).");

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM Placements WHERE id_placement = ? FOR UPDATE");
        $stmt->execute([$id_placement]);
        $placement = $stmt->fetch();

        if (!$placement) throw new RuntimeException("Placement introuvable.");

        $ancien_montant = (float)$placement['montant_initial'];
        $difference = $nouveau_montant - $ancien_montant;

        /* Synchronisation avec le client */
        if (abs($difference) > 0.01) {
            $col_epargne = $placement['devise'] === 'USD' ? 'solde_epargne_usd' : 'solde_epargne_cdf';

            $stmt2 = $pdo->prepare("SELECT $col_epargne AS solde_epargne FROM Client WHERE id_client = ? FOR UPDATE");
            $stmt2->execute([(int)$placement['id_client']]);
            $client = $stmt2->fetch();

            if ($difference > 0 && (float)$client['solde_epargne'] < $difference) {
                throw new RuntimeException(
                    "Le client n'a pas assez d'épargne pour augmenter ce placement de " .
                    number_format($difference, 2, ',', ' ') . " " . $placement['devise']
                );
            }

            $pdo->prepare("UPDATE Client SET $col_epargne = $col_epargne + ? WHERE id_client = ?")
                ->execute([$difference, (int)$placement['id_client']]);
        }

        /* Recalcul des intérêts si taux ou date modifiés */
        $recalcul = ((float)$nouveau_taux != (float)$placement['taux_applique'])
                 || ($nouvelle_date_debut != $placement['date_debut']);

        $nouveaux_interets = (float)$placement['interets_cumules'];
        $nouvelle_date_dernier_calcul = $placement['date_dernier_calcul'];

        if ($recalcul) {
            $jours = (int)((time() - strtotime($nouvelle_date_debut)) / 86400);
            if ($jours < 0) $jours = 0;
            $taux_journalier = $nouveau_taux / 30;
            $nouveaux_interets = round($nouveau_montant * $taux_journalier * $jours, 2);
            $nouvelle_date_dernier_calcul = date('Y-m-d');
        }

        $date_deblocage = date('Y-m-d', strtotime("$nouvelle_date_debut +6 months"));

        $pdo->prepare("
            UPDATE Placements
            SET montant_initial = ?,
                montant_actuel = ?,
                taux_applique = ?,
                duree_mois = ?,
                date_debut = ?,
                date_fin = ?,
                date_deblocage_interets = ?,
                statut = ?,
                interets_cumules = ?,
                date_dernier_calcul = ?
            WHERE id_placement = ?
        ")->execute([
            $nouveau_montant,
            $nouveau_montant - (float)$placement['capital_retire'],
            $nouveau_taux,
            $nouvelle_duree,
            $nouvelle_date_debut,
            $nouvelle_date_fin,
            $date_deblocage,
            $nouveau_statut,
            $nouveaux_interets,
            $nouvelle_date_dernier_calcul,
            $id_placement
        ]);

        $pdo->commit();

        logAction($pdo, 'admin', $admin_id, 'PLACEMENT_MODIFIE',
            "ID: $id_placement | Ancien: $ancien_montant | Nouveau: $nouveau_montant | Raison: $raison");

        $_SESSION['flash_success'] = "✅ Placement modifié avec succès !<br>
            <strong>Référence :</strong> " . htmlspecialchars($placement['reference']) . "<br>
            <strong>Ancien montant :</strong> " . number_format($ancien_montant, 2, ',', ' ') . " {$placement['devise']}<br>
            <strong>Nouveau montant :</strong> " . number_format($nouveau_montant, 2, ',', ' ') . " {$placement['devise']}<br>
            <strong>Intérêts recalculés :</strong> " . number_format($nouveaux_interets, 2, ',', ' ') . " {$placement['devise']}";

        header('Location: ' . url('admin/placements.php'));
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[ATF MODIFIER PLACEMENT] ' . $e->getMessage());
        $_SESSION['flash_error'] = "Erreur : " . $e->getMessage();
        header('Location: ' . url('admin/placements.php'));
        exit;
    }
}

/* ============================================================
   ACTION 2 : SUPPRIMER UN PLACEMENT
   ============================================================ */
if ($action === 'supprimer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) {
            throw new RuntimeException("Jeton de sécurité invalide.");
        }

        $id_placement = (int)($_POST['id_placement'] ?? 0);
        $raison = cleanString($_POST['raison'] ?? 'Suppression administrative', 255);

        if ($id_placement <= 0) throw new RuntimeException("Placement invalide.");

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM Placements WHERE id_placement = ? FOR UPDATE");
        $stmt->execute([$id_placement]);
        $placement = $stmt->fetch();

        if (!$placement) throw new RuntimeException("Placement introuvable.");

        $montant_initial    = (float)$placement['montant_initial'];
        $capital_retire     = (float)$placement['capital_retire'];
        $capital_restant    = $montant_initial - $capital_retire;
        $interets_a_annuler = (float)$placement['interets_cumules'] - (float)$placement['interets_retires'];

        $col_solde   = $placement['devise'] === 'USD' ? 'solde_usd' : 'solde_cdf';
        $col_epargne = $placement['devise'] === 'USD' ? 'solde_epargne_usd' : 'solde_epargne_cdf';

        $pdo->prepare("
            UPDATE Client
            SET $col_epargne = $col_epargne - ?,
                $col_solde   = $col_solde + ?
            WHERE id_client = ?
        ")->execute([$capital_restant, $capital_restant, (int)$placement['id_client']]);

        $reference = 'SUP-' . strtoupper(bin2hex(random_bytes(6)));

        $pdo->prepare("
            INSERT INTO Transactions
                (id_client, type_transaction, montant, devise, mode, effectue_par,
                 solde_avant, solde_apres, reference, description, ip_address)
            VALUES (?, 'Depot', ?, ?, 'Agence', 'Admin', 0, 0, ?, ?, ?)
        ")->execute([
            (int)$placement['id_client'], $capital_restant, $placement['devise'],
            $reference,
            "Suppression du placement {$placement['reference']} | Raison: $raison | Restitution capital: $capital_restant",
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);

        $pdo->prepare("DELETE FROM Placements WHERE id_placement = ?")->execute([$id_placement]);

        $pdo->commit();

        logAction($pdo, 'admin', $admin_id, 'PLACEMENT_SUPPRIME',
            "ID: $id_placement | Réf: {$placement['reference']} | Capital restitué: $capital_restant | Intérêts annulés: $interets_a_annuler");

        $_SESSION['flash_success'] = "✅ Placement supprimé !<br>
            <strong>Référence :</strong> " . htmlspecialchars($placement['reference']) . "<br>
            <strong>Capital restitué au client :</strong> " . number_format($capital_restant, 2, ',', ' ') . " {$placement['devise']}<br>
            <strong>Intérêts annulés :</strong> " . number_format($interets_a_annuler, 2, ',', ' ') . " {$placement['devise']}";

        header('Location: ' . url('admin/placements.php'));
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[ATF SUPPRIMER PLACEMENT] ' . $e->getMessage());
        $_SESSION['flash_error'] = "Erreur : " . $e->getMessage();
        header('Location: ' . url('admin/placements.php'));
        exit;
    }
}

/* ============================================================
   ACTION 3 : VOIR DÉTAILS CLIENT (JSON)
   ============================================================ */
if ($action === 'details_client' && isset($_GET['id_client'])) {
    header('Content-Type: application/json; charset=utf-8');

    $id_client = (int)$_GET['id_client'];

    $stmt = $pdo->prepare("
        SELECT
            id_client, nom, postnom, prenom, numero_compte, numero_phone,
            adresse_complete, photo_profil, statut,
            solde_cdf, solde_usd, solde_epargne_cdf, solde_epargne_usd,
            created_at
        FROM Client WHERE id_client = ?
    ");
    $stmt->execute([$id_client]);
    $client = $stmt->fetch();

    if (!$client) {
        echo json_encode(['success' => false, 'error' => 'Client introuvable']);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT p.*, pr.nom AS produit_nom
        FROM Placements p
        JOIN Produits_Epargne pr ON pr.id_produit = p.id_produit
        WHERE p.id_client = ?
        ORDER BY p.created_at DESC
    ");
    $stmt->execute([$id_client]);
    $placements_client = $stmt->fetchAll();

    echo json_encode([
        'success'    => true,
        'client'     => $client,
        'placements' => $placements_client
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* Redirection par défaut */
header('Location: ' . url('admin/placements.php'));
exit;
?>