<?php
/**
 * ============================================================
 * ATF MICROFINANCE — Admin : Gestion des Placements
 * ============================================================
 */
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/interets.php';
require_once __DIR__ . '/../config/parametres.php';
requireRole('admin');

$pdo = Database::getConnection();
$admin_id = (int)$_SESSION['admin_id'];

/* ============================================================
   CRÉDITER TOUS LES INTÉRÊTS (POST)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'crediter_tous') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) throw new RuntimeException("Jeton invalide.");
        $resultat = crediterTousInterets($pdo);
        logAction($pdo, 'admin', $admin_id, 'INTERETS_CREDIT_ALL',
            "Succès: {$resultat['succes']} | Total: {$resultat['total_interets']}");
        $_SESSION['flash_success'] = "✅ Intérêts crédités : {$resultat['succes']} placement(s) | Total : " .
            number_format($resultat['total_interets'], 2, ',', ' ') . " | Erreurs : {$resultat['erreurs']}";
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
    }
    header('Location: ' . url('admin/placements.php'));
    exit;
}

$success = '';
if (!empty($_SESSION['flash_success'])) { $success = $_SESSION['flash_success']; unset($_SESSION['flash_success']); }
$error = '';
if (!empty($_SESSION['flash_error'])) { $error = $_SESSION['flash_error']; unset($_SESSION['flash_error']); }

/* ============================================================
   FILTRES ET RECHERCHE
   ============================================================ */
$recherche     = trim($_GET['q'] ?? '');
$filtre_statut = in_array($_GET['statut'] ?? '', ['actif','cloture','anticipe'], true) ? $_GET['statut'] : '';
$filtre_devise = in_array($_GET['devise'] ?? '', ['CDF','USD'], true) ? $_GET['devise'] : '';
$tri           = $_GET['tri'] ?? 'recent';

/* ============================================================
   CONSTRUCTION DE LA REQUÊTE
   ============================================================ */
$sql = "
    SELECT p.*, 
           c.nom, c.postnom, c.prenom, c.numero_compte, c.numero_phone,
           c.photo_profil, c.solde_cdf, c.solde_usd,
           c.solde_epargne_cdf, c.solde_epargne_usd,
           pr.nom AS produit_nom, pr.taux_annuel,
           DATEDIFF(CURDATE(), p.date_debut) AS jours_ecoules,
           DATEDIFF(p.date_deblocage_interets, CURDATE()) AS jours_avant_deblocage
    FROM Placements p
    JOIN Client c ON c.id_client = p.id_client
    JOIN Produits_Epargne pr ON pr.id_produit = p.id_produit
    WHERE 1=1
";
$params = [];

if ($recherche !== '') {
    $sql .= " AND (c.nom LIKE ? OR c.postnom LIKE ? OR c.prenom LIKE ? 
                   OR c.numero_compte LIKE ? OR c.numero_phone LIKE ?
                   OR p.reference LIKE ?)";
    $like = "%$recherche%";
    array_push($params, $like, $like, $like, $like, $like, $like);
}
if ($filtre_statut !== '') {
    $sql .= " AND p.statut = ?";
    $params[] = $filtre_statut;
}
if ($filtre_devise !== '') {
    $sql .= " AND p.devise = ?";
    $params[] = $filtre_devise;
}

$sql .= match($tri) {
    'montant_desc' => " ORDER BY p.montant_initial DESC",
    'montant_asc'  => " ORDER BY p.montant_initial ASC",
    'interets'     => " ORDER BY p.interets_cumules DESC",
    'client'       => " ORDER BY c.nom ASC",
    default        => " ORDER BY p.created_at DESC",
};

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$placements = $stmt->fetchAll();

/* ============================================================
   STATISTIQUES
   ============================================================ */
$stats = [
    'total_placements' => count($placements),
    'actifs'           => 0,
    'capital_total'    => 0,
    'capital_actif'    => 0,
    'interets_total'   => 0,
    'interets_dispo'   => 0,
    'interets_bloques' => 0,
    'nb_clients'       => 0,
];

$clients_uniques = [];

foreach ($placements as $p) {
    if ($p['statut'] === 'actif') {
        $stats['actifs']++;
        $stats['capital_actif'] += (float)$p['montant_initial'];
    }
    $stats['capital_total'] += (float)$p['montant_initial'];
    $stats['interets_total'] += (float)$p['interets_cumules'];

    $interets_dispo = (float)$p['interets_cumules'] - (float)$p['interets_retires'];
    if ($p['statut'] === 'actif' && (int)$p['jours_avant_deblocage'] <= 0) {
        $stats['interets_dispo'] += $interets_dispo;
    } else if ($p['statut'] === 'actif') {
        $stats['interets_bloques'] += $interets_dispo;
    }

    $clients_uniques[(int)$p['id_client']] = true;
}
$stats['nb_clients'] = count($clients_uniques);

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Placements — ATF Admin</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/admin-premium.css">
<style>
/* ============================================================
   STYLES SPÉCIFIQUES
   ============================================================ */
.filters-bar {
  display: grid;
  grid-template-columns: 2fr 1fr 1fr 1fr;
  gap: 14px;
  padding: 20px;
  background: linear-gradient(145deg, var(--card), var(--card-2));
  border-radius: 16px;
  border: 1px solid var(--border);
  margin-bottom: 24px;
}
.filters-bar input,
.filters-bar select {
  padding: 12px 16px;
  background: var(--bg-1);
  border: 1px solid var(--border);
  border-radius: 10px;
  color: var(--text);
  font-size: .9rem;
  font-family: inherit;
}
.filters-bar input:focus,
.filters-bar select:focus {
  outline: none;
  border-color: var(--gold);
}
@media (max-width: 900px) {
  .filters-bar { grid-template-columns: 1fr; }
}

.client-cell { display: flex; align-items: center; gap: 12px; min-width: 200px; }
.client-photo-mini {
  width: 40px; height: 40px;
  border-radius: 50%;
  object-fit: cover;
  border: 2px solid var(--gold);
  flex-shrink: 0;
}
.client-photo-placeholder {
  width: 40px; height: 40px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  background: linear-gradient(135deg, #0D1B5E, #1E293B);
  color: var(--gold);
  font-weight: 800;
  font-size: .9rem;
  border: 2px solid var(--gold);
  flex-shrink: 0;
}
.client-info-mini .name { font-weight: 700; font-size: .88rem; color: var(--text); }
.client-info-mini .compte { font-family: monospace; font-size: .72rem; color: var(--gold); }

.actions-cell { display: flex; gap: 6px; flex-wrap: wrap; }
.btn-action-mini {
  padding: 6px 10px;
  border-radius: 8px;
  font-size: .72rem;
  font-weight: 700;
  border: none;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  transition: all .2s;
  font-family: inherit;
}
.btn-action-mini.view  { background: rgba(59,130,246,.15);  color: #93B4FF; border: 1px solid rgba(59,130,246,.3); }
.btn-action-mini.edit  { background: rgba(245,184,0,.15);   color: #F5B800; border: 1px solid rgba(245,184,0,.3); }
.btn-action-mini.delete{ background: rgba(239,68,68,.15);   color: #FCA5A5; border: 1px solid rgba(239,68,68,.3); }
.btn-action-mini:hover { transform: translateY(-1px); }

.modal-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(11,14,20,.85);
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
  border: 1px solid rgba(245,184,0,.3);
  box-shadow: 0 30px 80px rgba(0,0,0,.8);
  padding: 32px 28px;
  max-width: 600px;
  width: 100%;
  max-height: 90vh;
  overflow-y: auto;
  animation: slideUp .3s;
}
.modal-content.large { max-width: 800px; }
@keyframes slideUp {
  from { transform: translateY(30px); opacity: 0; }
  to   { transform: translateY(0);     opacity: 1; }
}
.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 24px;
  padding-bottom: 16px;
  border-bottom: 1px solid var(--border);
}
.modal-header h3 { color: var(--gold); font-size: 1.2rem; font-weight: 800; }
.modal-close {
  width: 36px; height: 36px;
  border-radius: 50%;
  background: rgba(239,68,68,.15);
  color: #FCA5A5;
  border: 1px solid rgba(239,68,68,.3);
  cursor: pointer;
  font-size: 1.2rem;
  font-weight: 800;
}
.modal-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
  margin-bottom: 20px;
}
@media (max-width: 600px) { .modal-grid { grid-template-columns: 1fr; } }
.modal-actions {
  display: flex;
  gap: 12px;
  justify-content: flex-end;
  margin-top: 20px;
  padding-top: 20px;
  border-top: 1px solid var(--border);
}

.info-client-modal {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 16px;
  background: rgba(11,14,20,.5);
  border-radius: 14px;
  border: 1px solid var(--border);
  margin-bottom: 20px;
}
.info-client-modal .photo {
  width: 70px; height: 70px;
  border-radius: 50%;
  object-fit: cover;
  border: 3px solid var(--gold);
}
.info-client-modal .photo-placeholder {
  width: 70px; height: 70px;
  border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  background: linear-gradient(135deg, #0D1B5E, #1E293B);
  color: var(--gold);
  font-weight: 800; font-size: 1.5rem;
  border: 3px solid var(--gold);
}
.info-client-modal .details { flex: 1; }
.info-client-modal .details .name  { font-size: 1.1rem; font-weight: 800; color: #fff; }
.info-client-modal .details .compte{ font-family: monospace; color: var(--gold); font-size: .85rem; }
.info-client-modal .details .phone { color: var(--muted); font-size: .82rem; }

.soldes-mini {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
  margin-bottom: 20px;
}
.solde-mini-box {
  padding: 12px 14px;
  border-radius: 10px;
  background: rgba(11,14,20,.5);
  border: 1px solid var(--border);
}
.solde-mini-box .lbl {
  font-size: .68rem;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 1px;
}
.solde-mini-box .val { font-size: 1.1rem; font-weight: 800; margin-top: 4px; }
.solde-mini-box .val.gold   { color: var(--gold); }
.solde-mini-box .val.purple { color: #A78BFA; }
.solde-mini-box .val.blue   { color: #93B4FF; }
.solde-mini-box .val.green  { color: #6EE7B7; }

.placement-mini-item {
  padding: 12px 14px;
  border-radius: 10px;
  background: rgba(11,14,20,.4);
  border: 1px solid var(--border);
  margin-bottom: 10px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}
.placement-mini-item .info { flex: 1; }
.placement-mini-item .info .ref { font-family: monospace; color: var(--gold); font-size: .8rem; font-weight: 700; }
.placement-mini-item .info .sub { font-size: .78rem; color: var(--muted); margin-top: 2px; }
.placement-mini-item .amount { font-weight: 800; color: #F5B800; font-size: .95rem; }

.empty-state { text-align: center; padding: 60px 20px; color: var(--muted); }
.empty-state .ico { font-size: 4rem; opacity: .4; margin-bottom: 16px; }

.field-inline { display: flex; flex-direction: column; gap: 6px; }
.field-inline label {
  font-size: .78rem;
  color: var(--muted);
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: .5px;
}
.field-inline input,
.field-inline select,
.field-inline textarea {
  padding: 12px 14px;
  background: var(--bg-1);
  border: 1px solid var(--border);
  border-radius: 10px;
  color: var(--text);
  font-size: .92rem;
  font-family: inherit;
}
.field-inline input:focus,
.field-inline select:focus,
.field-inline textarea:focus {
  outline: none;
  border-color: var(--gold);
}

.danger-box {
  padding: 16px;
  border-radius: 12px;
  background: rgba(239,68,68,.08);
  border: 1px solid rgba(239,68,68,.3);
  margin: 20px 0;
  color: #FCA5A5;
  font-size: .88rem;
  line-height: 1.6;
}

.statut-pill {
  display: inline-block;
  padding: 4px 10px;
  border-radius: 20px;
  font-size: .72rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: .5px;
}
.statut-pill.actif    { background: rgba(16,185,129,.15); color: #6EE7B7; border: 1px solid rgba(16,185,129,.3); }
.statut-pill.cloture  { background: rgba(107,114,128,.15); color: #D1D5DB; border: 1px solid rgba(107,114,128,.3); }
.statut-pill.anticipe { background: rgba(245,184,0,.15); color: #F5B800; border: 1px solid rgba(245,184,0,.3); }

.progress-interets {
  height: 6px;
  background: rgba(11,14,20,.6);
  border-radius: 3px;
  overflow: hidden;
  margin-top: 6px;
}
.progress-interets > div {
  height: 100%;
  background: linear-gradient(90deg, var(--gold), #F5B800);
  transition: width .3s;
}

.taux-badge {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 6px;
  background: rgba(167,139,250,.15);
  color: #A78BFA;
  font-size: .72rem;
  font-weight: 700;
  font-family: monospace;
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
    <a href="<?= $base ?>/admin/caissiers.php"><span class="ico">💼</span> Caissiers</a>
    <a href="<?= $base ?>/admin/produits.php"><span class="ico">📈</span> Produits</a>
    <a href="<?= $base ?>/admin/placements.php" class="active"><span class="ico">💰</span> Placements</a>
    <a href="<?= $base ?>/admin/commissions.php"><span class="ico">💵</span> Commissions</a>
    <a href="<?= $base ?>/admin/statistiques.php"><span class="ico">📈</span> Statistiques</a>
    <a href="<?= $base ?>/admin/transactions.php"><span class="ico">💳</span> Transactions</a>
    <a href="<?= $base ?>/admin/logs.php"><span class="ico">📜</span> Journal</a>
    <a href="<?= $base ?>/auth/logout.php"><span class="ico">🚪</span> Déconnexion</a>
  </aside>

  <main class="admin-main">

    <div class="welcome-head">
      <div>
        <h2>💰 Placements clients</h2>
        <p class="sub"><?= count($placements) ?> placement(s) affiché(s)</p>
      </div>
      <form method="POST" style="display:inline">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="crediter_tous">
        <button class="btn-gold" type="submit" onclick="return confirm('Créditer les intérêts sur TOUS les placements actifs ?')">
          🔄 Créditer tous les intérêts
        </button>
      </form>
    </div>

    <?php if ($success): ?><div class="alert success"><?= $success ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <!-- STATISTIQUES -->
    <section class="kpi-grid">
      <div class="stat-block gold animate-in delay-1">
        <div class="ico">💰</div>
        <div class="meta">
          <strong><?= number_format($stats['capital_actif'], 0, ',', ' ') ?></strong>
          <span>Capital actif (CDF)</span>
        </div>
      </div>
      <div class="stat-block green animate-in delay-2">
        <div class="ico">📈</div>
        <div class="meta">
          <strong><?= number_format($stats['interets_total'], 0, ',', ' ') ?></strong>
          <span>Intérêts générés</span>
        </div>
      </div>
      <div class="stat-block blue animate-in delay-3">
        <div class="ico">🎯</div>
        <div class="meta">
          <strong><?= $stats['actifs'] ?> / <?= $stats['total_placements'] ?></strong>
          <span>Placements actifs</span>
        </div>
      </div>
      <div class="stat-block purple animate-in delay-4">
        <div class="ico">👥</div>
        <div class="meta">
          <strong><?= $stats['nb_clients'] ?></strong>
          <span>Clients épargnants</span>
        </div>
      </div>
    </section>

    <!-- FILTRES -->
    <form method="GET" class="filters-bar">
      <input type="text" name="q" placeholder="🔍 Rechercher (nom, compte, téléphone, référence)..." value="<?= htmlspecialchars($recherche) ?>">
      <select name="statut">
        <option value="">Tous les statuts</option>
        <option value="actif"    <?= $filtre_statut === 'actif' ? 'selected' : '' ?>>Actif</option>
        <option value="cloture"  <?= $filtre_statut === 'cloture' ? 'selected' : '' ?>>Clôturé</option>
        <option value="anticipe" <?= $filtre_statut === 'anticipe' ? 'selected' : '' ?>>Anticipé</option>
      </select>
      <select name="devise">
        <option value="">Toutes devises</option>
        <option value="CDF" <?= $filtre_devise === 'CDF' ? 'selected' : '' ?>>CDF</option>
        <option value="USD" <?= $filtre_devise === 'USD' ? 'selected' : '' ?>>USD</option>
      </select>
      <select name="tri">
        <option value="recent"       <?= $tri === 'recent' ? 'selected' : '' ?>>Plus récents</option>
        <option value="montant_desc" <?= $tri === 'montant_desc' ? 'selected' : '' ?>>Montant ↓</option>
        <option value="montant_asc"  <?= $tri === 'montant_asc' ? 'selected' : '' ?>>Montant ↑</option>
        <option value="interets"     <?= $tri === 'interets' ? 'selected' : '' ?>>Intérêts ↓</option>
        <option value="client"       <?= $tri === 'client' ? 'selected' : '' ?>>Nom client</option>
      </select>
    </form>

    <!-- TABLEAU -->
    <div class="table-container animate-in">
      <?php if (empty($placements)): ?>
        <div class="empty-state">
          <div class="ico">💸</div>
          <p>Aucun placement trouvé pour ces critères.</p>
        </div>
      <?php else: ?>
        <table class="admin-table">
          <thead>
            <tr>
              <th>Référence</th>
              <th>Client</th>
              <th>Produit</th>
              <th>Montant</th>
              <th>Intérêts</th>
              <th>Statut</th>
              <th>Déblocage</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($placements as $p): 
              $initiales = strtoupper(mb_substr($p['prenom'] ?? '', 0, 1) . mb_substr($p['nom'] ?? '', 0, 1));
              $interets_dispo = (float)$p['interets_cumules'] - (float)$p['interets_retires'];
              $jours_restants = (int)$p['jours_avant_deblocage'];
              $progress_pct = min(100, max(0, 100 - ($jours_restants / 180 * 100)));
          ?>
            <tr>
              <td><code style="color:var(--gold);font-size:.78rem;"><?= htmlspecialchars($p['reference']) ?></code></td>
              <td>
                <div class="client-cell">
                  <?php if (!empty($p['photo_profil']) && file_exists(__DIR__ . '/../uploads/' . $p['photo_profil'])): ?>
                    <img src="<?= $base ?>/uploads/<?= htmlspecialchars($p['photo_profil']) ?>" class="client-photo-mini" alt="">
                  <?php else: ?>
                    <div class="client-photo-placeholder"><?= htmlspecialchars($initiales) ?></div>
                  <?php endif; ?>
                  <div class="client-info-mini">
                    <div class="name"><?= htmlspecialchars($p['prenom'] . ' ' . $p['nom']) ?></div>
                    <div class="compte"><?= htmlspecialchars($p['numero_compte']) ?></div>
                  </div>
                </div>
              </td>
              <td>
                <div style="font-weight:600;font-size:.85rem;"><?= htmlspecialchars($p['produit_nom']) ?></div>
                <span class="taux-badge"><?= number_format((float)$p['taux_applique'] * 100, 2, ',', ' ') ?>%/mois</span>
              </td>
              <td>
                <div style="font-weight:800;color:#F5B800;"><?= number_format((float)$p['montant_initial'], 2, ',', ' ') ?></div>
                <div style="font-size:.72rem;color:var(--muted);"><?= htmlspecialchars($p['devise']) ?></div>
              </td>
              <td>
                <div style="font-weight:700;color:#6EE7B7;">+<?= number_format((float)$p['interets_cumules'], 2, ',', ' ') ?></div>
                <?php if ($interets_dispo > 0): ?>
                  <div style="font-size:.72rem;color:var(--muted);">Dispo: <?= number_format($interets_dispo, 2, ',', ' ') ?></div>
                <?php endif; ?>
              </td>
              <td><span class="statut-pill <?= htmlspecialchars($p['statut']) ?>"><?= htmlspecialchars($p['statut']) ?></span></td>
              <td>
                <?php if ($jours_restants <= 0): ?>
                  <span style="color:#6EE7B7;font-weight:700;font-size:.8rem;">✅ Débloqué</span>
                <?php else: ?>
                  <div style="font-size:.78rem;color:#F5B800;font-weight:700;"><?= $jours_restants ?> jour(s)</div>
                  <div class="progress-interets"><div style="width:<?= $progress_pct ?>%"></div></div>
                <?php endif; ?>
              </td>
              <td>
                <div class="actions-cell">
                  <button class="btn-action-mini view" onclick='voirDetails(<?= (int)$p["id_client"] ?>)'>
                    👁 Voir
                  </button>
                  <button class="btn-action-mini edit"
                    data-placement='<?= htmlspecialchars(json_encode([
                      'id' => (int)$p['id_placement'],
                      'reference' => $p['reference'],
                      'montant' => (float)$p['montant_initial'],
                      'taux' => (float)$p['taux_applique'] * 100,
                      'duree' => (int)$p['duree_mois'],
                      'statut' => $p['statut'],
                      'date_debut' => $p['date_debut'],
                      'date_fin' => $p['date_fin'],
                      'devise' => $p['devise'],
                      'client_nom' => $p['prenom'] . ' ' . $p['nom'],
                      'client_compte' => $p['numero_compte'],
                    ]), ENT_QUOTES, 'UTF-8') ?>'
                    onclick="ouvrirModif(this)">
                    ✏️ Modifier
                  </button>
                  <button class="btn-action-mini delete"
                    data-placement='<?= htmlspecialchars(json_encode([
                      'id' => (int)$p['id_placement'],
                      'reference' => $p['reference'],
                      'montant' => (float)$p['montant_initial'],
                      'capital_retire' => (float)$p['capital_retire'],
                      'devise' => $p['devise'],
                      'client_nom' => $p['prenom'] . ' ' . $p['nom'],
                    ]), ENT_QUOTES, 'UTF-8') ?>'
                    onclick="ouvrirSuppr(this)">
                    🗑 Supprimer
                  </button>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

  </main>
</div>

<!-- ============================================================
     MODALE : VOIR DÉTAILS CLIENT
     ============================================================ -->
<div class="modal-overlay" id="modalDetails">
  <div class="modal-content large">
    <div class="modal-header">
      <h3>👤 Détails du client</h3>
      <button class="modal-close" onclick="fermerModal('modalDetails')">✕</button>
    </div>
    <div id="detailsContent">
      <div class="empty-state"><div class="ico">⏳</div><p>Chargement...</p></div>
    </div>
  </div>
</div>

<!-- ============================================================
     MODALE : MODIFIER PLACEMENT
     ============================================================ -->
<div class="modal-overlay" id="modalModif">
  <div class="modal-content">
    <div class="modal-header">
      <h3>✏️ Modifier le placement</h3>
      <button class="modal-close" onclick="fermerModal('modalModif')">✕</button>
    </div>

    <div class="info-client-modal">
      <div class="photo-placeholder" id="modifInitiales">?</div>
      <div class="details">
        <div class="name" id="modifClientNom">—</div>
        <div class="compte" id="modifClientCompte">—</div>
      </div>
    </div>

    <form method="POST" action="<?= $base ?>/admin/placements_action.php" id="formModif">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="modifier">
      <input type="hidden" name="id_placement" id="modifId">

      <div class="modal-grid">
        <div class="field-inline">
          <label>Montant initial</label>
          <input type="number" step="0.01" min="1" name="montant_initial" id="modifMontant" required>
        </div>
        <div class="field-inline">
          <label>Taux mensuel (%)</label>
          <input type="number" step="0.01" min="0.1" max="50" name="taux_applique" id="modifTaux" required>
        </div>
        <div class="field-inline">
          <label>Durée (mois)</label>
          <input type="number" min="1" max="120" name="duree_mois" id="modifDuree" required>
        </div>
        <div class="field-inline">
          <label>Statut</label>
          <select name="statut" id="modifStatut">
            <option value="actif">Actif</option>
            <option value="cloture">Clôturé</option>
            <option value="anticipe">Anticipé</option>
          </select>
        </div>
        <div class="field-inline">
          <label>Date début</label>
          <input type="date" name="date_debut" id="modifDateDebut" required>
        </div>
        <div class="field-inline">
          <label>Date fin</label>
          <input type="date" name="date_fin" id="modifDateFin" required>
        </div>
      </div>

      <div class="field-inline">
        <label>Raison de la modification (obligatoire)</label>
        <textarea name="raison" rows="2" placeholder="Ex: Correction du montant saisi par erreur..." required></textarea>
      </div>

      <div class="danger-box">
        ⚠️ La modification du montant ajustera automatiquement le solde d'épargne du client.
        Les intérêts seront recalculés si le taux ou la date change.
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-ghost" onclick="fermerModal('modalModif')">Annuler</button>
        <button type="submit" class="btn-gold">💾 Enregistrer</button>
      </div>
    </form>
  </div>
</div>

<!-- ============================================================
     MODALE : SUPPRIMER PLACEMENT
     ============================================================ -->
<div class="modal-overlay" id="modalSuppr">
  <div class="modal-content">
    <div class="modal-header">
      <h3>🗑 Supprimer le placement</h3>
      <button class="modal-close" onclick="fermerModal('modalSuppr')">✕</button>
    </div>

    <div class="info-client-modal">
      <div class="photo-placeholder" style="background:rgba(239,68,68,.2);color:#FCA5A5;border-color:#FCA5A5;">!</div>
      <div class="details">
        <div class="name" id="supprClientNom">—</div>
        <div class="compte" id="supprRef">—</div>
      </div>
    </div>

    <div class="soldes-mini">
      <div class="solde-mini-box">
        <div class="lbl">Montant initial</div>
        <div class="val gold" id="supprMontant">—</div>
      </div>
      <div class="solde-mini-box">
        <div class="lbl">Capital à restituer</div>
        <div class="val green" id="supprRestitution">—</div>
      </div>
    </div>

    <form method="POST" action="<?= $base ?>/admin/placements_action.php" id="formSuppr">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="supprimer">
      <input type="hidden" name="id_placement" id="supprId">

      <div class="field-inline" style="margin-bottom:20px;">
        <label>Raison de la suppression (obligatoire)</label>
        <textarea name="raison" rows="2" placeholder="Ex: Erreur de saisie, doublon..." required></textarea>
      </div>

      <div class="danger-box">
        ⚠️ <strong>Action irréversible.</strong><br>
        • Le capital restant sera restitué au solde disponible du client.<br>
        • Les intérêts non retirés seront annulés.<br>
        • Une transaction de restitution sera enregistrée.
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-ghost" onclick="fermerModal('modalSuppr')">Annuler</button>
        <button type="submit" class="btn-danger">🗑 Confirmer la suppression</button>
      </div>
    </form>
  </div>
</div>

<script>
/* ============================================================
   GESTION DES MODALES
   ============================================================ */
function fermerModal(id) {
  document.getElementById(id).classList.remove('active');
}
function ouvrirModal(id) {
  document.getElementById(id).classList.add('active');
}

/* Fermer au clic sur l'overlay */
document.querySelectorAll('.modal-overlay').forEach(ov => {
  ov.addEventListener('click', e => {
    if (e.target === ov) ov.classList.remove('active');
  });
});

/* Fermer avec Echap */
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-overlay.active').forEach(m => m.classList.remove('active'));
  }
});

/* ============================================================
   MODALE : VOIR DÉTAILS CLIENT
   ============================================================ */
async function voirDetails(idClient) {
  const box = document.getElementById('detailsContent');
  box.innerHTML = '<div class="empty-state"><div class="ico">⏳</div><p>Chargement...</p></div>';
  ouvrirModal('modalDetails');

  try {
    const res = await fetch(`<?= $base ?>/admin/placements_action.php?action=details_client&id_client=${idClient}`);
    const data = await res.json();

    if (!data.success) {
      box.innerHTML = `<div class="alert error">${data.error || 'Erreur'}</div>`;
      return;
    }

    const c = data.client;
    const initiales = ((c.prenom||'').charAt(0) + (c.nom||'').charAt(0)).toUpperCase();
    const photo = c.photo_profil 
      ? `<img src="<?= $base ?>/uploads/${c.photo_profil}" class="photo" alt="">`
      : `<div class="photo-placeholder">${initiales}</div>`;

    let html = `
      <div class="info-client-modal">
        ${photo}
        <div class="details">
          <div class="name">${escapeHtml(c.prenom + ' ' + c.nom)}</div>
          <div class="compte">${escapeHtml(c.numero_compte)}</div>
          <div class="phone">📞 ${escapeHtml(c.numero_phone || '—')}</div>
        </div>
      </div>

      <div class="soldes-mini">
        <div class="solde-mini-box">
          <div class="lbl">Solde CDF</div>
          <div class="val gold">${fmt(c.solde_cdf)} CDF</div>
        </div>
        <div class="solde-mini-box">
          <div class="lbl">Solde USD</div>
          <div class="val blue">${fmt(c.solde_usd)} USD</div>
        </div>
        <div class="solde-mini-box">
          <div class="lbl">Épargne CDF</div>
          <div class="val purple">${fmt(c.solde_epargne_cdf)} CDF</div>
        </div>
        <div class="solde-mini-box">
          <div class="lbl">Épargne USD</div>
          <div class="val green">${fmt(c.solde_epargne_usd)} USD</div>
        </div>
      </div>

      <h4 style="color:var(--gold);margin:20px 0 12px;">💰 Placements (${data.placements.length})</h4>
    `;

    if (data.placements.length === 0) {
      html += '<p style="color:var(--muted);text-align:center;padding:20px;">Aucun placement enregistré.</p>';
    } else {
      data.placements.forEach(p => {
        html += `
          <div class="placement-mini-item">
            <div class="info">
              <div class="ref">${escapeHtml(p.reference)}</div>
              <div class="sub">${escapeHtml(p.produit_nom)} · ${p.statut} · ${p.duree_mois} mois</div>
            </div>
            <div class="amount">${fmt(p.montant_initial)} ${p.devise}</div>
          </div>
        `;
      });
    }

    box.innerHTML = html;
  } catch (err) {
    box.innerHTML = `<div class="alert error">Erreur réseau : ${err.message}</div>`;
  }
}

/* ============================================================
   MODALE : MODIFIER
   ============================================================ */
function ouvrirModif(btn) {
  const p = JSON.parse(btn.dataset.placement);
  const initiales = (p.client_nom || '').split(' ').map(s => s.charAt(0)).join('').toUpperCase().slice(0,2);

  document.getElementById('modifInitiales').textContent = initiales || '?';
  document.getElementById('modifClientNom').textContent = p.client_nom;
  document.getElementById('modifClientCompte').textContent = p.client_compte;

  document.getElementById('modifId').value       = p.id;
  document.getElementById('modifMontant').value  = p.montant;
  document.getElementById('modifTaux').value     = p.taux;
  document.getElementById('modifDuree').value    = p.duree;
  document.getElementById('modifStatut').value   = p.statut;
  document.getElementById('modifDateDebut').value= p.date_debut;
  document.getElementById('modifDateFin').value  = p.date_fin;

  ouvrirModal('modalModif');
}

/* ============================================================
   MODALE : SUPPRIMER
   ============================================================ */
function ouvrirSuppr(btn) {
  const p = JSON.parse(btn.dataset.placement);
  const capital_restant = (parseFloat(p.montant) || 0) - (parseFloat(p.capital_retire) || 0);

  document.getElementById('supprClientNom').textContent = p.client_nom;
  document.getElementById('supprRef').textContent = p.reference;
  document.getElementById('supprMontant').textContent = fmt(p.montant) + ' ' + p.devise;
  document.getElementById('supprRestitution').textContent = fmt(capital_restant) + ' ' + p.devise;

  document.getElementById('supprId').value = p.id;
  ouvrirModal('modalSuppr');
}

/* ============================================================
   UTILITAIRES
   ============================================================ */
function fmt(n) {
  return new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(parseFloat(n) || 0);
}
function escapeHtml(str) {
  if (str == null) return '';
  return String(str).replace(/[&<>"']/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[s]));
}
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>