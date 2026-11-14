<?php
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
requireRole('admin');

$pdo = Database::getConnection();
$admin_id = (int)$_SESSION['admin_id'];

/* ============================================================
   FILTRES
   ============================================================ */
$debut = $_GET['debut'] ?? date('Y-m-01');
$fin   = $_GET['fin']   ?? date('Y-m-d');
$limit = min((int)($_GET['limit'] ?? 200), 1000);

// Validation dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $debut)) $debut = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fin))   $fin   = date('Y-m-d');

/* ============================================================
   COMMISSIONS DE LA PÉRIODE
   ============================================================ */
$stmt = $pdo->prepare("
    SELECT cm.*, c.nom, c.postnom, c.prenom, c.numero_compte
    FROM Commissions cm
    JOIN Client c ON c.id_client = cm.id_client
    WHERE DATE(cm.date_commission) BETWEEN ? AND ?
    ORDER BY cm.date_commission DESC
    LIMIT $limit
");
$stmt->execute([$debut, $fin]);
$commissions = $stmt->fetchAll();

/* ============================================================
   TOTAUX GLOBAUX
   ============================================================ */
$total_periode = [
    'brut'        => 0,
    'commission'  => 0,
    'net'         => 0,
    'nb'          => count($commissions),
];
foreach ($commissions as $c) {
    $total_periode['brut']       += (float)$c['montant_brut'];
    $total_periode['commission'] += (float)$c['montant_commission'];
    $total_periode['net']        += (float)$c['montant_net'];
}

/* Totaux all-time */
$stmt = $pdo->query("
    SELECT 
        COALESCE(SUM(montant_commission),0) AS total_commission,
        COALESCE(SUM(montant_brut),0) AS total_brut,
        COUNT(*) AS nb
    FROM Commissions
");
$all_time = $stmt->fetch();

/* ============================================================
   TOP 5 CLIENTS QUI GÉNÈRENT LE PLUS DE COMMISSIONS
   ============================================================ */
$top_clients = $pdo->query("
    SELECT c.nom, c.postnom, c.numero_compte,
           COUNT(*) AS nb_commissions,
           SUM(cm.montant_commission) AS total_commission
    FROM Commissions cm
    JOIN Client c ON c.id_client = cm.id_client
    GROUP BY c.id_client
    ORDER BY total_commission DESC
    LIMIT 5
")->fetchAll();

/* ============================================================
   COMMISSIONS PAR MOIS (6 derniers mois)
   ============================================================ */
$stmt = $pdo->query("
    SELECT 
        DATE_FORMAT(date_commission, '%Y-%m') AS mois,
        SUM(montant_commission) AS total
    FROM Commissions
    WHERE date_commission >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(date_commission, '%Y-%m')
    ORDER BY mois ASC
");
$commissions_mois = $stmt->fetchAll();

logAction($pdo, 'admin', $admin_id, 'COMMISSIONS_VIEW',
    "Période: $debut → $fin | Nb: {$total_periode['nb']}");

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Commissions — ATF Admin</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/admin-premium.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
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
    <a href="<?= $base ?>/admin/commissions.php" class="active"><span class="ico">💵</span> Commissions</a>
    <a href="<?= $base ?>/admin/statistiques.php"><span class="ico">📈</span> Statistiques</a>
    <a href="<?= $base ?>/admin/transactions.php"><span class="ico">💳</span> Transactions</a>
    <a href="<?= $base ?>/admin/logs.php"><span class="ico">📜</span> Journal</a>
    <a href="<?= $base ?>/auth/logout.php"><span class="ico">🚪</span> Déconnexion</a>
  </aside>

  <main class="admin-main">

    <div class="welcome-head">
      <div>
        <h2>💵 Commissions perçues (1%)</h2>
        <p class="sub">Suivi des frais prélevés sur les retraits et clôtures de placements</p>
      </div>
      <span class="badge gold"><?= $total_periode['nb'] ?> commission(s)</span>
    </div>

    <!-- KPIs -->
    <section class="kpi-grid">
      <div class="stat-block gold animate-in delay-1">
        <div class="ico">💵</div>
        <div class="meta">
          <strong><?= number_format($total_periode['commission'], 0, ',', ' ') ?></strong>
          <span>Commissions période</span>
        </div>
      </div>
      <div class="stat-block blue animate-in delay-2">
        <div class="ico">💰</div>
        <div class="meta">
          <strong><?= number_format($total_periode['brut'], 0, ',', ' ') ?></strong>
          <span>Volume brut retiré</span>
        </div>
      </div>
      <div class="stat-block green animate-in delay-3">
        <div class="ico">📊</div>
        <div class="meta">
          <strong><?= $total_periode['brut'] > 0 ? number_format(($total_periode['commission'] / $total_periode['brut']) * 100, 2) : '0.00' ?>%</strong>
          <span>Taux effectif moyen</span>
        </div>
      </div>
      <div class="stat-block red animate-in delay-4">
        <div class="ico">📈</div>
        <div class="meta">
          <strong><?= number_format((float)$all_time['total_commission'], 0, ',', ' ') ?></strong>
          <span>Total historique</span>
        </div>
      </div>
    </section>

    <!-- Filtre de dates -->
    <section class="card animate-in delay-2" style="padding:18px">
      <form method="GET" style="display:flex;gap:14px;align-items:end;flex-wrap:wrap">
        <label class="field" style="flex:1;min-width:180px">
          <span>Date début</span>
          <input type="date" name="debut" value="<?= htmlspecialchars($debut) ?>">
        </label>
        <label class="field" style="flex:1;min-width:180px">
          <span>Date fin</span>
          <input type="date" name="fin" value="<?= htmlspecialchars($fin) ?>">
        </label>
        <button class="btn-gold" type="submit">Filtrer</button>
        <a class="btn-ghost" href="?">Réinitialiser</a>
      </form>
    </section>

    <!-- Graphique -->
    <?php if ($commissions_mois): ?>
      <section class="chart-container animate-in delay-3" style="margin-bottom:30px">
        <h3 style="margin-bottom:20px">📊 Évolution des commissions (6 derniers mois)</h3>
        <div style="position:relative;height:280px">
          <canvas id="chartCommissions"></canvas>
        </div>
      </section>
    <?php endif; ?>

    <!-- Top clients -->
    <?php if ($top_clients): ?>
      <section class="card animate-in delay-4">
        <h3 style="margin-bottom:16px">🏆 Top 5 clients générateurs de commissions</h3>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Client</th><th>Compte</th>
                <th style="text-align:right">Nb commissions</th>
                <th style="text-align:right">Total commissions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($top_clients as $t): ?>
                <tr>
                  <td><strong><?= htmlspecialchars($t['nom'] . ' ' . $t['postnom']) ?></strong></td>
                  <td><code><?= htmlspecialchars($t['numero_compte']) ?></code></td>
                  <td style="text-align:right"><?= (int)$t['nb_commissions'] ?></td>
                  <td style="text-align:right;color:var(--gold);font-weight:700">
                    <?= number_format((float)$t['total_commission'], 2, ',', ' ') ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endif; ?>

    <!-- Tableau détaillé -->
    <section class="card animate-in delay-5">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px">
        <h3 style="margin:0">📋 Détail des commissions</h3>
        <input type="text" placeholder="🔍 Rechercher..." 
               data-table-filter="comTable" style="max-width:280px">
      </div>

      <div class="table-wrap">
        <table id="comTable">
          <thead>
            <tr>
              <th>Date</th><th>Client</th>
              <th style="text-align:right">Brut</th>
              <th style="text-align:right">Taux</th>
              <th style="text-align:right">Commission</th>
              <th style="text-align:right">Net versé</th>
              <th>Devise</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$commissions): ?>
              <tr><td colspan="7" class="muted center" style="padding:30px">Aucune commission sur cette période.</td></tr>
            <?php else: foreach ($commissions as $c): ?>
              <tr>
                <td><?= date('d/m/Y H:i', strtotime($c['date_commission'])) ?></td>
                <td>
                  <strong><?= htmlspecialchars($c['nom'] . ' ' . $c['postnom']) ?></strong><br>
                  <small class="muted"><?= htmlspecialchars($c['numero_compte']) ?></small>
                </td>
                <td style="text-align:right"><?= number_format((float)$c['montant_brut'], 2, ',', ' ') ?></td>
                <td style="text-align:right"><?= number_format((float)$c['taux_commission'] * 100, 2) ?>%</td>
                <td style="text-align:right;color:var(--gold);font-weight:700">
                  + <?= number_format((float)$c['montant_commission'], 2, ',', ' ') ?>
                </td>
                <td style="text-align:right;color:var(--green)">
                  <?= number_format((float)$c['montant_net'], 2, ',', ' ') ?>
                </td>
                <td><span class="badge" style="font-size:.7rem"><?= htmlspecialchars($c['devise']) ?></span></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </section>

  </main>
</div>

<footer class="atf-footer">© <?= date('Y') ?> AFRICAN TRUST FUND</footer>

<script>
/* Barre de progression */
window.addEventListener('scroll', () => {
  const h = document.documentElement.scrollHeight - window.innerHeight;
  document.getElementById('topProgress').style.width = (window.scrollY / h) * 100 + '%';
});

/* Graphique */
<?php if ($commissions_mois): ?>
const ctx = document.getElementById('chartCommissions').getContext('2d');
const grad = ctx.createLinearGradient(0, 0, 0, 300);
grad.addColorStop(0, 'rgba(245,184,0,.7)');
grad.addColorStop(1, 'rgba(245,184,0,.1)');

new Chart(ctx, {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_map(fn($r) => date('M Y', strtotime($r['mois'] . '-01')), $commissions_mois)) ?>,
    datasets: [{
      label: 'Commissions perçues',
      data: <?= json_encode(array_map(fn($r) => (float)$r['total'], $commissions_mois)) ?>,
      backgroundColor: grad,
      borderColor: '#F5B800',
      borderWidth: 2,
      borderRadius: 10,
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { labels: { color: '#E8ECF3', font: { size: 13, weight: '700' } } },
      tooltip: {
        backgroundColor: '#1A2130',
        titleColor: '#F5B800',
        bodyColor: '#E8ECF3',
        borderColor: '#F5B800',
        borderWidth: 1,
        padding: 14,
        callbacks: {
          label: (c) => ' ' + c.parsed.y.toLocaleString('fr-FR') + ' CDF'
        }
      }
    },
    scales: {
      x: { grid: { color: 'rgba(255,255,255,.05)' }, ticks: { color: '#8A93A6' } },
      y: { grid: { color: 'rgba(255,255,255,.05)' }, ticks: { color: '#8A93A6', callback: (v) => v.toLocaleString('fr-FR') } }
    }
  }
});
<?php endif; ?>
</script>
<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>