<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
requireRole('admin');

$pdo = Database::getConnection();
$admin_id = (int)$_SESSION['admin_id'];

/* ============================================================
   1. DONNÉES : ÉVOLUTION 12 MOIS
   ============================================================ */
$interets_mois = $pdo->query("
    SELECT DATE_FORMAT(date_calcul, '%Y-%m') AS mois, SUM(montant_interet) AS total
    FROM Interets_Historique
    WHERE date_calcul >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(date_calcul, '%Y-%m')
")->fetchAll();

$placements_mois = $pdo->query("
    SELECT DATE_FORMAT(created_at, '%Y-%m') AS mois,
           COUNT(*) AS nb, SUM(montant_initial) AS total
    FROM Placements
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
")->fetchAll();

$commissions_mois = $pdo->query("
    SELECT DATE_FORMAT(date_commission, '%Y-%m') AS mois, SUM(montant_commission) AS total
    FROM Commissions
    WHERE date_commission >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(date_commission, '%Y-%m')
")->fetchAll();

$transactions_mois = $pdo->query("
    SELECT DATE_FORMAT(date_transaction, '%Y-%m') AS mois,
           SUM(CASE WHEN type_transaction='Depot' THEN montant ELSE 0 END) AS depots,
           SUM(CASE WHEN type_transaction='Retrait' THEN montant ELSE 0 END) AS retraits,
           COUNT(*) AS nb_total
    FROM Transactions
    WHERE date_transaction >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(date_transaction, '%Y-%m')
")->fetchAll();

$clients_mois = $pdo->query("
    SELECT DATE_FORMAT(created_at, '%Y-%m') AS mois, COUNT(*) AS nb
    FROM Client
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
")->fetchAll();

/* ============================================================
   2. CONSTRUCTION DES 12 MOIS (avec zéros)
   ============================================================ */
$mois_labels = [];
$data_interets = [];
$data_placements = [];
$data_nb_placements = [];
$data_commissions = [];
$data_depots = [];
$data_retraits = [];
$data_nb_transactions = [];
$data_clients = [];

for ($i = 11; $i >= 0; $i--) {
    $mois = date('Y-m', strtotime("-$i months"));
    $mois_labels[] = date('M Y', strtotime("-$i months"));

    $v = 0; foreach ($interets_mois as $r) if ($r['mois'] === $mois) $v = (float)$r['total'];
    $data_interets[] = $v;

    $v = 0; $nb = 0;
    foreach ($placements_mois as $r) if ($r['mois'] === $mois) { $v = (float)$r['total']; $nb = (int)$r['nb']; }
    $data_placements[] = $v;
    $data_nb_placements[] = $nb;

    $v = 0; foreach ($commissions_mois as $r) if ($r['mois'] === $mois) $v = (float)$r['total'];
    $data_commissions[] = $v;

    $vd = 0; $vr = 0; $nb_tx = 0;
    foreach ($transactions_mois as $r) if ($r['mois'] === $mois) {
        $vd = (float)$r['depots']; $vr = (float)$r['retraits']; $nb_tx = (int)$r['nb_total'];
    }
    $data_depots[] = $vd;
    $data_retraits[] = $vr;
    $data_nb_transactions[] = $nb_tx;

    $vc = 0; foreach ($clients_mois as $r) if ($r['mois'] === $mois) $vc = (int)$r['nb'];
    $data_clients[] = $vc;
}

/* ============================================================
   3. RÉPARTITION PAR TYPE DE PRODUIT
   ============================================================ */
$repartition_produits = $pdo->query("
    SELECT pr.nom AS produit, COUNT(p.id_placement) AS nb, SUM(p.montant_initial) AS total
    FROM Placements p
    JOIN Produits_Epargne pr ON pr.id_produit = p.id_produit
    WHERE p.statut = 'actif'
    GROUP BY pr.id_produit
    ORDER BY total DESC
")->fetchAll();

/* ============================================================
   4. RÉPARTITION PAR DEVISE
   ============================================================ */
$repartition_devise = $pdo->query("
    SELECT devise, COUNT(*) AS nb, SUM(montant_initial) AS total
    FROM Placements
    WHERE statut = 'actif'
    GROUP BY devise
")->fetchAll();

/* ============================================================
   5. TOP 10 CLIENTS ÉPARGNANTS
   ============================================================ */
$top_clients = $pdo->query("
    SELECT c.nom, c.postnom, c.numero_compte,
           SUM(p.montant_actuel) AS total_place,
           SUM(p.interets_cumules) AS total_interets
    FROM Client c
    JOIN Placements p ON p.id_client = c.id_client
    WHERE p.statut = 'actif'
    GROUP BY c.id_client
    ORDER BY total_place DESC
    LIMIT 10
")->fetchAll();

/* ============================================================
   6. RÉPARTITION DES TRANSACTIONS PAR TYPE
   ============================================================ */
$types_tx = $pdo->query("
    SELECT type_transaction AS type, COUNT(*) AS nb, SUM(montant) AS total
    FROM Transactions
    GROUP BY type_transaction
")->fetchAll();

/* ============================================================
   7. RÉPARTITION PAR MODE
   ============================================================ */
$modes_tx = $pdo->query("
    SELECT mode, COUNT(*) AS nb, SUM(montant) AS total
    FROM Transactions
    GROUP BY mode
")->fetchAll();

/* ============================================================
   8. TOP 5 CAISSIERS PAR ACTIVITÉ
   ============================================================ */
$top_caissiers = $pdo->query("
    SELECT ca.id_caissier, ca.nom, ca.postnom, ca.post_prenom,
           COUNT(t.id_transaction) AS nb_operations,
           COALESCE(SUM(t.montant), 0) AS volume_total
    FROM Caissier ca
    LEFT JOIN Transactions t ON t.id_caissier = ca.id_caissier
    WHERE ca.statut = 'actif'
    GROUP BY ca.id_caissier
    ORDER BY volume_total DESC
    LIMIT 5
")->fetchAll();

/* ============================================================
   9. RÉPARTITION DES CLIENTS PAR TRANCHE DE SOLDE
   ============================================================ */
$tranches_solde = $pdo->query("
    SELECT 
        CASE 
            WHEN (solde_cdf + solde_usd * 2800) < 50000 THEN '0 - 50K'
            WHEN (solde_cdf + solde_usd * 2800) < 200000 THEN '50K - 200K'
            WHEN (solde_cdf + solde_usd * 2800) < 500000 THEN '200K - 500K'
            WHEN (solde_cdf + solde_usd * 2800) < 1000000 THEN '500K - 1M'
            WHEN (solde_cdf + solde_usd * 2800) < 5000000 THEN '1M - 5M'
            ELSE '5M+'
        END AS tranche,
        COUNT(*) AS nb,
        SUM(solde_cdf + solde_usd * 2800) AS total
    FROM Client
    WHERE statut = 'actif'
    GROUP BY tranche
    ORDER BY MIN(solde_cdf + solde_usd * 2800)
")->fetchAll();

/* ============================================================
   10. ANALYSE HEBDOMADAIRE (7 derniers jours)
   ============================================================ */
$analyse_semaine = $pdo->query("
    SELECT 
        DAYNAME(date_transaction) AS jour,
        DAYOFWEEK(date_transaction) AS jour_num,
        COUNT(*) AS nb,
        COALESCE(SUM(montant), 0) AS volume
    FROM Transactions
    WHERE date_transaction >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DAYOFWEEK(date_transaction), DAYNAME(date_transaction)
    ORDER BY DAYOFWEEK(date_transaction)
")->fetchAll();

$jours_semaine_labels = ['Dim', 'Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam'];
$data_semaine_nb = array_fill(0, 7, 0);
$data_semaine_vol = array_fill(0, 7, 0);

foreach ($analyse_semaine as $a) {
    $idx = (int)$a['jour_num'] - 1;
    if ($idx >= 0 && $idx < 7) {
        $data_semaine_nb[$idx] = (int)$a['nb'];
        $data_semaine_vol[$idx] = (float)$a['volume'];
    }
}

/* ============================================================
   11. RÉPARTITION TYPE DE TRANSACTION PAR DEVISE
   ============================================================ */
$tx_par_devise = $pdo->query("
    SELECT 
        devise,
        SUM(CASE WHEN type_transaction='Depot' THEN montant ELSE 0 END) AS depots,
        SUM(CASE WHEN type_transaction='Retrait' THEN montant ELSE 0 END) AS retraits,
        SUM(CASE WHEN type_transaction='Virement' THEN montant ELSE 0 END) AS virements
    FROM Transactions
    WHERE devise IN ('CDF', 'USD')
    GROUP BY devise
")->fetchAll();

/* ============================================================
   12. ÉVOLUTION DU SOLDE GLOBAL 12 MOIS
   ============================================================ */
$solde_evolution = $pdo->query("
    SELECT 
        DATE_FORMAT(date_transaction, '%Y-%m') AS mois,
        SUM(CASE 
            WHEN type_transaction='Depot' THEN montant 
            WHEN type_transaction='Retrait' THEN -montant 
            ELSE 0 
        END) AS variation
    FROM Transactions
    WHERE date_transaction >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
      AND devise = 'CDF'
    GROUP BY DATE_FORMAT(date_transaction, '%Y-%m')
")->fetchAll();

$data_solde_cumule = [];
$cumul = 0;
for ($i = 11; $i >= 0; $i--) {
    $mois = date('Y-m', strtotime("-$i months"));
    $variation = 0;
    foreach ($solde_evolution as $s) {
        if ($s['mois'] === $mois) $variation = (float)$s['variation'];
    }
    $cumul += $variation;
    $data_solde_cumule[] = $cumul;
}

/* ============================================================
   13. KPIs GLOBAUX
   ============================================================ */
$kpis = [
    'total_clients'      => (int)$pdo->query("SELECT COUNT(*) FROM Client")->fetchColumn(),
    'clients_actifs'     => (int)$pdo->query("SELECT COUNT(*) FROM Client WHERE statut='actif'")->fetchColumn(),
    'total_placements'   => (int)$pdo->query("SELECT COUNT(*) FROM Placements")->fetchColumn(),
    'placements_actifs'  => (int)$pdo->query("SELECT COUNT(*) FROM Placements WHERE statut='actif'")->fetchColumn(),
    'capital_place'      => (float)$pdo->query("SELECT COALESCE(SUM(montant_actuel),0) FROM Placements WHERE statut='actif'")->fetchColumn(),
    'total_interets'     => (float)$pdo->query("SELECT COALESCE(SUM(montant_interet),0) FROM Interets_Historique")->fetchColumn(),
    'total_commissions'  => (float)$pdo->query("SELECT COALESCE(SUM(montant_commission),0) FROM Commissions")->fetchColumn(),
    'total_transactions' => (int)$pdo->query("SELECT COUNT(*) FROM Transactions")->fetchColumn(),
    'volume_total'       => (float)$pdo->query("SELECT COALESCE(SUM(montant),0) FROM Transactions")->fetchColumn(),
    'total_caissiers'    => (int)$pdo->query("SELECT COUNT(*) FROM Caissier WHERE statut='actif'")->fetchColumn(),
    'total_produits'     => (int)$pdo->query("SELECT COUNT(*) FROM Produits_Epargne WHERE actif=1")->fetchColumn(),
];

$kpis['croissance_clients'] = $data_clients[11] > 0 
    ? (($data_clients[11] - ($data_clients[10] ?? 0)) / max(1, $data_clients[10] ?? 1)) * 100 
    : 0;

$kpis['croissance_placements'] = ($data_placements[10] ?? 0) > 0 
    ? (($data_placements[11] - ($data_placements[10] ?? 0)) / max(1, $data_placements[10] ?? 1)) * 100 
    : 0;

$kpis['taux_conversion'] = $kpis['total_clients'] > 0 
    ? ($kpis['placements_actifs'] / $kpis['total_clients']) * 100 
    : 0;

logAction($pdo, 'admin', $admin_id, 'STATISTIQUES_VIEW', 'Consultation statistiques avancées');

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Statistiques Avancées — ATF Admin</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/admin-premium.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
/* ============================================================
   TOOLBAR IMPRESSION
   ============================================================ */
.print-toolbar {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    align-items: center;
    padding: 16px 22px;
    background: linear-gradient(135deg, rgba(245,184,0,.08), rgba(139,92,246,.05));
    border: 1px solid rgba(245,184,0,.25);
    border-radius: 16px;
    margin-bottom: 24px;
}
.print-toolbar .title {
    font-weight: 800;
    color: var(--gold);
    font-size: .9rem;
    display: flex;
    align-items: center;
    gap: 8px;
}
.print-toolbar .spacer { flex: 1; }

.btn-print-main {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 14px 26px;
    border-radius: 12px;
    background: linear-gradient(135deg, #F5B800, #D4AF37);
    color: #0B0E14;
    text-decoration: none;
    font-weight: 800;
    font-size: .9rem;
    box-shadow: 0 10px 28px rgba(245,184,0,.4);
    transition: all .25s;
    border: none;
    cursor: pointer;
    font-family: inherit;
    position: relative;
    overflow: hidden;
}
.btn-print-main::before {
    content: "";
    position: absolute;
    top: 0; left: -100%;
    width: 100%; height: 100%;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,.3), transparent);
    transition: left .6s;
}
.btn-print-main:hover::before { left: 100%; }
.btn-print-main:hover {
    transform: translateY(-2px);
    box-shadow: 0 15px 38px rgba(245,184,0,.55);
}
.btn-print-main .icon { font-size: 1.15rem; }

.btn-print-option {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 12px 20px;
    border-radius: 12px;
    text-decoration: none;
    font-weight: 800;
    font-size: .82rem;
    transition: all .25s;
    border: 1px solid;
    background: transparent;
    cursor: pointer;
    font-family: inherit;
}
.btn-print-option.compact {
    background: rgba(59,130,246,.12);
    color: #93B4FF;
    border-color: rgba(59,130,246,.4);
}
.btn-print-option.compact:hover {
    background: rgba(59,130,246,.25);
    transform: translateY(-2px);
}
.btn-print-option.excel {
    background: rgba(16,185,129,.12);
    color: #6EE7B7;
    border-color: rgba(16,185,129,.4);
}
.btn-print-option.excel:hover {
    background: rgba(16,185,129,.25);
    transform: translateY(-2px);
}

/* Reste du CSS existant */
.stats-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:22px;margin-bottom:22px;}
.stats-grid-3{display:grid;grid-template-columns:repeat(3,1fr);gap:22px;margin-bottom:22px;}
@media (max-width:1024px){
  .stats-grid-2,.stats-grid-3{grid-template-columns:1fr}
  .print-toolbar { flex-direction: column; align-items: stretch; }
}
.chart-card{
  background:linear-gradient(145deg,var(--card),var(--card-2));
  border:1px solid var(--border);border-radius:20px;
  padding:24px;position:relative;overflow:hidden;
  animation:fadeInUp .6s ease-out both;
}
.chart-card::before{
  content:"";position:absolute;top:0;left:0;right:0;height:3px;
  background:linear-gradient(90deg,transparent,var(--gold),transparent);
  background-size:200% 100%;animation:shine 3s infinite;
}
.chart-card h3{
  font-size:1rem;font-weight:700;margin-bottom:18px;
  display:flex;align-items:center;gap:10px;
}
.chart-card h3 .ico{
  width:32px;height:32px;border-radius:10px;
  display:flex;align-items:center;justify-content:center;
  background:var(--gold-soft);color:var(--gold);font-size:1rem;
}
.chart-card .canvas-wrap{position:relative;height:280px;}
.chart-card.tall .canvas-wrap{height:340px;}
.chart-card.small .canvas-wrap{height:220px;}
.kpi-croissance{
  display:flex;align-items:center;gap:6px;
  font-size:.75rem;font-weight:700;margin-top:6px;
}
.kpi-croissance.up{color:var(--green)}
.kpi-croissance.down{color:#FCA5A5}
.section-group-title{
  font-size:1rem;color:var(--gold);text-transform:uppercase;
  letter-spacing:2px;font-weight:800;
  margin:32px 0 18px;padding-bottom:10px;
  border-bottom:2px solid rgba(245,184,0,.3);
  display:flex;align-items:center;gap:12px;
}
.section-group-title .ico{
  width:38px;height:38px;border-radius:12px;
  display:flex;align-items:center;justify-content:center;
  background:var(--gold-soft);color:var(--gold);font-size:1.1rem;
}
.top-caissier-item{
  display:flex;align-items:center;gap:14px;
  padding:14px 16px;border-radius:12px;
  background:rgba(11,14,20,.4);border:1px solid var(--border);
  margin-bottom:10px;transition:all .25s;
}
.top-caissier-item:hover{border-color:var(--gold);transform:translateX(4px);}
.top-caissier-rank{
  width:38px;height:38px;border-radius:50%;
  background:linear-gradient(135deg,var(--gold),var(--gold-2));
  color:#0B0E14;display:flex;align-items:center;justify-content:center;
  font-weight:900;font-size:1.1rem;flex-shrink:0;
}
.top-caissier-info{flex:1}
.top-caissier-info .name{font-weight:700;font-size:.9rem}
.top-caissier-info .sub{font-size:.75rem;color:var(--muted);margin-top:2px}
.top-caissier-stats{text-align:right}
.top-caissier-stats .val{font-weight:800;color:var(--gold);font-size:1rem}
.top-caissier-stats .lbl{font-size:.7rem;color:var(--muted)}
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
    <a href="<?= $base ?>/admin/actionnaires.php"><span class="ico">🏛️</span> Actionnaires</a>
    <a href="<?= $base ?>/admin/produits.php"><span class="ico">📈</span> Produits</a>
    <a href="<?= $base ?>/admin/placements.php"><span class="ico">💰</span> Placements</a>
    <a href="<?= $base ?>/admin/commissions.php"><span class="ico">💵</span> Commissions</a>
    <a href="<?= $base ?>/admin/statistiques.php" class="active"><span class="ico">📈</span> Statistiques</a>
    <a href="<?= $base ?>/admin/transactions.php"><span class="ico">💳</span> Transactions</a>
    <a href="<?= $base ?>/admin/logs.php"><span class="ico">📜</span> Journal</a>
    <a href="<?= $base ?>/auth/logout.php"><span class="ico">🚪</span> Déconnexion</a>
  </aside>

  <main class="admin-main">

    <div class="welcome-head">
      <div>
        <h2>📊 Statistiques & Analyses</h2>
        <p class="sub">12 graphiques analytiques — 12 derniers mois</p>
      </div>
    </div>

    <!-- ============================================================
         TOOLBAR D'IMPRESSION
         ============================================================ -->
    <div class="print-toolbar">
      <div class="title">
        🖨️ Exporter le rapport
      </div>

      <!-- Bouton principal : impression complète -->
      <a href="<?= $base ?>/admin/imprimer_stat.php?format=complet" 
         target="_blank"
         class="btn-print-main">
        <span class="icon">📄</span>
        <span>Rapport complet</span>
      </a>

      <!-- Version compacte -->
      <a href="<?= $base ?>/admin/imprimer_stat.php?format=compact" 
         target="_blank"
         class="btn-print-option compact">
        📋 Version compacte
      </a>

      <!-- Version résumé exécutif -->
      <a href="<?= $base ?>/admin/imprimer_stat.php?format=resume" 
         target="_blank"
         class="btn-print-option excel">
        📊 Résumé exécutif
      </a>

      <div class="spacer"></div>

      <span style="color:var(--muted);font-size:.78rem">
        📅 Rapport du <?= date('d/m/Y') ?>
      </span>
    </div>

    <!-- ============================================================
         KPIs PRINCIPAUX
         ============================================================ -->
    <section class="kpi-grid">
      <div class="stat-block gold animate-in delay-1">
        <div class="ico">👥</div>
        <div class="meta">
          <strong><?= $kpis['total_clients'] ?></strong>
          <span>Clients (<?= $kpis['clients_actifs'] ?> actifs)</span>
          <?php if ($kpis['croissance_clients'] != 0): ?>
            <div class="kpi-croissance <?= $kpis['croissance_clients'] > 0 ? 'up' : 'down' ?>">
              <?= $kpis['croissance_clients'] > 0 ? '📈' : '📉' ?>
              <?= number_format($kpis['croissance_clients'], 1) ?>% ce mois
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="stat-block green animate-in delay-2">
        <div class="ico">💰</div>
        <div class="meta">
          <strong><?= number_format($kpis['capital_place'], 0, ',', ' ') ?></strong>
          <span>Capital placé</span>
        </div>
      </div>

      <div class="stat-block blue animate-in delay-3">
        <div class="ico">📈</div>
        <div class="meta">
          <strong><?= number_format($kpis['total_interets'], 0, ',', ' ') ?></strong>
          <span>Intérêts versés</span>
        </div>
      </div>

      <div class="stat-block gold animate-in delay-4">
        <div class="ico">💵</div>
        <div class="meta">
          <strong><?= number_format($kpis['total_commissions'], 0, ',', ' ') ?></strong>
          <span>Commissions perçues</span>
        </div>
      </div>
    </section>

    <!-- ============================================================
         SECTION 1 : ÉVOLUTIONS MENSUELLES
         ============================================================ -->
    <div class="section-group-title">
      <span class="ico">📈</span> Évolutions mensuelles
    </div>

    <section class="chart-card tall animate-in delay-1" style="margin-bottom:22px">
      <h3><span class="ico">📈</span> Évolution des placements et intérêts (12 mois)</h3>
      <div class="canvas-wrap">
        <canvas id="chartEvolution"></canvas>
      </div>
    </section>

    <section class="chart-card tall animate-in delay-2" style="margin-bottom:22px">
      <h3><span class="ico">💳</span> Dépôts vs Retraits par mois</h3>
      <div class="canvas-wrap">
        <canvas id="chartDepotsRetraits"></canvas>
      </div>
    </section>

    <div class="stats-grid-2">
      <div class="chart-card animate-in delay-3">
        <h3><span class="ico">💵</span> Commissions perçues</h3>
        <div class="canvas-wrap">
          <canvas id="chartCommissions"></canvas>
        </div>
      </div>
      <div class="chart-card animate-in delay-4">
        <h3><span class="ico">👥</span> Nouveaux clients par mois</h3>
        <div class="canvas-wrap">
          <canvas id="chartClients"></canvas>
        </div>
      </div>
    </div>

    <!-- ============================================================
         SECTION 2 : ANALYSES AVANCÉES
         ============================================================ -->
    <div class="section-group-title">
      <span class="ico">🎯</span> Analyses avancées
    </div>

    <section class="chart-card tall animate-in delay-5" style="margin-bottom:22px">
      <h3><span class="ico">💰</span> Évolution du solde global cumulé (CDF)</h3>
      <div class="canvas-wrap">
        <canvas id="chartSoldeGlobal"></canvas>
      </div>
    </section>

    <div class="stats-grid-2">
      <div class="chart-card animate-in delay-6">
        <h3><span class="ico">📅</span> Activité par jour de semaine (30j)</h3>
        <div class="canvas-wrap">
          <canvas id="chartSemaine"></canvas>
        </div>
      </div>
      <div class="chart-card animate-in delay-7">
        <h3><span class="ico">👥</span> Clients par tranche de solde</h3>
        <div class="canvas-wrap">
          <canvas id="chartTranches"></canvas>
        </div>
      </div>
    </div>

    <!-- ============================================================
         SECTION 3 : RÉPARTITIONS
         ============================================================ -->
    <div class="section-group-title">
      <span class="ico">🥧</span> Répartitions
    </div>

    <div class="stats-grid-3">
      <div class="chart-card animate-in delay-8">
        <h3><span class="ico">🎯</span> Transactions par type</h3>
        <div class="canvas-wrap small">
          <canvas id="chartTypesTx"></canvas>
        </div>
      </div>
      <div class="chart-card animate-in delay-9">
        <h3><span class="ico">🌐</span> En ligne vs Agence</h3>
        <div class="canvas-wrap small">
          <canvas id="chartModes"></canvas>
        </div>
      </div>
      <div class="chart-card animate-in" style="animation-delay:1s">
        <h3><span class="ico">💱</span> Placements par devise</h3>
        <div class="canvas-wrap small">
          <canvas id="chartDevises"></canvas>
        </div>
      </div>
    </div>

    <!-- ============================================================
         SECTION 4 : COMPARAISONS
         ============================================================ -->
    <div class="section-group-title">
      <span class="ico">📊</span> Comparaisons
    </div>

    <section class="chart-card animate-in delay-1" style="margin-bottom:22px">
      <h3><span class="ico">💱</span> Comparaison transactions CDF vs USD</h3>
      <div class="canvas-wrap">
        <canvas id="chartComparaisonDevises"></canvas>
      </div>
    </section>

    <?php if ($repartition_produits): ?>
      <section class="chart-card animate-in delay-2" style="margin-bottom:22px">
        <h3><span class="ico">📦</span> Répartition des placements par produit</h3>
        <div class="canvas-wrap">
          <canvas id="chartProduits"></canvas>
        </div>
      </section>
    <?php endif; ?>

    <section class="chart-card animate-in delay-3" style="margin-bottom:22px">
      <h3><span class="ico">📊</span> Croissance cumulée du capital placé</h3>
      <div class="canvas-wrap">
        <canvas id="chartCroissance"></canvas>
      </div>
    </section>

    <!-- ============================================================
         TOP 5 PERFORMANCES
         ============================================================ -->
    <div class="section-group-title">
      <span class="ico">🏆</span> Performance
    </div>

    <div class="stats-grid-2">
      <section class="chart-card animate-in delay-4">
        <h3><span class="ico">💼</span> Top 5 caissiers</h3>
        <?php if (empty($top_caissiers)): ?>
          <p class="muted center" style="padding:20px">Aucune activité caissier.</p>
        <?php else: ?>
          <?php foreach ($top_caissiers as $i => $c): ?>
            <div class="top-caissier-item">
              <div class="top-caissier-rank">#<?= $i + 1 ?></div>
              <div class="top-caissier-info">
                <div class="name"><?= htmlspecialchars($c['nom'] . ' ' . $c['postnom']) ?></div>
                <div class="sub"><?= (int)$c['nb_operations'] ?> opération(s)</div>
              </div>
              <div class="top-caissier-stats">
                <div class="val"><?= number_format((float)$c['volume_total'], 0, ',', ' ') ?></div>
                <div class="lbl">CDF traités</div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </section>

      <section class="chart-card animate-in delay-5">
        <h3><span class="ico">🏆</span> Top 5 clients épargnants</h3>
        <?php if (empty($top_clients)): ?>
          <p class="muted center" style="padding:20px">Aucun placement actif.</p>
        <?php else: ?>
          <?php foreach (array_slice($top_clients, 0, 5) as $i => $t):
            $rendement = (float)$t['total_place'] > 0 
              ? ((float)$t['total_interets'] / (float)$t['total_place']) * 100 
              : 0;
          ?>
            <div class="top-caissier-item">
              <div class="top-caissier-rank">#<?= $i + 1 ?></div>
              <div class="top-caissier-info">
                <div class="name"><?= htmlspecialchars($t['nom'] . ' ' . $t['postnom']) ?></div>
                <div class="sub"><?= htmlspecialchars($t['numero_compte']) ?></div>
              </div>
              <div class="top-caissier-stats">
                <div class="val"><?= number_format((float)$t['total_place'], 0, ',', ' ') ?></div>
                <div class="lbl">Rendement : <?= number_format($rendement, 2) ?>%</div>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </section>
    </div>

    <!-- ============================================================
         TOP 10 CLIENTS ÉPARGNANTS
         ============================================================ -->
    <section class="card animate-in" style="margin-bottom:22px">
      <h3 style="margin-bottom:16px">🏆 Top 10 clients épargnants</h3>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>#</th><th>Client</th><th>Compte</th>
              <th style="text-align:right">Capital placé</th>
              <th style="text-align:right">Intérêts cumulés</th>
              <th style="text-align:right">Rendement</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$top_clients): ?>
              <tr><td colspan="6" class="muted center" style="padding:20px">Aucun placement actif.</td></tr>
            <?php else: foreach ($top_clients as $i => $t):
              $rendement = (float)$t['total_place'] > 0 
                ? ((float)$t['total_interets'] / (float)$t['total_place']) * 100 
                : 0;
            ?>
              <tr>
                <td><span class="badge <?= $i < 3 ? 'gold' : '' ?>">#<?= $i + 1 ?></span></td>
                <td><strong><?= htmlspecialchars($t['nom'] . ' ' . $t['postnom']) ?></strong></td>
                <td><code><?= htmlspecialchars($t['numero_compte']) ?></code></td>
                <td style="text-align:right;color:var(--gold);font-weight:700">
                  <?= number_format((float)$t['total_place'], 2, ',', ' ') ?>
                </td>
                <td style="text-align:right;color:var(--green);font-weight:700">
                  + <?= number_format((float)$t['total_interets'], 2, ',', ' ') ?>
                </td>
                <td style="text-align:right">
                  <span class="badge <?= $rendement >= 5 ? 'green' : 'blue' ?>">
                    <?= number_format($rendement, 2) ?>%
                  </span>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- ============================================================
         RÉCAPITULATIF MENSUEL
         ============================================================ -->
    <section class="card animate-in">
      <h3 style="margin-bottom:16px">📋 Récapitulatif mensuel</h3>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Mois</th>
              <th style="text-align:right">Nouveaux clients</th>
              <th style="text-align:right">Nouveaux placements</th>
              <th style="text-align:right">Capital placé</th>
              <th style="text-align:right">Dépôts</th>
              <th style="text-align:right">Retraits</th>
              <th style="text-align:right">Intérêts versés</th>
              <th style="text-align:right">Commissions</th>
            </tr>
          </thead>
          <tbody>
            <?php for ($i = 11; $i >= 0; $i--): ?>
              <tr>
                <td><strong><?= $mois_labels[$i] ?></strong></td>
                <td style="text-align:right"><?= $data_clients[$i] ?></td>
                <td style="text-align:right"><?= $data_nb_placements[$i] ?></td>
                <td style="text-align:right;color:var(--gold)">
                  <?= number_format($data_placements[$i], 0, ',', ' ') ?>
                </td>
                <td style="text-align:right;color:var(--green)">
                  +<?= number_format($data_depots[$i], 0, ',', ' ') ?>
                </td>
                <td style="text-align:right;color:#FCA5A5">
                  −<?= number_format($data_retraits[$i], 0, ',', ' ') ?>
                </td>
                <td style="text-align:right;color:var(--green)">
                  +<?= number_format($data_interets[$i], 0, ',', ' ') ?>
                </td>
                <td style="text-align:right;color:var(--gold)">
                  <?= number_format($data_commissions[$i], 0, ',', ' ') ?>
                </td>
              </tr>
            <?php endfor; ?>
          </tbody>
          <tfoot style="background:rgba(245,184,0,.08);font-weight:800">
            <tr>
              <td>TOTAL 12 MOIS</td>
              <td style="text-align:right"><?= array_sum($data_clients) ?></td>
              <td style="text-align:right"><?= array_sum($data_nb_placements) ?></td>
              <td style="text-align:right;color:var(--gold)"><?= number_format(array_sum($data_placements), 0, ',', ' ') ?></td>
              <td style="text-align:right;color:var(--green)">+<?= number_format(array_sum($data_depots), 0, ',', ' ') ?></td>
              <td style="text-align:right;color:#FCA5A5">−<?= number_format(array_sum($data_retraits), 0, ',', ' ') ?></td>
              <td style="text-align:right;color:var(--green)">+<?= number_format(array_sum($data_interets), 0, ',', ' ') ?></td>
              <td style="text-align:right;color:var(--gold)"><?= number_format(array_sum($data_commissions), 0, ',', ' ') ?></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </section>

    <!-- ============================================================
         BOUTON IMPRESSION BAS DE PAGE
         ============================================================ -->
    <div style="text-align:center;padding:36px 20px 10px">
      <a href="<?= $base ?>/admin/imprimer_stat.php?format=complet" 
         target="_blank"
         class="btn-print-main"
         style="padding:18px 40px;font-size:1rem">
        <span class="icon">🖨️</span>
        <span>Imprimer le rapport complet</span>
      </a>
    </div>

  </main>
</div>

<footer class="atf-footer">© <?= date('Y') ?> AFRICAN TRUST FUND</footer>

<script>
/* ============================================================
   CONFIGURATION GLOBALE
   ============================================================ */
Chart.defaults.color = '#8A93A6';
Chart.defaults.font.family = "'Inter','Segoe UI',sans-serif";
Chart.defaults.font.size = 12;
Chart.defaults.borderColor = 'rgba(255,255,255,.06)';

const GOLD = '#F5B800';
const GOLD_SOFT = 'rgba(245,184,0,.15)';
const GREEN = '#10B981';
const GREEN_SOFT = 'rgba(16,185,129,.15)';
const BLUE = '#3B82F6';
const BLUE_SOFT = 'rgba(59,130,246,.15)';
const RED = '#EF4444';
const RED_SOFT = 'rgba(239,68,68,.15)';
const PURPLE = '#8B5CF6';
const PURPLE_SOFT = 'rgba(139,92,246,.15)';
const CYAN = '#06B6D4';
const CYAN_SOFT = 'rgba(6,182,212,.15)';

const MOIS_LABELS = <?= json_encode($mois_labels) ?>;

function makeGradient(ctx, color1, color2 = 'transparent') {
  const g = ctx.createLinearGradient(0, 0, 0, 400);
  g.addColorStop(0, color1);
  g.addColorStop(1, color2);
  return g;
}

const axisOptions = {
  x: { grid: { color: 'rgba(255,255,255,.04)', drawBorder: false }, ticks: { color: '#8A93A6' } },
  y: {
    grid: { color: 'rgba(255,255,255,.04)', drawBorder: false },
    ticks: { color: '#8A93A6', callback: (v) => v.toLocaleString('fr-FR') }
  }
};

const tooltipDefaults = {
  backgroundColor: '#1A2130',
  titleColor: GOLD,
  bodyColor: '#E8ECF3',
  borderColor: GOLD,
  borderWidth: 1,
  padding: 14,
  titleFont: { size: 13, weight: '700' },
  bodyFont: { size: 12 },
};

/* ============================================================
   GRAPHIQUE 1
   ============================================================ */
(function() {
  const ctx = document.getElementById('chartEvolution').getContext('2d');
  new Chart(ctx, {
    type: 'line',
    data: {
      labels: MOIS_LABELS,
      datasets: [
        {
          label: 'Capital placé',
          data: <?= json_encode($data_placements) ?>,
          borderColor: GOLD,
          backgroundColor: makeGradient(ctx, 'rgba(245,184,0,.35)'),
          borderWidth: 3, tension: 0.4, fill: true,
          pointBackgroundColor: GOLD, pointBorderColor: '#0B0E14',
          pointBorderWidth: 3, pointRadius: 6, pointHoverRadius: 9,
        },
        {
          label: 'Intérêts versés',
          data: <?= json_encode($data_interets) ?>,
          borderColor: GREEN,
          backgroundColor: makeGradient(ctx, 'rgba(16,185,129,.35)'),
          borderWidth: 3, tension: 0.4, fill: true,
          pointBackgroundColor: GREEN, pointBorderColor: '#0B0E14',
          pointBorderWidth: 3, pointRadius: 6, pointHoverRadius: 9,
        }
      ]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { labels: { usePointStyle: true, pointStyle: 'circle', padding: 16, color: '#E8ECF3', font: { size: 13, weight: '700' } } },
        tooltip: { ...tooltipDefaults, callbacks: { label: (c) => ' ' + c.dataset.label + ' : ' + c.parsed.y.toLocaleString('fr-FR') + ' CDF' } }
      },
      scales: axisOptions
    }
  });
})();

/* GRAPHIQUE 2 */
(function() {
  new Chart(document.getElementById('chartDepotsRetraits'), {
    type: 'bar',
    data: {
      labels: MOIS_LABELS,
      datasets: [
        { label: 'Dépôts', data: <?= json_encode($data_depots) ?>,
          backgroundColor: GREEN_SOFT, borderColor: GREEN, borderWidth: 2, borderRadius: 8 },
        { label: 'Retraits', data: <?= json_encode($data_retraits) ?>,
          backgroundColor: RED_SOFT, borderColor: RED, borderWidth: 2, borderRadius: 8 }
      ]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { labels: { usePointStyle: true, padding: 16, color: '#E8ECF3', font: { size: 13, weight: '700' } } },
        tooltip: { ...tooltipDefaults, callbacks: { label: (c) => ' ' + c.dataset.label + ' : ' + c.parsed.y.toLocaleString('fr-FR') + ' CDF' } }
      },
      scales: axisOptions
    }
  });
})();

/* GRAPHIQUE 3 */
(function() {
  const ctx = document.getElementById('chartCommissions').getContext('2d');
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: MOIS_LABELS,
      datasets: [{
        label: 'Commissions',
        data: <?= json_encode($data_commissions) ?>,
        backgroundColor: makeGradient(ctx, 'rgba(245,184,0,.8)', 'rgba(245,184,0,.2)'),
        borderColor: GOLD, borderWidth: 2, borderRadius: 10,
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: { ...tooltipDefaults, callbacks: { label: (c) => ' ' + c.parsed.y.toLocaleString('fr-FR') + ' CDF' } }
      },
      scales: axisOptions
    }
  });
})();

/* GRAPHIQUE 4 */
(function() {
  const ctx = document.getElementById('chartClients').getContext('2d');
  new Chart(ctx, {
    type: 'line',
    data: {
      labels: MOIS_LABELS,
      datasets: [{
        label: 'Nouveaux clients',
        data: <?= json_encode($data_clients) ?>,
        borderColor: BLUE,
        backgroundColor: makeGradient(ctx, 'rgba(59,130,246,.4)'),
        borderWidth: 3, tension: 0.4, fill: true,
        pointBackgroundColor: BLUE, pointBorderColor: '#0B0E14',
        pointBorderWidth: 3, pointRadius: 6, pointHoverRadius: 9,
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: {
        legend: { labels: { usePointStyle: true, color: '#E8ECF3', font: { size: 13, weight: '700' } } },
        tooltip: { ...tooltipDefaults, callbacks: { label: (c) => ' ' + c.parsed.y + ' client(s)' } }
      },
      scales: {
        x: axisOptions.x,
        y: { ...axisOptions.y, ticks: { color: '#8A93A6', stepSize: 1, precision: 0 } }
      }
    }
  });
})();

/* GRAPHIQUE 10 */
(function() {
  const ctx = document.getElementById('chartSoldeGlobal').getContext('2d');
  new Chart(ctx, {
    type: 'line',
    data: {
      labels: MOIS_LABELS,
      datasets: [{
        label: 'Solde global cumulé',
        data: <?= json_encode($data_solde_cumule) ?>,
        borderColor: CYAN,
        backgroundColor: makeGradient(ctx, 'rgba(6,182,212,.4)', 'rgba(6,182,212,.02)'),
        borderWidth: 4, tension: 0.4, fill: true,
        pointBackgroundColor: CYAN, pointBorderColor: '#0B0E14',
        pointBorderWidth: 3, pointRadius: 6, pointHoverRadius: 9,
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: {
        legend: { labels: { usePointStyle: true, color: '#E8ECF3', font: { size: 13, weight: '700' } } },
        tooltip: { ...tooltipDefaults, callbacks: { label: (c) => ' Solde cumulé : ' + c.parsed.y.toLocaleString('fr-FR') + ' CDF' } }
      },
      scales: axisOptions
    }
  });
})();

/* GRAPHIQUE 11 */
(function() {
  const ctx = document.getElementById('chartSemaine').getContext('2d');
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: <?= json_encode($jours_semaine_labels) ?>,
      datasets: [{
        label: 'Nb de transactions',
        data: <?= json_encode($data_semaine_nb) ?>,
        backgroundColor: makeGradient(ctx, 'rgba(139,92,246,.8)', 'rgba(139,92,246,.2)'),
        borderColor: PURPLE, borderWidth: 2, borderRadius: 10,
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: { ...tooltipDefaults, callbacks: { label: (c) => ' ' + c.parsed.y + ' transaction(s)' } }
      },
      scales: {
        x: axisOptions.x,
        y: { ...axisOptions.y, ticks: { color: '#8A93A6', stepSize: 1, precision: 0 } }
      }
    }
  });
})();

/* GRAPHIQUE 12 */
(function() {
  const labels = <?= json_encode(array_map(fn($t) => $t['tranche'], $tranches_solde)) ?>;
  const data   = <?= json_encode(array_map(fn($t) => (int)$t['nb'], $tranches_solde)) ?>;

  if (data.length === 0) {
    document.getElementById('chartTranches').parentElement.innerHTML = 
      '<p class="muted center" style="line-height:280px">Aucune donnée</p>';
    return;
  }

  new Chart(document.getElementById('chartTranches'), {
    type: 'polarArea',
    data: {
      labels: labels,
      datasets: [{
        data: data,
        backgroundColor: [
          'rgba(239,68,68,.5)', 'rgba(245,158,11,.5)', 'rgba(245,184,0,.5)',
          'rgba(16,185,129,.5)', 'rgba(59,130,246,.5)', 'rgba(139,92,246,.5)'
        ],
        borderColor: '#1A2130', borderWidth: 3,
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: {
        legend: { position: 'bottom', labels: { usePointStyle: true, padding: 10, color: '#E8ECF3', font: { size: 11, weight: '700' } } },
        tooltip: { ...tooltipDefaults, callbacks: { label: (c) => ' ' + c.label + ' : ' + c.parsed.r + ' client(s)' } }
      },
      scales: {
        r: {
          grid: { color: 'rgba(255,255,255,.08)' },
          angleLines: { color: 'rgba(255,255,255,.08)' },
          ticks: { color: '#8A93A6', backdropColor: 'transparent' }
        }
      }
    }
  });
})();

/* GRAPHIQUE 13 */
(function() {
  const labels = <?= json_encode(array_map(fn($t) => $t['devise'], $tx_par_devise)) ?>;
  const depots = <?= json_encode(array_map(fn($t) => (float)$t['depots'], $tx_par_devise)) ?>;
  const retraits = <?= json_encode(array_map(fn($t) => (float)$t['retraits'], $tx_par_devise)) ?>;
  const virements = <?= json_encode(array_map(fn($t) => (float)$t['virements'], $tx_par_devise)) ?>;

  if (labels.length === 0) {
    document.getElementById('chartComparaisonDevises').parentElement.innerHTML = 
      '<p class="muted center" style="line-height:280px">Aucune donnée</p>';
    return;
  }

  new Chart(document.getElementById('chartComparaisonDevises'), {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [
        { label: 'Dépôts', data: depots, backgroundColor: GREEN_SOFT, borderColor: GREEN, borderWidth: 2, borderRadius: 8 },
        { label: 'Retraits', data: retraits, backgroundColor: RED_SOFT, borderColor: RED, borderWidth: 2, borderRadius: 8 },
        { label: 'Virements', data: virements, backgroundColor: GOLD_SOFT, borderColor: GOLD, borderWidth: 2, borderRadius: 8 }
      ]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { labels: { usePointStyle: true, padding: 16, color: '#E8ECF3', font: { size: 13, weight: '700' } } },
        tooltip: { ...tooltipDefaults, callbacks: { label: (c) => ' ' + c.dataset.label + ' : ' + c.parsed.y.toLocaleString('fr-FR') } }
      },
      scales: axisOptions
    }
  });
})();

/* GRAPHIQUE 5 */
(function() {
  const data = <?= json_encode(array_map(fn($t) => (int)$t['nb'], $types_tx)) ?>;
  const labels = <?= json_encode(array_map(fn($t) => $t['type'], $types_tx)) ?>;

  if (data.length === 0) {
    document.getElementById('chartTypesTx').parentElement.innerHTML = 
      '<p class="muted center" style="line-height:220px">Aucune donnée</p>';
    return;
  }

  new Chart(document.getElementById('chartTypesTx'), {
    type: 'pie',
    data: {
      labels: labels,
      datasets: [{ data: data, backgroundColor: [GREEN, RED, GOLD], borderColor: '#1A2130', borderWidth: 4, hoverOffset: 12 }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: {
        legend: { position: 'bottom', labels: { usePointStyle: true, padding: 14, color: '#E8ECF3', font: { size: 12, weight: '700' } } },
        tooltip: {
          ...tooltipDefaults,
          callbacks: {
            label: (c) => {
              const total = c.dataset.data.reduce((a, b) => a + b, 0);
              const pct = ((c.parsed / total) * 100).toFixed(1);
              return ' ' + c.label + ' : ' + c.parsed + ' (' + pct + '%)';
            }
          }
        }
      }
    }
  });
})();

/* GRAPHIQUE 6 */
(function() {
  const data = <?= json_encode(array_map(fn($m) => (int)$m['nb'], $modes_tx)) ?>;
  const labels = <?= json_encode(array_map(fn($m) => $m['mode'], $modes_tx)) ?>;

  if (data.length === 0) {
    document.getElementById('chartModes').parentElement.innerHTML = 
      '<p class="muted center" style="line-height:220px">Aucune donnée</p>';
    return;
  }

  new Chart(document.getElementById('chartModes'), {
    type: 'doughnut',
    data: {
      labels: labels,
      datasets: [{ data: data, backgroundColor: [BLUE, GOLD], borderColor: '#1A2130', borderWidth: 4, hoverOffset: 12 }]
    },
    options: {
      responsive: true, maintainAspectRatio: false, cutout: '65%',
      plugins: {
        legend: { position: 'bottom', labels: { usePointStyle: true, padding: 14, color: '#E8ECF3', font: { size: 12, weight: '700' } } },
        tooltip: {
          ...tooltipDefaults,
          callbacks: {
            label: (c) => {
              const total = c.dataset.data.reduce((a, b) => a + b, 0);
              const pct = ((c.parsed / total) * 100).toFixed(1);
              return ' ' + c.label + ' : ' + c.parsed + ' (' + pct + '%)';
            }
          }
        }
      }
    }
  });
})();

/* GRAPHIQUE 7 */
(function() {
  const data = <?= json_encode(array_map(fn($d) => (float)$d['total'], $repartition_devise)) ?>;
  const labels = <?= json_encode(array_map(fn($d) => $d['devise'], $repartition_devise)) ?>;

  if (data.length === 0) {
    document.getElementById('chartDevises').parentElement.innerHTML = 
      '<p class="muted center" style="line-height:220px">Aucun placement actif</p>';
    return;
  }

  new Chart(document.getElementById('chartDevises'), {
    type: 'doughnut',
    data: {
      labels: labels,
      datasets: [{ data: data, backgroundColor: [GOLD, CYAN], borderColor: '#1A2130', borderWidth: 4, hoverOffset: 12 }]
    },
    options: {
      responsive: true, maintainAspectRatio: false, cutout: '65%',
      plugins: {
        legend: { position: 'bottom', labels: { usePointStyle: true, padding: 14, color: '#E8ECF3', font: { size: 12, weight: '700' } } },
        tooltip: {
          ...tooltipDefaults,
          callbacks: {
            label: (c) => {
              const total = c.dataset.data.reduce((a, b) => a + b, 0);
              const pct = ((c.parsed / total) * 100).toFixed(1);
              return ' ' + c.label + ' : ' + c.parsed.toLocaleString('fr-FR') + ' (' + pct + '%)';
            }
          }
        }
      }
    }
  });
})();

/* GRAPHIQUE 8 */
<?php if ($repartition_produits): ?>
(function() {
  new Chart(document.getElementById('chartProduits'), {
    type: 'bar',
    data: {
      labels: <?= json_encode(array_map(fn($p) => $p['produit'], $repartition_produits)) ?>,
      datasets: [{
        label: 'Capital placé',
        data: <?= json_encode(array_map(fn($p) => (float)$p['total'], $repartition_produits)) ?>,
        backgroundColor: [GOLD, GREEN, BLUE, PURPLE, CYAN, RED],
        borderColor: '#1A2130', borderWidth: 2, borderRadius: 8,
      }]
    },
    options: {
      indexAxis: 'y', responsive: true, maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: { ...tooltipDefaults, callbacks: { label: (c) => ' ' + c.parsed.x.toLocaleString('fr-FR') + ' CDF' } }
      },
      scales: {
        x: { grid: { color: 'rgba(255,255,255,.04)' }, ticks: { color: '#8A93A6', callback: (v) => v.toLocaleString('fr-FR') } },
        y: { grid: { display: false }, ticks: { color: '#E8ECF3', font: { size: 12 } } }
      }
    }
  });
})();
<?php endif; ?>

/* GRAPHIQUE 9 */
(function() {
  const dataPlacements = <?= json_encode($data_placements) ?>;
  let cumul = 0;
  const cumulData = dataPlacements.map(v => { cumul += v; return cumul; });

  const ctx = document.getElementById('chartCroissance').getContext('2d');
  new Chart(ctx, {
    type: 'line',
    data: {
      labels: MOIS_LABELS,
      datasets: [{
        label: 'Capital cumulé placé',
        data: cumulData,
        borderColor: GOLD,
        backgroundColor: makeGradient(ctx, 'rgba(245,184,0,.5)', 'rgba(245,184,0,.05)'),
        borderWidth: 4, tension: 0.4, fill: true,
        pointBackgroundColor: GOLD, pointBorderColor: '#0B0E14',
        pointBorderWidth: 3, pointRadius: 7, pointHoverRadius: 10,
      }]
    },
    options: {
      responsive: true, maintainAspectRatio: false,
      plugins: {
        legend: { labels: { usePointStyle: true, color: '#E8ECF3', font: { size: 13, weight: '700' } } },
        tooltip: { ...tooltipDefaults, callbacks: { label: (c) => ' ' + c.parsed.y.toLocaleString('fr-FR') + ' CDF' } }
      },
      scales: axisOptions
    }
  });
})();

/* Barre progression scroll */
window.addEventListener('scroll', () => {
  const h = document.documentElement.scrollHeight - window.innerHeight;
  document.getElementById('topProgress').style.width = (window.scrollY / h) * 100 + '%';
});
</script>

<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>