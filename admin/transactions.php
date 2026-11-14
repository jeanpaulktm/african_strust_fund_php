<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
requireRole('admin');

$pdo = Database::getConnection();
$admin_id = (int)$_SESSION['admin_id'];

/* ============================================================
   FILTRES + PAGINATION
   ============================================================ */
$type   = in_array($_GET['type'] ?? '', ['Depot','Retrait','Virement'], true) ? $_GET['type'] : '';
$mode   = in_array($_GET['mode'] ?? '', ['En ligne','Agence'], true) ? $_GET['mode'] : '';
$statut = in_array($_GET['statut'] ?? '', ['success','failed'], true) ? $_GET['statut'] : '';

/* Recherche */
$search = trim((string)($_GET['q'] ?? ''));

/* Date */
$date_debut = $_GET['date_debut'] ?? '';
$date_fin   = $_GET['date_fin'] ?? '';

/* Pagination */
$par_page = 20; // ⭐ 20 transactions par page
$page = max(1, (int)($_GET['page'] ?? 1));

/* ============================================================
   CONSTRUCTION CLAUSE WHERE
   ============================================================ */
$where = " WHERE 1=1";
$params = [];

if ($type !== '') {
    $where .= " AND t.type_transaction = ?";
    $params[] = $type;
}
if ($mode !== '') {
    $where .= " AND t.mode = ?";
    $params[] = $mode;
}
if ($statut !== '') {
    $where .= " AND t.statut = ?";
    $params[] = $statut;
}
if ($search !== '') {
    $where .= " AND (t.reference LIKE ? OR c.nom LIKE ? OR c.postnom LIKE ? OR c.numero_compte LIKE ?)";
    $like = "%$search%";
    array_push($params, $like, $like, $like, $like);
}
if ($date_debut !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_debut)) {
    $where .= " AND DATE(t.date_transaction) >= ?";
    $params[] = $date_debut;
}
if ($date_fin !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_fin)) {
    $where .= " AND DATE(t.date_transaction) <= ?";
    $params[] = $date_fin;
}

/* ============================================================
   COMPTER LE TOTAL (pour la pagination)
   ============================================================ */
$sql_count = "SELECT COUNT(*) FROM Transactions t JOIN Client c ON c.id_client = t.id_client" . $where;
$stmt = $pdo->prepare($sql_count);
$stmt->execute($params);
$total_transactions = (int)$stmt->fetchColumn();

$total_pages = max(1, (int)ceil($total_transactions / $par_page));
if ($page > $total_pages) $page = $total_pages;

$offset = ($page - 1) * $par_page;

/* ============================================================
   REQUÊTE PAGINÉE
   ============================================================ */
$sql = "SELECT t.*, c.nom, c.postnom, c.prenom, c.numero_compte, c.devise AS devise_client
        FROM Transactions t
        JOIN Client c ON c.id_client = t.id_client"
        . $where
        . " ORDER BY t.date_transaction DESC LIMIT $par_page OFFSET $offset";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

/* ============================================================
   TOTAUX PAR TYPE
   ============================================================ */
$totaux = [
    'depot'    => (float)$pdo->query("SELECT COALESCE(SUM(montant),0) FROM Transactions WHERE type_transaction='Depot'")->fetchColumn(),
    'retrait'  => (float)$pdo->query("SELECT COALESCE(SUM(montant),0) FROM Transactions WHERE type_transaction='Retrait'")->fetchColumn(),
    'virement' => (float)$pdo->query("SELECT COALESCE(SUM(montant),0) FROM Transactions WHERE type_transaction='Virement'")->fetchColumn(),
    'total'    => (int)$pdo->query("SELECT COUNT(*) FROM Transactions")->fetchColumn(),
];

/* ============================================================
   STATS AUJOURD'HUI
   ============================================================ */
$aujourdhui = [
    'nb'     => (int)$pdo->query("SELECT COUNT(*) FROM Transactions WHERE DATE(date_transaction) = CURDATE()")->fetchColumn(),
    'volume' => (float)$pdo->query("SELECT COALESCE(SUM(montant),0) FROM Transactions WHERE DATE(date_transaction) = CURDATE()")->fetchColumn(),
];

logAction($pdo, 'admin', $admin_id, 'TRANSACTIONS_VIEW', "Page=$page type=$type mode=$mode");

$base = '/african-trust-fund';

/* ============================================================
   HELPER : Construire URL avec paramètres actuels
   ============================================================ */
function buildUrl(array $params = []): string {
    $current = [
        'type' => $_GET['type'] ?? '',
        'mode' => $_GET['mode'] ?? '',
        'statut' => $_GET['statut'] ?? '',
        'q' => $_GET['q'] ?? '',
        'date_debut' => $_GET['date_debut'] ?? '',
        'date_fin' => $_GET['date_fin'] ?? '',
    ];
    $merged = array_filter(array_merge($current, $params), fn($v) => $v !== '' && $v !== null);
    return '?' . http_build_query($merged);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Transactions — ATF Admin</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/admin-premium.css">
<style>
/* ============================================================
   TABLEAU AMÉLIORÉ
   ============================================================ */
.tx-table-wrap {
    background: linear-gradient(145deg, var(--card), var(--card-2));
    border-radius: 18px;
    border: 1px solid var(--border);
    overflow: hidden;
    box-shadow: 0 12px 40px rgba(0,0,0,.3);
}

.tx-table-header {
    padding: 20px 24px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    background: linear-gradient(135deg, rgba(245,184,0,.05), rgba(139,92,246,.03));
}
.tx-table-header h3 {
    margin: 0;
    font-size: 1.05rem;
    color: var(--gold);
    display: flex;
    align-items: center;
    gap: 10px;
}
.tx-table-header .counter {
    padding: 6px 14px;
    border-radius: 999px;
    background: rgba(245,184,0,.12);
    color: var(--gold);
    font-size: .78rem;
    font-weight: 800;
    border: 1px solid rgba(245,184,0,.3);
}

table.tx-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .85rem;
}
table.tx-table thead {
    background: rgba(11,14,20,.6);
    position: sticky;
    top: 0;
    z-index: 10;
}
table.tx-table thead th {
    padding: 14px 16px;
    text-align: left;
    font-size: .7rem;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 1px;
    font-weight: 800;
    white-space: nowrap;
    border-bottom: 2px solid var(--border);
}
table.tx-table tbody tr {
    transition: all .2s;
    border-bottom: 1px solid rgba(255,255,255,.04);
}
table.tx-table tbody tr:hover {
    background: rgba(245,184,0,.05);
}
table.tx-table tbody tr:nth-child(even) {
    background: rgba(255,255,255,.015);
}
table.tx-table td {
    padding: 14px 16px;
    vertical-align: middle;
    color: var(--text);
}
table.tx-table .ref {
    font-family: 'Courier New', monospace;
    color: var(--gold);
    font-weight: 700;
    font-size: .8rem;
    background: rgba(245,184,0,.08);
    padding: 4px 10px;
    border-radius: 6px;
    display: inline-block;
}
table.tx-table .client-cell strong {
    display: block;
    color: var(--text);
    font-weight: 700;
    margin-bottom: 3px;
}
table.tx-table .client-cell small {
    color: var(--muted);
    font-family: 'Courier New', monospace;
    font-size: .72rem;
}
table.tx-table .amount-cell {
    text-align: right;
    font-weight: 900;
    font-size: .95rem;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}
table.tx-table .amount-cell.pos { color: #6EE7B7; }
table.tx-table .amount-cell.neg { color: #FCA5A5; }
table.tx-table .solde-cell {
    text-align: right;
    color: var(--muted);
    font-variant-numeric: tabular-nums;
    font-size: .82rem;
}

/* Badge type transaction */
.tag-tx {
    padding: 5px 12px;
    border-radius: 8px;
    font-size: .72rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .3px;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    white-space: nowrap;
}
.tag-tx.depot {
    background: rgba(16,185,129,.15);
    color: #6EE7B7;
    border: 1px solid rgba(16,185,129,.4);
}
.tag-tx.retrait {
    background: rgba(239,68,68,.15);
    color: #FCA5A5;
    border: 1px solid rgba(239,68,68,.4);
}
.tag-tx.virement {
    background: rgba(59,130,246,.15);
    color: #93B4FF;
    border: 1px solid rgba(59,130,246,.4);
}

/* Badge mode */
.badge-mode {
    padding: 4px 10px;
    border-radius: 6px;
    font-size: .7rem;
    font-weight: 700;
    background: rgba(139,92,246,.12);
    color: #A78BFA;
    border: 1px solid rgba(139,92,246,.3);
    white-space: nowrap;
}

/* Badge effectué par */
.badge-par {
    padding: 4px 10px;
    border-radius: 6px;
    font-size: .68rem;
    font-weight: 700;
    background: rgba(245,184,0,.1);
    color: var(--gold);
    border: 1px solid rgba(245,184,0,.25);
}

/* ============================================================
   PAGINATION
   ============================================================ */
.pagination-wrap {
    padding: 22px 24px;
    background: linear-gradient(135deg, rgba(11,14,20,.4), rgba(11,14,20,.2));
    border-top: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
}

.pagination-info {
    color: var(--muted);
    font-size: .82rem;
    font-weight: 600;
}
.pagination-info strong {
    color: var(--gold);
    font-weight: 800;
}

.pagination {
    display: flex;
    gap: 6px;
    align-items: center;
    flex-wrap: wrap;
}

.page-btn {
    min-width: 40px;
    height: 40px;
    padding: 0 12px;
    border-radius: 10px;
    background: rgba(255,255,255,.04);
    border: 1px solid var(--border);
    color: var(--text);
    font-weight: 800;
    font-size: .85rem;
    font-family: inherit;
    cursor: pointer;
    transition: all .2s;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    gap: 5px;
    white-space: nowrap;
}
.page-btn:hover:not(:disabled):not(.active) {
    background: rgba(245,184,0,.12);
    border-color: rgba(245,184,0,.5);
    color: var(--gold);
    transform: translateY(-2px);
}
.page-btn.active {
    background: linear-gradient(135deg, var(--gold), #D4AF37);
    color: #0B0E14;
    border-color: var(--gold);
    box-shadow: 0 6px 20px rgba(245,184,0,.4);
    pointer-events: none;
}
.page-btn:disabled {
    opacity: .35;
    cursor: not-allowed;
}
.page-btn.prev-next {
    padding: 0 16px;
    background: rgba(59,130,246,.1);
    border-color: rgba(59,130,246,.3);
    color: #93B4FF;
}
.page-btn.prev-next:hover:not(:disabled) {
    background: rgba(59,130,246,.2);
    border-color: rgba(59,130,246,.5);
    color: #93B4FF;
}

.page-dots {
    color: var(--muted);
    padding: 0 4px;
    font-weight: 800;
    user-select: none;
}

/* ============================================================
   BARRE FILTRES AMÉLIORÉE
   ============================================================ */
.filter-panel {
    padding: 20px 24px;
    background: linear-gradient(145deg, var(--card), var(--card-2));
    border: 1px solid var(--border);
    border-radius: 16px;
    margin-bottom: 20px;
}
.filter-panel .row {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1fr 1fr auto;
    gap: 10px;
    align-items: center;
}
.filter-panel input,
.filter-panel select {
    padding: 11px 14px;
    background: var(--bg-1);
    border: 1px solid var(--border);
    border-radius: 10px;
    color: var(--text);
    font-size: .85rem;
    font-family: inherit;
    width: 100%;
}
.filter-panel input:focus,
.filter-panel select:focus {
    outline: none;
    border-color: var(--gold);
}
.btn-filter {
    padding: 11px 22px;
    border-radius: 10px;
    background: linear-gradient(135deg, var(--gold), #D4AF37);
    color: #0B0E14;
    font-weight: 800;
    border: none;
    cursor: pointer;
    font-family: inherit;
    font-size: .85rem;
    transition: all .2s;
    white-space: nowrap;
}
.btn-filter:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(245,184,0,.4);
}
.btn-reset {
    padding: 11px 18px;
    border-radius: 10px;
    background: rgba(255,255,255,.05);
    color: var(--muted);
    border: 1px solid var(--border);
    cursor: pointer;
    font-family: inherit;
    font-size: .82rem;
    font-weight: 700;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    white-space: nowrap;
    transition: all .2s;
}
.btn-reset:hover {
    background: rgba(239,68,68,.15);
    color: #FCA5A5;
    border-color: rgba(239,68,68,.3);
}

@media (max-width: 1100px) {
    .filter-panel .row { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 700px) {
    .filter-panel .row { grid-template-columns: 1fr; }
    .pagination-wrap { flex-direction: column; }
    table.tx-table { font-size: .78rem; }
    table.tx-table td, table.tx-table th { padding: 10px 8px; }
}

/* ============================================================
   RESPONSIVE TABLE
   ============================================================ */
@media (max-width: 900px) {
    .tx-table-wrap { overflow-x: auto; }
    table.tx-table { min-width: 800px; }
}
</style>
</head>
<body class="dark-auth">

<div class="top-progress" id="topProgress"></div>
<?php require __DIR__ . '/../includes/header.php'; ?>

<div class="admin-layout">
  <aside class="admin-sidebar animate-left">
    <h3>📊 Navigation</h3>
    <a href="<?= $base ?>/admin/dashboard.php"><span class="ico">📊</span> Tableau de bord</a>
    <a href="<?= $base ?>/admin/clients.php"><span class="ico">👥</span> Clients</a>
    <a href="<?= $base ?>/admin/caissiers.php"><span class="ico">💼</span> Caissiers</a>
    <a href="<?= $base ?>/admin/produits.php"><span class="ico">📈</span> Produits</a>
    <a href="<?= $base ?>/admin/placements.php"><span class="ico">💰</span> Placements</a>
    <a href="<?= $base ?>/admin/commissions.php"><span class="ico">💵</span> Commissions</a>
    <a href="<?= $base ?>/admin/statistiques.php"><span class="ico">📈</span> Statistiques</a>
    <a href="<?= $base ?>/admin/transactions.php" class="active"><span class="ico">💳</span> Transactions</a>
    <a href="<?= $base ?>/admin/logs.php"><span class="ico">📜</span> Journal</a>
    <a href="<?= $base ?>/auth/logout.php"><span class="ico">🚪</span> Déconnexion</a>
  </aside>

  <main class="admin-main">

    <div class="welcome-head">
      <div>
        <h2>💳 Historique global des transactions</h2>
        <p class="sub">
          <strong style="color:var(--gold)"><?= number_format($total_transactions, 0, ',', ' ') ?></strong>
          transaction(s) ·
          Page <strong style="color:var(--gold)"><?= $page ?></strong> sur <?= $total_pages ?>
        </p>
      </div>
      <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
        <span class="badge gold">📅 Aujourd'hui : <?= $aujourdhui['nb'] ?> tx</span>
        <span class="badge green">💰 <?= number_format($aujourdhui['volume'], 0, ',', ' ') ?> FC</span>
      </div>
    </div>

    <!-- KPIs -->
    <section class="kpi-grid">
      <div class="stat-block green animate-in delay-1">
        <div class="ico">＋</div>
        <div class="meta">
          <strong><?= number_format($totaux['depot'], 0, ',', ' ') ?></strong>
          <span>Total dépôts</span>
        </div>
      </div>
      <div class="stat-block red animate-in delay-2">
        <div class="ico">－</div>
        <div class="meta">
          <strong><?= number_format($totaux['retrait'], 0, ',', ' ') ?></strong>
          <span>Total retraits</span>
        </div>
      </div>
      <div class="stat-block blue animate-in delay-3">
        <div class="ico">⇄</div>
        <div class="meta">
          <strong><?= number_format($totaux['virement'], 0, ',', ' ') ?></strong>
          <span>Total virements</span>
        </div>
      </div>
      <div class="stat-block gold animate-in delay-4">
        <div class="ico">💳</div>
        <div class="meta">
          <strong><?= number_format($totaux['total'], 0, ',', ' ') ?></strong>
          <span>Transactions totales</span>
        </div>
      </div>
    </section>

    <!-- Onglets rapides -->
    <div class="admin-tabs">
      <a href="?" class="admin-tab <?= $type==='' && $mode==='' ? 'active' : '' ?>">Toutes</a>
      <a href="<?= buildUrl(['type' => 'Depot', 'page' => 1]) ?>" class="admin-tab <?= $type==='Depot' ? 'active' : '' ?>">＋ Dépôts</a>
      <a href="<?= buildUrl(['type' => 'Retrait', 'page' => 1]) ?>" class="admin-tab <?= $type==='Retrait' ? 'active' : '' ?>">－ Retraits</a>
      <a href="<?= buildUrl(['type' => 'Virement', 'page' => 1]) ?>" class="admin-tab <?= $type==='Virement' ? 'active' : '' ?>">⇄ Virements</a>
      <a href="<?= buildUrl(['mode' => 'En ligne', 'page' => 1]) ?>" class="admin-tab <?= $mode==='En ligne' ? 'active' : '' ?>">🌐 En ligne</a>
      <a href="<?= buildUrl(['mode' => 'Agence', 'page' => 1]) ?>" class="admin-tab <?= $mode==='Agence' ? 'active' : '' ?>">🏢 Agence</a>
    </div>

    <!-- Filtres avancés -->
    <form method="GET" class="filter-panel">
      <div class="row">
        <input type="text" name="q"
               placeholder="🔍 Rechercher (référence, client, compte...)"
               value="<?= htmlspecialchars($search) ?>">

        <select name="type">
          <option value="">Tous types</option>
          <option value="Depot" <?= $type === 'Depot' ? 'selected' : '' ?>>＋ Dépôt</option>
          <option value="Retrait" <?= $type === 'Retrait' ? 'selected' : '' ?>>－ Retrait</option>
          <option value="Virement" <?= $type === 'Virement' ? 'selected' : '' ?>>⇄ Virement</option>
        </select>

        <select name="mode">
          <option value="">Tous modes</option>
          <option value="En ligne" <?= $mode === 'En ligne' ? 'selected' : '' ?>>🌐 En ligne</option>
          <option value="Agence" <?= $mode === 'Agence' ? 'selected' : '' ?>>🏢 Agence</option>
        </select>

        <input type="date" name="date_debut" value="<?= htmlspecialchars($date_debut) ?>"
               title="Date de début">
        <input type="date" name="date_fin" value="<?= htmlspecialchars($date_fin) ?>"
               title="Date de fin">

        <button type="submit" class="btn-filter">🔍 Filtrer</button>
      </div>
    </form>

    <?php if ($search || $type || $mode || $date_debut || $date_fin): ?>
      <div style="margin-bottom:16px">
        <a href="?" class="btn-reset">✕ Réinitialiser les filtres</a>
      </div>
    <?php endif; ?>

    <!-- Tableau avec pagination -->
    <section class="tx-table-wrap animate-in delay-2">

      <div class="tx-table-header">
        <h3>
          📋 Transactions
          <span class="counter">Page <?= $page ?> / <?= $total_pages ?></span>
        </h3>
        <span style="color:var(--muted);font-size:.8rem">
          Affichage : <?= count($transactions) ?> sur <?= number_format($total_transactions, 0, ',', ' ') ?>
        </span>
      </div>

      <div style="overflow-x:auto">
        <table class="tx-table">
          <thead>
            <tr>
              <th>Référence</th>
              <th>Client</th>
              <th>Type</th>
              <th style="text-align:right">Montant</th>
              <th style="text-align:right">Solde après</th>
              <th>Mode</th>
              <th>Effectué par</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$transactions): ?>
              <tr>
                <td colspan="8" style="text-align:center;padding:50px 20px;color:var(--muted)">
                  <div style="font-size:3rem;opacity:.3;margin-bottom:12px">📭</div>
                  <strong>Aucune transaction trouvée</strong>
                  <p style="margin-top:6px;font-size:.85rem">Essayez de modifier vos filtres de recherche.</p>
                </td>
              </tr>
            <?php else: foreach ($transactions as $t):
                $type_lower = strtolower($t['type_transaction']);
                $is_retrait = $t['type_transaction'] === 'Retrait';
                $sign = $is_retrait ? '−' : '+';
                $amount_class = $is_retrait ? 'neg' : 'pos';

                $tag_icon = match($t['type_transaction']) {
                    'Depot' => '＋',
                    'Retrait' => '－',
                    'Virement' => '⇄',
                    default => '•',
                };
            ?>
              <tr>
                <td>
                  <span class="ref"><?= htmlspecialchars($t['reference']) ?></span>
                </td>
                <td class="client-cell">
                  <strong><?= htmlspecialchars($t['nom'] . ' ' . $t['postnom']) ?></strong>
                  <small><?= htmlspecialchars($t['numero_compte']) ?></small>
                </td>
                <td>
                  <span class="tag-tx <?= $type_lower ?>">
                    <?= $tag_icon ?> <?= htmlspecialchars($t['type_transaction']) ?>
                  </span>
                </td>
                <td class="amount-cell <?= $amount_class ?>">
                  <?= $sign ?><?= number_format((float)$t['montant'], 2, ',', ' ') ?>
                  <small style="color:var(--muted);font-size:.7rem;font-weight:600">
                    <?= htmlspecialchars($t['devise_client'] ?? 'USD') ?>
                  </small>
                </td>
                <td class="solde-cell">
                  <?= number_format((float)$t['solde_apres'], 2, ',', ' ') ?>
                </td>
                <td>
                  <span class="badge-mode"><?= htmlspecialchars($t['mode']) ?></span>
                </td>
                <td>
                  <span class="badge-par"><?= htmlspecialchars($t['effectue_par']) ?></span>
                </td>
                <td style="color:var(--muted);font-size:.8rem;white-space:nowrap">
                  <?= date('d/m/Y', strtotime($t['date_transaction'])) ?><br>
                  <small><?= date('H:i:s', strtotime($t['date_transaction'])) ?></small>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>

      <!-- ============================================================
           PAGINATION
           ============================================================ -->
      <?php if ($total_pages > 1): ?>
      <div class="pagination-wrap">

        <div class="pagination-info">
          Page <strong><?= $page ?></strong> sur <strong><?= $total_pages ?></strong>
          · <strong><?= number_format($total_transactions, 0, ',', ' ') ?></strong> transactions
        </div>

        <div class="pagination">

          <!-- Bouton Précédent -->
          <?php if ($page > 1): ?>
            <a href="<?= buildUrl(['page' => $page - 1]) ?>" class="page-btn prev-next">
              ← Précédent
            </a>
          <?php else: ?>
            <button class="page-btn prev-next" disabled>← Précédent</button>
          <?php endif; ?>

          <!-- Numéros de pages -->
          <?php
            $max_visible = 5;
            $start = max(1, $page - 2);
            $end = min($total_pages, $start + $max_visible - 1);

            if ($start > 1) {
                echo '<a href="' . buildUrl(['page' => 1]) . '" class="page-btn">1</a>';
                if ($start > 2) echo '<span class="page-dots">…</span>';
            }

            for ($p = $start; $p <= $end; $p++) {
                $active = $p === $page ? ' active' : '';
                echo '<a href="' . buildUrl(['page' => $p]) . '" class="page-btn' . $active . '">' . $p . '</a>';
            }

            if ($end < $total_pages) {
                if ($end < $total_pages - 1) echo '<span class="page-dots">…</span>';
                echo '<a href="' . buildUrl(['page' => $total_pages]) . '" class="page-btn">' . $total_pages . '</a>';
            }
          ?>

          <!-- Bouton Suivant -->
          <?php if ($page < $total_pages): ?>
            <a href="<?= buildUrl(['page' => $page + 1]) ?>" class="page-btn prev-next">
              Suivant →
            </a>
          <?php else: ?>
            <button class="page-btn prev-next" disabled>Suivant →</button>
          <?php endif; ?>

        </div>

        <!-- Sélecteur d'accès direct -->
        <form method="GET" style="display:flex;gap:6px;align-items:center">
          <?php
            /* Conserver les filtres existants dans le formulaire */
            foreach (['type','mode','statut','q','date_debut','date_fin'] as $key) {
                if (!empty($_GET[$key])) {
                    echo '<input type="hidden" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars((string)$_GET[$key]) . '">';
                }
            }
          ?>
          <label style="color:var(--muted);font-size:.78rem;font-weight:700;white-space:nowrap">
            Aller à :
          </label>
          <input type="number" name="page" min="1" max="<?= $total_pages ?>"
                 value="<?= $page ?>"
                 style="width:70px;padding:8px 10px;background:var(--bg-1);
                        border:1px solid var(--border);border-radius:8px;
                        color:var(--text);font-family:inherit;font-size:.85rem;
                        text-align:center;font-weight:700">
          <button type="submit" class="page-btn" style="padding:0 14px;height:36px">→</button>
        </form>

      </div>
      <?php endif; ?>

    </section>

  </main>
</div>

<footer class="atf-footer">© <?= date('Y') ?> AFRICAN TRUST FUND</footer>

<script>
/* ============================================================
   Barre de progression scroll
   ============================================================ */
window.addEventListener('scroll', () => {
  const h = document.documentElement.scrollHeight - window.innerHeight;
  const p = h > 0 ? (window.scrollY / h) * 100 : 0;
  document.getElementById('topProgress').style.width = p + '%';
});

/* ============================================================
   Raccourcis clavier pour la pagination
   ============================================================ */
document.addEventListener('keydown', function(e) {
    /* Ne pas déclencher si on tape dans un champ */
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT' || e.target.tagName === 'TEXTAREA') return;

    const currentPage = <?= $page ?>;
    const totalPages = <?= $total_pages ?>;

    if (e.key === 'ArrowLeft' && currentPage > 1) {
        window.location.href = '<?= buildUrl(['page' => $page - 1]) ?>';
    } else if (e.key === 'ArrowRight' && currentPage < totalPages) {
        window.location.href = '<?= buildUrl(['page' => $page + 1]) ?>';
    }
});

/* ============================================================
   Compteur live + reset du filtre date
   ============================================================ */
console.log('%c💳 Transactions ATF', 'color:#F5B800;font-weight:bold;font-size:14px');
console.log('Page :', <?= $page ?>, '/', <?= $total_pages ?>);
console.log('Total :', <?= $total_transactions ?>);
console.log('Affichage :', <?= count($transactions) ?>, 'transactions');
console.log('%c⌨️ Utilisez ← → pour naviguer entre les pages', 'color:#10B981;font-weight:bold');
</script>

<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>