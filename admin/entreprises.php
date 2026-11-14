<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/prets.php';
requireRole('admin');

$pdo = Database::getConnection();
$admin_id = (int)$_SESSION['admin_id'];

$errors = [];
$success = '';

/* ============================================================
   SUPPRESSION ENTREPRISE
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'supprimer') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) throw new RuntimeException("Jeton invalide.");
        
        $id_ent = (int)($_POST['id_entreprise'] ?? 0);
        $raison = cleanString($_POST['raison'] ?? '', 255);
        
        if ($id_ent <= 0) throw new RuntimeException("Entreprise invalide.");
        
        $pdo->beginTransaction();
        
        /* Charger entreprise */
        $stmt = $pdo->prepare("SELECT e.*, c.id_client FROM Entreprises e JOIN Client c ON c.id_client = e.id_client WHERE e.id_entreprise = ? FOR UPDATE");
        $stmt->execute([$id_ent]);
        $ent = $stmt->fetch();
        
        if (!$ent) throw new RuntimeException("Entreprise introuvable.");
        
        /* Vérifier qu'il n'y a pas de prêt en cours */
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM Prets 
            WHERE id_client = ? AND statut NOT IN ('solde','rejete','annule')
        ");
        $stmt->execute([(int)$ent['id_client']]);
        if ((int)$stmt->fetchColumn() > 0) {
            throw new RuntimeException("Impossible : l'entreprise a des prêts en cours.");
        }
        
        /* Supprimer */
        $pdo->prepare("DELETE FROM Entreprises WHERE id_entreprise = ?")->execute([$id_ent]);
        
        logAction($pdo, 'admin', $admin_id, 'ENTREPRISE_DELETE',
            "ID: $id_ent | Raison: {$ent['raison_sociale']} | Motif: $raison");
        
        $pdo->commit();
        
        $_SESSION['flash_success'] = "✅ Entreprise supprimée : " . htmlspecialchars($ent['raison_sociale']);
        header('Location: ' . url('admin/entreprises.php'));
        exit;
        
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['flash_error'] = $e->getMessage();
        header('Location: ' . url('admin/entreprises.php'));
        exit;
    }
}

/* ============================================================
   VÉRIFICATION / REJET ENTREPRISE
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['verifier','rejeter'], true)) {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) throw new RuntimeException("Jeton invalide.");
        
        $id_ent = (int)($_POST['id_entreprise'] ?? 0);
        $action = $_POST['action'];
        
        if ($id_ent <= 0) throw new RuntimeException("Entreprise invalide.");
        
        $nouveau_statut = $action === 'verifier' ? 'verifie' : 'rejete';
        $notes = cleanString($_POST['notes'] ?? '', 500);
        
        $pdo->prepare("
            UPDATE Entreprises 
            SET statut_verification = ?, 
                verifie_par = ?, 
                date_verification = NOW(),
                notes_verification = ?
            WHERE id_entreprise = ?
        ")->execute([$nouveau_statut, $admin_id, $notes ?: null, $id_ent]);
        
        logAction($pdo, 'admin', $admin_id, 'ENTREPRISE_' . strtoupper($action),
            "ID: $id_ent | Notes: $notes");
        
        $_SESSION['flash_success'] = "✅ Entreprise " . ($action === 'verifier' ? 'vérifiée' : 'rejetée') . ".";
        header('Location: ' . url('admin/entreprises.php'));
        exit;
        
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
        header('Location: ' . url('admin/entreprises.php'));
        exit;
    }
}

/* ============================================================
   CHARGEMENT AVEC FILTRES
   ============================================================ */
$statut_verif = in_array($_GET['statut'] ?? '', ['en_attente','verifie','rejete'], true) ? $_GET['statut'] : '';
$recherche = trim($_GET['q'] ?? '');

$sql = "
    SELECT e.*, c.numero_compte, c.numero_phone, c.statut AS client_statut, c.id_client,
           (SELECT COUNT(*) FROM Prets p WHERE p.id_client = e.id_client) AS nb_prets,
           (SELECT COUNT(*) FROM Prets p WHERE p.id_client = e.id_client AND p.statut IN ('decaisse','en_cours','en_retard')) AS nb_prets_actifs,
           (SELECT COUNT(*) FROM Entreprise_Documents d WHERE d.id_entreprise = e.id_entreprise) AS nb_docs,
           (SELECT COUNT(*) FROM Entreprise_Dirigeants dir WHERE dir.id_entreprise = e.id_entreprise) AS nb_dirigeants
    FROM Entreprises e
    JOIN Client c ON c.id_client = e.id_client
    WHERE 1=1
";
$params = [];

if ($statut_verif !== '') {
    $sql .= " AND e.statut_verification = ?";
    $params[] = $statut_verif;
}
if ($recherche !== '') {
    $sql .= " AND (e.raison_sociale LIKE ? OR e.rccm LIKE ? OR e.nif LIKE ? OR c.numero_compte LIKE ?)";
    $like = "%$recherche%";
    array_push($params, $like, $like, $like, $like);
}
$sql .= " ORDER BY e.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$entreprises = $stmt->fetchAll();

/* ============================================================
   STATISTIQUES
   ============================================================ */
$stats = [
    'total' => (int)$pdo->query("SELECT COUNT(*) FROM Entreprises")->fetchColumn(),
    'verifiees' => (int)$pdo->query("SELECT COUNT(*) FROM Entreprises WHERE statut_verification = 'verifie'")->fetchColumn(),
    'en_attente' => (int)$pdo->query("SELECT COUNT(*) FROM Entreprises WHERE statut_verification = 'en_attente'")->fetchColumn(),
    'rejetees' => (int)$pdo->query("SELECT COUNT(*) FROM Entreprises WHERE statut_verification = 'rejete'")->fetchColumn(),
    'capital_total' => (float)$pdo->query("SELECT COALESCE(SUM(capital_social), 0) FROM Entreprises")->fetchColumn(),
];

/* Flash */
if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    $errors[] = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Entreprises — ATF Admin</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/admin-premium.css">
<style>
/* ============================================================
   KPIs
   ============================================================ */
.kpi-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
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
}
.stat-block .ico { font-size: 1.8rem; }
.stat-block .meta strong {
  display: block;
  font-size: 1.4rem;
  color: var(--gold);
  line-height: 1;
}
.stat-block .meta span {
  font-size: .72rem;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 1px;
  font-weight: 700;
}
.stat-block.green .meta strong { color: #6EE7B7; }
.stat-block.blue .meta strong { color: #93B4FF; }
.stat-block.red .meta strong { color: #FCA5A5; }

/* ============================================================
   FILTRES
   ============================================================ */
.filters-tabs {
  display: flex;
  gap: 8px;
  margin-bottom: 18px;
  flex-wrap: wrap;
}
.filters-tabs a {
  padding: 10px 18px;
  border-radius: 999px;
  background: var(--card);
  border: 1px solid var(--border);
  color: var(--text);
  text-decoration: none;
  font-weight: 700;
  font-size: .85rem;
  transition: all .2s;
}
.filters-tabs a:hover { border-color: var(--gold); }
.filters-tabs a.active {
  background: linear-gradient(135deg, #F5B800, #D4AF37);
  color: #0B0E14;
  border-color: transparent;
}

.filters-bar {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 20px;
  padding: 16px;
  background: linear-gradient(145deg, var(--card), var(--card-2));
  border: 1px solid var(--border);
  border-radius: 14px;
}
.filters-bar input {
  flex: 1;
  min-width: 220px;
  padding: 12px 16px;
  background: var(--bg-1);
  border: 1px solid var(--border);
  border-radius: 10px;
  color: var(--text);
  font-size: .9rem;
  font-family: inherit;
}
.filters-bar input:focus {
  outline: none;
  border-color: var(--gold);
}

/* ============================================================
   TABLEAU ENTREPRISES
   ============================================================ */
.ent-table {
  width: 100%;
  border-collapse: collapse;
  background: linear-gradient(145deg, var(--card), var(--card-2));
  border-radius: 14px;
  overflow: hidden;
}
.ent-table th {
  padding: 14px 12px;
  text-align: left;
  font-size: .72rem;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: .5px;
  font-weight: 700;
  background: rgba(11,14,20,.4);
  border-bottom: 1px solid var(--border);
}
.ent-table td {
  padding: 14px 12px;
  border-bottom: 1px solid var(--border);
  font-size: .85rem;
  vertical-align: middle;
}
.ent-table tr:hover {
  background: rgba(245,184,0,.03);
}
.ent-table tr:last-child td { border-bottom: none; }

/* Logo miniature */
.ent-logo-mini {
  width: 42px;
  height: 42px;
  border-radius: 10px;
  background: linear-gradient(135deg, #8B5CF6, #7C3AED);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 900;
  font-size: .85rem;
  flex-shrink: 0;
}
.ent-cell {
  display: flex;
  align-items: center;
  gap: 12px;
}
.ent-cell-info .name {
  font-weight: 800;
  color: var(--text);
  font-size: .9rem;
}
.ent-cell-info .sub {
  color: var(--muted);
  font-size: .75rem;
  margin-top: 2px;
}

/* Badges statut */
.badge-verif {
  display: inline-block;
  padding: 4px 12px;
  border-radius: 999px;
  font-size: .7rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: .5px;
  white-space: nowrap;
}
.badge-verif.en_attente {
  background: rgba(245,184,0,.15);
  color: #FCD34D;
  border: 1px solid rgba(245,184,0,.3);
}
.badge-verif.verifie {
  background: rgba(16,185,129,.15);
  color: #6EE7B7;
  border: 1px solid rgba(16,185,129,.3);
}
.badge-verif.rejete {
  background: rgba(239,68,68,.15);
  color: #FCA5A5;
  border: 1px solid rgba(239,68,68,.3);
}

/* ============================================================
   BOUTONS D'ACTION
   ============================================================ */
.actions-cell {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}
.btn-action-mini {
  padding: 7px 12px;
  border-radius: 8px;
  font-size: .72rem;
  font-weight: 800;
  border: 1px solid transparent;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  transition: all .2s;
  text-decoration: none;
  font-family: inherit;
  white-space: nowrap;
}
.btn-action-mini:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(0,0,0,.3);
}

/* Voir — bleu */
.btn-action-mini.view {
  background: rgba(59,130,246,.15);
  color: #93B4FF;
  border-color: rgba(59,130,246,.35);
}
.btn-action-mini.view:hover { background: rgba(59,130,246,.25); }

/* Dirigeants — violet */
.btn-action-mini.dirigeants {
  background: rgba(139,92,246,.15);
  color: #A78BFA;
  border-color: rgba(139,92,246,.35);
}
.btn-action-mini.dirigeants:hover { background: rgba(139,92,246,.25); }

/* Bilans — vert */
.btn-action-mini.bilans {
  background: rgba(16,185,129,.15);
  color: #6EE7B7;
  border-color: rgba(16,185,129,.35);
}
.btn-action-mini.bilans:hover { background: rgba(16,185,129,.25); }

/* Vérifier — or */
.btn-action-mini.verifier {
  background: rgba(245,184,0,.15);
  color: var(--gold);
  border-color: rgba(245,184,0,.35);
}
.btn-action-mini.verifier:hover { background: rgba(245,184,0,.25); }

/* Rejeter — rouge */
.btn-action-mini.rejeter {
  background: rgba(239,68,68,.15);
  color: #FCA5A5;
  border-color: rgba(239,68,68,.35);
}
.btn-action-mini.rejeter:hover { background: rgba(239,68,68,.25); }

/* Supprimer — gris */
.btn-action-mini.supprimer {
  background: rgba(107,114,128,.15);
  color: #9CA3AF;
  border-color: rgba(107,114,128,.35);
}
.btn-action-mini.supprimer:hover {
  background: rgba(239,68,68,.15);
  color: #FCA5A5;
  border-color: rgba(239,68,68,.35);
}

/* ============================================================
   MODALES
   ============================================================ */
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
  max-width: 500px;
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
  margin-bottom: 22px;
  padding-bottom: 16px;
  border-bottom: 1px solid var(--border);
}
.modal-header h3 {
  color: var(--gold);
  font-size: 1.15rem;
  font-weight: 800;
  margin: 0;
}
.modal-close {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: rgba(239,68,68,.15);
  color: #FCA5A5;
  border: 1px solid rgba(239,68,68,.3);
  cursor: pointer;
  font-size: 1.2rem;
  font-weight: 800;
  display: flex;
  align-items: center;
  justify-content: center;
}

.modal-actions {
  display: flex;
  gap: 12px;
  justify-content: flex-end;
  margin-top: 22px;
  padding-top: 18px;
  border-top: 1px solid var(--border);
  flex-wrap: wrap;
}

.danger-box {
  padding: 16px;
  border-radius: 12px;
  background: rgba(239,68,68,.08);
  border: 1px solid rgba(239,68,68,.3);
  margin: 18px 0;
  color: #FCA5A5;
  font-size: .88rem;
  line-height: 1.6;
}

/* Bouton créer entreprise */
.btn-nouvelle {
  padding: 12px 22px;
  border-radius: 12px;
  background: linear-gradient(135deg, #F5B800, #D4AF37);
  color: #0B0E14;
  text-decoration: none;
  font-weight: 800;
  font-size: .9rem;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  transition: all .25s;
}
.btn-nouvelle:hover {
  transform: translateY(-2px);
  box-shadow: 0 10px 28px rgba(245,184,0,.5);
}

/* Empty state */
.empty-state {
  text-align: center;
  padding: 60px 20px;
}
.empty-state .ico {
  font-size: 3.5rem;
  opacity: .5;
  margin-bottom: 14px;
}
.empty-state h3 {
  color: var(--text);
  font-size: 1.1rem;
  margin-bottom: 8px;
}

/* ============================================================
   RESPONSIVE MOBILE
   ============================================================ */
@media (max-width: 900px) {
  .admin-layout { grid-template-columns: 1fr; }
}

@media (max-width: 768px) {
  .ent-table thead { display: none; }
  .ent-table tbody tr {
    display: block;
    margin-bottom: 14px;
    padding: 16px;
    border: 1px solid var(--border);
    border-radius: 14px;
    background: var(--card);
  }
  .ent-table td {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    border: none;
    text-align: right;
    gap: 12px;
  }
  .ent-table td::before {
    content: attr(data-label);
    color: var(--muted);
    font-size: .7rem;
    text-transform: uppercase;
    font-weight: 700;
    text-align: left;
    flex-shrink: 0;
  }
  .actions-cell {
    justify-content: flex-end;
    flex-wrap: wrap;
  }
  .modal-content { padding: 24px 20px; }
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
    <a href="<?= $base ?>/admin/entreprises.php" class="active"><span class="ico">🏢</span> Entreprises</a>
    <a href="<?= $base ?>/admin/prets.php"><span class="ico">💳</span> Prêts</a>
    <a href="<?= $base ?>/admin/prets_produits.php"><span class="ico">📋</span> Produits prêt</a>
    <a href="<?= $base ?>/admin/prets_echeances.php"><span class="ico">📅</span> Échéances</a>
    <a href="<?= $base ?>/admin/rapport_prets.php"><span class="ico">📈</span> Rapport prêts</a>
    <a href="<?= $base ?>/admin/tests_flux.php"><span class="ico">🧪</span> Tests flux</a>
    <a href="<?= $base ?>/admin/logs.php"><span class="ico">📜</span> Journal</a>
    <a href="<?= $base ?>/auth/logout.php"><span class="ico">🚪</span> Déconnexion</a>
  </aside>

  <main class="admin-main">

    <!-- En-tête -->
    <div class="welcome-head">
      <div>
        <h2>🏢 Entreprises (Personnes Morales)</h2>
        <p class="sub"><?= $stats['total'] ?> entreprise(s) — <?= $stats['verifiees'] ?> vérifiée(s)</p>
      </div>
      <a class="btn-nouvelle" href="<?= $base ?>/admin/entreprise_nouvelle.php">
        ➕ Nouvelle entreprise
      </a>
    </div>

    <!-- Messages -->
    <?php if ($success): ?>
      <div class="alert success"><?= $success ?></div>
    <?php endif; ?>
    <?php if ($errors): ?>
      <div class="alert error"><ul><?php foreach ($errors as $e) echo "<li>" . htmlspecialchars($e) . "</li>"; ?></ul></div>
    <?php endif; ?>

    <!-- KPIs -->
    <section class="kpi-grid">
      <div class="stat-block">
        <div class="ico">🏢</div>
        <div class="meta"><strong><?= $stats['total'] ?></strong><span>Total</span></div>
      </div>
      <div class="stat-block green">
        <div class="ico">✅</div>
        <div class="meta"><strong><?= $stats['verifiees'] ?></strong><span>Vérifiées</span></div>
      </div>
      <div class="stat-block">
        <div class="ico">⏳</div>
        <div class="meta"><strong><?= $stats['en_attente'] ?></strong><span>En attente</span></div>
      </div>
      <div class="stat-block red">
        <div class="ico">❌</div>
        <div class="meta"><strong><?= $stats['rejetees'] ?></strong><span>Rejetées</span></div>
      </div>
      <div class="stat-block blue">
        <div class="ico">💰</div>
        <div class="meta">
          <strong><?= number_format($stats['capital_total'], 0, ',', ' ') ?></strong>
          <span>Capital total (CDF)</span>
        </div>
      </div>
    </section>

    <!-- Filtres onglets -->
    <div class="filters-tabs">
      <a href="?statut=" class="<?= $statut_verif === '' ? 'active' : '' ?>">🏢 Toutes</a>
      <a href="?statut=en_attente" class="<?= $statut_verif === 'en_attente' ? 'active' : '' ?>">⏳ En attente</a>
      <a href="?statut=verifie" class="<?= $statut_verif === 'verifie' ? 'active' : '' ?>">✅ Vérifiées</a>
      <a href="?statut=rejete" class="<?= $statut_verif === 'rejete' ? 'active' : '' ?>">❌ Rejetées</a>
    </div>

    <!-- Barre recherche -->
    <form method="GET" class="filters-bar">
      <input type="hidden" name="statut" value="<?= htmlspecialchars($statut_verif) ?>">
      <input type="text" name="q" placeholder="🔍 Raison sociale, RCCM, NIF, numéro de compte..." 
             value="<?= htmlspecialchars($recherche) ?>">
      <button class="btn-gold" type="submit" style="padding:12px 24px;border-radius:10px">Rechercher</button>
      <?php if ($recherche): ?>
        <a href="?statut=<?= htmlspecialchars($statut_verif) ?>" 
           style="padding:12px 20px;border-radius:10px;background:rgba(107,114,128,.15);color:#9CA3AF;text-decoration:none;font-weight:700">
          ✕ Effacer
        </a>
      <?php endif; ?>
    </form>

    <!-- Liste -->
    <?php if (empty($entreprises)): ?>
      <div class="card empty-state">
        <div class="ico">🏢</div>
        <h3>Aucune entreprise trouvée</h3>
        <p class="muted" style="margin-top:8px">
          <?= $recherche || $statut_verif ? 'Essayez de modifier vos filtres.' : 'Créez votre première entreprise.' ?>
        </p>
      </div>
    <?php else: ?>
      <div class="table-container">
        <table class="ent-table">
          <thead>
            <tr>
              <th>Entreprise</th>
              <th>Forme</th>
              <th>RCCM / NIF</th>
              <th>Capital</th>
              <th>Prêts</th>
              <th>Statut</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($entreprises as $e):
              $initiales = strtoupper(mb_substr($e['raison_sociale'], 0, 2));
            ?>
              <tr>
                <!-- Entreprise -->
                <td data-label="Entreprise">
                  <div class="ent-cell">
                    <div class="ent-logo-mini"><?= htmlspecialchars($initiales) ?></div>
                    <div class="ent-cell-info">
                      <div class="name"><?= htmlspecialchars($e['raison_sociale']) ?></div>
                      <div class="sub">
                        <?= htmlspecialchars($e['numero_compte']) ?>
                        <?php if ($e['secteur_activite']): ?>
                          · <?= htmlspecialchars($e['secteur_activite']) ?>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                </td>

                <!-- Forme -->
                <td data-label="Forme">
                  <span class="badge gold"><?= htmlspecialchars($e['forme_juridique']) ?></span>
                </td>

                <!-- RCCM / NIF -->
                <td data-label="RCCM / NIF">
                  <div style="font-family:monospace;font-size:.75rem;color:#93B4FF">
                    <?= htmlspecialchars($e['rccm'] ?? '—') ?>
                  </div>
                  <div style="font-family:monospace;font-size:.75rem;color:var(--muted)">
                    <?= htmlspecialchars($e['nif'] ?? '—') ?>
                  </div>
                </td>

                <!-- Capital -->
                <td data-label="Capital">
                  <strong style="color:var(--gold)">
                    <?= number_format((float)$e['capital_social'], 0, ',', ' ') ?>
                  </strong>
                  <span style="color:var(--muted);font-size:.75rem">
                    <?= htmlspecialchars($e['devise_capital']) ?>
                  </span>
                </td>

                <!-- Prêts -->
                <td data-label="Prêts">
                  <div style="display:flex;flex-direction:column;gap:4px">
                    <span style="color:#93B4FF;font-weight:700">
                      <?= (int)$e['nb_prets'] ?> total
                    </span>
                    <?php if ((int)$e['nb_prets_actifs'] > 0): ?>
                      <span style="color:#FCD34D;font-size:.75rem">
                        <?= (int)$e['nb_prets_actifs'] ?> actif(s)
                      </span>
                    <?php endif; ?>
                  </div>
                </td>

                <!-- Statut -->
                <td data-label="Statut">
                  <span class="badge-verif <?= htmlspecialchars($e['statut_verification']) ?>">
                    <?php 
                      $labels = [
                        'en_attente' => '⏳ En attente',
                        'verifie' => '✅ Vérifié',
                        'rejete' => '❌ Rejeté',
                      ];
                      echo $labels[$e['statut_verification']] ?? htmlspecialchars($e['statut_verification']);
                    ?>
                  </span>
                  <div style="font-size:.7rem;color:var(--muted);margin-top:4px">
                    <?= (int)$e['nb_dirigeants'] ?> dirigeant(s) · <?= (int)$e['nb_docs'] ?> doc(s)
                  </div>
                </td>

                <!-- Actions -->
                <td data-label="Actions">
                  <div class="actions-cell">
                    <!-- VOIR -->
                    <a class="btn-action-mini view" 
                       href="<?= $base ?>/admin/entreprise_details.php?token=<?= htmlspecialchars(genererToken('entreprise', (int)$e['id_entreprise'])) ?>"
                       title="Voir les détails">
                      👁 Voir
                    </a>

                    <!-- DIRIGEANTS -->
                    <a class="btn-action-mini dirigeants"
                       href="<?= $base ?>/admin/entreprise_dirigeants.php?token=<?= htmlspecialchars(genererToken('entreprise', (int)$e['id_entreprise'])) ?>"
                       title="Gérer les dirigeants">
                      👥 Dirigeants
                    </a>

                    <!-- BILANS -->
                    <a class="btn-action-mini bilans"
                       href="<?= $base ?>/admin/entreprise_bilan.php?token=<?= htmlspecialchars(genererToken('entreprise', (int)$e['id_entreprise'])) ?>"
                       title="Voir les bilans">
                      📊 Bilans
                    </a>

                    <!-- VÉRIFIER (si en attente) -->
                    <?php if ($e['statut_verification'] === 'en_attente'): ?>
                      <button class="btn-action-mini verifier"
                              data-entreprise='<?= htmlspecialchars(json_encode([
                                'id' => (int)$e['id_entreprise'],
                                'nom' => $e['raison_sociale'],
                                'action' => 'verifier',
                              ]), ENT_QUOTES, 'UTF-8') ?>'
                              onclick="ouvrirValidation(this)"
                              title="Valider l'entreprise">
                        ✅ Vérifier
                      </button>
                      <button class="btn-action-mini rejeter"
                              data-entreprise='<?= htmlspecialchars(json_encode([
                                'id' => (int)$e['id_entreprise'],
                                'nom' => $e['raison_sociale'],
                                'action' => 'rejeter',
                              ]), ENT_QUOTES, 'UTF-8') ?>'
                              onclick="ouvrirValidation(this)"
                              title="Rejeter l'entreprise">
                        ❌ Rejeter
                      </button>
                    <?php endif; ?>

                    <!-- SUPPRIMER -->
                    <button class="btn-action-mini supprimer"
                            data-entreprise='<?= htmlspecialchars(json_encode([
                              'id' => (int)$e['id_entreprise'],
                              'nom' => $e['raison_sociale'],
                              'nb_prets' => (int)$e['nb_prets'],
                              'nb_prets_actifs' => (int)$e['nb_prets_actifs'],
                            ]), ENT_QUOTES, 'UTF-8') ?>'
                            onclick="ouvrirSuppression(this)"
                            title="Supprimer l'entreprise">
                      🗑 Supprimer
                    </button>
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
     MODALE : VALIDATION (Vérifier / Rejeter)
     ============================================================ -->
<div class="modal-overlay" id="modalValidation">
  <div class="modal-content">
    <div class="modal-header">
      <h3 id="modalValTitle">Validation</h3>
      <button class="modal-close" onclick="fermerModal('modalValidation')">✕</button>
    </div>

    <div id="modalValInfo" style="padding:14px;border-radius:12px;background:rgba(11,14,20,.5);border:1px solid var(--border);margin-bottom:18px">
      <div style="color:var(--muted);font-size:.75rem;text-transform:uppercase;font-weight:700;margin-bottom:4px">Entreprise</div>
      <div style="font-weight:800;color:var(--text);font-size:1rem" id="modalValNom">—</div>
    </div>

    <form method="POST" id="formValidation">
      <?= csrfField() ?>
      <input type="hidden" name="action" id="modalValAction">
      <input type="hidden" name="id_entreprise" id="modalValId">

      <label class="field" style="display:block;margin-bottom:16px">
        <span style="display:block;color:var(--muted);font-size:.78rem;text-transform:uppercase;font-weight:700;margin-bottom:8px">
          Notes / Motif (optionnel)
        </span>
        <textarea name="notes" rows="3" maxlength="500"
                  style="width:100%;padding:12px;background:var(--bg-1);border:1px solid var(--border);border-radius:10px;color:var(--text);font-family:inherit;resize:vertical"
                  placeholder="Ex: Documents vérifiés, RCCM conforme..."></textarea>
      </label>

      <div class="modal-actions">
        <button type="button" class="btn-ghost" onclick="fermerModal('modalValidation')">Annuler</button>
        <button type="submit" class="btn-gold" id="modalValSubmit">Confirmer</button>
      </div>
    </form>
  </div>
</div>

<!-- ============================================================
     MODALE : SUPPRESSION
     ============================================================ -->
<div class="modal-overlay" id="modalSuppression">
  <div class="modal-content">
    <div class="modal-header">
      <h3>🗑 Supprimer l'entreprise</h3>
      <button class="modal-close" onclick="fermerModal('modalSuppression')">✕</button>
    </div>

    <div class="danger-box">
      ⚠️ <strong>Attention ! Action irréversible</strong><br>
      Vous êtes sur le point de supprimer :<br>
      <strong id="supprNom" style="font-size:1.05rem;color:#FCA5A5;display:block;margin-top:8px">—</strong>
      <small id="supprInfo" style="color:#94A3B8;display:block;margin-top:6px"></small>
    </div>

    <form method="POST" id="formSuppression" onsubmit="return confirm('Confirmer la suppression définitive ?')">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="supprimer">
      <input type="hidden" name="id_entreprise" id="supprId">

      <label class="field" style="display:block;margin-bottom:16px">
        <span style="display:block;color:var(--muted);font-size:.78rem;text-transform:uppercase;font-weight:700;margin-bottom:8px">
          Raison de la suppression *
        </span>
        <textarea name="raison" required rows="2" maxlength="255"
                  style="width:100%;padding:12px;background:var(--bg-1);border:1px solid var(--border);border-radius:10px;color:var(--text);font-family:inherit;resize:vertical"
                  placeholder="Ex: Erreur de saisie, doublon..."></textarea>
      </label>

      <p style="color:#FCA5A5;font-size:.8rem;margin-bottom:14px">
        💡 Tous les dirigeants, bilans et documents associés seront également supprimés.
      </p>

      <div class="modal-actions">
        <button type="button" class="btn-ghost" onclick="fermerModal('modalSuppression')">Annuler</button>
        <button type="submit" 
                style="padding:12px 24px;border-radius:12px;background:linear-gradient(135deg,#DC2626,#EF4444);color:#fff;border:none;font-weight:800;cursor:pointer">
          🗑 Confirmer la suppression
        </button>
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

/* Fermer au clic sur l'overlay */
document.querySelectorAll('.modal-overlay').forEach(ov => {
  ov.addEventListener('click', e => {
    if (e.target === ov) {
      ov.classList.remove('active');
      document.body.style.overflow = '';
    }
  });
});

/* Fermer avec Echap */
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-overlay.active').forEach(m => m.classList.remove('active'));
    document.body.style.overflow = '';
  }
});

/* ============================================================
   VALIDATION (Vérifier / Rejeter)
   ============================================================ */
function ouvrirValidation(btn) {
  const e = JSON.parse(btn.dataset.entreprise);
  const action = e.action;
  
  document.getElementById('modalValId').value = e.id;
  document.getElementById('modalValNom').textContent = e.nom;
  document.getElementById('modalValAction').value = action;
  
  if (action === 'verifier') {
    document.getElementById('modalValTitle').textContent = '✅ Vérifier l\'entreprise';
    document.getElementById('modalValSubmit').textContent = '✅ Confirmer la vérification';
    document.getElementById('modalValSubmit').style.background = 'linear-gradient(135deg, #10B981, #059669)';
    document.getElementById('modalValSubmit').style.color = '#fff';
  } else {
    document.getElementById('modalValTitle').textContent = '❌ Rejeter l\'entreprise';
    document.getElementById('modalValSubmit').textContent = '❌ Confirmer le rejet';
    document.getElementById('modalValSubmit').style.background = 'linear-gradient(135deg, #DC2626, #EF4444)';
    document.getElementById('modalValSubmit').style.color = '#fff';
  }
  
  ouvrirModal('modalValidation');
}

/* ============================================================
   SUPPRESSION
   ============================================================ */
function ouvrirSuppression(btn) {
  const e = JSON.parse(btn.dataset.entreprise);
  
  document.getElementById('supprId').value = e.id;
  document.getElementById('supprNom').textContent = e.nom;
  
  let info = e.nb_prets + ' prêt(s) enregistré(s)';
  if (e.nb_prets_actifs > 0) {
    info += ' — ⚠️ ' + e.nb_prets_actifs + ' prêt(s) actif(s) !';
  }
  document.getElementById('supprInfo').textContent = info;
  
  ouvrirModal('modalSuppression');
}

/* ============================================================
   SOUMISSION AVEC FEEDBACK
   ============================================================ */
document.getElementById('formValidation')?.addEventListener('submit', function() {
  const btn = document.getElementById('modalValSubmit');
  btn.disabled = true;
  btn.textContent = '⏳ Traitement...';
});

document.getElementById('formSuppression')?.addEventListener('submit', function() {
  const btn = this.querySelector('button[type="submit"]');
  btn.disabled = true;
  btn.textContent = '⏳ Suppression...';
});

/* ============================================================
   EFFECT TOAST
   ============================================================ */
console.log('%c🏢 Page Entreprises ATF chargée', 'color:#F5B800;font-weight:bold;font-size:13px');
</script>

<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>