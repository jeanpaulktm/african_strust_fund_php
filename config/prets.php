<?php
declare(strict_types=1);

/**
 * ============================================================
 * MODULE PRÊT — Helpers métier + Sécurité
 * AFRICAN TRUST FUND
 * ============================================================
 */

const PRET_DOC_MAX_SIZE = 5 * 1024 * 1024;
const PRET_DOC_MIME_WHITELIST = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'application/pdf' => 'pdf',
];
const PRET_UPLOAD_DIR = __DIR__ . '/../uploads/pret_documents/';

/* ============================================================
   GÉNÉRATION DE RÉFÉRENCE UNIQUE
   ============================================================ */
function genererReferencePret(PDO $pdo): string
{
    $annee  = date('Y');
    $prefix = "PRT-$annee-";
    
    $stmt = $pdo->prepare("SELECT reference FROM Prets WHERE reference LIKE ? ORDER BY reference DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();
    
    $num = 1;
    if ($last) $num = (int)substr($last, -6) + 1;
    
    return $prefix . str_pad((string)$num, 6, '0', STR_PAD_LEFT);
}

/* ============================================================
   CALCUL DE LA MENSUALITÉ (formule dégressive)
   ============================================================ */
function calculerMensualite(float $capital, float $taux_annuel, int $duree_mois): float
{
    if ($taux_annuel <= 0) return round($capital / $duree_mois, 2);
    
    $taux_mensuel = $taux_annuel / 12;
    $facteur = pow(1 + $taux_mensuel, -$duree_mois);
    $mensualite = $capital * $taux_mensuel / (1 - $facteur);
    
    return round($mensualite, 2);
}

/* ============================================================
   GÉNÉRATION ÉCHÉANCIER
   ============================================================ */
function genererEcheancier(PDO $pdo, int $id_pret, float $capital, float $taux_annuel, int $duree_mois, string $date_debut): array
{
    $mensualite = calculerMensualite($capital, $taux_annuel, $duree_mois);
    $taux_mensuel = $taux_annuel / 12;
    $capital_restant = $capital;
    $echeances = [];
    $date_courante = new DateTime($date_debut);
    
    $pdo->prepare("DELETE FROM Echeances_Pret WHERE id_pret = ?")->execute([$id_pret]);
    
    $stmt = $pdo->prepare("
        INSERT INTO Echeances_Pret 
            (id_pret, numero_echeance, date_echeance, montant_capital, montant_interet, montant_total, statut)
        VALUES (?, ?, ?, ?, ?, ?, 'en_attente')
    ");
    
    for ($i = 1; $i <= $duree_mois; $i++) {
        $date_courante->modify('+1 month');
        $date_echeance = $date_courante->format('Y-m-d');
        
        $interet = round($capital_restant * $taux_mensuel, 2);
        $capital_part = round($mensualite - $interet, 2);
        
        if ($i === $duree_mois) {
            $capital_part = round($capital_restant, 2);
            $total = round($capital_part + $interet, 2);
        } else {
            $total = $mensualite;
        }
        
        $capital_restant = round($capital_restant - $capital_part, 2);
        
        $stmt->execute([$id_pret, $i, $date_echeance, $capital_part, $interet, $total]);
        
        $echeances[] = ['numero' => $i, 'date' => $date_echeance, 'capital' => $capital_part, 'interet' => $interet, 'total' => $total];
    }
    
    return $echeances;
}

/* ============================================================
   SCORE DE CRÉDIT (0-100)
   ============================================================ */
function calculerScoreCredit(PDO $pdo, int $id_client): array
{
    $stmt = $pdo->prepare("SELECT * FROM Client WHERE id_client = ?");
    $stmt->execute([$id_client]);
    $client = $stmt->fetch();
    if (!$client) return ['score' => 0, 'niveau' => 'mauvais', 'fiabilite' => 0, 'competence' => 0, 'bonne_volonte' => 0, 'details' => []];
    
    $fiab = 0; $comp = 0; $bv = 0;
    $details = [];
    
    /* FIABILITÉ (/33) */
    $anciennete = (int)((time() - strtotime($client['created_at'])) / (30 * 86400));
    if ($anciennete >= 24) $fiab += 10;
    elseif ($anciennete >= 12) $fiab += 7;
    elseif ($anciennete >= 6) $fiab += 5;
    elseif ($anciennete >= 3) $fiab += 3;
    $details['anciennete_mois'] = $anciennete;
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM Prets WHERE id_client = ? AND statut = 'solde'");
    $stmt->execute([$id_client]);
    $prets_ok = (int)$stmt->fetchColumn();
    $fiab += min(10, $prets_ok * 5);
    $details['prets_rembourses'] = $prets_ok;
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM Prets WHERE id_client = ? AND statut IN ('en_retard','contentieux')");
    $stmt->execute([$id_client]);
    $retards = (int)$stmt->fetchColumn();
    if ($retards > 0) $fiab -= 8 * $retards;
    $details['retards'] = $retards;
    
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT MONTH(date_transaction)) FROM Transactions WHERE id_client = ? AND type_transaction = 'Depot' AND date_transaction >= DATE_SUB(NOW(), INTERVAL 12 MONTH)");
    $stmt->execute([$id_client]);
    $mois_actifs = (int)$stmt->fetchColumn();
    $fiab += min(13, $mois_actifs);
    $details['mois_actifs'] = $mois_actifs;
    
    $fiab = max(0, min(33, $fiab));
    
    /* COMPÉTENCE (/33) */
    $epargne = (float)($client['solde_epargne_cdf'] ?? 0);
    if ($epargne >= 1000000) $comp += 12;
    elseif ($epargne >= 500000) $comp += 9;
    elseif ($epargne >= 100000) $comp += 6;
    elseif ($epargne >= 50000) $comp += 3;
    $details['epargne_cdf'] = $epargne;
    
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT type_document) FROM Client_Documents WHERE id_client = ? AND verifie = TRUE");
    $stmt->execute([$id_client]);
    $docs = (int)$stmt->fetchColumn();
    $comp += min(10, $docs * 2);
    $details['documents_verifies'] = $docs;
    
    $revenu = (float)($client['revenu_mensuel'] ?? 0);
    if ($revenu >= 500000) $comp += 11;
    elseif ($revenu >= 200000) $comp += 8;
    elseif ($revenu >= 100000) $comp += 5;
    elseif ($revenu >= 50000) $comp += 3;
    $details['revenu_mensuel'] = $revenu;
    
    $comp = max(0, min(33, $comp));
    
    /* BONNE VOLONTÉ (/34) */
    if (($client['statut'] ?? '') === 'actif') $bv += 20;
    $details['statut'] = $client['statut'] ?? 'inconnu';
    
    $note = (int)($client['note_comite'] ?? 0);
    $bv += min(14, $note);
    $details['note_comite'] = $note;
    
    $bv = max(0, min(34, $bv));
    
    $total = $fiab + $comp + $bv;
    $niveau = 'mauvais';
    if ($total >= 80) $niveau = 'excellent';
    elseif ($total >= 60) $niveau = 'bon';
    elseif ($total >= 40) $niveau = 'moyen';
    elseif ($total >= 20) $niveau = 'faible';
    
    return [
        'score' => $total,
        'niveau' => $niveau,
        'fiabilite' => $fiab,
        'competence' => $comp,
        'bonne_volonte' => $bv,
        'details' => $details,
    ];
}

/* ============================================================
   SAUVEGARDER SCORE
   ============================================================ */
function sauvegarderScoreCredit(PDO $pdo, int $id_client, array $score, string $type_client): int
{
    $stmt = $pdo->prepare("
        INSERT INTO Scores_Credit 
            (id_client, type_client, score_global, score_fiabilite, score_competence, score_bonne_volonte, niveau, details_json, valide_jusqu_a)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, DATE_ADD(CURDATE(), INTERVAL 6 MONTH))
    ");
    $stmt->execute([
        $id_client, $type_client,
        $score['score'], $score['fiabilite'], $score['competence'], $score['bonne_volonte'],
        $score['niveau'], json_encode($score['details'], JSON_UNESCAPED_UNICODE)
    ]);
    
    return (int)$pdo->lastInsertId();
}

/* ============================================================
   UPLOAD SÉCURISÉ DE DOCUMENT
   ============================================================ */
function uploadDocumentSecurise(array $file, string $prefix = 'doc_'): array
{
    if ($file['error'] !== UPLOAD_ERR_OK) throw new RuntimeException("Erreur upload : code " . $file['error']);
    if ($file['size'] > PRET_DOC_MAX_SIZE) throw new RuntimeException("Fichier trop grand (max 5 Mo).");
    
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    
    if (!isset(PRET_DOC_MIME_WHITELIST[$mime])) {
        throw new RuntimeException("Format non autorisé. Acceptés : JPG, PNG, WEBP, PDF.");
    }
    
    $extension = PRET_DOC_MIME_WHITELIST[$mime];
    $nom_fichier = $prefix . bin2hex(random_bytes(16)) . '.' . $extension;
    
    if (!is_dir(PRET_UPLOAD_DIR)) mkdir(PRET_UPLOAD_DIR, 0750, true);
    
    $chemin_final = PRET_UPLOAD_DIR . $nom_fichier;
    
    if (!move_uploaded_file($file['tmp_name'], $chemin_final)) {
        throw new RuntimeException("Échec de l'enregistrement.");
    }
    
    chmod($chemin_final, 0640);
    $hash = hash_file('sha256', $chemin_final);
    
    return [
        'nom_fichier' => $nom_fichier,
        'chemin' => 'uploads/pret_documents/' . $nom_fichier,
        'hash' => $hash,
        'mime' => $mime,
        'taille' => $file['size'],
    ];
}

/* ============================================================
   FORMATER MONTANT
   ============================================================ */
function formaterMontant(float $montant, string $devise): string
{
    if ($devise === 'USD') return '$ ' . number_format($montant, 2, '.', ',');
    return number_format($montant, 0, ',', ' ') . ' CDF';
}

/* ============================================================
   LOGGING PRÊT
   ============================================================ */
function logPret(PDO $pdo, int $id_pret, string $action, ?string $ancien, string $nouveau, string $role, int $user_id, string $comm = ''): void
{
    try {
        $pdo->prepare("
            INSERT INTO Prets_Historique 
                (id_pret, ancien_statut, nouveau_statut, action, effectue_par_role, effectue_par_id, commentaire, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([$id_pret, $ancien, $nouveau, $action, $role, $user_id, $comm, $_SERVER['REMOTE_ADDR'] ?? null, substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);
    } catch (Throwable $e) {
        error_log('[ATF PRET LOG] ' . $e->getMessage());
    }
}

/* ============================================================
   PEUT DEMANDER UN PRÊT ?
   ============================================================ */
function peutDemanderPret(PDO $pdo, int $id_client): array
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM Prets WHERE id_client = ? AND statut NOT IN ('solde','rejete','annule')");
    $stmt->execute([$id_client]);
    if ((int)$stmt->fetchColumn() > 0) {
        return ['ok' => false, 'raison' => 'Vous avez déjà un prêt en cours.'];
    }
    
    $stmt = $pdo->prepare("SELECT statut, created_at FROM Client WHERE id_client = ?");
    $stmt->execute([$id_client]);
    $c = $stmt->fetch();
    
    if (!$c || $c['statut'] !== 'actif') {
        return ['ok' => false, 'raison' => 'Votre compte doit être actif.'];
    }
    
    $anciennete = (int)((time() - strtotime($c['created_at'])) / (30 * 86400));
    if ($anciennete < 3) {
        return ['ok' => false, 'raison' => 'Vous devez avoir au moins 3 mois d\'ancienneté.'];
    }
    
    return ['ok' => true, 'raison' => ''];
}

/* ============================================================
   STATUTS AUTORISÉS (whitelist)
   ============================================================ */
const PRET_STATUTS = [
    'brouillon','soumis','en_analyse','approuve','signe','decaisse',
    'en_cours','en_retard','solde','rejete','annule','contentieux'
];

/* ============================================================
   BADGE STATUT (affichage)
   ============================================================ */
function badgeStatutPret(string $statut): string
{
    $map = [
        'brouillon' => ['#6B7280', '📝 Brouillon'],
        'soumis' => ['#3B82F6', '📤 Soumis'],
        'en_analyse' => ['#8B5CF6', '🔍 En analyse'],
        'approuve' => ['#10B981', '✅ Approuvé'],
        'signe' => ['#06B6D4', '✍️ Signé'],
        'decaisse' => ['#F5B800', '💰 Décaissé'],
        'en_cours' => ['#F5B800', '🔄 En cours'],
        'en_retard' => ['#EF4444', '⚠️ En retard'],
        'solde' => ['#10B981', '🎉 Soldé'],
        'rejete' => ['#DC2626', '❌ Rejeté'],
        'annule' => ['#6B7280', '🚫 Annulé'],
        'contentieux' => ['#991B1B', '⚖️ Contentieux'],
    ];
    [$color, $label] = $map[$statut] ?? ['#6B7280', $statut];
    return "<span style='display:inline-block;padding:3px 10px;border-radius:999px;background:{$color}20;color:{$color};border:1px solid {$color}50;font-size:.72rem;font-weight:800'>$label</span>";
}
?>