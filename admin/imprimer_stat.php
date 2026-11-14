<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
requireRole('admin');

$pdo = Database::getConnection();
$admin_id = (int)$_SESSION['admin_id'];

$format = $_GET['format'] ?? 'complet'; // complet | compact | resume

/* ============================================================
   RÉCUPÉRATION DES DONNÉES
   ============================================================ */

/* KPIs globaux */
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
];

/* 12 derniers mois */
$interets_mois = $pdo->query("
    SELECT DATE_FORMAT(date_calcul, '%Y-%m') AS mois, SUM(montant_interet) AS total
    FROM Interets_Historique
    WHERE date_calcul >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(date_calcul, '%Y-%m')
")->fetchAll();

$placements_mois = $pdo->query("
    SELECT DATE_FORMAT(created_at, '%Y-%m') AS mois, COUNT(*) AS nb, SUM(montant_initial) AS total
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

/* Construction 12 mois */
$mois_data = [];
for ($i = 11; $i >= 0; $i--) {
    $mois = date('Y-m', strtotime("-$i months"));
    $mois_data[] = [
        'mois' => $mois,
        'label' => date('M Y', strtotime("-$i months")),
        'label_court' => date('M', strtotime("-$i months")),
        'clients' => 0,
        'placements' => 0,
        'nb_placements' => 0,
        'depots' => 0,
        'retraits' => 0,
        'nb_transactions' => 0,
        'interets' => 0,
        'commissions' => 0,
    ];
}

foreach ($interets_mois as $r) {
    foreach ($mois_data as &$m) {
        if ($m['mois'] === $r['mois']) $m['interets'] = (float)$r['total'];
    }
    unset($m);
}
foreach ($placements_mois as $r) {
    foreach ($mois_data as &$m) {
        if ($m['mois'] === $r['mois']) {
            $m['placements'] = (float)$r['total'];
            $m['nb_placements'] = (int)$r['nb'];
        }
    }
    unset($m);
}
foreach ($commissions_mois as $r) {
    foreach ($mois_data as &$m) {
        if ($m['mois'] === $r['mois']) $m['commissions'] = (float)$r['total'];
    }
    unset($m);
}
foreach ($transactions_mois as $r) {
    foreach ($mois_data as &$m) {
        if ($m['mois'] === $r['mois']) {
            $m['depots'] = (float)$r['depots'];
            $m['retraits'] = (float)$r['retraits'];
            $m['nb_transactions'] = (int)$r['nb_total'];
        }
    }
    unset($m);
}
foreach ($clients_mois as $r) {
    foreach ($mois_data as &$m) {
        if ($m['mois'] === $r['mois']) $m['clients'] = (int)$r['nb'];
    }
    unset($m);
}

/* Préparation des tableaux pour les graphiques */
$mois_labels_court = array_column($mois_data, 'label_court');
$data_placements_chart = array_column($mois_data, 'placements');
$data_interets_chart = array_column($mois_data, 'interets');
$data_depots_chart = array_column($mois_data, 'depots');
$data_retraits_chart = array_column($mois_data, 'retraits');
$data_clients_chart = array_column($mois_data, 'clients');
$data_commissions_chart = array_column($mois_data, 'commissions');

/* Top 10 clients */
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

/* Top 5 caissiers */
$top_caissiers = $pdo->query("
    SELECT ca.id_caissier, ca.nom, ca.postnom,
           COUNT(t.id_transaction) AS nb_operations,
           COALESCE(SUM(t.montant), 0) AS volume_total
    FROM Caissier ca
    LEFT JOIN Transactions t ON t.id_caissier = ca.id_caissier
    WHERE ca.statut = 'actif'
    GROUP BY ca.id_caissier
    ORDER BY volume_total DESC
    LIMIT 5
")->fetchAll();

/* Répartitions */
$repartition_produits = $pdo->query("
    SELECT pr.nom AS produit, COUNT(p.id_placement) AS nb, SUM(p.montant_initial) AS total
    FROM Placements p
    JOIN Produits_Epargne pr ON pr.id_produit = p.id_produit
    WHERE p.statut = 'actif'
    GROUP BY pr.id_produit
    ORDER BY total DESC
")->fetchAll();

$repartition_devise = $pdo->query("
    SELECT devise, COUNT(*) AS nb, SUM(montant_initial) AS total
    FROM Placements
    WHERE statut = 'actif'
    GROUP BY devise
")->fetchAll();

$types_tx = $pdo->query("
    SELECT type_transaction AS type, COUNT(*) AS nb, SUM(montant) AS total
    FROM Transactions
    GROUP BY type_transaction
")->fetchAll();

$modes_tx = $pdo->query("
    SELECT mode, COUNT(*) AS nb, SUM(montant) AS total
    FROM Transactions
    GROUP BY mode
")->fetchAll();

/* Tranches de solde */
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

/* Totaux 12 mois */
$total_12m = [
    'clients' => array_sum(array_column($mois_data, 'clients')),
    'placements' => array_sum(array_column($mois_data, 'placements')),
    'nb_placements' => array_sum(array_column($mois_data, 'nb_placements')),
    'depots' => array_sum(array_column($mois_data, 'depots')),
    'retraits' => array_sum(array_column($mois_data, 'retraits')),
    'interets' => array_sum(array_column($mois_data, 'interets')),
    'commissions' => array_sum(array_column($mois_data, 'commissions')),
];

logAction($pdo, 'admin', $admin_id, 'IMPRESSION_STAT', "Format: $format");

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Rapport Statistiques ATF — <?= date('d/m/Y') ?></title>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
/* ============================================================
   RESET + BASE
   ============================================================ */
* { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    background: #F1F5F9;
    color: #1E293B;
    padding: 30px 20px;
    line-height: 1.5;
    font-size: 12px;
}

/* ============================================================
   BOUTONS FLOTTANTS
   ============================================================ */
.btn-float {
    position: fixed;
    top: 20px;
    z-index: 9999;
    padding: 12px 22px;
    border-radius: 999px;
    font-weight: 800;
    font-size: .88rem;
    cursor: pointer;
    border: none;
    font-family: inherit;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all .25s;
    box-shadow: 0 8px 24px rgba(0,0,0,.15);
}
.btn-float.print {
    right: 20px;
    background: linear-gradient(135deg, #F5B800, #D4AF37);
    color: #0B0E14;
}
.btn-float.print:hover { transform: translateY(-3px); box-shadow: 0 12px 32px rgba(245,184,0,.5); }
.btn-float.back {
    left: 20px;
    background: #0D1B5E;
    color: #F5B800;
    border: 2px solid #F5B800;
}
.btn-float.back:hover { background: #F5B800; color: #0D1B5E; transform: translateY(-3px); }

/* ============================================================
   CONTAINER
   ============================================================ */
.page {
    max-width: 1100px;
    margin: 0 auto;
    background: #FFFFFF;
    border-radius: 12px;
    padding: 40px 45px;
    box-shadow: 0 20px 60px rgba(0,0,0,.08);
}

/* ============================================================
   EN-TÊTE
   ============================================================ */
.report-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 24px;
    padding-bottom: 22px;
    border-bottom: 3px solid #0D1B5E;
    margin-bottom: 30px;
}
.report-header .logo-block { display: flex; align-items: center; gap: 16px; }
.report-header .logo-box {
    width: 72px; height: 72px;
    border-radius: 16px;
    background: linear-gradient(135deg, #F5B800, #D4AF37);
    display: flex; align-items: center; justify-content: center;
    color: #0D1B5E; font-weight: 900; font-size: 1.5rem;
    box-shadow: 0 8px 20px rgba(245,184,0,.3);
    letter-spacing: -.5px;
}
.report-header h1 {
    color: #0D1B5E; font-size: 1.45rem; font-weight: 900;
    letter-spacing: -.3px; margin-bottom: 4px;
}
.report-header .slogan { color: #64748B; font-size: .78rem; font-style: italic; }
.report-header .meta-right {
    text-align: right; font-size: .78rem;
    color: #475569; line-height: 1.6;
}
.report-header .meta-right .titre {
    color: #0D1B5E; font-size: 1rem; font-weight: 900;
    text-transform: uppercase; letter-spacing: 1px;
    margin-bottom: 6px; display: block;
}
.report-header .meta-right .ref {
    font-family: 'Courier New', monospace;
    color: #F5B800; font-weight: 800; font-size: .72rem;
    background: #FFFBEB; padding: 3px 8px; border-radius: 6px;
    display: inline-block; margin-top: 4px; border: 1px solid #F5B800;
}

/* ============================================================
   BANDEAU
   ============================================================ */
.synthese-banner {
    padding: 16px 20px;
    background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 100%);
    border-left: 5px solid #F5B800;
    border-radius: 10px;
    margin-bottom: 26px;
    display: flex; align-items: center; gap: 14px;
    font-size: .82rem; color: #78350F;
}
.synthese-banner .ico { font-size: 1.6rem; flex-shrink: 0; }
.synthese-banner strong { color: #92400E; font-weight: 800; }

/* ============================================================
   SECTIONS
   ============================================================ */
.section { margin-bottom: 30px; page-break-inside: avoid; }
.section-title {
    font-size: 1rem; color: #0D1B5E; font-weight: 900;
    text-transform: uppercase; letter-spacing: 1.5px;
    padding-bottom: 10px; margin-bottom: 18px;
    border-bottom: 2px solid #F5B800;
    display: flex; align-items: center; gap: 10px;
}
.section-title .num {
    width: 30px; height: 30px;
    border-radius: 8px;
    background: #0D1B5E; color: #F5B800;
    display: flex; align-items: center; justify-content: center;
    font-size: .82rem; font-weight: 900;
}

/* ============================================================
   KPI GRID
   ============================================================ */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 22px;
}
.kpi-card {
    padding: 16px 14px;
    border-radius: 10px;
    background: #F8FAFC;
    border: 1px solid #E2E8F0;
    text-align: center;
    position: relative;
    overflow: hidden;
}
.kpi-card::before {
    content: ""; position: absolute;
    top: 0; left: 0; right: 0; height: 3px;
    background: #0D1B5E;
}
.kpi-card.gold::before { background: #F5B800; }
.kpi-card.green::before { background: #10B981; }
.kpi-card.blue::before { background: #3B82F6; }
.kpi-card.red::before { background: #EF4444; }
.kpi-card.purple::before { background: #8B5CF6; }

.kpi-card .ico { font-size: 1.4rem; margin-bottom: 6px; }
.kpi-card .lbl {
    font-size: .68rem; color: #64748B;
    text-transform: uppercase; letter-spacing: 1px;
    font-weight: 800; margin-bottom: 6px;
}
.kpi-card .val {
    font-size: 1.35rem; font-weight: 900;
    color: #0D1B5E; font-variant-numeric: tabular-nums;
    line-height: 1.2;
}
.kpi-card .val-sub {
    font-size: .7rem; color: #64748B;
    margin-top: 3px; font-weight: 600;
}
.kpi-card.gold .val { color: #92400E; }
.kpi-card.green .val { color: #065F46; }
.kpi-card.blue .val { color: #1E40AF; }
.kpi-card.red .val { color: #991B1B; }
.kpi-card.purple .val { color: #5B21B6; }

/* ============================================================
   GRAPHIQUES
   ============================================================ */
.charts-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 22px;
}
.chart-block {
    padding: 18px;
    background: #F8FAFC;
    border: 1px solid #E2E8F0;
    border-radius: 12px;
    page-break-inside: avoid;
    position: relative;
}
.chart-block.full { grid-column: 1 / -1; }
.chart-block h4 {
    font-size: .82rem;
    color: #0D1B5E;
    font-weight: 900;
    text-transform: uppercase;
    letter-spacing: .8px;
    margin-bottom: 14px;
    padding-bottom: 8px;
    border-bottom: 2px solid #F5B800;
    display: flex;
    align-items: center;
    gap: 8px;
}
.chart-block h4 .ico {
    width: 24px; height: 24px;
    border-radius: 6px;
    background: #0D1B5E;
    color: #F5B800;
    display: flex; align-items: center; justify-content: center;
    font-size: .72rem;
    font-weight: 900;
}
.chart-wrap {
    position: relative;
    height: 240px;
}
.chart-wrap.tall { height: 280px; }
.chart-wrap.small { height: 200px; }

@media (max-width: 900px) {
    .charts-grid { grid-template-columns: 1fr; }
    .kpi-grid { grid-template-columns: 1fr 1fr; }
}

/* ============================================================
   TABLEAUX
   ============================================================ */
table.data-table {
    width: 100%; border-collapse: collapse;
    font-size: .78rem; background: #FFFFFF;
    border: 1px solid #E2E8F0; border-radius: 8px;
    overflow: hidden;
}
table.data-table thead { background: #0D1B5E; color: #FFFFFF; }
table.data-table th {
    padding: 10px 12px; text-align: left;
    font-size: .68rem; text-transform: uppercase;
    letter-spacing: .5px; font-weight: 800;
    white-space: nowrap;
}
table.data-table td {
    padding: 9px 12px;
    border-bottom: 1px solid #F1F5F9;
    color: #334155; vertical-align: middle;
}
table.data-table tbody tr:nth-child(even) { background: #F8FAFC; }
table.data-table tbody tr:hover { background: #FEF3C7; }
table.data-table .right { text-align: right; }
table.data-table .center { text-align: center; }
table.data-table .gold { color: #92400E; font-weight: 700; }
table.data-table .green { color: #065F46; font-weight: 700; }
table.data-table .red { color: #991B1B; font-weight: 700; }
table.data-table code {
    font-family: 'Courier New', monospace;
    font-size: .72rem; color: #0D1B5E;
    background: #F1F5F9; padding: 2px 6px; border-radius: 4px;
}
table.data-table tfoot {
    background: #FFFBEB;
    border-top: 2px solid #F5B800;
}
table.data-table tfoot td {
    padding: 12px; color: #92400E;
    font-weight: 900; border: none; font-size: .82rem;
}

/* ============================================================
   GRILLE 2 COL
   ============================================================ */
.grid-2 {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 22px;
    margin-bottom: 22px;
}

/* ============================================================
   TOP LIST
   ============================================================ */
.top-list { display: flex; flex-direction: column; gap: 8px; }
.top-item {
    display: flex; align-items: center; gap: 12px;
    padding: 10px 14px;
    background: #F8FAFC; border-radius: 8px;
    border-left: 3px solid #F5B800; font-size: .82rem;
}
.top-item.gold {
    background: linear-gradient(90deg, #FFFBEB, #F8FAFC);
    border-left-color: #F5B800;
}
.top-item .rank {
    width: 28px; height: 28px; border-radius: 50%;
    background: #0D1B5E; color: #F5B800;
    display: flex; align-items: center; justify-content: center;
    font-weight: 900; font-size: .75rem; flex-shrink: 0;
}
.top-item .info { flex: 1; min-width: 0; }
.top-item .info .name {
    font-weight: 800; color: #0D1B5E; margin-bottom: 2px;
}
.top-item .info .sub { font-size: .7rem; color: #64748B; }
.top-item .value {
    text-align: right; font-weight: 900;
    color: #92400E; font-size: .88rem;
    font-variant-numeric: tabular-nums;
}
.top-item .value .sub {
    display: block; font-size: .68rem;
    color: #64748B; font-weight: 600; margin-top: 2px;
}

/* ============================================================
   FOOTER
   ============================================================ */
.report-footer {
    margin-top: 40px; padding-top: 22px;
    border-top: 2px solid #0D1B5E;
    text-align: center; color: #64748B;
    font-size: .72rem; line-height: 1.8;
}
.report-footer strong { color: #0D1B5E; font-weight: 900; }
.report-footer .signature {
    margin-top: 22px;
    display: grid; grid-template-columns: 1fr 1fr;
    gap: 40px; text-align: center; font-size: .75rem;
}
.report-footer .signature .block {
    padding-top: 50px;
    border-top: 1.5px dashed #94A3B8;
    color: #475569; font-weight: 700;
}
.report-footer .signature .block small {
    display: block; color: #94A3B8;
    font-weight: 400; margin-top: 4px; font-size: .68rem;
}

/* ============================================================
   IMPRESSION — CANVAS À L'ÉCRAN + IMPRESSION FIDÈLE
   ============================================================ */
@media print {
    @page { margin: 1.2cm; size: A4; }

    body {
        background: #FFFFFF;
        padding: 0;
        font-size: 10px;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    .btn-float { display: none !important; }

    .page {
        box-shadow: none;
        border-radius: 0;
        padding: 0;
        max-width: 100%;
    }

    .section { page-break-inside: avoid; }

    /* ⭐ IMPRESSION DES GRAPHIQUES */
    .chart-block {
        page-break-inside: avoid;
        break-inside: avoid;
        background: #F8FAFC !important;
        border: 1px solid #E2E8F0 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    .chart-wrap {
        page-break-inside: avoid;
        break-inside: avoid;
    }

    .chart-wrap canvas {
        max-width: 100% !important;
        height: auto !important;
        page-break-inside: avoid;
        break-inside: avoid;
    }

    .kpi-card,
    .top-item,
    table.data-table thead,
    .synthese-banner {
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    table.data-table { page-break-inside: auto; }
    table.data-table tr { page-break-inside: avoid; }
    thead { display: table-header-group; }

    .report-footer { page-break-inside: avoid; }
}

/* Compact */
body.format-compact .page { padding: 30px 35px; }
body.format-compact .kpi-card { padding: 12px 10px; }
body.format-compact .kpi-card .val { font-size: 1.15rem; }
body.format-compact .section { margin-bottom: 20px; }
body.format-compact .chart-wrap { height: 200px; }
</style>
</head>
<body class="format-<?= htmlspecialchars($format) ?>">

<!-- Boutons flottants -->
<button class="btn-float print" onclick="window.print()">
    🖨️ Imprimer / PDF
</button>
<a class="btn-float back" href="<?= $base ?>/admin/statistiques.php">
    ← Retour
</a>

<div class="page">

    <!-- ============================================================
         EN-TÊTE
         ============================================================ -->
    <div class="report-header">
        <div class="logo-block">
            <div class="logo-box">ATF</div>
            <div>
                <h1>AFRICAN TRUST FUND</h1>
                <div class="slogan">L'argent de l'Afrique pour construire l'Afrique</div>
            </div>
        </div>
        <div class="meta-right">
            <span class="titre">Rapport Statistiques</span>
            Généré le : <strong><?= date('d/m/Y à H:i') ?></strong><br>
            Période analysée : <strong>12 derniers mois</strong><br>
            <span class="ref">RÉF. ATF-STAT-<?= date('Ymd-His') ?></span>
        </div>
    </div>

    <!-- ============================================================
         BANDEAU
         ============================================================ -->
    <div class="synthese-banner">
        <span class="ico">📊</span>
        <div>
            <strong>Rapport analytique complet</strong> — 
            Ce document présente les indicateurs de performance et tendances de la plateforme 
            AFRICAN TRUST FUND sur les 12 derniers mois. 
            Toutes les valeurs monétaires sont exprimées en <strong>Francs Congolais (CDF)</strong>.
        </div>
    </div>

    <!-- ============================================================
         SECTION 1 : SYNTHÈSE GLOBALE
         ============================================================ -->
    <div class="section">
        <h2 class="section-title">
            <span class="num">1</span>
            Synthèse globale
        </h2>

        <div class="kpi-grid">
            <div class="kpi-card blue">
                <div class="ico">👥</div>
                <div class="lbl">Clients</div>
                <div class="val"><?= number_format($kpis['total_clients'], 0, ',', ' ') ?></div>
                <div class="val-sub"><?= $kpis['clients_actifs'] ?> actifs</div>
            </div>

            <div class="kpi-card gold">
                <div class="ico">💰</div>
                <div class="lbl">Capital placé</div>
                <div class="val"><?= number_format($kpis['capital_place'], 0, ',', ' ') ?></div>
                <div class="val-sub">CDF</div>
            </div>

            <div class="kpi-card green">
                <div class="ico">📈</div>
                <div class="lbl">Intérêts versés</div>
                <div class="val"><?= number_format($kpis['total_interets'], 0, ',', ' ') ?></div>
                <div class="val-sub">CDF</div>
            </div>

            <div class="kpi-card purple">
                <div class="ico">💵</div>
                <div class="lbl">Commissions</div>
                <div class="val"><?= number_format($kpis['total_commissions'], 0, ',', ' ') ?></div>
                <div class="val-sub">CDF</div>
            </div>
        </div>

        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="ico">📋</div>
                <div class="lbl">Placements</div>
                <div class="val"><?= number_format($kpis['total_placements'], 0, ',', ' ') ?></div>
                <div class="val-sub"><?= $kpis['placements_actifs'] ?> actifs</div>
            </div>

            <div class="kpi-card">
                <div class="ico">💳</div>
                <div class="lbl">Transactions</div>
                <div class="val"><?= number_format($kpis['total_transactions'], 0, ',', ' ') ?></div>
                <div class="val-sub">Volume total</div>
            </div>

            <div class="kpi-card red">
                <div class="ico">💼</div>
                <div class="lbl">Caissiers actifs</div>
                <div class="val"><?= $kpis['total_caissiers'] ?></div>
                <div class="val-sub">Opérationnels</div>
            </div>

            <div class="kpi-card blue">
                <div class="ico">💱</div>
                <div class="lbl">Volume total</div>
                <div class="val"><?= number_format($kpis['volume_total'], 0, ',', ' ') ?></div>
                <div class="val-sub">CDF traités</div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         SECTION 2 : GRAPHIQUES ÉVOLUTION MENSUELLE
         ============================================================ -->
    <div class="section">
        <h2 class="section-title">
            <span class="num">2</span>
            Évolutions mensuelles (graphiques)
        </h2>

        <!-- GRAPHIQUE 1 : ÉVOLUTION PLACEMENTS + INTÉRÊTS (PLEINE LARGEUR) -->
        <div class="chart-block full" style="margin-bottom:20px">
            <h4><span class="ico">1</span> Évolution du capital placé et intérêts versés</h4>
            <div class="chart-wrap tall">
                <canvas id="chartEvolution"></canvas>
            </div>
        </div>

        <!-- GRILLE 2 COLONNES -->
        <div class="charts-grid">
            <!-- GRAPHIQUE 2 : DÉPÔTS vs RETRAITS -->
            <div class="chart-block">
                <h4><span class="ico">2</span> Dépôts vs Retraits</h4>
                <div class="chart-wrap">
                    <canvas id="chartDepotsRetraits"></canvas>
                </div>
            </div>

            <!-- GRAPHIQUE 3 : NOUVEAUX CLIENTS -->
            <div class="chart-block">
                <h4><span class="ico">3</span> Nouveaux clients</h4>
                <div class="chart-wrap">
                    <canvas id="chartClients"></canvas>
                </div>
            </div>

            <!-- GRAPHIQUE 4 : COMMISSIONS -->
            <div class="chart-block">
                <h4><span class="ico">4</span> Commissions perçues</h4>
                <div class="chart-wrap">
                    <canvas id="chartCommissions"></canvas>
                </div>
            </div>

            <!-- GRAPHIQUE 5 : CAPITAL CUMULÉ -->
            <div class="chart-block">
                <h4><span class="ico">5</span> Croissance cumulée du capital</h4>
                <div class="chart-wrap">
                    <canvas id="chartCumul"></canvas>
                </div>
            </div>
        </div>

        <!-- GRAPHIQUE 6 : PLACEMENTS PAR PRODUIT -->
        <?php if (!empty($repartition_produits)): ?>
        <div class="chart-block full" style="margin-bottom:20px">
            <h4><span class="ico">6</span> Répartition du capital par produit</h4>
            <div class="chart-wrap">
                <canvas id="chartProduits"></canvas>
            </div>
        </div>
        <?php endif; ?>

        <!-- GRILLE : CLIENTS PAR TRANCHE + TX PAR TYPE -->
        <div class="charts-grid">
            <?php if (!empty($tranches_solde)): ?>
            <!-- GRAPHIQUE 7 : CLIENTS PAR TRANCHE -->
            <div class="chart-block">
                <h4><span class="ico">7</span> Clients par tranche de solde</h4>
                <div class="chart-wrap">
                    <canvas id="chartTranches"></canvas>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($types_tx)): ?>
            <!-- GRAPHIQUE 8 : TRANSACTIONS PAR TYPE -->
            <div class="chart-block">
                <h4><span class="ico">8</span> Transactions par type</h4>
                <div class="chart-wrap">
                    <canvas id="chartTypesTx"></canvas>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ============================================================
         SECTION 3 : RÉCAPITULATIF MENSUEL
         ============================================================ -->
    <div class="section">
        <h2 class="section-title">
            <span class="num">3</span>
            Récapitulatif mensuel (données chiffrées)
        </h2>

        <table class="data-table">
            <thead>
                <tr>
                    <th>Mois</th>
                    <th class="right">Clients</th>
                    <th class="right">Placements</th>
                    <th class="right">Capital placé</th>
                    <th class="right">Dépôts</th>
                    <th class="right">Retraits</th>
                    <th class="right">Intérêts</th>
                    <th class="right">Commissions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($mois_data as $m): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($m['label']) ?></strong></td>
                        <td class="right"><?= (int)$m['clients'] ?></td>
                        <td class="right"><?= (int)$m['nb_placements'] ?></td>
                        <td class="right gold"><?= number_format($m['placements'], 0, ',', ' ') ?></td>
                        <td class="right green">+<?= number_format($m['depots'], 0, ',', ' ') ?></td>
                        <td class="right red">−<?= number_format($m['retraits'], 0, ',', ' ') ?></td>
                        <td class="right green">+<?= number_format($m['interets'], 0, ',', ' ') ?></td>
                        <td class="right gold"><?= number_format($m['commissions'], 0, ',', ' ') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td>TOTAL 12 MOIS</td>
                    <td class="right"><?= number_format($total_12m['clients'], 0, ',', ' ') ?></td>
                    <td class="right"><?= number_format($total_12m['nb_placements'], 0, ',', ' ') ?></td>
                    <td class="right"><?= number_format($total_12m['placements'], 0, ',', ' ') ?></td>
                    <td class="right">+<?= number_format($total_12m['depots'], 0, ',', ' ') ?></td>
                    <td class="right">−<?= number_format($total_12m['retraits'], 0, ',', ' ') ?></td>
                    <td class="right">+<?= number_format($total_12m['interets'], 0, ',', ' ') ?></td>
                    <td class="right"><?= number_format($total_12m['commissions'], 0, ',', ' ') ?></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- ============================================================
         SECTION 4 : TOP PERFORMANCES
         ============================================================ -->
    <div class="section">
        <h2 class="section-title">
            <span class="num">4</span>
            Top performances
        </h2>

        <div class="grid-2">
            <div>
                <h3 style="color:#0D1B5E;font-size:.85rem;font-weight:800;margin-bottom:12px;text-transform:uppercase;letter-spacing:1px">
                    🏆 Top 5 clients épargnants
                </h3>
                <?php if (empty($top_clients)): ?>
                    <p style="color:#94A3B8;font-style:italic;padding:12px">Aucune donnée</p>
                <?php else: ?>
                    <div class="top-list">
                        <?php foreach (array_slice($top_clients, 0, 5) as $i => $t):
                            $rendement = (float)$t['total_place'] > 0 
                                ? ((float)$t['total_interets'] / (float)$t['total_place']) * 100 
                                : 0;
                        ?>
                            <div class="top-item <?= $i === 0 ? 'gold' : '' ?>">
                                <div class="rank"><?= $i + 1 ?></div>
                                <div class="info">
                                    <div class="name"><?= htmlspecialchars($t['nom'] . ' ' . $t['postnom']) ?></div>
                                    <div class="sub"><?= htmlspecialchars($t['numero_compte']) ?></div>
                                </div>
                                <div class="value">
                                    <?= number_format((float)$t['total_place'], 0, ',', ' ') ?>
                                    <span class="sub">Rdt : <?= number_format($rendement, 2) ?>%</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div>
                <h3 style="color:#0D1B5E;font-size:.85rem;font-weight:800;margin-bottom:12px;text-transform:uppercase;letter-spacing:1px">
                    💼 Top 5 caissiers
                </h3>
                <?php if (empty($top_caissiers)): ?>
                    <p style="color:#94A3B8;font-style:italic;padding:12px">Aucune donnée</p>
                <?php else: ?>
                    <div class="top-list">
                        <?php foreach ($top_caissiers as $i => $c): ?>
                            <div class="top-item <?= $i === 0 ? 'gold' : '' ?>">
                                <div class="rank"><?= $i + 1 ?></div>
                                <div class="info">
                                    <div class="name"><?= htmlspecialchars($c['nom'] . ' ' . $c['postnom']) ?></div>
                                    <div class="sub"><?= (int)$c['nb_operations'] ?> opération(s)</div>
                                </div>
                                <div class="value">
                                    <?= number_format((float)$c['volume_total'], 0, ',', ' ') ?>
                                    <span class="sub">CDF traités</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ============================================================
         SECTION 5 : RÉPARTITIONS DÉTAILLÉES
         ============================================================ -->
    <div class="section">
        <h2 class="section-title">
            <span class="num">5</span>
            Répartitions détaillées
        </h2>

        <div class="grid-2">
            <div>
                <h3 style="color:#0D1B5E;font-size:.85rem;font-weight:800;margin-bottom:12px;text-transform:uppercase;letter-spacing:1px">
                    📦 Placements par produit
                </h3>
                <?php if (empty($repartition_produits)): ?>
                    <p style="color:#94A3B8;font-style:italic;padding:12px">Aucune donnée</p>
                <?php else: ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Produit</th>
                                <th class="right">Nb</th>
                                <th class="right">Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($repartition_produits as $p): ?>
                                <tr>
                                    <td><?= htmlspecialchars($p['produit']) ?></td>
                                    <td class="right"><?= (int)$p['nb'] ?></td>
                                    <td class="right gold"><?= number_format((float)$p['total'], 0, ',', ' ') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div>
                <h3 style="color:#0D1B5E;font-size:.85rem;font-weight:800;margin-bottom:12px;text-transform:uppercase;letter-spacing:1px">
                    💱 Placements par devise
                </h3>
                <?php if (empty($repartition_devise)): ?>
                    <p style="color:#94A3B8;font-style:italic;padding:12px">Aucune donnée</p>
                <?php else: ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Devise</th>
                                <th class="right">Nb placements</th>
                                <th class="right">Capital total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($repartition_devise as $d): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($d['devise']) ?></strong></td>
                                    <td class="right"><?= (int)$d['nb'] ?></td>
                                    <td class="right gold">
                                        <?= number_format((float)$d['total'], 0, ',', ' ') ?>
                                        <?= $d['devise'] === 'CDF' ? 'FC' : '$' ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <div class="grid-2">
            <div>
                <h3 style="color:#0D1B5E;font-size:.85rem;font-weight:800;margin-bottom:12px;text-transform:uppercase;letter-spacing:1px">
                    🎯 Transactions par type
                </h3>
                <?php if (empty($types_tx)): ?>
                    <p style="color:#94A3B8;font-style:italic;padding:12px">Aucune donnée</p>
                <?php else: ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th class="right">Nb</th>
                                <th class="right">Volume</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($types_tx as $t): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($t['type']) ?></strong></td>
                                    <td class="right"><?= number_format((int)$t['nb'], 0, ',', ' ') ?></td>
                                    <td class="right gold"><?= number_format((float)$t['total'], 0, ',', ' ') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <div>
                <h3 style="color:#0D1B5E;font-size:.85rem;font-weight:800;margin-bottom:12px;text-transform:uppercase;letter-spacing:1px">
                    🌐 Transactions par mode
                </h3>
                <?php if (empty($modes_tx)): ?>
                    <p style="color:#94A3B8;font-style:italic;padding:12px">Aucune donnée</p>
                <?php else: ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Mode</th>
                                <th class="right">Nb</th>
                                <th class="right">Volume</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($modes_tx as $m): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($m['mode']) ?></strong></td>
                                    <td class="right"><?= number_format((int)$m['nb'], 0, ',', ' ') ?></td>
                                    <td class="right gold"><?= number_format((float)$m['total'], 0, ',', ' ') ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- ============================================================
         SECTION 6 : CLIENTS PAR TRANCHE
         ============================================================ -->
    <?php if (!empty($tranches_solde)): ?>
    <div class="section">
        <h2 class="section-title">
            <span class="num">6</span>
            Répartition clients par tranche de solde
        </h2>

        <table class="data-table">
            <thead>
                <tr>
                    <th>Tranche de solde (CDF)</th>
                    <th class="right">Nombre de clients</th>
                    <th class="right">Pourcentage</th>
                    <th class="right">Total cumulé</th>
                </tr>
            </thead>
            <tbody>
                <?php
                    $total_clients_tranches = array_sum(array_column($tranches_solde, 'nb'));
                    foreach ($tranches_solde as $t):
                        $pct = $total_clients_tranches > 0 
                            ? ((int)$t['nb'] / $total_clients_tranches) * 100 
                            : 0;
                ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($t['tranche']) ?> CDF</strong></td>
                        <td class="right"><?= (int)$t['nb'] ?></td>
                        <td class="right"><?= number_format($pct, 1) ?>%</td>
                        <td class="right gold"><?= number_format((float)$t['total'], 0, ',', ' ') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- ============================================================
         PIED DE PAGE
         ============================================================ -->
    <div class="report-footer">
        <p><strong>AFRICAN TRUST FUND</strong> — Société de Gestion d'Actifs (S.G.A)</p>
        <p>Document confidentiel — Toute reproduction non autorisée est strictement interdite.</p>
        <p style="margin-top:8px">
            Référence : <strong>ATF-STAT-<?= date('Ymd-His') ?></strong>
            — Généré par l'administrateur #<?= $admin_id ?>
            — Le <?= date('d/m/Y à H:i:s') ?>
        </p>

        <div class="signature">
            <div class="block">
                Le Directeur Général
                <small>Signature & cachet</small>
            </div>
            <div class="block">
                Le Responsable Sécurité
                <small>Signature & cachet</small>
            </div>
        </div>
    </div>

</div>

<!-- ============================================================
     SCRIPTS : GRAPHIQUES CHART.JS AVEC DESIGN IMPRIMABLE
     ============================================================ -->
<script>
/* Configuration globale Chart.js */
Chart.defaults.font.family = "'Inter', -apple-system, 'Segoe UI', sans-serif";
Chart.defaults.font.size = 11;
Chart.defaults.color = '#475569';
Chart.defaults.borderColor = '#E2E8F0';
Chart.defaults.animation = false; // ⭐ pas d'animation pour impression rapide

/* Palette de couleurs */
const COLORS = {
    navy: '#0D1B5E',
    gold: '#F5B800',
    goldDark: '#D4AF37',
    green: '#10B981',
    greenDark: '#059669',
    red: '#EF4444',
    redDark: '#DC2626',
    blue: '#3B82F6',
    purple: '#8B5CF6',
    cyan: '#06B6D4',
    slate: '#64748B',
};

const tooltipOptions = {
    backgroundColor: '#0D1B5E',
    titleColor: '#F5B800',
    bodyColor: '#FFFFFF',
    borderColor: '#F5B800',
    borderWidth: 1,
    padding: 12,
    titleFont: { size: 12, weight: '700' },
    bodyFont: { size: 11, weight: '600' },
    displayColors: true,
    cornerRadius: 6,
};

const axisStyle = {
    x: {
        grid: { color: '#F1F5F9', drawBorder: false },
        ticks: { color: '#64748B', font: { size: 10, weight: '600' } },
    },
    y: {
        grid: { color: '#F1F5F9', drawBorder: false },
        ticks: {
            color: '#64748B',
            font: { size: 10, weight: '600' },
            callback: (v) => {
                if (v >= 1000000) return (v / 1000000).toFixed(1) + 'M';
                if (v >= 1000) return (v / 1000).toFixed(0) + 'K';
                return v;
            }
        },
    }
};

const legendStyle = {
    display: true,
    position: 'top',
    labels: {
        usePointStyle: true,
        pointStyle: 'circle',
        padding: 10,
        color: '#0D1B5E',
        font: { size: 11, weight: '700' },
        boxWidth: 8,
        boxHeight: 8,
    }
};

/* ============================================================
   GRAPHIQUE 1 : ÉVOLUTION PLACEMENTS + INTÉRÊTS
   ============================================================ */
(function() {
    const ctx = document.getElementById('chartEvolution').getContext('2d');
    
    const gradientPlacements = ctx.createLinearGradient(0, 0, 0, 280);
    gradientPlacements.addColorStop(0, 'rgba(245, 184, 0, 0.4)');
    gradientPlacements.addColorStop(1, 'rgba(245, 184, 0, 0.02)');
    
    const gradientInterets = ctx.createLinearGradient(0, 0, 0, 280);
    gradientInterets.addColorStop(0, 'rgba(16, 185, 129, 0.35)');
    gradientInterets.addColorStop(1, 'rgba(16, 185, 129, 0.02)');

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode($mois_labels_court) ?>,
            datasets: [
                {
                    label: 'Capital placé (CDF)',
                    data: <?= json_encode($data_placements_chart) ?>,
                    borderColor: COLORS.gold,
                    backgroundColor: gradientPlacements,
                    borderWidth: 3,
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: COLORS.gold,
                    pointBorderColor: '#FFFFFF',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7,
                },
                {
                    label: 'Intérêts versés (CDF)',
                    data: <?= json_encode($data_interets_chart) ?>,
                    borderColor: COLORS.green,
                    backgroundColor: gradientInterets,
                    borderWidth: 3,
                    tension: 0.4,
                    fill: true,
                    pointBackgroundColor: COLORS.green,
                    pointBorderColor: '#FFFFFF',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: legendStyle,
                tooltip: {
                    ...tooltipOptions,
                    callbacks: {
                        label: (c) => ' ' + c.dataset.label.split('(')[0].trim() + ' : ' + 
                                     c.parsed.y.toLocaleString('fr-FR') + ' CDF'
                    }
                }
            },
            scales: axisStyle
        }
    });
})();

/* ============================================================
   GRAPHIQUE 2 : DÉPÔTS vs RETRAITS
   ============================================================ */
(function() {
    new Chart(document.getElementById('chartDepotsRetraits'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($mois_labels_court) ?>,
            datasets: [
                {
                    label: 'Dépôts',
                    data: <?= json_encode($data_depots_chart) ?>,
                    backgroundColor: COLORS.green,
                    borderColor: COLORS.greenDark,
                    borderWidth: 1,
                    borderRadius: 5,
                },
                {
                    label: 'Retraits',
                    data: <?= json_encode($data_retraits_chart) ?>,
                    backgroundColor: COLORS.red,
                    borderColor: COLORS.redDark,
                    borderWidth: 1,
                    borderRadius: 5,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: legendStyle,
                tooltip: {
                    ...tooltipOptions,
                    callbacks: {
                        label: (c) => ' ' + c.dataset.label + ' : ' + 
                                     c.parsed.y.toLocaleString('fr-FR') + ' CDF'
                    }
                }
            },
            scales: {
                x: { ...axisStyle.x, stacked: false },
                y: { ...axisStyle.y, stacked: false }
            }
        }
    });
})();

/* ============================================================
   GRAPHIQUE 3 : NOUVEAUX CLIENTS
   ============================================================ */
(function() {
    const ctx = document.getElementById('chartClients').getContext('2d');
    const gradient = ctx.createLinearGradient(0, 0, 0, 240);
    gradient.addColorStop(0, 'rgba(59, 130, 246, 0.5)');
    gradient.addColorStop(1, 'rgba(59, 130, 246, 0.05)');

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode($mois_labels_court) ?>,
            datasets: [{
                label: 'Nouveaux clients',
                data: <?= json_encode($data_clients_chart) ?>,
                borderColor: COLORS.blue,
                backgroundColor: gradient,
                borderWidth: 3,
                tension: 0.4,
                fill: true,
                pointBackgroundColor: COLORS.blue,
                pointBorderColor: '#FFFFFF',
                pointBorderWidth: 2,
                pointRadius: 5,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    ...tooltipOptions,
                    callbacks: {
                        label: (c) => ' ' + c.parsed.y + ' client(s)'
                    }
                }
            },
            scales: {
                x: axisStyle.x,
                y: {
                    ...axisStyle.y,
                    ticks: {
                        ...axisStyle.y.ticks,
                        callback: (v) => v,
                        stepSize: 1,
                        precision: 0
                    }
                }
            }
        }
    });
})();

/* ============================================================
   GRAPHIQUE 4 : COMMISSIONS
   ============================================================ */
(function() {
    const ctx = document.getElementById('chartCommissions').getContext('2d');
    const gradient = ctx.createLinearGradient(0, 0, 0, 240);
    gradient.addColorStop(0, COLORS.purple);
    gradient.addColorStop(1, 'rgba(139, 92, 246, 0.3)');

    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?= json_encode($mois_labels_court) ?>,
            datasets: [{
                label: 'Commissions',
                data: <?= json_encode($data_commissions_chart) ?>,
                backgroundColor: gradient,
                borderColor: COLORS.purple,
                borderWidth: 1,
                borderRadius: 6,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    ...tooltipOptions,
                    callbacks: {
                        label: (c) => ' ' + c.parsed.y.toLocaleString('fr-FR') + ' CDF'
                    }
                }
            },
            scales: axisStyle
        }
    });
})();

/* ============================================================
   GRAPHIQUE 5 : CAPITAL CUMULÉ
   ============================================================ */
(function() {
    const data = <?= json_encode($data_placements_chart) ?>;
    let cumul = 0;
    const cumulData = data.map(v => { cumul += v; return cumul; });

    const ctx = document.getElementById('chartCumul').getContext('2d');
    const gradient = ctx.createLinearGradient(0, 0, 0, 240);
    gradient.addColorStop(0, 'rgba(6, 182, 212, 0.5)');
    gradient.addColorStop(1, 'rgba(6, 182, 212, 0.05)');

    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode($mois_labels_court) ?>,
            datasets: [{
                label: 'Capital cumulé',
                data: cumulData,
                borderColor: COLORS.cyan,
                backgroundColor: gradient,
                borderWidth: 3,
                tension: 0.4,
                fill: true,
                pointBackgroundColor: COLORS.cyan,
                pointBorderColor: '#FFFFFF',
                pointBorderWidth: 2,
                pointRadius: 5,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    ...tooltipOptions,
                    callbacks: {
                        label: (c) => ' Cumul : ' + c.parsed.y.toLocaleString('fr-FR') + ' CDF'
                    }
                }
            },
            scales: axisStyle
        }
    });
})();

/* ============================================================
   GRAPHIQUE 6 : CAPITAL PAR PRODUIT (HORIZONTAL BAR)
   ============================================================ */
<?php if (!empty($repartition_produits)): ?>
(function() {
    new Chart(document.getElementById('chartProduits'), {
        type: 'bar',
        data: {
            labels: <?= json_encode(array_map(fn($p) => $p['produit'], $repartition_produits)) ?>,
            datasets: [{
                label: 'Capital placé',
                data: <?= json_encode(array_map(fn($p) => (float)$p['total'], $repartition_produits)) ?>,
                backgroundColor: [
                    COLORS.gold,
                    COLORS.green,
                    COLORS.blue,
                    COLORS.purple,
                    COLORS.cyan,
                    COLORS.red,
                    '#F59E0B',
                ],
                borderColor: '#FFFFFF',
                borderWidth: 2,
                borderRadius: 6,
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    ...tooltipOptions,
                    callbacks: {
                        label: (c) => ' ' + c.parsed.x.toLocaleString('fr-FR') + ' CDF'
                    }
                }
            },
            scales: {
                x: {
                    grid: { color: '#F1F5F9' },
                    ticks: {
                        color: '#64748B',
                        font: { size: 10, weight: '600' },
                        callback: (v) => {
                            if (v >= 1000000) return (v / 1000000).toFixed(1) + 'M';
                            if (v >= 1000) return (v / 1000).toFixed(0) + 'K';
                            return v;
                        }
                    }
                },
                y: {
                    grid: { display: false },
                    ticks: {
                        color: '#0D1B5E',
                        font: { size: 11, weight: '700' }
                    }
                }
            }
        }
    });
})();
<?php endif; ?>

/* ============================================================
   GRAPHIQUE 7 : CLIENTS PAR TRANCHE DE SOLDE
   ============================================================ */
<?php if (!empty($tranches_solde)): ?>
(function() {
    const labels = <?= json_encode(array_map(fn($t) => $t['tranche'], $tranches_solde)) ?>;
    const data = <?= json_encode(array_map(fn($t) => (int)$t['nb'], $tranches_solde)) ?>;

    if (data.length === 0) return;

    new Chart(document.getElementById('chartTranches'), {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: data,
                backgroundColor: [
                    '#EF4444',
                    '#F59E0B',
                    '#F5B800',
                    '#10B981',
                    '#3B82F6',
                    '#8B5CF6',
                ],
                borderColor: '#FFFFFF',
                borderWidth: 3,
                hoverOffset: 8,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '55%',
            plugins: {
                legend: {
                    position: 'right',
                    labels: {
                        usePointStyle: true,
                        pointStyle: 'circle',
                        padding: 10,
                        color: '#0D1B5E',
                        font: { size: 10, weight: '700' },
                        boxWidth: 10,
                        boxHeight: 10,
                    }
                },
                tooltip: {
                    ...tooltipOptions,
                    callbacks: {
                        label: (c) => {
                            const total = c.dataset.data.reduce((a, b) => a + b, 0);
                            const pct = ((c.parsed / total) * 100).toFixed(1);
                            return ' ' + c.label + ' : ' + c.parsed + ' clients (' + pct + '%)';
                        }
                    }
                }
            }
        }
    });
})();
<?php endif; ?>

/* ============================================================
   GRAPHIQUE 8 : TRANSACTIONS PAR TYPE
   ============================================================ */
<?php if (!empty($types_tx)): ?>
(function() {
    const labels = <?= json_encode(array_map(fn($t) => $t['type'], $types_tx)) ?>;
    const data = <?= json_encode(array_map(fn($t) => (int)$t['nb'], $types_tx)) ?>;

    if (data.length === 0) return;

    new Chart(document.getElementById('chartTypesTx'), {
        type: 'pie',
        data: {
            labels: labels,
            datasets: [{
                data: data,
                backgroundColor: [
                    COLORS.green,
                    COLORS.red,
                    COLORS.gold,
                    COLORS.blue,
                    COLORS.purple,
                ],
                borderColor: '#FFFFFF',
                borderWidth: 3,
                hoverOffset: 8,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'right',
                    labels: {
                        usePointStyle: true,
                        pointStyle: 'circle',
                        padding: 10,
                        color: '#0D1B5E',
                        font: { size: 10, weight: '700' },
                    }
                },
                tooltip: {
                    ...tooltipOptions,
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
<?php endif; ?>

/* ============================================================
   RACCOURCI IMPRESSION + ATTENTE CHARGEMENT GRAPHIQUES
   ============================================================ */
document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
        e.preventDefault();
        window.print();
    }
});

/* Attendre que tous les graphiques soient rendus avant impression */
window.addEventListener('beforeprint', function() {
    console.log('%c🖨️ Préparation impression — graphiques figés', 'color:#F5B800;font-weight:bold');
});

console.log('%c📊 Rapport Statistiques ATF (avec graphiques)', 'color:#F5B800;font-weight:bold;font-size:14px');
console.log('Format :', <?= json_encode($format) ?>);
console.log('Réf :', 'ATF-STAT-<?= date('Ymd-His') ?>');
console.log('✅ 8 graphiques chargés');
</script>

</body>
</html>