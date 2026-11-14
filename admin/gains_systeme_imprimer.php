<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
requireRole('admin');

$pdo = Database::getConnection();
$admin_id = (int)$_SESSION['admin_id'];

/* Période */
$periode = $_GET['periode'] ?? 'mois';
$debut_param = $_GET['debut'] ?? '';
$fin_param = $_GET['fin'] ?? '';

$TAUX_USD_CDF = 2800;

/* Calcul période */
$aujourdhui = date('Y-m-d');
switch ($periode) {
    case 'jour':
        $debut = $fin = $aujourdhui;
        $periode_label = "Aujourd'hui · " . date('d/m/Y');
        break;
    case 'semaine':
        $debut = date('Y-m-d', strtotime('monday this week'));
        $fin = date('Y-m-d', strtotime('sunday this week'));
        $periode_label = "Semaine du " . date('d/m/Y', strtotime($debut)) . " au " . date('d/m/Y', strtotime($fin));
        break;
    case 'mois':
        $debut = date('Y-m-01');
        $fin = date('Y-m-t');
        $periode_label = "Mois de " . date('F Y', strtotime($debut));
        break;
    case 'trimestre':
        $trimestre = (int)ceil((int)date('m') / 3);
        $debut = date('Y-' . str_pad((string)(($trimestre - 1) * 3 + 1), 2, '0', STR_PAD_LEFT) . '-01');
        $fin = date('Y-m-t', strtotime($debut . ' +2 months'));
        $periode_label = "T{$trimestre} " . date('Y');
        break;
    case 'annee':
        $debut = date('Y-01-01');
        $fin = date('Y-12-31');
        $periode_label = "Année " . date('Y');
        break;
    case 'personnalise':
        $debut = $debut_param ?: date('Y-m-01');
        $fin = $fin_param ?: date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $debut)) $debut = date('Y-m-01');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fin)) $fin = date('Y-m-d');
        $periode_label = "Du " . date('d/m/Y', strtotime($debut)) . " au " . date('d/m/Y', strtotime($fin));
        break;
    default:
        $debut = date('Y-m-01');
        $fin = date('Y-m-t');
        $periode_label = "Ce mois";
}

/* ============================================================
   RÉCUPÉRATION DES SOURCES DE REVENUS
   ============================================================ */

/* SOURCE 1 : RETRAITS */
$s_retraits = ['usd' => 0, 'cdf' => 0, 'nb_usd' => 0, 'nb_cdf' => 0];
try {
    $stmt = $pdo->prepare("
        SELECT devise, COUNT(*) AS nb, COALESCE(SUM(montant_commission), 0) AS total
        FROM Commissions
        WHERE DATE(date_commission) BETWEEN ? AND ?
          AND (type_commission = 'retrait' OR LOWER(COALESCE(description, '')) LIKE '%retrait%')
        GROUP BY devise
    ");
    $stmt->execute([$debut, $fin]);
    foreach ($stmt->fetchAll() as $r) {
        $dev = strtoupper($r['devise']);
        if ($dev === 'USD') { $s_retraits['usd'] = (float)$r['total']; $s_retraits['nb_usd'] = (int)$r['nb']; }
        else { $s_retraits['cdf'] = (float)$r['total']; $s_retraits['nb_cdf'] = (int)$r['nb']; }
    }
} catch (Throwable $e) {}

/* SOURCE 2 : PRÊTS */
$s_prets = ['usd' => 0, 'cdf' => 0, 'nb_usd' => 0, 'nb_cdf' => 0];
try {
    $stmt = $pdo->prepare("
        SELECT devise, COUNT(*) AS nb,
               COALESCE(SUM(GREATEST(0, montant_paye - montant_accorde)), 0) AS total
        FROM Prets
        WHERE statut IN ('decaisse','en_cours','en_retard','solde')
          AND DATE(date_demande) BETWEEN ? AND ?
        GROUP BY devise
    ");
    $stmt->execute([$debut, $fin]);
    foreach ($stmt->fetchAll() as $r) {
        $dev = strtoupper($r['devise']);
        if ($dev === 'USD') { $s_prets['usd'] = (float)$r['total']; $s_prets['nb_usd'] = (int)$r['nb']; }
        else { $s_prets['cdf'] = (float)$r['total']; $s_prets['nb_cdf'] = (int)$r['nb']; }
    }
} catch (Throwable $e) {}

/* SOURCE 3 : PLACEMENTS */
$s_placements = ['usd' => 0, 'cdf' => 0, 'nb_usd' => 0, 'nb_cdf' => 0];
try {
    $stmt = $pdo->prepare("
        SELECT devise, COUNT(*) AS nb, COALESCE(SUM(montant_commission), 0) AS total
        FROM Commissions
        WHERE DATE(date_commission) BETWEEN ? AND ?
          AND (type_commission IN ('placement','gestion_epargne')
               OR LOWER(COALESCE(description, '')) LIKE '%placement%'
               OR LOWER(COALESCE(description, '')) LIKE '%gestion%')
        GROUP BY devise
    ");
    $stmt->execute([$debut, $fin]);
    foreach ($stmt->fetchAll() as $r) {
        $dev = strtoupper($r['devise']);
        if ($dev === 'USD') { $s_placements['usd'] = (float)$r['total']; $s_placements['nb_usd'] = (int)$r['nb']; }
        else { $s_placements['cdf'] = (float)$r['total']; $s_placements['nb_cdf'] = (int)$r['nb']; }
    }
} catch (Throwable $e) {}

/* SOURCE 4 : VIREMENTS */
$s_virements = ['usd' => 0, 'cdf' => 0, 'nb_usd' => 0, 'nb_cdf' => 0];
try {
    $stmt = $pdo->prepare("
        SELECT devise, COUNT(*) AS nb, COALESCE(SUM(montant_commission), 0) AS total
        FROM Commissions
        WHERE DATE(date_commission) BETWEEN ? AND ?
          AND (type_commission = 'virement'
               OR LOWER(COALESCE(description, '')) LIKE '%virement%'
               OR LOWER(COALESCE(description, '')) LIKE '%transfert%')
        GROUP BY devise
    ");
    $stmt->execute([$debut, $fin]);
    foreach ($stmt->fetchAll() as $r) {
        $dev = strtoupper($r['devise']);
        if ($dev === 'USD') { $s_virements['usd'] = (float)$r['total']; $s_virements['nb_usd'] = (int)$r['nb']; }
        else { $s_virements['cdf'] = (float)$r['total']; $s_virements['nb_cdf'] = (int)$r['nb']; }
    }
} catch (Throwable $e) {}

/* SOURCE 5 : AUTRES */
$s_autres = ['usd' => 0, 'cdf' => 0, 'nb_usd' => 0, 'nb_cdf' => 0];
try {
    $stmt = $pdo->prepare("
        SELECT devise, COUNT(*) AS nb, COALESCE(SUM(montant_commission), 0) AS total
        FROM Commissions
        WHERE DATE(date_commission) BETWEEN ? AND ?
          AND (type_commission IS NULL OR type_commission NOT IN ('retrait','placement','gestion_epargne','virement'))
          AND LOWER(COALESCE(description, '')) NOT LIKE '%retrait%'
          AND LOWER(COALESCE(description, '')) NOT LIKE '%placement%'
          AND LOWER(COALESCE(description, '')) NOT LIKE '%gestion%'
          AND LOWER(COALESCE(description, '')) NOT LIKE '%virement%'
          AND LOWER(COALESCE(description, '')) NOT LIKE '%transfert%'
        GROUP BY devise
    ");
    $stmt->execute([$debut, $fin]);
    foreach ($stmt->fetchAll() as $r) {
        $dev = strtoupper($r['devise']);
        if ($dev === 'USD') { $s_autres['usd'] = (float)$r['total']; $s_autres['nb_usd'] = (int)$r['nb']; }
        else { $s_autres['cdf'] = (float)$r['total']; $s_autres['nb_cdf'] = (int)$r['nb']; }
    }
} catch (Throwable $e) {}

/* TOTAUX */
$total_usd = $s_retraits['usd'] + $s_prets['usd'] + $s_placements['usd'] + $s_virements['usd'] + $s_autres['usd'];
$total_cdf = $s_retraits['cdf'] + $s_prets['cdf'] + $s_placements['cdf'] + $s_virements['cdf'] + $s_autres['cdf'];
$total_eq_cdf = $total_cdf + $total_usd * $TAUX_USD_CDF;

$nb_usd_total = $s_retraits['nb_usd'] + $s_prets['nb_usd'] + $s_placements['nb_usd'] + $s_virements['nb_usd'] + $s_autres['nb_usd'];
$nb_cdf_total = $s_retraits['nb_cdf'] + $s_prets['nb_cdf'] + $s_placements['nb_cdf'] + $s_virements['nb_cdf'] + $s_autres['nb_cdf'];

/* Top 10 clients */
$top_clients = [];
try {
    $stmt = $pdo->prepare("
        SELECT c.nom, c.postnom, c.numero_compte,
               COALESCE(SUM(CASE WHEN cm.devise='USD' THEN cm.montant_commission ELSE 0 END), 0) AS gains_usd,
               COALESCE(SUM(CASE WHEN cm.devise='CDF' THEN cm.montant_commission ELSE 0 END), 0) AS gains_cdf,
               COUNT(cm.id_commission) AS nb_commissions
        FROM Client c
        JOIN Commissions cm ON cm.id_client = c.id_client
        WHERE DATE(cm.date_commission) BETWEEN ? AND ?
        GROUP BY c.id_client, c.nom, c.postnom, c.numero_compte
        ORDER BY (
            COALESCE(SUM(CASE WHEN cm.devise='USD' THEN cm.montant_commission ELSE 0 END), 0) * 2800
            + COALESCE(SUM(CASE WHEN cm.devise='CDF' THEN cm.montant_commission ELSE 0 END), 0)
        ) DESC
        LIMIT 10
    ");
    $stmt->execute([$debut, $fin]);
    $top_clients = $stmt->fetchAll();
} catch (Throwable $e) {}

logAction($pdo, 'admin', $admin_id, 'GAINS_IMPRIMER', "Période: $debut → $fin");

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Rapport Gains Système ATF — <?= date('d/m/Y') ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    background: #F1F5F9;
    color: #1E293B;
    padding: 30px 20px;
    line-height: 1.5;
    font-size: 12px;
}

/* Boutons flottants */
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
.btn-float.print:hover { transform: translateY(-3px); }
.btn-float.back {
    left: 20px;
    background: #0D1B5E;
    color: #F5B800;
    border: 2px solid #F5B800;
}
.btn-float.back:hover { background: #F5B800; color: #0D1B5E; }

/* Page A4 */
.page {
    max-width: 1100px;
    margin: 0 auto;
    background: #FFFFFF;
    border-radius: 12px;
    padding: 40px 45px;
    box-shadow: 0 20px 60px rgba(0,0,0,.08);
}

/* En-tête */
.report-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 24px;
    padding-bottom: 22px;
    border-bottom: 3px solid #0D1B5E;
    margin-bottom: 26px;
}
.report-header .logo-block {
    display: flex;
    align-items: center;
    gap: 16px;
}
.report-header .logo-box {
    width: 72px; height: 72px;
    border-radius: 16px;
    background: linear-gradient(135deg, #F5B800, #D4AF37);
    display: flex; align-items: center; justify-content: center;
    color: #0D1B5E; font-weight: 900; font-size: 1.5rem;
    box-shadow: 0 8px 20px rgba(245,184,0,.3);
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

/* Bandeau période */
.periode-banner {
    padding: 14px 22px;
    background: linear-gradient(135deg, #FFFBEB, #FEF3C7);
    border-left: 5px solid #F5B800;
    border-radius: 10px;
    margin-bottom: 24px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 14px;
    font-size: .85rem;
    color: #78350F;
    flex-wrap: wrap;
}
.periode-banner strong { color: #92400E; font-weight: 800; }

/* HERO USD & CDF */
.hero-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 26px;
}
.hero-print {
    padding: 26px 28px;
    border-radius: 16px;
    color: #FFFFFF;
    position: relative;
    overflow: hidden;
    page-break-inside: avoid;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
    border: 2px solid;
}
.hero-print.usd { background: linear-gradient(135deg, #065F46, #10B981); border-color: #059669; }
.hero-print.cdf { background: linear-gradient(135deg, #0D1B5E, #2563EB); border-color: #1E3A8A; }
.hero-print::before {
    content: "";
    position: absolute;
    top: -40px; right: -40px;
    width: 140px; height: 140px;
    border-radius: 50%;
    opacity: .15;
}
.hero-print.usd::before { background: #FFFFFF; }
.hero-print.cdf::before { background: #F5B800; }

.hero-print .flag-row {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 12px;
    position: relative;
    z-index: 1;
}
.hero-print .flag-icon { font-size: 1.8rem; }
.hero-print .flag-label {
    font-size: .74rem; font-weight: 800;
    text-transform: uppercase; letter-spacing: 2px;
}
.hero-print .devise-tag {
    margin-left: auto; padding: 3px 10px;
    border-radius: 999px; background: rgba(255,255,255,.2);
    border: 1px solid rgba(255,255,255,.3);
    font-size: .68rem; font-weight: 900; letter-spacing: 2px;
}
.hero-print .amount {
    font-size: 2.2rem; font-weight: 900;
    letter-spacing: -1px; line-height: 1;
    margin-bottom: 6px;
    position: relative; z-index: 1;
    font-variant-numeric: tabular-nums;
}
.hero-print .amount .unit {
    font-size: 1rem; opacity: .85;
    margin-left: 6px; font-weight: 800;
}
.hero-print .sub-label {
    font-size: .78rem; opacity: .85;
    position: relative; z-index: 1;
    margin-bottom: 14px;
}
.hero-print .meta-row {
    display: flex;
    gap: 20px;
    padding-top: 14px;
    border-top: 1px solid rgba(255,255,255,.25);
    position: relative;
    z-index: 1;
}
.hero-print .meta-item .lbl {
    font-size: .64rem; opacity: .75;
    text-transform: uppercase; letter-spacing: 1px;
    font-weight: 800; margin-bottom: 3px;
}
.hero-print .meta-item .val {
    font-size: .95rem; font-weight: 900;
    font-variant-numeric: tabular-nums;
}
.hero-print.usd .meta-item .val { color: #D1FAE5; }
.hero-print.cdf .meta-item .val { color: #FCD34D; }

/* Bandeau équivalent */
.eq-banner {
    padding: 16px 24px;
    background: linear-gradient(135deg, #FFFBEB, #FEF3C7);
    border: 2px solid #F5B800;
    border-radius: 12px;
    margin-bottom: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 16px;
    text-align: center;
    flex-wrap: wrap;
    page-break-inside: avoid;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}
.eq-banner .ico { font-size: 1.5rem; }
.eq-banner .text {
    color: #78350F; font-size: .88rem; font-weight: 700;
}
.eq-banner .text strong {
    color: #92400E; font-size: 1.2rem;
    font-weight: 900; display: block;
    margin-top: 4px;
    letter-spacing: -.5px;
}

/* Section titre */
.section-title {
    font-size: .95rem; color: #0D1B5E; font-weight: 900;
    text-transform: uppercase; letter-spacing: 1.5px;
    padding-bottom: 10px; margin: 28px 0 16px;
    border-bottom: 2px solid #F5B800;
    display: flex; align-items: center; gap: 10px;
}
.section-title .num {
    width: 28px; height: 28px;
    border-radius: 8px;
    background: #0D1B5E; color: #F5B800;
    display: flex; align-items: center; justify-content: center;
    font-size: .78rem; font-weight: 900;
}

/* Tableau des sources */
table.sources-table {
    width: 100%; border-collapse: collapse;
    font-size: .82rem;
    background: #FFFFFF;
    border: 1px solid #E2E8F0;
    border-radius: 8px;
    overflow: hidden;
    page-break-inside: avoid;
}
table.sources-table thead {
    background: #0D1B5E; color: #FFFFFF;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}
table.sources-table th {
    padding: 11px 12px;
    text-align: left;
    font-size: .68rem;
    text-transform: uppercase;
    letter-spacing: .5px;
    font-weight: 800;
    white-space: nowrap;
}
table.sources-table td {
    padding: 12px;
    border-bottom: 1px solid #F1F5F9;
    color: #334155;
    vertical-align: middle;
}
table.sources-table tbody tr:nth-child(even) { background: #F8FAFC; }
table.sources-table .right { text-align: right; }
table.sources-table .center { text-align: center; }
table.sources-table .usd-col { color: #065F46; font-weight: 800; text-align: right; font-variant-numeric: tabular-nums; }
table.sources-table .cdf-col { color: #1E40AF; font-weight: 800; text-align: right; font-variant-numeric: tabular-nums; }
table.sources-table .eq-col { color: #92400E; font-weight: 900; text-align: right; font-variant-numeric: tabular-nums; }
table.sources-table .source-name {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 800;
    color: #0D1B5E;
}
table.sources-table .source-icon {
    width: 30px; height: 30px;
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: .9rem;
    flex-shrink: 0;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}
table.sources-table .source-icon.retrait { background: #FEE2E2; color: #991B1B; }
table.sources-table .source-icon.pret { background: #D1FAE5; color: #065F46; }
table.sources-table .source-icon.placement { background: #EDE9FE; color: #5B21B6; }
table.sources-table .source-icon.virement { background: #DBEAFE; color: #1E40AF; }
table.sources-table .source-icon.autre { background: #F1F5F9; color: #475569; }

table.sources-table tfoot {
    background: #FFFBEB;
    border-top: 3px solid #F5B800;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}
table.sources-table tfoot td {
    padding: 14px 12px;
    color: #92400E;
    font-weight: 900;
    border: none;
    font-size: .85rem;
}

/* Top clients */
table.top-print {
    width: 100%; border-collapse: collapse;
    font-size: .8rem;
    background: #FFFFFF;
    border: 1px solid #E2E8F0;
    border-radius: 8px;
    overflow: hidden;
    page-break-inside: avoid;
}
table.top-print thead {
    background: #0D1B5E; color: #FFFFFF;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}
table.top-print th {
    padding: 10px 12px;
    text-align: left;
    font-size: .68rem;
    text-transform: uppercase;
    font-weight: 800;
    letter-spacing: .5px;
}
table.top-print td {
    padding: 10px 12px;
    border-bottom: 1px solid #F1F5F9;
    color: #334155;
    vertical-align: middle;
}
table.top-print tbody tr:nth-child(even) { background: #F8FAFC; }
table.top-print .rank {
    width: 26px; height: 26px;
    border-radius: 50%;
    background: #0D1B5E; color: #F5B800;
    display: flex; align-items: center; justify-content: center;
    font-weight: 900; font-size: .72rem;
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}
table.top-print .rank.gold { background: #F5B800; color: #0D1B5E; }
table.top-print .rank.silver { background: #94A3B8; color: #FFFFFF; }
table.top-print .rank.bronze { background: #CD7F32; color: #FFFFFF; }
table.top-print .right { text-align: right; }
table.top-print .usd-col { color: #065F46; font-weight: 800; font-variant-numeric: tabular-nums; }
table.top-print .cdf-col { color: #1E40AF; font-weight: 800; font-variant-numeric: tabular-nums; }
table.top-print .eq-col { color: #92400E; font-weight: 900; font-variant-numeric: tabular-nums; }
table.top-print code {
    font-family: 'Courier New', monospace;
    color: #0D1B5E;
    font-size: .72rem;
    background: #F1F5F9;
    padding: 2px 6px;
    border-radius: 4px;
}

/* Footer + Signatures */
.report-footer {
    margin-top: 40px;
    padding-top: 22px;
    border-top: 2px solid #0D1B5E;
    text-align: center;
    color: #64748B;
    font-size: .72rem;
    line-height: 1.8;
}
.report-footer strong { color: #0D1B5E; font-weight: 900; }
.report-footer .signature {
    margin-top: 30px;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 40px;
    text-align: center;
    font-size: .75rem;
}
.report-footer .signature .block {
    padding-top: 50px;
    border-top: 1.5px dashed #94A3B8;
    color: #475569;
    font-weight: 700;
}
.report-footer .signature .block small {
    display: block;
    color: #94A3B8;
    font-weight: 400;
    margin-top: 4px;
    font-size: .68rem;
}

/* IMPRESSION */
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
    .section-title { page-break-after: avoid; }
    table.sources-table, table.top-print, .hero-print, .eq-banner { page-break-inside: avoid; }
    .report-footer { page-break-inside: avoid; }
}

@media (max-width: 768px) {
    body { padding: 15px; }
    .page { padding: 20px; }
    .hero-grid { grid-template-columns: 1fr; }
    .report-header { flex-direction: column; }
    .report-header .meta-right { text-align: left; }
    .btn-float { padding: 10px 16px; font-size: .78rem; }
}
</style>
</head>
<body>

<button class="btn-float print" onclick="window.print()">🖨️ Imprimer / PDF</button>
<a class="btn-float back" href="<?= $base ?>/admin/gains_systeme.php?periode=<?= urlencode($periode) ?>">← Retour</a>

<div class="page">

    <!-- EN-TÊTE -->
    <div class="report-header">
        <div class="logo-block">
            <div class="logo-box">ATF</div>
            <div>
                <h1>AFRICAN TRUST FUND</h1>
                <div class="slogan">L'argent de l'Afrique pour construire l'Afrique</div>
            </div>
        </div>
        <div class="meta-right">
            <span class="titre">Rapport des Gains Système</span>
            Généré le : <strong><?= date('d/m/Y à H:i') ?></strong><br>
            Période : <strong><?= htmlspecialchars($periode_label) ?></strong><br>
            <span class="ref">RÉF. ATF-GAIN-<?= date('Ymd-His') ?></span>
        </div>
    </div>

    <!-- BANDEAU PÉRIODE -->
    <div class="periode-banner">
        <span>📅 <strong>Période analysée :</strong> <?= htmlspecialchars($periode_label) ?></span>
        <span style="font-size:.78rem">
            Du <?= date('d/m/Y', strtotime($debut)) ?> au <?= date('d/m/Y', strtotime($fin)) ?>
        </span>
    </div>

    <!-- HERO USD & CDF SÉPARÉS -->
    <div class="hero-grid">

        <div class="hero-print usd">
            <div class="flag-row">
                <span class="flag-icon">🇺🇸</span>
                <span class="flag-label">Dollar Américain</span>
                <span class="devise-tag">USD</span>
            </div>
            <div class="amount">
                $ <?= number_format($total_usd, 2, ',', ' ') ?>
                <span class="unit">USD</span>
            </div>
            <div class="sub-label">Total des gains générés en dollars</div>
            <div class="meta-row">
                <div class="meta-item">
                    <div class="lbl">Opérations</div>
                    <div class="val"><?= (int)$nb_usd_total ?></div>
                </div>
                <div class="meta-item">
                    <div class="lbl">Moyenne</div>
                    <div class="val">
                        $ <?= $nb_usd_total > 0 ? number_format($total_usd / $nb_usd_total, 2, ',', ' ') : '0,00' ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="hero-print cdf">
            <div class="flag-row">
                <span class="flag-icon">🇨🇩</span>
                <span class="flag-label">Franc Congolais</span>
                <span class="devise-tag">CDF</span>
            </div>
            <div class="amount">
                <?= number_format($total_cdf, 0, ',', ' ') ?>
                <span class="unit">FC</span>
            </div>
            <div class="sub-label">Total des gains générés en francs</div>
            <div class="meta-row">
                <div class="meta-item">
                    <div class="lbl">Opérations</div>
                    <div class="val"><?= (int)$nb_cdf_total ?></div>
                </div>
                <div class="meta-item">
                    <div class="lbl">Moyenne</div>
                    <div class="val">
                        <?= $nb_cdf_total > 0 ? number_format($total_cdf / $nb_cdf_total, 0, ',', ' ') : '0' ?> FC
                    </div>
                </div>
            </div>
        </div>

    </div>

    <!-- ÉQUIVALENT CONSOLIDÉ -->
    <div class="eq-banner">
        <span class="ico">💱</span>
        <div class="text">
            Équivalent consolidé total · taux : 1 USD = <?= number_format($TAUX_USD_CDF, 0, ',', ' ') ?> FC
            <strong><?= number_format($total_eq_cdf, 0, ',', ' ') ?> FC</strong>
        </div>
        <span class="ico">📊</span>
    </div>

    <!-- SECTION 1 : SOURCES DE REVENUS -->
    <h2 class="section-title">
        <span class="num">1</span>
        Détail des sources de revenus
    </h2>

    <table class="sources-table">
        <thead>
            <tr>
                <th>Source de revenu</th>
                <th class="center">Nb op.</th>
                <th class="right">🇺🇸 Gains USD</th>
                <th class="right">🇨🇩 Gains CDF</th>
                <th class="right">Équivalent CDF</th>
            </tr>
        </thead>
        <tbody>
            <!-- Retraits -->
            <tr>
                <td>
                    <div class="source-name">
                        <div class="source-icon retrait">💸</div>
                        <span>Commissions de retrait</span>
                    </div>
                </td>
                <td class="center"><?= (int)($s_retraits['nb_usd'] + $s_retraits['nb_cdf']) ?></td>
                <td class="usd-col">$ <?= number_format($s_retraits['usd'], 2, ',', ' ') ?></td>
                <td class="cdf-col"><?= number_format($s_retraits['cdf'], 0, ',', ' ') ?> FC</td>
                <td class="eq-col"><?= number_format($s_retraits['cdf'] + $s_retraits['usd'] * $TAUX_USD_CDF, 0, ',', ' ') ?> FC</td>
            </tr>

            <!-- Prêts -->
            <tr>
                <td>
                    <div class="source-name">
                        <div class="source-icon pret">💵</div>
                        <span>Intérêts de prêts</span>
                    </div>
                </td>
                <td class="center"><?= (int)($s_prets['nb_usd'] + $s_prets['nb_cdf']) ?></td>
                <td class="usd-col">$ <?= number_format($s_prets['usd'], 2, ',', ' ') ?></td>
                <td class="cdf-col"><?= number_format($s_prets['cdf'], 0, ',', ' ') ?> FC</td>
                <td class="eq-col"><?= number_format($s_prets['cdf'] + $s_prets['usd'] * $TAUX_USD_CDF, 0, ',', ' ') ?> FC</td>
            </tr>

            <!-- Placements -->
            <tr>
                <td>
                    <div class="source-name">
                        <div class="source-icon placement">📈</div>
                        <span>Commissions sur placements</span>
                    </div>
                </td>
                <td class="center"><?= (int)($s_placements['nb_usd'] + $s_placements['nb_cdf']) ?></td>
                <td class="usd-col">$ <?= number_format($s_placements['usd'], 2, ',', ' ') ?></td>
                <td class="cdf-col"><?= number_format($s_placements['cdf'], 0, ',', ' ') ?> FC</td>
                <td class="eq-col"><?= number_format($s_placements['cdf'] + $s_placements['usd'] * $TAUX_USD_CDF, 0, ',', ' ') ?> FC</td>
            </tr>

            <!-- Virements -->
            <tr>
                <td>
                    <div class="source-name">
                        <div class="source-icon virement">🔄</div>
                        <span>Commissions de virement</span>
                    </div>
                </td>
                <td class="center"><?= (int)($s_virements['nb_usd'] + $s_virements['nb_cdf']) ?></td>
                <td class="usd-col">$ <?= number_format($s_virements['usd'], 2, ',', ' ') ?></td>
                <td class="cdf-col"><?= number_format($s_virements['cdf'], 0, ',', ' ') ?> FC</td>
                <td class="eq-col"><?= number_format($s_virements['cdf'] + $s_virements['usd'] * $TAUX_USD_CDF, 0, ',', ' ') ?> FC</td>
            </tr>

            <!-- Autres -->
            <tr>
                <td>
                    <div class="source-name">
                        <div class="source-icon autre">🏦</div>
                        <span>Autres frais</span>
                    </div>
                </td>
                <td class="center"><?= (int)($s_autres['nb_usd'] + $s_autres['nb_cdf']) ?></td>
                <td class="usd-col">$ <?= number_format($s_autres['usd'], 2, ',', ' ') ?></td>
                <td class="cdf-col"><?= number_format($s_autres['cdf'], 0, ',', ' ') ?> FC</td>
                <td class="eq-col"><?= number_format($s_autres['cdf'] + $s_autres['usd'] * $TAUX_USD_CDF, 0, ',', ' ') ?> FC</td>
            </tr>
        </tbody>
        <tfoot>
            <tr>
                <td>TOTAL GÉNÉRAL</td>
                <td class="center"><?= (int)($nb_usd_total + $nb_cdf_total) ?></td>
                <td class="usd-col">$ <?= number_format($total_usd, 2, ',', ' ') ?></td>
                <td class="cdf-col"><?= number_format($total_cdf, 0, ',', ' ') ?> FC</td>
                <td class="eq-col"><?= number_format($total_eq_cdf, 0, ',', ' ') ?> FC</td>
            </tr>
        </tfoot>
    </table>

    <!-- SECTION 2 : TOP CLIENTS -->
    <?php if (!empty($top_clients)): ?>
    <h2 class="section-title">
        <span class="num">2</span>
        Top 10 clients générateurs de revenus
    </h2>

    <table class="top-print">
        <thead>
            <tr>
                <th style="width:50px">#</th>
                <th>Client</th>
                <th>Compte</th>
                <th class="right">Nb op.</th>
                <th class="right">🇺🇸 Gains USD</th>
                <th class="right">🇨🇩 Gains CDF</th>
                <th class="right">Équivalent CDF</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($top_clients as $i => $t):
                $eq = (float)$t['gains_cdf'] + (float)$t['gains_usd'] * $TAUX_USD_CDF;
                $rank_class = $i === 0 ? 'gold' : ($i === 1 ? 'silver' : ($i === 2 ? 'bronze' : ''));
            ?>
                <tr>
                    <td><div class="rank <?= $rank_class ?>"><?= $i + 1 ?></div></td>
                    <td><strong><?= htmlspecialchars($t['nom'] . ' ' . $t['postnom']) ?></strong></td>
                    <td><code><?= htmlspecialchars($t['numero_compte']) ?></code></td>
                    <td class="right" style="color:#92400E;font-weight:800"><?= (int)$t['nb_commissions'] ?></td>
                    <td class="right usd-col">$ <?= number_format((float)$t['gains_usd'], 2, ',', ' ') ?></td>
                    <td class="right cdf-col"><?= number_format((float)$t['gains_cdf'], 0, ',', ' ') ?> FC</td>
                    <td class="right eq-col"><?= number_format($eq, 0, ',', ' ') ?> FC</td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <!-- SECTION 3 : SYNTHÈSE -->
    <h2 class="section-title">
        <span class="num">3</span>
        Synthèse et répartition
    </h2>

    <table class="sources-table">
        <thead>
            <tr>
                <th>Répartition par devise</th>
                <th class="right">Montant</th>
                <th class="right">Pourcentage</th>
                <th class="right">Équivalent CDF</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><strong>🇺🇸 Total en dollars (USD)</strong></td>
                <td class="usd-col">$ <?= number_format($total_usd, 2, ',', ' ') ?></td>
                <td class="right" style="color:#065F46;font-weight:800">
                    <?= $total_eq_cdf > 0 ? number_format(($total_usd * $TAUX_USD_CDF / $total_eq_cdf) * 100, 1) : '0,0' ?>%
                </td>
                <td class="eq-col"><?= number_format($total_usd * $TAUX_USD_CDF, 0, ',', ' ') ?> FC</td>
            </tr>
            <tr>
                <td><strong>🇨🇩 Total en francs (CDF)</strong></td>
                <td class="cdf-col"><?= number_format($total_cdf, 0, ',', ' ') ?> FC</td>
                <td class="right" style="color:#1E40AF;font-weight:800">
                    <?= $total_eq_cdf > 0 ? number_format(($total_cdf / $total_eq_cdf) * 100, 1) : '0,0' ?>%
                </td>
                <td class="eq-col"><?= number_format($total_cdf, 0, ',', ' ') ?> FC</td>
            </tr>
        </tbody>
        <tfoot>
            <tr>
                <td>TOTAL CONSOLIDÉ</td>
                <td class="right">—</td>
                <td class="right">100%</td>
                <td class="eq-col"><?= number_format($total_eq_cdf, 0, ',', ' ') ?> FC</td>
            </tr>
        </tfoot>
    </table>

    <!-- PIED DE PAGE -->
    <div class="report-footer">
        <p><strong>AFRICAN TRUST FUND</strong> — Société de Gestion d'Actifs (S.G.A)</p>
        <p>Document confidentiel — Toute reproduction non autorisée est strictement interdite.</p>
        <p style="margin-top:8px">
            Référence : <strong>ATF-GAIN-<?= date('Ymd-His') ?></strong>
            — Généré par l'administrateur #<?= $admin_id ?>
            — Le <?= date('d/m/Y à H:i:s') ?>
        </p>

        <div class="signature">
            <div class="block">
                Le Directeur Général
                <small>Signature & cachet</small>
            </div>
            <div class="block">
                Le Responsable Financier
                <small>Signature & cachet</small>
            </div>
        </div>
    </div>

</div>

<script>
/* Raccourci clavier Ctrl+P */
document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
        e.preventDefault();
        window.print();
    }
});

console.log('%c💰 Rapport Gains Système ATF', 'color:#F5B800;font-weight:bold;font-size:14px');
console.log('Total USD : $', <?= json_encode($total_usd) ?>);
console.log('Total CDF :', <?= json_encode($total_cdf) ?>, 'FC');
console.log('Équivalent :', <?= json_encode($total_eq_cdf) ?>, 'FC');
</script>

</body>
</html>
