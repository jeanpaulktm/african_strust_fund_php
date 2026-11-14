<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
requireRole('client');

$pdo = Database::getConnection();
$client_id = (int)$_SESSION['client_id'];

/* ============================================================
   1. FILTRES
   ============================================================ */
$type     = in_array($_GET['type'] ?? '', ['Depot', 'Retrait', 'Virement'], true) ? $_GET['type'] : '';
$mode     = in_array($_GET['mode'] ?? '', ['En ligne', 'Agence'], true) ? $_GET['mode'] : '';
$date_d   = $_GET['date_debut'] ?? '';
$date_f   = $_GET['date_fin'] ?? '';
$page     = max(1, (int)($_GET['page'] ?? 1));
$par_page = 20;
$offset   = ($page - 1) * $par_page;

/* Validation dates */
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_d)) $date_d = '';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_f)) $date_f = '';

/* ============================================================
   2. CONSTRUCTION DE LA REQUÊTE
   ============================================================ */
$where = " WHERE t.id_client = ?";
$params = [$client_id];

if ($type !== '') {
    $where .= " AND t.type_transaction = ?";
    $params[] = $type;
}
if ($mode !== '') {
    $where .= " AND t.mode = ?";
    $params[] = $mode;
}
if ($date_d !== '') {
    $where .= " AND DATE(t.date_transaction) >= ?";
    $params[] = $date_d;
}
if ($date_f !== '') {
    $where .= " AND DATE(t.date_transaction) <= ?";
    $params[] = $date_f;
}

/* Compter le total */
$stmt = $pdo->prepare("SELECT COUNT(*) FROM Transactions t" . $where);
$stmt->execute($params);
$total_transactions = (int)$stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_transactions / $par_page));

/* ============================================================
   3. RÉCUPÉRATION DES TRANSACTIONS
   ============================================================ */
$sql = "SELECT t.*, 
               c.nom AS dest_nom, c.postnom AS dest_postnom, c.numero_compte AS dest_compte
        FROM Transactions t
        LEFT JOIN Client c ON c.id_client = t.id_destinataire
        $where
        ORDER BY t.date_transaction DESC
        LIMIT $par_page OFFSET $offset";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

/* ============================================================
   4. STATISTIQUES (toutes périodes, non filtrées)
   ============================================================ */
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) AS total,
        COALESCE(SUM(CASE WHEN type_transaction='Depot' THEN montant ELSE 0 END),0) AS total_depots,
        COALESCE(SUM(CASE WHEN type_transaction='Retrait' THEN montant ELSE 0 END),0) AS total_retraits,
        COALESCE(SUM(CASE WHEN type_transaction='Virement' THEN montant ELSE 0 END),0) AS total_virements,
        MAX(date_transaction) AS derniere_operation
    FROM Transactions
    WHERE id_client = ?
");
$stmt->execute([$client_id]);
$stats = $stmt->fetch();

/* ============================================================
   5. INFOS CLIENT
   ============================================================ */
$stmt = $pdo->prepare("SELECT solde, devise, numero_compte FROM Client WHERE id_client = ?");
$stmt->execute([$client_id]);
$client = $stmt->fetch();

/* ============================================================
   6. GÉNÉRATION DES TOKENS POUR REÇUS
   ============================================================ */
$tokens_recu = [];
foreach ($transactions as $t) {
    $token_key = 'tok_' . (int)$t['id_transaction'];
    $tokens_recu[$token_key] = genererToken('transaction', (int)$t['id_transaction'], [
        'action' => 'view_recu',
        'client_id' => $client_id,
    ]);
}

/* Audit */
logAction($pdo, 'client', $client_id, 'TRANSACTIONS_VIEW',
    "Consultation historique | Filtres: type=$type mode=$mode");

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mes Transactions — AFRICAN TRUST FUND</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<style>
/* ============================================================
   STYLES PAGE TRANSACTIONS CLIENT
   ============================================================ */

/* Bandeau solde */
.solde-banner {
  padding: 24px 28px;
  border-radius: 18px;
  background: linear-gradient(135deg, rgba(13,27,94,.6), rgba(30,41,59,.4)),
              radial-gradient(circle at 90% 10%, rgba(245,184,0,.15), transparent 60%);
  border: 1px solid var(--border-strong);
  margin-bottom: 24px;
  position: relative;
  overflow: hidden;
  animation: fadeInUp .5s ease-out both;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 20px;
}
.solde-banner::before {
  content: "";
  position: absolute;
  top: -50%; right: -5%;
  width: 260px; height: 260px;
  border-radius: 50%;
  background: radial-gradient(circle, rgba(245,184,0,.18), transparent 70%);
  animation: float 7s ease-in-out infinite;
}
.solde-banner .solde-item {
  position: relative;
  z-index: 1;
}
.solde-banner .lbl {
  color: var(--muted);
  font-size: .75rem;
  text-transform: uppercase;
  letter-spacing: 1.5px;
  font-weight: 700;
}
.solde-banner .val {
  font-size: 2.2rem;
  font-weight: 800;
  color: var(--gold);
  text-shadow: 0 0 25px rgba(245,184,0,.35);
  line-height: 1.1;
  margin-top: 4px;
}
.solde-banner .compte-info {
  position: relative;
  z-index: 1;
  text-align: right;
}
.solde-banner .compte-info code {
  background: rgba(245,184,0,.15);
  color: var(--gold);
  padding: 6px 14px;
  border-radius: 8px;
  font-size: .9rem;
  border: 1px solid rgba(245,184,0,.3);
}

/* Grille KPIs */
.kpi-mini-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 14px;
  margin-bottom: 24px;
}
.kpi-mini {
  padding: 18px 20px;
  border-radius: 14px;
  background: linear-gradient(145deg, var(--card), var(--card-2));
  border: 1px solid var(--border);
  position: relative;
  overflow: hidden;
  animation: fadeInUp .5s ease-out both;
}
.kpi-mini::before {
  content: "";
  position: absolute;
  top: 0; left: 0;
  width: 4px; height: 100%;
}
.kpi-mini.green::before { background: var(--green); }
.kpi-mini.red::before { background: #EF4444; }
.kpi-mini.blue::before { background: #3B82F6; }
.kpi-mini.gold::before { background: var(--gold); }
.kpi-mini .lbl {
  font-size: .72rem;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 1px;
  font-weight: 700;
}
.kpi-mini .val {
  font-size: 1.4rem;
  font-weight: 800;
  margin-top: 6px;
  color: #fff;
}
.kpi-mini.green .val { color: var(--green); }
.kpi-mini.red .val { color: #FCA5A5; }
.kpi-mini.blue .val { color: #93B4FF; }
.kpi-mini.gold .val { color: var(--gold); }

/* Filtres */
.filters-panel {
  padding: 18px 20px;
  background: linear-gradient(145deg, var(--card), var(--card-2));
  border: 1px solid var(--border);
  border-radius: 14px;
  margin-bottom: 24px;
  animation: fadeInUp .6s ease-out both;
}
.filters-panel .filters-row {
  display: flex;
  gap: 12px;
  flex-wrap: wrap;
  align-items: end;
}
.filters-panel .field {
  flex: 1;
  min-width: 140px;
}
.filters-panel label {
  display: block;
  color: var(--muted);
  font-size: .72rem;
  text-transform: uppercase;
  letter-spacing: 1px;
  font-weight: 700;
  margin-bottom: 6px;
}
.filters-panel select,
.filters-panel input[type="date"] {
  width: 100%;
  padding: 10px 14px;
  background: var(--bg-1);
  border: 1px solid var(--border);
  border-radius: 10px;
  color: var(--text);
  font-size: .88rem;
  font-family: inherit;
}
.filters-panel select:focus,
.filters-panel input:focus {
  outline: none;
  border-color: var(--gold);
  box-shadow: 0 0 0 3px rgba(245,184,0,.15);
}

/* Chips rapides */
.quick-filters {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 20px;
}
.chip {
  padding: 8px 16px;
  border-radius: 999px;
  background: var(--card);
  border: 1px solid var(--border);
  color: var(--muted);
  font-size: .82rem;
  font-weight: 600;
  cursor: pointer;
  text-decoration: none;
  transition: all .2s;
}
.chip:hover {
  border-color: var(--gold);
  color: var(--gold);
  background: var(--gold-soft);
}
.chip.active {
  background: linear-gradient(135deg, var(--gold), var(--gold-2));
  color: #0B0E14;
  border-color: var(--gold);
  box-shadow: 0 6px 20px rgba(245,184,0,.3);
}

/* Table */
.tx-table {
  width: 100%;
  border-collapse: collapse;
  font-size: .88rem;
}
.tx-table thead {
  background: rgba(11,14,20,.6);
}
.tx-table th {
  padding: 14px 12px;
  text-align: left;
  color: var(--muted);
  font-size: .72rem;
  text-transform: uppercase;
  letter-spacing: 1px;
  font-weight: 700;
  border-bottom: 1px solid var(--border);
  white-space: nowrap;
}
.tx-table td {
  padding: 14px 12px;
  border-bottom: 1px solid var(--border);
  vertical-align: middle;
}
.tx-table tbody tr {
  transition: background .2s;
}
.tx-table tbody tr:hover {
  background: rgba(245,184,0,.04);
}
.tx-table .ref {
  font-family: 'Courier New', monospace;
  font-size: .78rem;
  background: var(--bg-1);
  padding: 3px 8px;
  border-radius: 6px;
  color: var(--gold);
  font-weight: 700;
}

/* Bouton reçu */
.btn-recu {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 14px;
  border-radius: 8px;
  background: linear-gradient(135deg, var(--gold), var(--gold-2));
  color: #0B0E14;
  font-weight: 700;
  font-size: .78rem;
  text-decoration: none;
  transition: all .2s;
  white-space: nowrap;
  border: none;
  cursor: pointer;
}
.btn-recu:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 20px rgba(245,184,0,.4);
  color: #0B0E14;
}

/* Pagination */
.pagination {
  display: flex;
  justify-content: center;
  gap: 8px;
  margin-top: 24px;
  flex-wrap: wrap;
}
.pagination a,
.pagination span {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 40px;
  height: 40px;
  padding: 0 12px;
  border-radius: 10px;
  background: var(--card);
  border: 1px solid var(--border);
  color: var(--text);
  text-decoration: none;
  font-weight: 700;
  font-size: .85rem;
  transition: all .2s;
}
.pagination a:hover {
  border-color: var(--gold);
  color: var(--gold);
  background: var(--gold-soft);
}
.pagination .active {
  background: linear-gradient(135deg, var(--gold), var(--gold-2));
  color: #0B0E14;
  border-color: var(--gold);
}
.pagination .disabled {
  opacity: .4;
  cursor: not-allowed;
  pointer-events: none;
}

/* Empty state */
.empty-state {
  text-align: center;
  padding: 60px 20px;
  color: var(--muted);
}
.empty-state .ico {
  font-size: 4rem;
  margin-bottom: 16px;
  opacity: .6;
}
.empty-state h3 {
  color: var(--text);
  margin-bottom: 8px;
  font-size: 1.1rem;
}

/* Info bandeau */
.tx-info {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 18px;
  background: rgba(59,130,246,.08);
  border: 1px solid rgba(59,130,246,.25);
  border-radius: 12px;
  color: #93B4FF;
  font-size: .85rem;
  margin-bottom: 16px;
  flex-wrap: wrap;
  gap: 10px;
}
.tx-info strong { color: #fff; }

/* Responsive */
@media (max-width: 900px) {
  .tx-table thead { display: none; }
  .tx-table tbody tr {
    display: block;
    margin-bottom: 12px;
    padding: 14px;
    border: 1px solid var(--border);
    border-radius: 12px;
    background: var(--card);
  }
  .tx-table td {
    display: flex;
    justify-content: space-between;
    padding: 6px 0;
    border: none;
    text-align: right;
  }
  .tx-table td::before {
    content: attr(data-label);
    color: var(--muted);
    font-size: .72rem;
    text-transform: uppercase;
    font-weight: 700;
    text-align: left;
    flex: 1;
  }
  .solde-banner .val { font-size: 1.6rem; }
  .solde-banner .compte-info { text-align: left; width: 100%; }
}
</style>
</head>
<body class="dark-auth">

<?php require __DIR__ . '/../includes/header.php'; ?>

<main class="dash">

  <!-- ============ EN-TÊTE ============ -->
  <div class="welcome-head">
    <div>
      <h2>📋 Mes Transactions</h2>
      <p class="sub">Consultez et téléchargez les reçus de vos opérations</p>
    </div>
    <a class="btn-ghost" href="<?= $base ?>/client/dashboard.php">← Tableau de bord</a>
  </div>

  <!-- ============ BANDEAU SOLDE ============ -->
  <div class="solde-banner">
    <div class="solde-item">
      <div class="lbl">💰 Solde actuel</div>
      <div class="val">
        <?= number_format((float)$client['solde'], 2, ',', ' ') ?>
        <small style="font-size:.9rem;color:var(--muted);font-weight:600"><?= htmlspecialchars($client['devise']) ?></small>
      </div>
    </div>
    <div class="compte-info">
      <div class="lbl" style="margin-bottom:6px">Numéro de compte</div>
      <code><?= htmlspecialchars($client['numero_compte']) ?></code>
    </div>
  </div>

  <!-- ============ KPIs ============ -->
  <div class="kpi-mini-grid">
    <div class="kpi-mini gold animate-in delay-1">
      <div class="lbl">Total transactions</div>
      <div class="val"><?= number_format((int)$stats['total'], 0, ',', ' ') ?></div>
    </div>
    <div class="kpi-mini green animate-in delay-2">
      <div class="lbl">Total dépôts</div>
      <div class="val">+<?= number_format((float)$stats['total_depots'], 0, ',', ' ') ?></div>
    </div>
    <div class="kpi-mini red animate-in delay-3">
      <div class="lbl">Total retraits</div>
      <div class="val">−<?= number_format((float)$stats['total_retraits'], 0, ',', ' ') ?></div>
    </div>
    <div class="kpi-mini blue animate-in delay-4">
      <div class="lbl">Virements</div>
      <div class="val"><?= number_format((float)$stats['total_virements'], 0, ',', ' ') ?></div>
    </div>
  </div>

  <!-- ============ CHIPS RAPIDES ============ -->
  <div class="quick-filters">
    <a href="?" class="chip <?= $type==='' && $mode==='' && $date_d==='' && $date_f==='' ? 'active' : '' ?>">
      Toutes
    </a>
    <a href="?type=Depot" class="chip <?= $type==='Depot' ? 'active' : '' ?>">
      ➕ Dépôts
    </a>
    <a href="?type=Retrait" class="chip <?= $type==='Retrait' ? 'active' : '' ?>">
      ➖ Retraits
    </a>
    <a href="?type=Virement" class="chip <?= $type==='Virement' ? 'active' : '' ?>">
      ⇄ Virements
    </a>
    <a href="?mode=En%20ligne" class="chip <?= $mode==='En ligne' ? 'active' : '' ?>">
      🌐 En ligne
    </a>
    <a href="?mode=Agence" class="chip <?= $mode==='Agence' ? 'active' : '' ?>">
      🏢 Agence
    </a>
  </div>

  <!-- ============ FILTRES AVANCÉS ============ -->
  <div class="filters-panel">
    <form method="GET" class="filters-row">
      <div class="field">
        <label>Type</label>
        <select name="type">
          <option value="">Tous</option>
          <option value="Depot" <?= $type==='Depot' ? 'selected' : '' ?>>Dépôts</option>
          <option value="Retrait" <?= $type==='Retrait' ? 'selected' : '' ?>>Retraits</option>
          <option value="Virement" <?= $type==='Virement' ? 'selected' : '' ?>>Virements</option>
        </select>
      </div>

      <div class="field">
        <label>Mode</label>
        <select name="mode">
          <option value="">Tous</option>
          <option value="En ligne" <?= $mode==='En ligne' ? 'selected' : '' ?>>En ligne</option>
          <option value="Agence" <?= $mode==='Agence' ? 'selected' : '' ?>>Agence</option>
        </select>
      </div>

      <div class="field">
        <label>Du</label>
        <input type="date" name="date_debut" value="<?= htmlspecialchars($date_d) ?>">
      </div>

      <div class="field">
        <label>Au</label>
        <input type="date" name="date_fin" value="<?= htmlspecialchars($date_f) ?>">
      </div>

      <button class="btn-gold" type="submit">🔍 Filtrer</button>
      <a class="btn-ghost" href="?">↺ Réinitialiser</a>
    </form>
  </div>

  <!-- ============ INFO ============ -->
  <div class="tx-info">
    <span>
      📊 <strong><?= count($transactions) ?></strong> transaction(s) affichée(s)
      sur <strong><?= number_format($total_transactions, 0, ',', ' ') ?></strong>
    </span>
    <span>
      Dernière opération :
      <strong>
        <?= !empty($stats['derniere_operation'])
            ? date('d/m/Y à H:i', strtotime($stats['derniere_operation']))
            : 'Aucune' ?>
      </strong>
    </span>
  </div>

  <!-- ============ TABLEAU ============ -->
  <section class="card">
    <?php if (empty($transactions)): ?>
      <div class="empty-state">
        <div class="ico">📭</div>
        <h3>Aucune transaction trouvée</h3>
        <p>
          <?php if ($type || $mode || $date_d || $date_f): ?>
            Essayez de modifier vos filtres ou
            <a href="?" style="color:var(--gold)">réinitialisez</a>.
          <?php else: ?>
            Vos futures transactions apparaîtront ici.
          <?php endif; ?>
        </p>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="tx-table">
          <thead>
            <tr>
              <th>Référence</th>
              <th>Type</th>
              <th style="text-align:right">Montant</th>
              <th style="text-align:right">Solde après</th>
              <th>Mode</th>
              <th>Détails</th>
              <th>Date</th>
              <th style="text-align:center">Reçu</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($transactions as $t):
              $type_tx = $t['type_transaction'];
              $is_retrait = $type_tx === 'Retrait';
              $token_key = 'tok_' . (int)$t['id_transaction'];
              $token = $tokens_recu[$token_key] ?? '';
            ?>
              <tr>
                <td data-label="Référence">
                  <span class="ref"><?= htmlspecialchars($t['reference']) ?></span>
                </td>

                <td data-label="Type">
                  <span class="tag <?= strtolower($type_tx) ?>">
                    <?php if ($type_tx === 'Depot'): ?>
                      ➕ Dépôt
                    <?php elseif ($type_tx === 'Retrait'): ?>
                      ➖ Retrait
                    <?php else: ?>
                      ⇄ Virement
                    <?php endif; ?>
                  </span>
                </td>

                <td data-label="Montant" style="text-align:right">
                  <strong style="color:<?= $is_retrait ? '#FCA5A5' : 'var(--green)' ?>;font-size:1rem">
                    <?= $is_retrait ? '−' : '+' ?>
                    <?= number_format((float)$t['montant'], 2, ',', ' ') ?>
                  </strong>
                  <br>
                  <small class="muted"><?= htmlspecialchars($t['devise'] ?? $client['devise']) ?></small>
                </td>

                <td data-label="Solde après" style="text-align:right">
                  <strong><?= number_format((float)$t['solde_apres'], 2, ',', ' ') ?></strong>
                </td>

                <td data-label="Mode">
                  <?php if ($t['mode'] === 'En ligne'): ?>
                    <span class="badge blue" style="font-size:.7rem">🌐 En ligne</span>
                  <?php else: ?>
                    <span class="badge gold" style="font-size:.7rem">🏢 Agence</span>
                  <?php endif; ?>
                </td>

                <td data-label="Détails">
                  <small class="muted">
                    <?php if ($type_tx === 'Virement' && !empty($t['dest_compte'])): ?>
                      📤 Vers <?= htmlspecialchars($t['dest_nom'] . ' ' . $t['dest_postnom']) ?><br>
                      <code style="font-size:.7rem"><?= htmlspecialchars($t['dest_compte']) ?></code>
                    <?php elseif (!empty($t['description'])): ?>
                      <?= htmlspecialchars(mb_substr($t['description'], 0, 50)) ?>
                    <?php else: ?>
                      —
                    <?php endif; ?>
                  </small>
                </td>

                <td data-label="Date">
                  <strong><?= date('d/m/Y', strtotime($t['date_transaction'])) ?></strong>
                  <br>
                  <small class="muted"><?= date('H:i:s', strtotime($t['date_transaction'])) ?></small>
                </td>

                <td data-label="Reçu" style="text-align:center">
                  <a class="btn-recu"
                     href="<?= $base ?>/client/recu.php?token=<?= htmlspecialchars($token) ?>"
                     target="_blank"
                     title="Voir et télécharger le reçu">
                    🧾 Reçu
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- ============ PAGINATION ============ -->
      <?php if ($total_pages > 1): ?>
        <?php
          // Préparer les paramètres GET pour la pagination
          $qs_params = $_GET;
          unset($qs_params['page']);
          $qs_base = http_build_query($qs_params);
          $qs_prefix = $qs_base ? '&' : '';
        ?>
        <div class="pagination">
          <!-- Première page -->
          <?php if ($page > 1): ?>
            <a href="?<?= $qs_base ?><?= $qs_prefix ?>page=1" title="Première page">«</a>
          <?php else: ?>
            <span class="disabled">«</span>
          <?php endif; ?>

          <!-- Précédent -->
          <?php if ($page > 1): ?>
            <a href="?<?= $qs_base ?><?= $qs_prefix ?>page=<?= $page - 1 ?>">‹ Précédent</a>
          <?php else: ?>
            <span class="disabled">‹ Précédent</span>
          <?php endif; ?>

          <!-- Numéros de page -->
          <?php
            $start = max(1, $page - 2);
            $end   = min($total_pages, $page + 2);

            if ($start > 1) {
              echo '<a href="?' . $qs_base . $qs_prefix . 'page=1">1</a>';
              if ($start > 2) echo '<span class="disabled">…</span>';
            }

            for ($i = $start; $i <= $end; $i++):
          ?>
            <?php if ($i === $page): ?>
              <span class="active"><?= $i ?></span>
            <?php else: ?>
              <a href="?<?= $qs_base ?><?= $qs_prefix ?>page=<?= $i ?>"><?= $i ?></a>
            <?php endif; ?>
          <?php endfor; ?>

          <?php if ($end < $total_pages): ?>
            <?php if ($end < $total_pages - 1) echo '<span class="disabled">…</span>'; ?>
            <a href="?<?= $qs_base ?><?= $qs_prefix ?>page=<?= $total_pages ?>"><?= $total_pages ?></a>
          <?php endif; ?>

          <!-- Suivant -->
          <?php if ($page < $total_pages): ?>
            <a href="?<?= $qs_base ?><?= $qs_prefix ?>page=<?= $page + 1 ?>">Suivant ›</a>
          <?php else: ?>
            <span class="disabled">Suivant ›</span>
          <?php endif; ?>

          <!-- Dernière page -->
          <?php if ($page < $total_pages): ?>
            <a href="?<?= $qs_base ?><?= $qs_prefix ?>page=<?= $total_pages ?>" title="Dernière page">»</a>
          <?php else: ?>
            <span class="disabled">»</span>
          <?php endif; ?>
        </div>

        <p class="center muted" style="margin-top:16px;font-size:.82rem">
          Page <?= $page ?> sur <?= $total_pages ?>
          · <?= number_format($total_transactions, 0, ',', ' ') ?> transaction(s) au total
        </p>
      <?php endif; ?>
    <?php endif; ?>
  </section>

  <!-- ============ AIDE ============ -->
  <section class="card" style="background:linear-gradient(145deg, rgba(59,130,246,.08), rgba(59,130,246,.02));border-color:rgba(59,130,246,.25)">
    <h3 style="margin-bottom:12px;color:#93B4FF">💡 Aide</h3>
    <ul style="color:var(--muted);font-size:.88rem;line-height:1.8;margin-left:20px">
      <li>Cliquez sur <strong style="color:var(--gold)">🧾 Reçu</strong> pour voir et télécharger un reçu PDF officiel</li>
      <li>Utilisez les <strong>chips</strong> en haut pour filtrer rapidement par type</li>
      <li>Combinez <strong>plusieurs filtres</strong> pour des recherches précises</li>
      <li>Les reçus restent disponibles <strong>indéfiniment</strong> dans votre historique</li>
    </ul>
  </section>

</main>

<footer class="atf-footer">
  © <?= date('Y') ?> AFRICAN TRUST FUND — L'argent de l'Afrique pour construire l'Afrique
</footer>

<script>
/* ============================================================
   Auto-submit des filtres au changement
   ============================================================ */
document.querySelectorAll('.filters-panel select, .filters-panel input[type="date"]').forEach(el => {
  el.addEventListener('change', () => {
    // Soumettre automatiquement quand tous les champs sont remplis
    const allFilled = Array.from(document.querySelectorAll('.filters-panel select, .filters-panel input[type="date"]'))
      .every(f => f.value !== '');
    if (allFilled) {
      el.form.submit();
    }
  });
});

/* ============================================================
   Confirmation visuelle des clics sur Reçu
   ============================================================ */
document.querySelectorAll('.btn-recu').forEach(btn => {
  btn.addEventListener('click', function () {
    this.textContent = '⏳ Chargement...';
    setTimeout(() => {
      this.textContent = '🧾 Reçu';
    }, 1500);
  });
});

console.log('%c📋 Historique transactions ATF chargé', 'color:#F5B800;font-weight:bold;font-size:13px');
</script>

<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>