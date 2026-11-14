<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/prets.php';
requireRole('admin');

$pdo = Database::getConnection();
$admin_id = (int)$_SESSION['admin_id'];
$action = $_POST['action'] ?? '';

try {
    if (!verifyCsrf($_POST['csrf'] ?? null)) throw new RuntimeException("Jeton CSRF invalide.");
    
    $id_pret = (int)($_POST['id_pret'] ?? 0);
    if ($id_pret <= 0) throw new RuntimeException("Prêt invalide.");
    
    $pdo->beginTransaction();
    
    $stmt = $pdo->prepare("SELECT * FROM Prets WHERE id_pret = ? FOR UPDATE");
    $stmt->execute([$id_pret]);
    $p = $stmt->fetch();
    
    if (!$p) throw new RuntimeException("Prêt introuvable.");
    
    /* ============================================================
       APPROUVER
       ============================================================ */
    if ($action === 'approuver') {
        if (!in_array($p['statut'], ['soumis','en_analyse'], true)) {
            throw new RuntimeException("Statut non compatible avec l'approbation.");
        }
        
        $montant_accorde = (float)($_POST['montant_accorde'] ?? $p['montant_demande']);
        $taux = (float)($_POST['taux'] ?? 0.24);
        
        if ($montant_accorde <= 0) throw new RuntimeException("Montant invalide.");
        if ($taux <= 0 || $taux > 0.5) throw new RuntimeException("Taux invalide.");
        
        // Recalculer score
        $score = calculerScoreCredit($pdo, (int)$p['id_client']);
        
        $pdo->prepare("
            UPDATE Prets SET
                montant_accorde = ?, taux_applique = ?, score_credit = ?, niveau_score = ?,
                statut = 'approuve', date_approbation = NOW(), approuve_par = ?
            WHERE id_pret = ?
        ")->execute([$montant_accorde, $taux, $score['score'], $score['niveau'], $admin_id, $id_pret]);
        
        logPret($pdo, $id_pret, 'PRET_APPROUVE', $p['statut'], 'approuve', 'admin', $admin_id, "Montant accordé : $montant_accorde {$p['devise']}");
        
        $_SESSION['flash_success'] = "✅ Prêt approuvé !";
    }
    
    /* ============================================================
       REJETER
       ============================================================ */
    elseif ($action === 'rejeter') {
        if (!in_array($p['statut'], ['soumis','en_analyse'], true)) {
            throw new RuntimeException("Statut non compatible avec le rejet.");
        }
        
        $motif = cleanString($_POST['motif'] ?? '', 500);
        if ($motif === '') throw new RuntimeException("Motif obligatoire.");
        
        $pdo->prepare("
            UPDATE Prets SET statut = 'rejete', motif_rejet = ? WHERE id_pret = ?
        ")->execute([$motif, $id_pret]);
        
        logPret($pdo, $id_pret, 'PRET_REJETE', $p['statut'], 'rejete', 'admin', $admin_id, $motif);
        
        $_SESSION['flash_success'] = "❌ Prêt rejeté.";
    }
    
    /* ============================================================
       DÉCAISSER
       ============================================================ */
    elseif ($action === 'decaisser') {
        if ($p['statut'] !== 'approuve') throw new RuntimeException("Le prêt doit d'abord être approuvé.");
        if (!$p['montant_accorde'] || $p['montant_accorde'] <= 0) throw new RuntimeException("Montant accordé manquant.");
        
        // Créditer le client
        $col = $p['devise'] === 'USD' ? 'solde_usd' : 'solde_cdf';
        $pdo->prepare("UPDATE Client SET $col = $col + ? WHERE id_client = ?")
            ->execute([(float)$p['montant_accorde'], (int)$p['id_client']]);
        
        // Générer échéancier
        $date_debut = date('Y-m-d');
        $echeances = genererEcheancier($pdo, $id_pret, (float)$p['montant_accorde'], (float)$p['taux_applique'], (int)$p['duree_mois'], $date_debut);
        
        $total_du = array_sum(array_column($echeances, 'total'));
        $date_fin = end($echeances)['date'] ?? null;
        
        $pdo->prepare("
            UPDATE Prets SET
                statut = 'en_cours', date_decaissement = NOW(), decaisse_par = ?,
                montant_total_du = ?, montant_restant_du = ?, date_echeance_final = ?
            WHERE id_pret = ?
        ")->execute([$admin_id, $total_du, $total_du, $date_fin, $id_pret]);
        
        // Transaction
        $ref = 'DEC-' . strtoupper(bin2hex(random_bytes(6)));
        $pdo->prepare("
            INSERT INTO Transactions 
                (id_client, type_transaction, montant, devise, mode, effectue_par, solde_avant, solde_apres, reference, description, ip_address)
            VALUES (?, 'Depot', ?, ?, 'Agence', 'Admin', 0, 0, ?, ?, ?)
        ")->execute([
            (int)$p['id_client'], (float)$p['montant_accorde'], $p['devise'], $ref,
            "Décaissement prêt {$p['reference']}", $_SERVER['REMOTE_ADDR'] ?? null
        ]);
        
        logPret($pdo, $id_pret, 'PRET_DECAISSE', $p['statut'], 'en_cours', 'admin', $admin_id, "Montant : $total_du {$p['devise']}");
        
        $_SESSION['flash_success'] = "💰 Prêt décaissé ! Total à rembourser : " . formaterMontant($total_du, $p['devise']);
    }
    
    else {
        throw new RuntimeException("Action inconnue.");
    }
    
    $pdo->commit();
    
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['flash_error'] = $e->getMessage();
    error_log('[ATF PRET ACTION] ' . $e->getMessage());
}

header('Location: ' . url('admin/prets.php'));
exit;