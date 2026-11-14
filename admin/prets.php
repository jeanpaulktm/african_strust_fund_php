<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/prets.php';
requireRole('admin');

$pdo = Database::getConnection();
$admin_id = (int)$_SESSION['admin_id'];

$errors  = [];
$success = '';

/* ============================================================
   TRAITEMENT DES ACTIONS POST
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) {
            throw new RuntimeException("Jeton de sécurité invalide.");
        }

        $id_pret = (int)($_POST['id_pret'] ?? 0);
        if ($id_pret <= 0) throw new RuntimeException("Prêt invalide.");

        /* ============================================================
           METTRE EN ANALYSE
           ============================================================ */
        if ($action === 'en_analyse') {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("SELECT * FROM Prets WHERE id_pret = ? FOR UPDATE");
            $stmt->execute([$id_pret]);
            $pret = $stmt->fetch();
            
            if (!$pret) throw new RuntimeException("Prêt introuvable.");
            if ($pret['statut'] !== 'soumis') {
                throw new RuntimeException("Seuls les prêts au statut 'soumis' peuvent passer en analyse.");
            }
            
            $pdo->prepare("
                UPDATE Prets 
                SET statut = 'en_analyse', analyse_par = ?, date_analyse = NOW() 
                WHERE id_pret = ?
            ")->execute([$admin_id, $id_pret]);
            
            logPret($pdo, $id_pret, 'PRET_EN_ANALYSE', 'soumis', 'en_analyse', 'admin', $admin_id, '');
            
            $pdo->commit();
            
            $_SESSION['flash_success'] = "🔍 Prêt <strong>{$pret['reference']}</strong> mis en analyse.";
            header('Location: ' . url('admin/prets.php'));
            exit;
        }

        /* ============================================================
           APPROUVER
           ============================================================ */
        if ($action === 'approuver') {
            $montant_accorde = (float)str_replace([' ', ','], ['', '.'], $_POST['montant_accorde'] ?? '0');
            $taux_pct = (float)($_POST['taux'] ?? 0);
            $commentaire = cleanString($_POST['commentaire'] ?? '', 500);

            if ($montant_accorde <= 0) throw new RuntimeException("Montant accordé invalide.");
            if ($taux_pct <= 0 || $taux_pct > 50) throw new RuntimeException("Taux invalide (0-50%).");

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM Prets WHERE id_pret = ? FOR UPDATE");
            $stmt->execute([$id_pret]);
            $pret = $stmt->fetch();

            if (!$pret) throw new RuntimeException("Prêt introuvable.");
            if (!in_array($pret['statut'], ['soumis','en_analyse'], true)) {
                throw new RuntimeException("Ce prêt ne peut pas être approuvé (statut actuel : {$pret['statut']}).");
            }

            $score = calculerScoreCredit($pdo, (int)$pret['id_client']);

            $pdo->prepare("
                UPDATE Prets SET
                    montant_accorde = ?,
                    taux_applique = ?,
                    score_credit = ?,
                    niveau_score = ?,
                    statut = 'approuve',
                    date_approbation = NOW(),
                    approuve_par = ?
                WHERE id_pret = ?
            ")->execute([
                $montant_accorde,
                $taux_pct / 100,
                $score['score'],
                $score['niveau'],
                $admin_id,
                $id_pret
            ]);

            logPret($pdo, $id_pret, 'PRET_APPROUVE', $pret['statut'], 'approuve', 'admin', $admin_id,
                "Montant accordé : " . formaterMontant($montant_accorde, $pret['devise']) .
                ($commentaire ? " | Note : $commentaire" : "")
            );

            $pdo->commit();

            $_SESSION['flash_success'] = "✅ Prêt <strong>{$pret['reference']}</strong> approuvé !<br>
                Montant : " . formaterMontant($montant_accorde, $pret['devise']);

            header('Location: ' . url('admin/prets.php'));
            exit;
        }

        /* ============================================================
           REJETER
           ============================================================ */
        if ($action === 'rejeter') {
            $motif = cleanString($_POST['motif'] ?? '', 500);
            if ($motif === '') throw new RuntimeException("Le motif du rejet est obligatoire.");

            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM Prets WHERE id_pret = ? FOR UPDATE");
            $stmt->execute([$id_pret]);
            $pret = $stmt->fetch();

            if (!$pret) throw new RuntimeException("Prêt introuvable.");
            if (!in_array($pret['statut'], ['soumis','en_analyse','approuve'], true)) {
                throw new RuntimeException("Ce prêt ne peut pas être rejeté (statut actuel : {$pret['statut']}).");
            }

            $pdo->prepare("UPDATE Prets SET statut = 'rejete', motif_rejet = ? WHERE id_pret = ?")
                ->execute([$motif, $id_pret]);

            logPret($pdo, $id_pret, 'PRET_REJETE', $pret['statut'], 'rejete', 'admin', $admin_id, "Motif : $motif");

            $pdo->commit();

            $_SESSION['flash_success'] = "❌ Prêt <strong>{$pret['reference']}</strong> rejeté.";

            header('Location: ' . url('admin/prets.php'));
            exit;
        }

        /* ============================================================
           DÉCAISSER
           ============================================================ */
        if ($action === 'decaisser') {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM Prets WHERE id_pret = ? FOR UPDATE");
            $stmt->execute([$id_pret]);
            $pret = $stmt->fetch();

            if (!$pret) throw new RuntimeException("Prêt introuvable.");
            if ($pret['statut'] !== 'approuve') {
                throw new RuntimeException("Le prêt doit d'abord être approuvé.");
            }
            if (!$pret['montant_accorde'] || $pret['montant_accorde'] <= 0) {
                throw new RuntimeException("Montant accordé manquant.");
            }

            $devise = $pret['devise'];
            $col_solde = $devise === 'USD' ? 'solde_usd' : 'solde_cdf';

            $pdo->prepare("UPDATE Client SET $col_solde = $col_solde + ? WHERE id_client = ?")
                ->execute([(float)$pret['montant_accorde'], (int)$pret['id_client']]);

            $date_debut = date('Y-m-d');
            $echeances = genererEcheancier(
                $pdo, $id_pret,
                (float)$pret['montant_accorde'],
                (float)$pret['taux_applique'],
                (int)$pret['duree_mois'],
                $date_debut
            );

            $total_du = array_sum(array_column($echeances, 'total'));
            $date_fin = end($echeances)['date'] ?? null;

            $pdo->prepare("
                UPDATE Prets SET
                    statut = 'en_cours',
                    date_decaissement = NOW(),
                    decaisse_par = ?,
                    montant_total_du = ?,
                    montant_restant_du = ?,
                    date_echeance_final = ?
                WHERE id_pret = ?
            ")->execute([$admin_id, $total_du, $total_du, $date_fin, $id_pret]);

            $ref = 'DEC-' . strtoupper(bin2hex(random_bytes(6)));
            $pdo->prepare("
                INSERT INTO Transactions 
                    (id_client, type_transaction, montant, devise, mode, effectue_par,
                     solde_avant, solde_apres, reference, description, ip_address)
                VALUES (?, 'Depot', ?, ?, 'Agence', 'Admin', 0, 0, ?, ?, ?)
            ")->execute([
                (int)$pret['id_client'],
                (float)$pret['montant_accorde'],
                $devise,
                $ref,
                "Décaissement prêt {$pret['reference']}",
                $_SERVER['REMOTE_ADDR'] ?? null
            ]);

            logPret($pdo, $id_pret, 'PRET_DECAISSE', 'approuve', 'en_cours', 'admin', $admin_id,
                "Montant décaissé : " . formaterMontant((float)$pret['montant_accorde'], $devise)
            );

            $pdo->commit();

            $_SESSION['flash_success'] = "💰 Prêt <strong>{$pret['reference']}</strong> décaissé !<br>
                Montant versé : " . formaterMontant((float)$pret['montant_accorde'], $devise) . "<br>
                Total à rembourser : " . formaterMontant($total_du, $devise);

            header('Location: ' . url('admin/prets.php'));
            exit;
        }

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = $e->getMessage();
    }
}

/* Flash */
if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

/* ============================================================
   FILTRES
   ============================================================ */
$statut_filtre = $_GET['statut'] ?? '';
$type_client   = in_array($_GET['type'] ?? '', ['physique','morale'], true) ? $_GET['type'] : '';
$devise_filtre = in_array($_GET['devise'] ?? '', ['CDF','USD'], true) ? $_GET['devise'] : '';
$recherche     = trim($_GET['q'] ?? '');
$tri           = $_GET['tri'] ?? 'recent';

/* ============================================================
   CONSTRUCTION DE LA REQUÊTE
   ============================================================ */
$sql = "
    SELECT 
        p.*,
        c.nom, c.postnom, c.prenom, c.numero_compte, c.numero_phone, 
        c.photo_profil,
        pr.nom AS produit_nom, 
        pr.taux_annuel AS produit_taux,
        pr.methode_interet,
        DATEDIFF(CURDATE(), p.date_demande) AS jours_depuis_demande,
        (SELECT COUNT(*) FROM Echeances_Pret e WHERE e.id_pret = p.id_pret) AS nb_echeances,
        (SELECT COUNT(*) FROM Echeances_Pret e WHERE e.id_pret = p.id_pret AND e.statut = 'paye') AS nb_echeances_payees
    FROM Prets p
    JOIN Client c ON c.id_client = p.id_client
    JOIN Produits_Pret pr ON pr.id_produit = p.id_produit
    WHERE 1=1
";
$params = [];

if ($statut_filtre !== '' && in_array($statut_filtre, PRET_STATUTS, true)) {
    $sql .= " AND p.statut = ?";
    $params[] = $statut_filtre;
}
if ($type_client !== '') {
    $sql .= " AND p.type_client = ?";
    $params[] = $type_client;
}
if ($devise_filtre !== '') {
    $sql .= " AND p.devise = ?";
    $params[] = $devise_filtre;
}
if ($recherche !== '') {
    $sql .= " AND (c.nom LIKE ? OR c.postnom LIKE ? OR c.prenom LIKE ? 
                   OR c.numero_compte LIKE ? OR c.numero_phone LIKE ?
                   OR p.reference LIKE ?)";
    $like = "%$recherche%";
    array_push($params, $like, $like, $like, $like, $like, $like);
}

$sql .= match($tri) {
    'montant_desc' => " ORDER BY p.montant_demande DESC",
    'montant_asc'  => " ORDER BY p.montant_demande ASC",
    'client'       => " ORDER BY c.nom ASC",
    'ancien'       => " ORDER BY p.date_demande ASC",
    'statut'       => " ORDER BY FIELD(p.statut, 'soumis','en_analyse','approuve','decaisse','en_cours','en_retard','solde','rejete','annule','contentieux'), p.date_demande DESC",
    default        => " ORDER BY p.date_demande DESC",
};

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$prets = $stmt->fetchAll();

/* ============================================================
   STATISTIQUES GLOBALES
   ============================================================ */
$stats = [
    'total'      => (int)$pdo->query("SELECT COUNT(*) FROM Prets")->fetchColumn(),
    'soumis'     => (int)$pdo->query("SELECT COUNT(*) FROM Prets WHERE statut = 'soumis'")->fetchColumn(),
    'en_analyse' => (int)$pdo->query("SELECT COUNT(*) FROM Prets WHERE statut = 'en_analyse'")->fetchColumn(),
    'approuve'   => (int)$pdo->query("SELECT COUNT(*) FROM Prets WHERE statut = 'approuve'")->fetchColumn(),
    'en_cours'   => (int)$pdo->query("SELECT COUNT(*) FROM Prets WHERE statut IN ('decaisse','en_cours')")->fetchColumn(),
    'en_retard'  => (int)$pdo->query("SELECT COUNT(*) FROM Prets WHERE statut = 'en_retard'")->fetchColumn(),
    'rejete'     => (int)$pdo->query("SELECT COUNT(*) FROM Prets WHERE statut IN ('rejete','annule')")->fetchColumn(),
    'solde'      => (int)$pdo->query("SELECT COUNT(*) FROM Prets WHERE statut = 'solde'")->fetchColumn(),
];

$montants = [
    'encours_cdf' => (float)$pdo->query("SELECT COALESCE(SUM(montant_restant_du),0) FROM Prets WHERE devise='CDF' AND statut IN ('en_cours','en_retard')")->fetchColumn(),
    'encours_usd' => (float)$pdo->query("SELECT COALESCE(SUM(montant_restant_du),0) FROM Prets WHERE devise='USD' AND statut IN ('en_cours','en_retard')")->fetchColumn(),
    'total_accorde' => (float)$pdo->query("SELECT COALESCE(SUM(montant_accorde),0) FROM Prets WHERE statut NOT IN ('rejete','annule')")->fetchColumn(),
];

$base = '/african-trust-fund';

logAction($pdo, 'admin', $admin_id, 'PRETS_VIEW', 'Consultation liste des prêts');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Gestion des Prêts — ATF Admin</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/admin-premium.css">
<style>
/* ============================================================
   HERO
   ============================================================ */
.page-hero {
  padding: 24px 28px;
  border-radius: 20px;
  background: linear-gradient(135deg, rgba(59,130,246,.12), rgba(30,64,175,.04)),
              radial-gradient(circle at 80% 20%, rgba(59,130,246,.15), transparent 60%);
  border: 1px solid rgba(59,130,246,.35);
  margin-bottom: 24px;
  position: relative;
  overflow: hidden;
}
.page-hero::before {
  content: "";
  position: absolute;
  top: -40%; right: -10%;
  width: 300px; height: 300px;
  border-radius: 50%;
  background: radial-gradient(circle, rgba(59,130,246,.2), transparent 70%);
  animation: float 8s ease-in-out infinite;
}
.page-hero h2 {
  color: #93B4FF;
  font-size: 1.5rem;
  margin: 0 0 6px 0;
  position: relative;
  z-index: 1;
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}
.page-hero p {
  color: var(--muted);
  font-size: .92rem;
  position: relative;
  z-index: 1;
  margin: 0;
}

/* ============================================================
   KPIs
   ============================================================ */
.kpi-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 14px;
  margin-bottom: 24px;
}
.stat-block {
  padding: 18px;
  border-radius: 16px;
  background: linear-gradient(145deg, var(--card), var(--card-2));
  border: 1px solid var(--border);
  display: flex;
  gap: 12px;
  align-items: center;
  transition: all .25s;
  cursor: pointer;
  text-decoration: none;
  color: inherit;
}
.stat-block:hover {
  transform: translateY(-3px);
  border-color: rgba(245,184,0,.4);
  box-shadow: 0 12px 30px rgba(0,0,0,.3);
}
.stat-block .ico { font-size: 1.8rem; }
.stat-block .meta strong {
  display: block;
  font-size: 1.5rem;
  color: var(--gold);
  line-height: 1;
}
.stat-block .meta span {
  font-size: .7rem;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 1px;
  font-weight: 700;
  margin-top: 4px;
  display: block;
}
.stat-block.blue .meta strong { color: #93B4FF; }
.stat-block.green .meta strong { color: #6EE7B7; }
.stat-block.red .meta strong { color: #FCA5A5; }
.stat-block.purple .meta strong { color: #A78BFA; }

/* ============================================================
   ONGLETS FILTRES
   ============================================================ */
.filters-tabs {
  display: flex;
  gap: 8px;
  margin-bottom: 18px;
  flex-wrap: wrap;
}
.filters-tabs a {
  padding: 10px 16px;
  border-radius: 999px;
  background: var(--card);
  border: 1px solid var(--border);
  color: var(--text);
  text-decoration: none;
  font-weight: 700;
  font-size: .82rem;
  transition: all .2s;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.filters-tabs a:hover { border-color: var(--gold); }
.filters-tabs a.active {
  background: linear-gradient(135deg, #F5B800, #D4AF37);
  color: #0B0E14;
  border-color: transparent;
}
.filters-tabs .count {
  background: rgba(0,0,0,.25);
  padding: 2px 8px;
  border-radius: 999px;
  font-size: .7rem;
  font-weight: 800;
}
.filters-tabs a.active .count { background: rgba(0,0,0,.2); color: #0B0E14; }

/* ============================================================
   BARRE RECHERCHE
   ============================================================ */
.search-bar {
  display: grid;
  grid-template-columns: 2fr 1fr 1fr 1fr auto;
  gap: 12px;
  padding: 16px;
  background: linear-gradient(145deg, var(--card), var(--card-2));
  border: 1px solid var(--border);
  border-radius: 14px;
  margin-bottom: 20px;
}
.search-bar input,
.search-bar select {
  padding: 12px 14px;
  background: var(--bg-1);
  border: 1px solid var(--border);
  border-radius: 10px;
  color: var(--text);
  font-size: .9rem;
  font-family: inherit;
}
.search-bar input:focus,
.search-bar select:focus {
  outline: none;
  border-color: var(--gold);
}
@media (max-width: 1000px) {
  .search-bar { grid-template-columns: 1fr 1fr; }
}

/* ============================================================
   TABLEAU
   ============================================================ */
.pret-table {
  width: 100%;
  border-collapse: collapse;
  background: linear-gradient(145deg, var(--card), var(--card-2));
  border-radius: 14px;
  overflow: hidden;
  font-size: .85rem;
}
.pret-table th {
  padding: 14px 10px;
  text-align: left;
  font-size: .68rem;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: .5px;
  font-weight: 800;
  background: rgba(11,14,20,.5);
  border-bottom: 2px solid var(--border);
  white-space: nowrap;
}
.pret-table td {
  padding: 12px 10px;
  border-bottom: 1px solid var(--border);
  vertical-align: middle;
}
.pret-table tr:hover {
  background: rgba(245,184,0,.03);
}
.pret-table tr:last-child td { border-bottom: none; }

/* Cellule client */
.client-cell {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 160px;
}
.client-photo-mini {
  width: 38px; height: 38px;
  border-radius: 50%;
  object-fit: cover;
  border: 2px solid var(--gold);
  flex-shrink: 0;
}
.client-photo-placeholder {
  width: 38px; height: 38px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #0D1B5E, #1E293B);
  color: var(--gold);
  font-weight: 800;
  font-size: .8rem;
  border: 2px solid var(--gold);
  flex-shrink: 0;
}
.client-info-mini .name {
  font-weight: 700;
  font-size: .85rem;
  color: var(--text);
  white-space: nowrap;
}
.client-info-mini .compte {
  font-family: monospace;
  font-size: .7rem;
  color: var(--gold);
}

/* Badges de statut */
.statut-badge {
  display: inline-block;
  padding: 4px 10px;
  border-radius: 999px;
  font-size: .68rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: .5px;
  white-space: nowrap;
}
.statut-badge.soumis { background: rgba(59,130,246,.15); color: #93B4FF; border: 1px solid rgba(59,130,246,.35); }
.statut-badge.en_analyse { background: rgba(139,92,246,.15); color: #A78BFA; border: 1px solid rgba(139,92,246,.35); }
.statut-badge.approuve { background: rgba(16,185,129,.15); color: #6EE7B7; border: 1px solid rgba(16,185,129,.35); }
.statut-badge.signe { background: rgba(6,182,212,.15); color: #67E8F9; border: 1px solid rgba(6,182,212,.35); }
.statut-badge.decaisse,
.statut-badge.en_cours { background: rgba(245,184,0,.15); color: var(--gold); border: 1px solid rgba(245,184,0,.35); }
.statut-badge.en_retard { background: rgba(239,68,68,.15); color: #FCA5A5; border: 1px solid rgba(239,68,68,.35); }
.statut-badge.solde { background: rgba(16,185,129,.25); color: #6EE7B7; border: 1px solid rgba(16,185,129,.5); }
.statut-badge.rejete,
.statut-badge.annule { background: rgba(107,114,128,.15); color: #9CA3AF; border: 1px solid rgba(107,114,128,.3); }
.statut-badge.contentieux { background: rgba(153,27,27,.25); color: #FCA5A5; border: 1px solid rgba(153,27,27,.5); }

/* ============================================================
   BOUTON "VOIR PLUS" AMÉLIORÉ
   ============================================================ */
.btn-voir-plus {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 14px;
  border-radius: 10px;
  background: linear-gradient(135deg, #3B82F6, #2563EB);
  color: #fff !important;
  text-decoration: none;
  font-weight: 800;
  font-size: .72rem;
  transition: all .25s;
  box-shadow: 0 4px 12px rgba(59,130,246,.35);
  white-space: nowrap;
  border: 1px solid rgba(255,255,255,.15);
}
.btn-voir-plus:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 20px rgba(59,130,246,.5);
  color: #fff !important;
}
.btn-voir-plus .arrow {
  transition: transform .25s;
}
.btn-voir-plus:hover .arrow {
  transform: translateX(3px);
}

/* Actions */
.actions-cell {
  display: flex;
  gap: 5px;
  flex-wrap: wrap;
  justify-content: flex-end;
  align-items: center;
}
.btn-action-mini {
  padding: 6px 10px;
  border-radius: 8px;
  font-size: .68rem;
  font-weight: 800;
  border: 1px solid transparent;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 3px;
  transition: all .2s;
  text-decoration: none;
  font-family: inherit;
  white-space: nowrap;
}
.btn-action-mini:hover { transform: translateY(-1px); }

.btn-action-mini.approve { background: rgba(16,185,129,.15); color: #6EE7B7; border-color: rgba(16,185,129,.35); }
.btn-action-mini.approve:hover { background: rgba(16,185,129,.25); }
.btn-action-mini.reject { background: rgba(239,68,68,.15); color: #FCA5A5; border-color: rgba(239,68,68,.35); }
.btn-action-mini.reject:hover { background: rgba(239,68,68,.25); }
.btn-action-mini.analyse { background: rgba(139,92,246,.15); color: #A78BFA; border-color: rgba(139,92,246,.35); }
.btn-action-mini.analyse:hover { background: rgba(139,92,246,.25); }
.btn-action-mini.cash { background: rgba(245,184,0,.15); color: var(--gold); border-color: rgba(245,184,0,.35); }
.btn-action-mini.cash:hover { background: rgba(245,184,0,.25); }

/* Montants */
.montant-cell {
  text-align: right;
  white-space: nowrap;
}
.montant-cell .main {
  font-weight: 800;
  color: var(--gold);
  font-size: .9rem;
}
.montant-cell .sub {
  font-size: .7rem;
  color: var(--muted);
  margin-top: 2px;
}
.montant-cell .sub.green { color: #6EE7B7; }

/* ============================================================
   MODALES
   ============================================================ */
.modal-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(11,14,20,.9);
  backdrop-filter: blur(10px);
  z-index: 99999;
  align-items: center;
  justify-content: center;
  padding: 20px;
  overflow-y: auto;
}
.modal-overlay.active { display: flex; }

.modal-content {
  background: linear-gradient(145deg, #1A2130, #121824);
  border-radius: 20px;
  border: 1px solid rgba(245,184,0,.35);
  box-shadow: 0 30px 80px rgba(0,0,0,.8);
  padding: 28px 26px;
  max-width: 560px;
  width: 100%;
  max-height: 90vh;
  overflow-y: auto;
  animation: slideUp .3s;
}
@keyframes slideUp {
  from { transform: translateY(30px); opacity: 0; }
  to { transform: translateY(0); opacity: 1; }
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
  padding-bottom: 14px;
  border-bottom: 1px solid var(--border);
}
.modal-header h3 {
  color: var(--gold);
  font-size: 1.1rem;
  font-weight: 800;
  margin: 0;
  display: flex;
  align-items: center;
  gap: 8px;
}
.modal-close {
  width: 34px; height: 34px;
  border-radius: 50%;
  background: rgba(239,68,68,.15);
  color: #FCA5A5;
  border: 1px solid rgba(239,68,68,.3);
  cursor: pointer;
  font-size: 1.1rem;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 800;
  transition: all .2s;
}
.modal-close:hover { background: rgba(239,68,68,.25); }

.modal-info {
  padding: 14px 16px;
  border-radius: 12px;
  background: rgba(11,14,20,.6);
  border: 1px solid var(--border);
  margin-bottom: 18px;
}
.modal-info .row {
  display: flex;
  justify-content: space-between;
  padding: 5px 0;
  font-size: .85rem;
}
.modal-info .row:not(:last-child) {
  border-bottom: 1px dashed rgba(255,255,255,.05);
}
.modal-info .row .lbl { color: var(--muted); }
.modal-info .row .val { font-weight: 800; color: var(--text); }
.modal-info .row .val.gold { color: var(--gold); }
.modal-info .row .val.blue { color: #93B4FF; }
.modal-info .row .val.green { color: #6EE7B7; }

.form-group { margin-bottom: 16px; }
.form-group label {
  display: block;
  color: var(--muted);
  font-size: .75rem;
  text-transform: uppercase;
  letter-spacing: 1px;
  font-weight: 700;
  margin-bottom: 8px;
}
.form-group input,
.form-group textarea,
.form-group select {
  width: 100%;
  padding: 12px 14px;
  background: var(--bg-1);
  border: 1px solid var(--border);
  border-radius: 10px;
  color: var(--text);
  font-size: .92rem;
  font-family: inherit;
}
.form-group input:focus,
.form-group textarea:focus {
  outline: none;
  border-color: var(--gold);
  box-shadow: 0 0 0 3px rgba(245,184,0,.12);
}
.form-group textarea { resize: vertical; min-height: 70px; }
.form-group .hint {
  color: var(--muted);
  font-size: .72rem;
  margin-top: 5px;
}

.modal-actions {
  display: flex;
  gap: 10px;
  justify-content: flex-end;
  margin-top: 20px;
  padding-top: 16px;
  border-top: 1px solid var(--border);
  flex-wrap: wrap;
}
.btn-modal-cancel {
  padding: 11px 20px;
  border-radius: 10px;
  background: transparent;
  color: var(--text);
  border: 1px solid var(--border);
  font-weight: 700;
  cursor: pointer;
  font-family: inherit;
  font-size: .85rem;
}
.btn-modal-cancel:hover { background: rgba(255,255,255,.05); }
.btn-modal-approve {
  padding: 11px 20px;
  border-radius: 10px;
  background: linear-gradient(135deg, #10B981, #059669);
  color: #fff;
  border: none;
  font-weight: 800;
  cursor: pointer;
  font-family: inherit;
  font-size: .85rem;
}
.btn-modal-approve:hover { transform: translateY(-1px); box-shadow: 0 6px 18px rgba(16,185,129,.4); }
.btn-modal-reject {
  padding: 11px 20px;
  border-radius: 10px;
  background: linear-gradient(135deg, #DC2626, #EF4444);
  color: #fff;
  border: none;
  font-weight: 800;
  cursor: pointer;
  font-family: inherit;
  font-size: .85rem;
}
.btn-modal-reject:hover { transform: translateY(-1px); box-shadow: 0 6px 18px rgba(220,38,38,.4); }
.btn-modal-cash {
  padding: 11px 20px;
  border-radius: 10px;
  background: linear-gradient(135deg, #F5B800, #D4AF37);
  color: #0B0E14;
  border: none;
  font-weight: 800;
  cursor: pointer;
  font-family: inherit;
  font-size: .85rem;
}
.btn-modal-cash:hover { transform: translateY(-1px); box-shadow: 0 6px 18px rgba(245,184,0,.4); }

.danger-box {
  padding: 14px 16px;
  border-radius: 12px;
  background: rgba(239,68,68,.08);
  border: 1px solid rgba(239,68,68,.3);
  margin: 16px 0;
  color: #FCA5A5;
  font-size: .85rem;
  line-height: 1.6;
}
.info-box-modal {
  padding: 14px 16px;
  border-radius: 12px;
  background: rgba(59,130,246,.08);
  border: 1px solid rgba(59,130,246,.3);
  margin: 16px 0;
  color: #93B4FF;
  font-size: .85rem;
  line-height: 1.6;
}

/* Empty state */
.empty-state {
  text-align: center;
  padding: 60px 20px;
  color: var(--muted);
  background: linear-gradient(145deg, var(--card), var(--card-2));
  border-radius: 14px;
  border: 1px solid var(--border);
}
.empty-state .ico { font-size: 4rem; opacity: .4; margin-bottom: 16px; }

@keyframes float {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-15px); }
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 900px) {
  .admin-layout { grid-template-columns: 1fr; }
  .search-bar { grid-template-columns: 1fr; }
  .kpi-grid { grid-template-columns: 1fr 1fr; }
}

@media (max-width: 768px) {
  .pret-table thead { display: none; }
  .pret-table tbody tr {
    display: block;
    margin-bottom: 14px;
    padding: 16px;
    border: 1px solid var(--border);
    border-radius: 14px;
    background: var(--card);
    position: relative;
  }
  .pret-table td {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    border: none;
    text-align: right;
    gap: 12px;
  }
  .pret-table td::before {
    content: attr(data-label);
    color: var(--muted);
    font-size: .68rem;
    text-transform: uppercase;
    font-weight: 800;
    text-align: left;
    flex-shrink: 0;
  }
  .actions-cell { justify-content: flex-end; flex-wrap: wrap; }
  .modal-content { padding: 22px 18px; }
  .btn-voir-plus { width: 100%; justify-content: center; }
}

@media (max-width: 480px) {
  .kpi-grid { grid-template-columns: 1fr; }
  .stat-block .meta strong { font-size: 1.3rem; }
  .page-hero { padding: 18px; }
  .page-hero h2 { font-size: 1.2rem; }
}
</style>
</head>
<body class="dark-auth">

<?php require __DIR__ . '/../includes/header.php'; ?>

<div class="admin-layout">
  <aside class="admin-sidebar animate-left">
    <h3>📊 Navigation</h3>
    <a href="<?= $base ?>/admin/dashboard.php"><span class="ico">📊</span> Tableau de bord</a>
    <a href="<?= $base ?>/admin/clients.php"><span class="ico">👥</span> Clients</a>
    <a href="<?= $base ?>/admin/entreprises.php"><span class="ico">🏢</span> Entreprises</a>
    <a href="<?= $base ?>/admin/actionnaires.php"><span class="ico">🏛️</span> Actionnaires</a>
    <a href="<?= $base ?>/admin/prets.php" class="active"><span class="ico">💳</span> Prêts</a>
    <a href="<?= $base ?>/admin/prets_produits.php"><span class="ico">📋</span> Produits prêt</a>
    <a href="<?= $base ?>/admin/prets_echeances.php"><span class="ico">📅</span> Échéances</a>
    <a href="<?= $base ?>/admin/rapport_prets.php"><span class="ico">📈</span> Rapport prêts</a>
    <a href="<?= $base ?>/admin/tests_flux.php"><span class="ico">🧪</span> Tests flux</a>
    <a href="<?= $base ?>/admin/logs.php"><span class="ico">📜</span> Journal</a>
    <a href="<?= $base ?>/auth/logout.php"><span class="ico">🚪</span> Déconnexion</a>
  </aside>

  <main class="admin-main">

    <!-- HERO -->
    <div class="page-hero">
      <h2>💳 Gestion des Prêts</h2>
      <p>
        <?= $stats['total'] ?> dossier(s) au total
        <?php if ($stats['soumis'] > 0): ?>
          · <strong style="color:#93B4FF"><?= $stats['soumis'] ?> en attente d'approbation</strong>
        <?php endif; ?>
        <?php if ($stats['en_retard'] > 0): ?>
          · <strong style="color:#FCA5A5"><?= $stats['en_retard'] ?> en retard</strong>
        <?php endif; ?>
      </p>
    </div>

    <!-- MESSAGES -->
    <?php if ($success): ?>
      <div class="alert success"><?= $success ?></div>
    <?php endif; ?>
    <?php if ($errors): ?>
      <div class="alert error">
        <strong>❌ Erreur :</strong>
        <ul style="margin-top:6px;margin-left:20px">
          <?php foreach ($errors as $e) echo "<li>" . htmlspecialchars($e) . "</li>"; ?>
        </ul>
      </div>
    <?php endif; ?>

    <!-- KPIs CLIQUABLES -->
    <section class="kpi-grid">
      <a href="?statut=soumis" class="stat-block blue">
        <div class="ico">📤</div>
        <div class="meta">
          <strong><?= $stats['soumis'] ?></strong>
          <span>En attente</span>
        </div>
      </a>
      <a href="?statut=en_analyse" class="stat-block purple">
        <div class="ico">🔍</div>
        <div class="meta">
          <strong><?= $stats['en_analyse'] ?></strong>
          <span>En analyse</span>
        </div>
      </a>
      <a href="?statut=approuve" class="stat-block green">
        <div class="ico">✅</div>
        <div class="meta">
          <strong><?= $stats['approuve'] ?></strong>
          <span>Approuvés</span>
        </div>
      </a>
      <a href="?statut=en_cours" class="stat-block">
        <div class="ico">💰</div>
        <div class="meta">
          <strong><?= $stats['en_cours'] ?></strong>
          <span>En cours</span>
        </div>
      </a>
      <a href="?statut=en_retard" class="stat-block red">
        <div class="ico">⚠️</div>
        <div class="meta">
          <strong><?= $stats['en_retard'] ?></strong>
          <span>Retards</span>
        </div>
      </a>
      <a href="?statut=solde" class="stat-block green">
        <div class="ico">🎉</div>
        <div class="meta">
          <strong><?= $stats['solde'] ?></strong>
          <span>Soldés</span>
        </div>
      </a>
    </section>

    <!-- ONGLETS -->
    <div class="filters-tabs">
      <a href="?statut=" class="<?= $statut_filtre === '' ? 'active' : '' ?>">
        📋 Tous <span class="count"><?= $stats['total'] ?></span>
      </a>
      <a href="?statut=soumis" class="<?= $statut_filtre === 'soumis' ? 'active' : '' ?>">
        📤 En attente <span class="count"><?= $stats['soumis'] ?></span>
      </a>
      <a href="?statut=en_analyse" class="<?= $statut_filtre === 'en_analyse' ? 'active' : '' ?>">
        🔍 Analyse <span class="count"><?= $stats['en_analyse'] ?></span>
      </a>
      <a href="?statut=approuve" class="<?= $statut_filtre === 'approuve' ? 'active' : '' ?>">
        ✅ Approuvés <span class="count"><?= $stats['approuve'] ?></span>
      </a>
      <a href="?statut=en_cours" class="<?= $statut_filtre === 'en_cours' ? 'active' : '' ?>">
        💰 En cours <span class="count"><?= $stats['en_cours'] ?></span>
      </a>
      <a href="?statut=en_retard" class="<?= $statut_filtre === 'en_retard' ? 'active' : '' ?>">
        ⚠️ Retards <span class="count"><?= $stats['en_retard'] ?></span>
      </a>
      <a href="?statut=rejete" class="<?= $statut_filtre === 'rejete' ? 'active' : '' ?>">
        ❌ Rejetés <span class="count"><?= $stats['rejete'] ?></span>
      </a>
      <a href="?statut=solde" class="<?= $statut_filtre === 'solde' ? 'active' : '' ?>">
        🎉 Soldés <span class="count"><?= $stats['solde'] ?></span>
      </a>
    </div>

    <!-- RECHERCHE -->
    <form method="GET" class="search-bar">
      <input type="text" name="q" placeholder="🔍 Rechercher (nom, compte, référence...)" 
             value="<?= htmlspecialchars($recherche) ?>">
      <select name="statut">
        <option value="">Tous statuts</option>
        <?php foreach (PRET_STATUTS as $s): ?>
          <option value="<?= $s ?>" <?= $statut_filtre === $s ? 'selected' : '' ?>>
            <?= ucfirst(str_replace('_', ' ', $s)) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <select name="type">
        <option value="">Tous types</option>
        <option value="physique" <?= $type_client === 'physique' ? 'selected' : '' ?>>Personne physique</option>
        <option value="morale" <?= $type_client === 'morale' ? 'selected' : '' ?>>Personne morale</option>
      </select>
      <select name="devise">
        <option value="">Toutes devises</option>
        <option value="CDF" <?= $devise_filtre === 'CDF' ? 'selected' : '' ?>>CDF</option>
        <option value="USD" <?= $devise_filtre === 'USD' ? 'selected' : '' ?>>USD</option>
      </select>
      <button class="btn-gold" type="submit" style="padding:12px 20px;border-radius:10px;font-weight:800">
        Filtrer
      </button>
    </form>

    <!-- TABLEAU -->
    <?php if (empty($prets)): ?>
      <div class="empty-state">
        <div class="ico">📭</div>
        <h3 style="color:var(--text);margin-bottom:8px">Aucun prêt trouvé</h3>
        <p>
          <?= $recherche || $statut_filtre 
              ? 'Essayez de modifier vos filtres.' 
              : 'Aucune demande de prêt pour le moment.' ?>
        </p>
      </div>
    <?php else: ?>

      <div style="overflow-x:auto">
        <table class="pret-table">
          <thead>
            <tr>
              <th>Référence</th>
              <th>Client</th>
              <th>Produit</th>
              <th style="text-align:right">Montant demandé</th>
              <th style="text-align:right">Montant accordé</th>
              <th>Durée</th>
              <th>Statut</th>
              <th>Date</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($prets as $p):
              $initiales = strtoupper(
                  mb_substr($p['prenom'] ?? '', 0, 1) . 
                  mb_substr($p['nom'] ?? '', 0, 1)
              );
              $peut_analyser = ($p['statut'] === 'soumis');
              $peut_approuver = in_array($p['statut'], ['soumis','en_analyse'], true);
              $peut_rejeter = in_array($p['statut'], ['soumis','en_analyse','approuve'], true);
              $peut_decaisser = ($p['statut'] === 'approuve');
              
              /* Générer le token pour la page détails */
              $token_details = genererToken('pret', (int)$p['id_pret'], [
                  'action' => 'admin_view',
                  'admin_id' => $admin_id,
              ]);
            ?>
              <tr>
                <!-- Référence -->
                <td data-label="Référence">
                  <code style="color:var(--gold);font-size:.75rem;font-weight:800"><?= htmlspecialchars($p['reference']) ?></code>
                  <?php if ((int)$p['nb_echeances'] > 0): ?>
                    <div style="font-size:.68rem;color:var(--muted);margin-top:3px">
                      <?= (int)$p['nb_echeances_payees'] ?>/<?= (int)$p['nb_echeances'] ?> échéances
                    </div>
                  <?php endif; ?>
                </td>

                <!-- Client -->
                <td data-label="Client">
                  <div class="client-cell">
                    <?php if (!empty($p['photo_profil']) && file_exists(__DIR__ . '/../' . $p['photo_profil'])): ?>
                      <img src="<?= $base ?>/<?= htmlspecialchars($p['photo_profil']) ?>" class="client-photo-mini" alt="">
                    <?php else: ?>
                      <div class="client-photo-placeholder"><?= htmlspecialchars($initiales) ?></div>
                    <?php endif; ?>
                    <div class="client-info-mini">
                      <div class="name"><?= htmlspecialchars($p['prenom'] . ' ' . $p['nom']) ?></div>
                      <div class="compte"><?= htmlspecialchars($p['numero_compte']) ?></div>
                    </div>
                  </div>
                </td>

                <!-- Produit -->
                <td data-label="Produit">
                  <div style="font-weight:700;font-size:.82rem"><?= htmlspecialchars($p['produit_nom']) ?></div>
                  <?php if ($p['taux_applique']): ?>
                    <div style="font-size:.68rem;color:#6EE7B7;margin-top:2px">
                      <?= number_format((float)$p['taux_applique'] * 100, 2) ?>% / an
                    </div>
                  <?php elseif ($p['produit_taux']): ?>
                    <div style="font-size:.68rem;color:var(--muted);margin-top:2px">
                      Taux proposé : <?= number_format((float)$p['produit_taux'] * 100, 2) ?>%
                    </div>
                  <?php endif; ?>
                </td>

                <!-- Montant demandé -->
                <td data-label="Demandé" class="montant-cell">
                  <div class="main">
                    <?= htmlspecialchars(formaterMontant((float)$p['montant_demande'], $p['devise'])) ?>
                  </div>
                </td>

                <!-- Montant accordé -->
                <td data-label="Accordé" class="montant-cell">
                  <?php if ($p['montant_accorde']): ?>
                    <div class="main" style="color:#6EE7B7">
                      <?= htmlspecialchars(formaterMontant((float)$p['montant_accorde'], $p['devise'])) ?>
                    </div>
                    <?php if ($p['montant_restant_du'] && in_array($p['statut'], ['decaisse','en_cours','en_retard'], true)): ?>
                      <div class="sub">Restant : <?= htmlspecialchars(formaterMontant((float)$p['montant_restant_du'], $p['devise'])) ?></div>
                    <?php endif; ?>
                  <?php else: ?>
                    <div style="color:var(--muted);font-size:.78rem">—</div>
                  <?php endif; ?>
                </td>

                <!-- Durée -->
                <td data-label="Durée">
                  <strong><?= (int)$p['duree_mois'] ?></strong> mois
                </td>

                <!-- Statut -->
                <td data-label="Statut">
                  <span class="statut-badge <?= htmlspecialchars($p['statut']) ?>">
                    <?= htmlspecialchars(str_replace('_', ' ', $p['statut'])) ?>
                  </span>
                  <?php if ((int)$p['jours_depuis_demande'] > 0 && in_array($p['statut'], ['soumis','en_analyse'], true)): ?>
                    <div style="font-size:.65rem;color:var(--muted);margin-top:4px">
                      ⏱️ <?= (int)$p['jours_depuis_demande'] ?>j
                    </div>
                  <?php endif; ?>
                </td>

                <!-- Date -->
                <td data-label="Date">
                  <div style="font-weight:700;font-size:.78rem"><?= date('d/m/Y', strtotime($p['date_demande'])) ?></div>
                  <div style="font-size:.68rem;color:var(--muted)"><?= date('H:i', strtotime($p['date_demande'])) ?></div>
                </td>

                <!-- Actions -->
                <td data-label="Actions">
                  <div class="actions-cell">
                    
                    <!-- 🆕 BOUTON VOIR PLUS -->
                    <a class="btn-voir-plus" 
                       href="<?= $base ?>/admin/pret_details.php?token=<?= htmlspecialchars($token_details) ?>"
                       title="Voir tous les détails du prêt">
                      🔍 Voir plus <span class="arrow">→</span>
                    </a>

                    <?php if ($peut_analyser): ?>
                      <button class="btn-action-mini analyse"
                              data-pret='<?= htmlspecialchars(json_encode([
                                'id' => (int)$p['id_pret'],
                                'reference' => $p['reference'],
                                'client' => $p['prenom'] . ' ' . $p['nom'],
                                'montant' => (float)$p['montant_demande'],
                                'devise' => $p['devise'],
                              ]), ENT_QUOTES, 'UTF-8') ?>'
                              onclick="ouvrirAnalyse(this)"
                              title="Mettre en analyse">
                        🔍 Analyser
                      </button>
                    <?php endif; ?>

                    <?php if ($peut_approuver): ?>
                      <button class="btn-action-mini approve"
                              data-pret='<?= htmlspecialchars(json_encode([
                                'id' => (int)$p['id_pret'],
                                'reference' => $p['reference'],
                                'client' => $p['prenom'] . ' ' . $p['nom'],
                                'montant' => (float)$p['montant_demande'],
                                'devise' => $p['devise'],
                                'duree' => (int)$p['duree_mois'],
                                'taux' => (float)($p['produit_taux'] ?? 0.24) * 100,
                                'score' => (int)($p['score_credit'] ?? 0),
                              ]), ENT_QUOTES, 'UTF-8') ?>'
                              onclick="ouvrirApprobation(this)"
                              title="Approuver">
                        ✅ Approuver
                      </button>
                    <?php endif; ?>

                    <?php if ($peut_rejeter): ?>
                      <button class="btn-action-mini reject"
                              data-pret='<?= htmlspecialchars(json_encode([
                                'id' => (int)$p['id_pret'],
                                'reference' => $p['reference'],
                                'client' => $p['prenom'] . ' ' . $p['nom'],
                                'montant' => (float)$p['montant_demande'],
                                'devise' => $p['devise'],
                              ]), ENT_QUOTES, 'UTF-8') ?>'
                              onclick="ouvrirRejet(this)"
                              title="Rejeter">
                        ❌ Rejeter
                      </button>
                    <?php endif; ?>

                    <?php if ($peut_decaisser): ?>
                      <button class="btn-action-mini cash"
                              data-pret='<?= htmlspecialchars(json_encode([
                                'id' => (int)$p['id_pret'],
                                'reference' => $p['reference'],
                                'client' => $p['prenom'] . ' ' . $p['nom'],
                                'montant' => (float)$p['montant_accorde'],
                                'devise' => $p['devise'],
                                'duree' => (int)$p['duree_mois'],
                                'taux' => (float)$p['taux_applique'] * 100,
                              ]), ENT_QUOTES, 'UTF-8') ?>'
                              onclick="ouvrirDecaissement(this)"
                              title="Décaisser">
                        💰 Décaisser
                      </button>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

    <?php endif; ?>

  </main>
</div>

<!-- ============================================================
     MODALE ANALYSE
     ============================================================ -->
<div class="modal-overlay" id="modalAnalyse">
  <div class="modal-content">
    <div class="modal-header">
      <h3>🔍 Mettre en analyse</h3>
      <button class="modal-close" onclick="fermerModal('modalAnalyse')">✕</button>
    </div>

    <div class="modal-info">
      <div class="row"><span class="lbl">Référence</span><span class="val gold" id="anaRef">—</span></div>
      <div class="row"><span class="lbl">Client</span><span class="val" id="anaClient">—</span></div>
      <div class="row"><span class="lbl">Montant</span><span class="val blue" id="anaMontant">—</span></div>
    </div>

    <div class="info-box-modal">
      🔍 Cette action marque le prêt comme <strong>en cours d'analyse</strong>. Le client sera notifié.
    </div>

    <form method="POST" id="formAnalyse">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="en_analyse">
      <input type="hidden" name="id_pret" id="anaId">

      <div class="modal-actions">
        <button type="button" class="btn-modal-cancel" onclick="fermerModal('modalAnalyse')">Annuler</button>
        <button type="submit" class="btn-modal-approve">🔍 Confirmer</button>
      </div>
    </form>
  </div>
</div>

<!-- ============================================================
     MODALE APPROBATION
     ============================================================ -->
<div class="modal-overlay" id="modalApprobation">
  <div class="modal-content">
    <div class="modal-header">
      <h3>✅ Approuver le prêt</h3>
      <button class="modal-close" onclick="fermerModal('modalApprobation')">✕</button>
    </div>

    <div class="modal-info">
      <div class="row"><span class="lbl">Référence</span><span class="val gold" id="appRef">—</span></div>
      <div class="row"><span class="lbl">Client</span><span class="val" id="appClient">—</span></div>
      <div class="row"><span class="lbl">Montant demandé</span><span class="val blue" id="appMontant">—</span></div>
      <div class="row"><span class="lbl">Score crédit</span><span class="val" id="appScore">—</span></div>
    </div>

    <form method="POST" id="formApprobation">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="approuver">
      <input type="hidden" name="id_pret" id="appId">

      <div class="form-group">
        <label>Montant accordé *</label>
        <input type="text" name="montant_accorde" id="appMontantAccorde" required inputmode="decimal">
        <div class="hint">Peut être différent du montant demandé</div>
      </div>

      <div class="form-group">
        <label>Taux annuel appliqué (%) *</label>
        <input type="number" name="taux" id="appTaux" step="0.01" min="0.1" max="50" required>
        <div class="hint">Taux par défaut du produit — modifiable si besoin</div>
      </div>

      <div class="form-group">
        <label>Commentaire (optionnel)</label>
        <textarea name="commentaire" maxlength="500" rows="2" 
                  placeholder="Ex: Dossier conforme, garanties vérifiées..."></textarea>
      </div>

      <div class="info-box-modal">
        ✅ Une fois approuvé, le client pourra <strong>signer le contrat</strong>. Vous pourrez ensuite décaisser les fonds.
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-modal-cancel" onclick="fermerModal('modalApprobation')">Annuler</button>
        <button type="submit" class="btn-modal-approve">✅ Approuver</button>
      </div>
    </form>
  </div>
</div>

<!-- ============================================================
     MODALE REJET
     ============================================================ -->
<div class="modal-overlay" id="modalRejet">
  <div class="modal-content">
    <div class="modal-header">
      <h3>❌ Rejeter le prêt</h3>
      <button class="modal-close" onclick="fermerModal('modalRejet')">✕</button>
    </div>

    <div class="modal-info">
      <div class="row"><span class="lbl">Référence</span><span class="val gold" id="rejRef">—</span></div>
      <div class="row"><span class="lbl">Client</span><span class="val" id="rejClient">—</span></div>
      <div class="row"><span class="lbl">Montant</span><span class="val blue" id="rejMontant">—</span></div>
    </div>

    <div class="danger-box">
      ⚠️ <strong>Action sensible</strong><br>
      Le client recevra le motif du rejet. Soyez clair et respectueux.
    </div>

    <form method="POST" id="formRejet">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="rejeter">
      <input type="hidden" name="id_pret" id="rejId">

      <div class="form-group">
        <label>Motif du rejet *</label>
        <textarea name="motif" required rows="3" maxlength="500"
                  placeholder="Ex: Score insuffisant, documents manquants, garanties non conformes..."></textarea>
        <div class="hint">Ce motif sera visible par le client</div>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-modal-cancel" onclick="fermerModal('modalRejet')">Annuler</button>
        <button type="submit" class="btn-modal-reject">❌ Confirmer le rejet</button>
      </div>
    </form>
  </div>
</div>

<!-- ============================================================
     MODALE DÉCAISSEMENT
     ============================================================ -->
<div class="modal-overlay" id="modalDecaissement">
  <div class="modal-content">
    <div class="modal-header">
      <h3>💰 Décaisser le prêt</h3>
      <button class="modal-close" onclick="fermerModal('modalDecaissement')">✕</button>
    </div>

    <div class="modal-info">
      <div class="row"><span class="lbl">Référence</span><span class="val gold" id="decRef">—</span></div>
      <div class="row"><span class="lbl">Client</span><span class="val" id="decClient">—</span></div>
      <div class="row"><span class="lbl">Montant à verser</span><span class="val blue" id="decMontant">—</span></div>
      <div class="row"><span class="lbl">Durée</span><span class="val" id="decDuree">—</span></div>
      <div class="row"><span class="lbl">Taux appliqué</span><span class="val" id="decTaux">—</span></div>
    </div>

    <div class="info-box-modal">
      💰 <strong>Cette action va :</strong><br>
      1. Créditer immédiatement le solde du client<br>
      2. Générer l'échéancier de remboursement<br>
      3. Enregistrer une transaction de décaissement
    </div>

    <form method="POST" id="formDecaissement" onsubmit="return confirm('⚠️ Confirmer le décaissement ? Cette action est irréversible.')">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="decaisser">
      <input type="hidden" name="id_pret" id="decId">

      <div class="modal-actions">
        <button type="button" class="btn-modal-cancel" onclick="fermerModal('modalDecaissement')">Annuler</button>
        <button type="submit" class="btn-modal-cash">💰 Confirmer</button>
      </div>
    </form>
  </div>
</div>

<footer class="atf-footer">© <?= date('Y') ?> AFRICAN TRUST FUND</footer>

<script>
/* ============================================================
   GESTION DES MODALES
   ============================================================ */
function ouvrirModal(id) {
    document.getElementById(id).classList.add('active');
    document.body.style.overflow = 'hidden';
}
function fermerModal(id) {
    document.getElementById(id).classList.remove('active');
    document.body.style.overflow = '';
}

document.querySelectorAll('.modal-overlay').forEach(ov => {
    ov.addEventListener('click', e => {
        if (e.target === ov) {
            ov.classList.remove('active');
            document.body.style.overflow = '';
        }
    });
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.active').forEach(m => m.classList.remove('active'));
        document.body.style.overflow = '';
    }
});

/* ============================================================
   FORMAT MONTANT
   ============================================================ */
function formatMontant(v, devise) {
    if (devise === 'USD') return '$ ' + Number(v).toLocaleString('fr-FR', {minimumFractionDigits:2, maximumFractionDigits:2});
    return Number(v).toLocaleString('fr-FR', {maximumFractionDigits:0}) + ' CDF';
}

/* ============================================================
   ANALYSE
   ============================================================ */
function ouvrirAnalyse(btn) {
    const p = JSON.parse(btn.dataset.pret);
    
    document.getElementById('anaId').value = p.id;
    document.getElementById('anaRef').textContent = p.reference;
    document.getElementById('anaClient').textContent = p.client;
    document.getElementById('anaMontant').textContent = formatMontant(p.montant, p.devise);
    
    ouvrirModal('modalAnalyse');
}

/* ============================================================
   APPROBATION
   ============================================================ */
function ouvrirApprobation(btn) {
    const p = JSON.parse(btn.dataset.pret);
    
    document.getElementById('appId').value = p.id;
    document.getElementById('appRef').textContent = p.reference;
    document.getElementById('appClient').textContent = p.client;
    document.getElementById('appMontant').textContent = formatMontant(p.montant, p.devise);
    document.getElementById('appScore').textContent = (p.score || 0) + '/100';
    
    document.getElementById('appMontantAccorde').value = p.montant;
    document.getElementById('appTaux').value = p.taux.toFixed(2);
    
    ouvrirModal('modalApprobation');
}

/* ============================================================
   REJET
   ============================================================ */
function ouvrirRejet(btn) {
    const p = JSON.parse(btn.dataset.pret);
    
    document.getElementById('rejId').value = p.id;
    document.getElementById('rejRef').textContent = p.reference;
    document.getElementById('rejClient').textContent = p.client;
    document.getElementById('rejMontant').textContent = formatMontant(p.montant, p.devise);
    
    ouvrirModal('modalRejet');
}

/* ============================================================
   DÉCAISSEMENT
   ============================================================ */
function ouvrirDecaissement(btn) {
    const p = JSON.parse(btn.dataset.pret);
    
    document.getElementById('decId').value = p.id;
    document.getElementById('decRef').textContent = p.reference;
    document.getElementById('decClient').textContent = p.client;
    document.getElementById('decMontant').textContent = formatMontant(p.montant, p.devise);
    document.getElementById('decDuree').textContent = p.duree + ' mois';
    document.getElementById('decTaux').textContent = (p.taux || 0).toFixed(2) + '% / an';
    
    ouvrirModal('modalDecaissement');
}

/* ============================================================
   FORMAT INPUT MONTANT
   ============================================================ */
document.querySelectorAll('input[name="montant_accorde"]').forEach(inp => {
    inp.addEventListener('input', function() {
        let v = this.value.replace(/[^\d.,]/g, '').replace(',', '.');
        this.value = v;
    });
});

/* ============================================================
   SUBMIT FEEDBACK
   ============================================================ */
document.querySelectorAll('.modal-content form').forEach(form => {
    form.addEventListener('submit', function() {
        const btn = this.querySelector('button[type="submit"]');
        if (btn) {
            btn.disabled = true;
            const orig = btn.innerHTML;
            btn.innerHTML = '<span style="display:inline-block;width:14px;height:14px;border:2px solid #fff;border-top-color:transparent;border-radius:50%;animation:spin .8s linear infinite;margin-right:6px"></span> Traitement...';
        }
    });
});

console.log('%c💳 Gestion Prêts ATF', 'color:#F5B800;font-weight:bold;font-size:14px');
</script>

<style>
@keyframes spin { to { transform: rotate(360deg); } }
</style>

<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>