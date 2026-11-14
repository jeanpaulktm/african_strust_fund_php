<?php
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/nombres.php';
requireRole('client');

$pdo = Database::getConnection();
$client_id = (int)$_SESSION['client_id'];

/* ============================================================
   RÉCUPÉRATION SÉCURISÉE DU TOKEN
   ============================================================ */
$id_target = null;

if (!empty($_GET['token'])) {
    $data = consommerToken('client', $_GET['token']);
    if ($data && (int)$data['id'] === $client_id) {
        $id_target = (int)$data['id'];
    }
}

if (!$id_target) {
    $id_target = $client_id;
}

/* ============================================================
   PÉRIODE PAR DÉFAUT : 12 derniers mois
   ============================================================ */
$debut = $_GET['debut'] ?? date('Y-m-d', strtotime('-12 months'));
$fin   = $_GET['fin']   ?? date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $debut)) $debut = date('Y-m-d', strtotime('-12 months'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fin))   $fin   = date('Y-m-d');

/* Taux de change (à adapter si table Parametres existe) */
$taux_usd_cdf = 2800;

/* ============================================================
   CHARGEMENT DU CLIENT
   ============================================================ */
$stmt = $pdo->prepare("SELECT * FROM Client WHERE id_client = ?");
$stmt->execute([$id_target]);
$client = $stmt->fetch();

if (!$client) die("Client introuvable.");

/* ============================================================
   🆕 SOLDES CLASSIFIÉS PAR DEVISE
   ============================================================ */
$solde_dispo_cdf       = (float)($client['solde_cdf'] ?? 0);
$solde_dispo_usd       = (float)($client['solde_usd'] ?? 0);
$epargne_investie_cdf  = (float)($client['solde_epargne_cdf'] ?? 0);
$epargne_investie_usd  = (float)($client['solde_epargne_usd'] ?? 0);

/* ============================================================
   🆕 STATISTIQUES PLACEMENTS COMPLÈTES
   ============================================================ */
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) AS nb_total,
        COUNT(CASE WHEN statut='actif' THEN 1 END) AS nb_actifs,
        COUNT(CASE WHEN statut='cloture' THEN 1 END) AS nb_clotures,
        COUNT(CASE WHEN statut='anticipe' THEN 1 END) AS nb_anticipe,
        COALESCE(SUM(montant_initial),0) AS capital_total,
        COALESCE(SUM(capital_retire),0) AS capital_retire,
        COALESCE(SUM(interets_cumules),0) AS interets_cumules,
        COALESCE(SUM(interets_retires),0) AS interets_retires
    FROM Placements
    WHERE id_client = ?
");
$stmt->execute([$id_target]);
$stats_pl = $stmt->fetch();

$capital_restant    = (float)$stats_pl['capital_total'] - (float)$stats_pl['capital_retire'];
$interets_restants  = (float)$stats_pl['interets_cumules'] - (float)$stats_pl['interets_retires'];

/* 🆕 Capital par devise */
$stmt = $pdo->prepare("
    SELECT 
        devise,
        COUNT(*) AS nb,
        COALESCE(SUM(montant_initial),0) AS capital,
        COALESCE(SUM(interets_cumules),0) AS interets,
        COALESCE(SUM(interets_cumules - interets_retires),0) AS interets_restants
    FROM Placements
    WHERE id_client = ?
    GROUP BY devise
");
$stmt->execute([$id_target]);
$capital_par_devise = [];
foreach ($stmt->fetchAll() as $r) {
    $capital_par_devise[$r['devise']] = $r;
}

$capital_cdf = (float)($capital_par_devise['CDF']['capital'] ?? 0);
$capital_usd = (float)($capital_par_devise['USD']['capital'] ?? 0);

/* 🆕 Intérêts disponibles (débloqués) */
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(interets_cumules - interets_retires), 0)
    FROM Placements
    WHERE id_client = ? AND statut = 'actif' AND date_deblocage_interets <= CURDATE()
");
$stmt->execute([$id_target]);
$interets_disponibles = (float)$stmt->fetchColumn();

/* 🆕 Intérêts bloqués (pas encore débloqués) */
$stmt = $pdo->prepare("
    SELECT 
        COALESCE(SUM(interets_cumules - interets_retires), 0) AS bloque,
        MIN(DATEDIFF(date_deblocage_interets, CURDATE())) AS jours_min,
        MAX(DATEDIFF(date_deblocage_interets, CURDATE())) AS jours_max
    FROM Placements
    WHERE id_client = ? AND statut = 'actif' AND date_deblocage_interets > CURDATE()
");
$stmt->execute([$id_target]);
$int_bloques_info = $stmt->fetch();
$interets_bloques = (float)($int_bloques_info['bloque'] ?? 0);

/* ============================================================
   TRANSACTIONS
   ============================================================ */
$stmt = $pdo->prepare("
    SELECT t.*, ca.nom AS caissier_nom, ca.postnom AS caissier_postnom
    FROM Transactions t
    LEFT JOIN Caissier ca ON ca.id_caissier = t.id_caissier
    WHERE t.id_client = ? AND DATE(t.date_transaction) BETWEEN ? AND ?
    ORDER BY t.date_transaction ASC
");
$stmt->execute([$id_target, $debut, $fin]);
$transactions = $stmt->fetchAll();

/* 🆕 Totaux transactions par type */
$total_depots = 0;
$total_retraits = 0;
$total_virements = 0;
$total_depots_cdf = 0;
$total_depots_usd = 0;
$total_retraits_cdf = 0;
$total_retraits_usd = 0;

foreach ($transactions as $t) {
    $m = (float)$t['montant'];
    $dev = $t['devise'] ?? 'CDF';
    if ($t['type_transaction'] === 'Depot') {
        $total_depots += $m;
        if ($dev === 'CDF') $total_depots_cdf += $m;
        if ($dev === 'USD') $total_depots_usd += $m;
    }
    if ($t['type_transaction'] === 'Retrait') {
        $total_retraits += $m;
        if ($dev === 'CDF') $total_retraits_cdf += $m;
        if ($dev === 'USD') $total_retraits_usd += $m;
    }
    if ($t['type_transaction'] === 'Virement') $total_virements += $m;
}

/* ============================================================
   PLACEMENTS DÉTAILLÉS
   ============================================================ */
$stmt = $pdo->prepare("
    SELECT 
        p.*, 
        pr.nom AS produit_nom, 
        pr.taux_annuel,
        DATEDIFF(CURDATE(), p.date_debut) AS jours_ecoules,
        DATEDIFF(p.date_fin, p.date_debut) AS jours_total,
        DATEDIFF(p.date_deblocage_interets, CURDATE()) AS jours_avant_deblocage
    FROM Placements p
    JOIN Produits_Epargne pr ON pr.id_produit = p.id_produit
    WHERE p.id_client = ?
    ORDER BY p.statut ASC, p.created_at DESC
");
$stmt->execute([$id_target]);
$placements = $stmt->fetchAll();

/* 🆕 Regroupement par statut */
$placements_actifs    = array_filter($placements, fn($p) => $p['statut'] === 'actif');
$placements_clotures  = array_filter($placements, fn($p) => $p['statut'] === 'cloture');
$placements_anticipes = array_filter($placements, fn($p) => $p['statut'] === 'anticipe');

/* ============================================================
   INTÉRÊTS HISTORIQUE
   ============================================================ */
$stmt = $pdo->prepare("
    SELECT ih.*, p.reference AS placement_ref, p.devise,
           pr.nom AS produit_nom
    FROM Interets_Historique ih
    JOIN Placements p ON p.id_placement = ih.id_placement
    JOIN Produits_Epargne pr ON pr.id_produit = p.id_produit
    WHERE p.id_client = ? AND DATE(ih.date_calcul) BETWEEN ? AND ?
    ORDER BY ih.date_calcul ASC
");
$stmt->execute([$id_target, $debut, $fin]);
$interets = $stmt->fetchAll();

$total_interets_periode = array_sum(array_map(fn($i) => (float)$i['montant_interet'], $interets));

/* 🆕 Intérêts cumulés par placement */
$stmt = $pdo->prepare("
    SELECT 
        p.id_placement,
        p.reference,
        p.devise,
        pr.nom AS produit_nom,
        p.montant_initial,
        p.interets_cumules,
        p.interets_retires,
        p.date_debut,
        p.date_fin,
        p.statut
    FROM Placements p
    JOIN Produits_Epargne pr ON pr.id_produit = p.id_produit
    WHERE p.id_client = ? AND p.statut = 'actif'
    ORDER BY p.interets_cumules DESC
");
$stmt->execute([$id_target]);
$interets_par_placement = $stmt->fetchAll();

/* ============================================================
   COMMISSIONS
   ============================================================ */
$stmt = $pdo->prepare("
    SELECT * FROM Commissions
    WHERE id_client = ? AND DATE(date_commission) BETWEEN ? AND ?
    ORDER BY date_commission ASC
");
$stmt->execute([$id_target, $debut, $fin]);
$commissions = $stmt->fetchAll();

$total_commissions = array_sum(array_map(fn($c) => (float)$c['montant_commission'], $commissions));

/* ============================================================
   🆕 SYNTHÈSE PATRIMONIALE
   ============================================================ */
$patrimoine_cdf = $solde_dispo_cdf + $capital_cdf + $interets_restants;
$patrimoine_usd = $solde_dispo_usd + $capital_usd;
$patrimoine_total_equiv_cdf = $patrimoine_cdf + ($patrimoine_usd * $taux_usd_cdf);

$base = '/african-trust-fund';

/* Audit */
logAction($pdo, 'client', $client_id, 'RELEVE_PRINT',
    "Période: $debut → $fin | " . count($transactions) . " tx | " . count($placements) . " placements");
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Relevé de compte <?= htmlspecialchars($client['numero_compte']) ?> — ATF</title>
<style>
@page { size: A4; margin: 10mm 12mm; }
* { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
  color: #1a1a1a;
  background: #e8e8e8;
  padding: 30px 20px;
  font-size: 10px;
  line-height: 1.4;
}

.document {
  max-width: 950px;
  margin: 0 auto;
  background: #fff;
  padding: 30px 40px;
  box-shadow: 0 8px 40px rgba(0,0,0,.18);
  border-radius: 4px;
  border-top: 6px solid #0D1B5E;
}

/* ============================================================
   EN-TÊTE
   ============================================================ */
.official-header {
  display: grid;
  grid-template-columns: 1fr 1.4fr;
  gap: 20px;
  align-items: center;
  padding-bottom: 14px;
  border-bottom: 3px double #0D1B5E;
  margin-bottom: 12px;
}
.rdc-block { text-align: center; font-size: 9px; line-height: 1.5; color: #0D1B5E; }
.rdc-block .rep { font-weight: 900; font-size: 10px; letter-spacing: 1px; text-transform: uppercase; }
.rdc-block .devise { font-style: italic; font-size: 9px; color: #444; margin-top: 2px; }
.rdc-block .ministere { font-weight: 700; font-size: 9px; margin-top: 4px; }

.atf-header-block { display: flex; align-items: center; gap: 14px; justify-content: flex-end; }
.atf-logo-official {
  width: 65px; height: 65px; object-fit: contain;
  border: 3px solid #F5B800; border-radius: 50%;
  padding: 4px; background: #fff;
  box-shadow: 0 0 0 2px #0D1B5E inset;
}
.atf-brand-info { text-align: right; }
.atf-brand-name { font-size: 20px; font-weight: 900; color: #0D1B5E; letter-spacing: 2px; line-height: 1.1; }
.atf-brand-slogan { font-size: 9px; color: #666; font-style: italic; margin-top: 3px; }
.atf-brand-legal { font-size: 8px; color: #888; margin-top: 2px; }

.separator-line {
  height: 2px;
  background: linear-gradient(90deg, #0D1B5E, #F5B800, #0D1B5E);
  margin: 10px 0;
}

.legal-band {
  display: flex; justify-content: space-between; flex-wrap: wrap; gap: 6px;
  padding: 6px 12px;
  background: linear-gradient(90deg, #0D1B5E, #1E293B);
  color: #fff; font-size: 8px; border-radius: 4px; margin-bottom: 14px;
}
.legal-band strong { color: #F5B800; }

/* TITRE */
.doc-title { text-align: center; margin: 20px 0 8px; }
.doc-title h1 {
  font-size: 16px; color: #0D1B5E; font-weight: 900;
  letter-spacing: 3px; text-transform: uppercase;
  padding-bottom: 6px; border-bottom: 3px solid #F5B800;
  display: inline-block; min-width: 60%;
}
.doc-title .subtitle { font-size: 10px; color: #666; margin-top: 6px; font-style: italic; }

.periode-badge {
  text-align: center; padding: 10px; margin: 16px 0 20px;
  background: #FFF8E1; border-left: 4px solid #F5B800;
  border-radius: 6px; font-size: 11px; color: #0D1B5E; font-weight: 700;
}

/* INFO CLIENT */
.info-grid {
  display: grid; grid-template-columns: 1fr 1fr;
  gap: 14px; margin-bottom: 20px;
}
.info-block {
  border: 1px solid #e5e5e5; border-radius: 8px;
  padding: 12px 14px; background: #fafafa;
}
.info-block h3 {
  font-size: 10px; color: #0D1B5E; text-transform: uppercase;
  letter-spacing: 1px; margin-bottom: 8px;
  padding-bottom: 5px; border-bottom: 1px solid #ddd;
}
.info-block table { width: 100%; border-collapse: collapse; font-size: 10px; }
.info-block td { padding: 4px 0; vertical-align: top; }
.info-block td:first-child { color: #666; width: 42%; }
.info-block td:last-child { font-weight: 700; color: #1a1a1a; }

/* ============================================================
   🆕 SYNTHÈSE PATRIMONIALE
   ============================================================ */
.patrimoine-box {
  padding: 18px 22px;
  background: linear-gradient(135deg, rgba(13,27,94,.06), rgba(245,184,0,.06));
  border: 2px solid #F5B800;
  border-radius: 10px;
  margin: 20px 0;
  position: relative;
}
.patrimoine-box::before {
  content: "💎 PATRIMOINE TOTAL";
  position: absolute;
  top: -10px; left: 20px;
  background: #fff;
  padding: 0 10px;
  color: #0D1B5E;
  font-size: 9px;
  font-weight: 900;
  letter-spacing: 1.5px;
}
.patrimoine-total {
  text-align: center;
  margin-bottom: 16px;
  padding-bottom: 14px;
  border-bottom: 1px dashed #F5B800;
}
.patrimoine-total .val {
  font-size: 24px;
  font-weight: 900;
  color: #0D1B5E;
  letter-spacing: -.5px;
}
.patrimoine-total .dev {
  font-size: 12px;
  color: #B8860B;
  margin-left: 6px;
  font-weight: 700;
}
.patrimoine-total .equiv {
  font-size: 10px;
  color: #666;
  margin-top: 4px;
}

.patrimoine-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 12px;
}
.patrimoine-item {
  padding: 12px 14px;
  background: #fff;
  border-radius: 8px;
  border-left: 4px solid #0D1B5E;
  box-shadow: 0 2px 6px rgba(0,0,0,.04);
}
.patrimoine-item.dispo { border-left-color: #059669; }
.patrimoine-item.investi { border-left-color: #8B5CF6; }
.patrimoine-item.interets { border-left-color: #10B981; }
.patrimoine-item .lbl {
  font-size: 8px;
  color: #666;
  text-transform: uppercase;
  letter-spacing: 1px;
  font-weight: 700;
  margin-bottom: 4px;
}
.patrimoine-item .val {
  font-size: 14px;
  font-weight: 900;
  color: #0D1B5E;
}
.patrimoine-item .val.green { color: #059669; }
.patrimoine-item .val.purple { color: #7C3AED; }
.patrimoine-item .sub {
  font-size: 8px;
  color: #888;
  margin-top: 3px;
}

/* ============================================================
   🆕 SOLDES PAR DEVISE
   ============================================================ */
.soldes-devise-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
  margin: 20px 0;
}
.solde-devise-card {
  padding: 16px 18px;
  border-radius: 10px;
  border: 2px solid #e5e5e5;
}
.solde-devise-card.cdf {
  background: rgba(245,184,0,.06);
  border-color: rgba(245,184,0,.4);
}
.solde-devise-card.usd {
  background: rgba(59,130,246,.06);
  border-color: rgba(59,130,246,.4);
}
.solde-devise-card .flag {
  font-size: 22px;
  display: inline-block;
  margin-right: 6px;
  vertical-align: middle;
}
.solde-devise-card .dev-label {
  font-size: 11px;
  font-weight: 900;
  color: #0D1B5E;
  letter-spacing: 1px;
  vertical-align: middle;
}
.solde-devise-card table {
  width: 100%;
  border-collapse: collapse;
  margin-top: 12px;
}
.solde-devise-card table td {
  padding: 6px 0;
  border-bottom: 1px dashed #e5e5e5;
  font-size: 10px;
}
.solde-devise-card table td:first-child {
  color: #666;
}
.solde-devise-card table td:last-child {
  font-weight: 800;
  text-align: right;
  color: #0D1B5E;
}
.solde-devise-card table tr.total {
  border-top: 2px solid #0D1B5E;
}
.solde-devise-card table tr.total td {
  font-size: 11px;
  padding-top: 10px;
  border-bottom: none;
}
.solde-devise-card table tr.total td:last-child {
  font-size: 13px;
  color: #059669;
}
.solde-devise-card table tr.epargne td:last-child { color: #7C3AED; }

/* KPIs */
.kpi-grid {
  display: grid; grid-template-columns: repeat(4, 1fr);
  gap: 10px; margin: 20px 0;
}
.kpi-box {
  padding: 14px 12px; border-radius: 8px;
  background: #f9f9f9; border: 1px solid #e5e5e5;
  text-align: center;
}
.kpi-box.green { background: rgba(16,185,129,.08); border-color: rgba(16,185,129,.4); }
.kpi-box.red { background: rgba(239,68,68,.08); border-color: rgba(239,68,68,.4); }
.kpi-box.gold { background: rgba(245,184,0,.08); border-color: rgba(245,184,0,.4); }
.kpi-box.purple { background: rgba(139,92,246,.08); border-color: rgba(139,92,246,.4); }
.kpi-box .lbl { font-size: 8px; color: #666; text-transform: uppercase; letter-spacing: 1px; font-weight: 700; }
.kpi-box .val { font-size: 15px; font-weight: 900; color: #0D1B5E; margin-top: 4px; }
.kpi-box.green .val { color: #059669; }
.kpi-box.red .val { color: #DC2626; }
.kpi-box.gold .val { color: #B8860B; }
.kpi-box.purple .val { color: #7C3AED; }

/* SECTION */
.section-title {
  font-size: 11px; color: #0D1B5E; text-transform: uppercase;
  letter-spacing: 1.5px; font-weight: 800;
  margin: 22px 0 10px; padding-bottom: 6px;
  border-bottom: 2px solid #F5B800;
}

/* TABLEAU */
table.data {
  width: 100%; border-collapse: collapse; font-size: 9px;
}
table.data thead { background: #0D1B5E; color: #fff; }
table.data th {
  padding: 8px 6px; text-align: left; font-weight: 700;
  font-size: 8px; text-transform: uppercase; letter-spacing: .5px;
}
table.data td { padding: 7px 6px; border-bottom: 1px solid #e5e5e5; }
table.data tbody tr:nth-child(even) { background: #fafafa; }
table.data .pos { color: #059669; font-weight: 700; }
table.data .neg { color: #DC2626; font-weight: 700; }
table.data .empty {
  text-align: center; color: #999; font-style: italic; padding: 20px;
}
table.data tfoot {
  background: #FFF8E1; font-weight: 800; color: #0D1B5E;
}
table.data tfoot td {
  border-top: 2px solid #F5B800; padding: 10px 6px; font-size: 10px;
}

/* 🆕 STATUT BADGES */
.statut-badge {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 8px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: .5px;
}
.statut-badge.actif { background: rgba(16,185,129,.15); color: #059669; border: 1px solid rgba(16,185,129,.4); }
.statut-badge.cloture { background: rgba(107,114,128,.15); color: #4B5563; border: 1px solid rgba(107,114,128,.4); }
.statut-badge.anticipe { background: rgba(245,184,0,.15); color: #B8860B; border: 1px solid rgba(245,184,0,.4); }

/* 🆕 PROGRESSION BAR */
.progress-mini {
  height: 6px;
  background: #e5e5e5;
  border-radius: 3px;
  overflow: hidden;
  margin-top: 4px;
  position: relative;
}
.progress-mini > div {
  height: 100%;
  background: linear-gradient(90deg, #8B5CF6, #A78BFA);
  border-radius: 3px;
}
.progress-mini.debloque > div {
  background: linear-gradient(90deg, #059669, #10B981);
}

/* PIED */
.print-footer {
  margin-top: 26px; padding-top: 12px;
  border-top: 3px double #F5B800;
  font-size: 8px; color: #555;
}
.footer-grid {
  display: grid; grid-template-columns: 1fr 1fr;
  gap: 14px; margin-bottom: 10px;
}
.footer-block {
  padding: 8px 12px; background: #f9f9f9;
  border-radius: 6px; border-left: 3px solid #F5B800;
  line-height: 1.6;
}
.footer-block strong {
  color: #0D1B5E; display: block; margin-bottom: 3px;
  font-size: 9px; text-transform: uppercase; letter-spacing: 1px;
}
.footer-note {
  text-align: center; color: #888; font-size: 7px;
  padding-top: 8px; border-top: 1px solid #e5e5e5; line-height: 1.6;
}

/* BARRE ACTIONS */
.action-bar {
  position: fixed; top: 20px; right: 20px;
  display: flex; gap: 10px; z-index: 9999;
  background: rgba(11,14,20,.9);
  padding: 10px 12px; border-radius: 999px;
  backdrop-filter: blur(12px);
  box-shadow: 0 8px 32px rgba(0,0,0,.5);
  border: 1px solid rgba(245,184,0,.25);
  flex-wrap: wrap;
}
.btn-action {
  padding: 11px 20px; border-radius: 999px; border: none;
  font-weight: 700; cursor: pointer; font-size: 12px;
  display: inline-flex; align-items: center; gap: 6px;
  text-decoration: none; transition: all .25s;
  font-family: inherit; white-space: nowrap;
}
.btn-action:hover { transform: translateY(-2px); }
.btn-print {
  background: linear-gradient(135deg, #F5B800, #D4AF37);
  color: #0B0E14;
  box-shadow: 0 6px 20px rgba(245,184,0,.45);
}
.btn-back {
  background: rgba(255,255,255,.1); color: #E8ECF3;
  border: 1px solid rgba(255,255,255,.2);
}
.btn-period {
  background: rgba(59,130,246,.9); color: #fff;
}
.btn-csv {
  background: rgba(16,185,129,.9); color: #fff;
}

.date-input {
  padding: 9px 14px; border-radius: 999px;
  background: rgba(255,255,255,.1);
  border: 1px solid rgba(255,255,255,.2);
  color: #E8ECF3; font-weight: 700; font-size: 11px;
  font-family: inherit;
}
.date-input::-webkit-calendar-picker-indicator { filter: invert(1); cursor: pointer; }

/* IMPRESSION */
@media print {
  html, body { background: #fff !important; padding: 0 !important; margin: 0 !important; }
  .document { box-shadow: none; border-radius: 0; padding: 0; max-width: 100%; }
  .action-bar { display: none !important; }
  * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
  .section-title, table.data thead, .kpi-box, .legal-band, .patrimoine-box, .solde-devise-card {
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
  }
  table.data thead { display: table-header-group; }
  tr { page-break-inside: avoid; }
  .patrimoine-box { page-break-inside: avoid; }
}

@media screen and (max-width: 768px) {
  body { padding: 20px 10px; }
  .document { padding: 20px 16px; }
  .official-header { grid-template-columns: 1fr; text-align: center; }
  .atf-header-block { justify-content: center; }
  .atf-brand-info { text-align: center; }
  .info-grid, .footer-grid, .soldes-devise-grid { grid-template-columns: 1fr; }
  .kpi-grid, .patrimoine-grid { grid-template-columns: 1fr 1fr; }
  .patrimoine-total .val { font-size: 18px; }
  .action-bar { top: auto; bottom: 15px; left: 10px; right: 10px; justify-content: center; border-radius: 20px; }
  .btn-action { font-size: 11px; padding: 9px 14px; }
}
</style>
</head>
<body>

<!-- BARRE D'ACTIONS -->
<div class="action-bar">
  <a class="btn-action btn-back" href="<?= $base ?>/client/dashboard.php">← Retour</a>
  <input type="date" class="date-input" id="dateDebut" value="<?= htmlspecialchars($debut) ?>">
  <input type="date" class="date-input" id="dateFin" value="<?= htmlspecialchars($fin) ?>">
  <button class="btn-action btn-period" onclick="applyPeriod()" type="button">📅 Appliquer</button>
  <button class="btn-action btn-csv" onclick="exportCSV()" type="button">📥 CSV</button>
  <button class="btn-action btn-print" onclick="window.print()" type="button">🖨️ Imprimer</button>
</div>

<!-- DOCUMENT -->
<div class="document">

  <!-- EN-TÊTE -->
  <div class="official-header">
    <div class="rdc-block">
      <div class="rep">République Démocratique du Congo</div>
      <div class="devise">« Justice – Paix – Travail »</div>
      <div class="ministere">Ministère des Finances</div>
      <div style="font-size:8px;color:#666;margin-top:2px">Direction Générale des Institutions Financières</div>
    </div>
    <div class="atf-header-block">
      <div class="atf-brand-info">
        <div class="atf-brand-name">AFRICAN TRUST FUND</div>
        <div class="atf-brand-slogan">« L'argent de l'Afrique pour construire l'Afrique »</div>
        <div class="atf-brand-legal">Société de Gestion d'Actifs (S.G.A)</div>
      </div>
      <img src="<?= $base ?>/assets/img/logo.png" alt="ATF" class="atf-logo-official"
           onerror="this.src='data:image/svg+xml;utf8,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><circle cx=%2250%22 cy=%2250%22 r=%2245%22 fill=%22%23F5B800%22/><text x=%2250%22 y=%2262%22 font-size=%2234%22 text-anchor=%22middle%22 fill=%22%230B0E14%22 font-weight=%22bold%22 font-family=%22Arial%22>ATF</text></svg>'">
    </div>
  </div>

  <div class="separator-line"></div>

  <div class="legal-band">
    <span><strong>RCCM :</strong> CD/KIN/RCCM/24-B-01234</span>
    <span><strong>ID Nat :</strong> 01-234-N56789A</span>
    <span><strong>NIF :</strong> A0123456Z</span>
    <span><strong>Agrément BCC :</strong> 045/2024</span>
  </div>

  <!-- TITRE -->
  <div class="doc-title">
    <h1>Relevé de Compte</h1>
    <div class="subtitle">Édité le <?= date('d/m/Y à H:i') ?> · Document officiel</div>
  </div>

  <div class="periode-badge">
    📅 Période du <strong><?= date('d/m/Y', strtotime($debut)) ?></strong> au <strong><?= date('d/m/Y', strtotime($fin)) ?></strong>
  </div>

  <!-- ============================================================
       INFO CLIENT
       ============================================================ -->
  <div class="info-grid">
    <div class="info-block">
      <h3>👤 Titulaire du compte</h3>
      <table>
        <tr><td>Nom complet</td><td><?= htmlspecialchars($client['nom'] . ' ' . $client['postnom'] . ' ' . $client['prenom']) ?></td></tr>
        <tr><td>N° de compte</td><td><strong><?= htmlspecialchars($client['numero_compte']) ?></strong></td></tr>
        <tr><td>Téléphone</td><td><?= htmlspecialchars($client['numero_phone']) ?></td></tr>
        <tr><td>Adresse</td><td><?= htmlspecialchars(mb_substr($client['adresse_complete'] ?? '—', 0, 60)) ?></td></tr>
        <tr><td>Client depuis</td><td><?= date('d/m/Y', strtotime($client['created_at'])) ?></td></tr>
      </table>
    </div>
    <div class="info-block">
      <h3>💼 Statut du compte</h3>
      <table>
        <tr><td>Statut</td><td>
          <span class="statut-badge <?= strtolower($client['statut']) ?>">
            <?= strtoupper(htmlspecialchars($client['statut'])) ?>
          </span>
        </td></tr>
        <tr><td>Solde disponible CDF</td><td style="color:#059669"><?= number_format($solde_dispo_cdf, 2, ',', ' ') ?> CDF</td></tr>
        <tr><td>Solde disponible USD</td><td style="color:#3B82F6">$ <?= number_format($solde_dispo_usd, 2, '.', ',') ?></td></tr>
        <tr><td>Nb placements actifs</td><td><?= (int)$stats_pl['nb_actifs'] ?></td></tr>
        <tr><td>Devise principale</td><td><?= htmlspecialchars($client['devise'] ?? 'CDF') ?></td></tr>
      </table>
    </div>
  </div>

  <!-- ============================================================
       🆕 SYNTHÈSE PATRIMONIALE
       ============================================================ -->
  <div class="patrimoine-box">
    <div class="patrimoine-total">
      <div class="val">
        <?= number_format($patrimoine_total_equiv_cdf, 0, ',', ' ') ?>
        <span class="dev">CDF</span>
      </div>
      <?php if ($patrimoine_usd > 0): ?>
        <div class="equiv">≈ $ <?= number_format($patrimoine_total_equiv_cdf / $taux_usd_cdf, 0, '.', ',') ?> USD (taux: <?= number_format($taux_usd_cdf, 0, ',', ' ') ?>)</div>
      <?php endif; ?>
    </div>

    <div class="patrimoine-grid">
      <div class="patrimoine-item dispo">
        <div class="lbl">💰 Disponible</div>
        <div class="val green"><?= number_format($solde_dispo_cdf + ($solde_dispo_usd * $taux_usd_cdf), 0, ',', ' ') ?></div>
        <div class="sub">Solde utilisable immédiatement</div>
      </div>
      <div class="patrimoine-item investi">
        <div class="lbl">💎 Capital investi</div>
        <div class="val purple"><?= number_format($capital_cdf + ($capital_usd * $taux_usd_cdf), 0, ',', ' ') ?></div>
        <div class="sub"><?= (int)$stats_pl['nb_actifs'] ?> placement(s) en cours</div>
      </div>
      <div class="patrimoine-item interets">
        <div class="lbl">📈 Intérêts générés</div>
        <div class="val green">+<?= number_format($interets_restants, 0, ',', ' ') ?></div>
        <div class="sub"><?= $interets_disponibles > 0 ? 'dont ' . number_format($interets_disponibles, 0, ',', ' ') . ' dispo' : 'Bloqués' ?></div>
      </div>
    </div>
  </div>

  <!-- ============================================================
       🆕 SOLDES PAR DEVISE (CDF & USD)
       ============================================================ -->
  <div class="section-title">💰 Détail des soldes par devise</div>

  <div class="soldes-devise-grid">
    <!-- CDF -->
    <div class="solde-devise-card cdf">
      <span class="flag">🇨🇩</span>
      <span class="dev-label">FRANCS CONGOLAIS (CDF)</span>
      <table>
        <tr>
          <td>💵 Solde disponible</td>
          <td><?= number_format($solde_dispo_cdf, 2, ',', ' ') ?></td>
        </tr>
        <tr class="epargne">
          <td>💎 Investi en épargne</td>
          <td><?= number_format($capital_cdf, 2, ',', ' ') ?></td>
        </tr>
        <tr>
          <td>📈 Intérêts disponibles</td>
          <td style="color:#059669">
            <?php
              // Intérêts disponibles en CDF uniquement
              $stmt_ic = $pdo->prepare("
                  SELECT COALESCE(SUM(interets_cumules - interets_retires), 0)
                  FROM Placements
                  WHERE id_client = ? AND devise = 'CDF' 
                    AND statut = 'actif' AND date_deblocage_interets <= CURDATE()
              ");
              $stmt_ic->execute([$id_target]);
              echo number_format((float)$stmt_ic->fetchColumn(), 2, ',', ' ');
            ?>
          </td>
        </tr>
        <tr>
          <td>🔒 Intérêts bloqués</td>
          <td style="color:#B8860B">
            <?php
              $stmt_ib = $pdo->prepare("
                  SELECT COALESCE(SUM(interets_cumules - interets_retires), 0)
                  FROM Placements
                  WHERE id_client = ? AND devise = 'CDF' 
                    AND statut = 'actif' AND date_deblocage_interets > CURDATE()
              ");
              $stmt_ib->execute([$id_target]);
              echo number_format((float)$stmt_ib->fetchColumn(), 2, ',', ' ');
            ?>
          </td>
        </tr>
        <tr class="total">
          <td>TOTAL PATRIMOINE CDF</td>
          <td><?= number_format($solde_dispo_cdf + $capital_cdf + $interets_restants, 2, ',', ' ') ?></td>
        </tr>
      </table>
    </div>

    <!-- USD -->
    <div class="solde-devise-card usd">
      <span class="flag">🇺🇸</span>
      <span class="dev-label">DOLLARS AMÉRICAINS (USD)</span>
      <table>
        <tr>
          <td>💵 Solde disponible</td>
          <td>$ <?= number_format($solde_dispo_usd, 2, '.', ',') ?></td>
        </tr>
        <tr class="epargne">
          <td>💎 Investi en épargne</td>
          <td>$ <?= number_format($capital_usd, 2, '.', ',') ?></td>
        </tr>
        <tr>
          <td>📈 Intérêts disponibles</td>
          <td style="color:#059669">
            <?php
              $stmt_icusd = $pdo->prepare("
                  SELECT COALESCE(SUM(interets_cumules - interets_retires), 0)
                  FROM Placements
                  WHERE id_client = ? AND devise = 'USD' 
                    AND statut = 'actif' AND date_deblocage_interets <= CURDATE()
              ");
              $stmt_icusd->execute([$id_target]);
              echo '$ ' . number_format((float)$stmt_icusd->fetchColumn(), 2, '.', ',');
            ?>
          </td>
        </tr>
        <tr>
          <td>🔒 Intérêts bloqués</td>
          <td style="color:#B8860B">
            <?php
              $stmt_ibusd = $pdo->prepare("
                  SELECT COALESCE(SUM(interets_cumules - interets_retires), 0)
                  FROM Placements
                  WHERE id_client = ? AND devise = 'USD' 
                    AND statut = 'actif' AND date_deblocage_interets > CURDATE()
              ");
              $stmt_ibusd->execute([$id_target]);
              echo '$ ' . number_format((float)$stmt_ibusd->fetchColumn(), 2, '.', ',');
            ?>
          </td>
        </tr>
        <tr class="total">
          <td>TOTAL PATRIMOINE USD</td>
          <td>$ <?= number_format($solde_dispo_usd + $capital_usd, 2, '.', ',') ?></td>
        </tr>
      </table>
    </div>
  </div>

  <!-- ============================================================
       KPIs PÉRIODE
       ============================================================ -->
  <div class="kpi-grid">
    <div class="kpi-box green">
      <div class="lbl">Total dépôts</div>
      <div class="val">+<?= number_format($total_depots, 0, ',', ' ') ?></div>
    </div>
    <div class="kpi-box red">
      <div class="lbl">Total retraits</div>
      <div class="val">−<?= number_format($total_retraits, 0, ',', ' ') ?></div>
    </div>
    <div class="kpi-box gold">
      <div class="lbl">Intérêts gagnés (période)</div>
      <div class="val">+<?= number_format($total_interets_periode, 0, ',', ' ') ?></div>
    </div>
    <div class="kpi-box purple">
      <div class="lbl">Nb placements</div>
      <div class="val"><?= (int)$stats_pl['nb_total'] ?></div>
    </div>
  </div>

  <!-- ============================================================
       🆕 PLACEMENTS DÉTAILLÉS AVEC PROGRESSION
       ============================================================ -->
  <?php if (!empty($placements)): ?>
    <div class="section-title">💎 Mes placements d'épargne (<?= count($placements) ?>)</div>
    <table class="data">
      <thead>
        <tr>
          <th>Référence</th>
          <th>Produit</th>
          <th style="text-align:right">Capital</th>
          <th style="text-align:right">Intérêts</th>
          <th style="text-align:right">Dispo</th>
          <th>Progression</th>
          <th>Statut</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($placements as $p):
          $jours_ecoules = max(0, (int)$p['jours_ecoules']);
          $jours_total   = max(1, (int)$p['jours_total']);
          $progress      = min(100, ($jours_ecoules / $jours_total) * 100);
          $debloque      = (int)$p['jours_avant_deblocage'] <= 0;
          $int_dispo     = (float)$p['interets_cumules'] - (float)$p['interets_retires'];
          $dev           = $p['devise'];
          $sym           = $dev === 'USD' ? '$ ' : '';
        ?>
          <tr>
            <td><strong><?= htmlspecialchars($p['reference']) ?></strong></td>
            <td><?= htmlspecialchars($p['produit_nom']) ?></td>
            <td style="text-align:right">
              <?= $sym ?><?= number_format((float)$p['montant_initial'], 2, $dev === 'USD' ? '.' : ',', $dev === 'USD' ? ',' : ' ') ?>
              <?= $dev === 'CDF' ? ' CDF' : '' ?>
            </td>
            <td style="text-align:right" class="pos">
              +<?= number_format((float)$p['interets_cumules'], 2, $dev === 'USD' ? '.' : ',', $dev === 'USD' ? ',' : ' ') ?>
            </td>
            <td style="text-align:right;font-weight:700;color:#059669">
              <?= number_format($int_dispo, 2, $dev === 'USD' ? '.' : ',', $dev === 'USD' ? ',' : ' ') ?>
            </td>
            <td style="min-width:90px">
              <div class="progress-mini <?= $debloque ? 'debloque' : '' ?>">
                <div style="width:<?= $progress ?>%"></div>
              </div>
              <div style="font-size:7px;color:#888;margin-top:2px">
                <?php if ($debloque): ?>
                  ✅ Intérêts débloqués
                <?php else: ?>
                  🔒 <?= (int)$p['jours_avant_deblocage'] ?>j restants
                <?php endif; ?>
              </div>
            </td>
            <td>
              <span class="statut-badge <?= $p['statut'] ?>">
                <?= htmlspecialchars($p['statut']) ?>
              </span>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="2" style="text-align:right">TOTAUX</td>
          <td style="text-align:right"><?= number_format($capital_cdf, 0, ',', ' ') ?> CDF + $ <?= number_format($capital_usd, 2, '.', ',') ?></td>
          <td style="text-align:right;color:#059669">+<?= number_format((float)$stats_pl['interets_cumules'], 0, ',', ' ') ?></td>
          <td style="text-align:right;color:#059669"><?= number_format($interets_restants, 0, ',', ' ') ?></td>
          <td colspan="2"></td>
        </tr>
      </tfoot>
    </table>
  <?php endif; ?>

  <!-- ============================================================
       🆕 INTÉRÊTS PAR PLACEMENT (ACTIFS)
       ============================================================ -->
  <?php if (!empty($interets_par_placement)): ?>
    <div class="section-title">📈 Intérêts cumulés par placement</div>
    <table class="data">
      <thead>
        <tr>
          <th>Référence</th>
          <th>Produit</th>
          <th>Devise</th>
          <th style="text-align:right">Capital</th>
          <th style="text-align:right">Intérêts cumulés</th>
          <th style="text-align:right">Intérêts retirés</th>
          <th style="text-align:right">Solde intérêts</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($interets_par_placement as $ip):
          $dev = $ip['devise'];
          $solde_int = (float)$ip['interets_cumules'] - (float)$ip['interets_retires'];
        ?>
          <tr>
            <td><strong><?= htmlspecialchars($ip['reference']) ?></strong></td>
            <td><?= htmlspecialchars($ip['produit_nom']) ?></td>
            <td><strong><?= htmlspecialchars($dev) ?></strong></td>
            <td style="text-align:right"><?= number_format((float)$ip['montant_initial'], 2, ',', ' ') ?></td>
            <td style="text-align:right" class="pos">+<?= number_format((float)$ip['interets_cumules'], 2, ',', ' ') ?></td>
            <td style="text-align:right;color:#888"><?= number_format((float)$ip['interets_retires'], 2, ',', ' ') ?></td>
            <td style="text-align:right;font-weight:800;color:#059669"><?= number_format($solde_int, 2, ',', ' ') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <!-- ============================================================
       TRANSACTIONS
       ============================================================ -->
  <div class="section-title">📊 Détail des transactions (<?= count($transactions) ?>)</div>
  <table class="data">
    <thead>
      <tr>
        <th>Date</th>
        <th>Référence</th>
        <th>Type</th>
        <th style="text-align:right">Montant</th>
        <th>Devise</th>
        <th style="text-align:right">Solde après</th>
        <th>Mode</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($transactions)): ?>
        <tr><td colspan="7" class="empty">Aucune transaction sur cette période.</td></tr>
      <?php else: foreach ($transactions as $t): ?>
        <tr>
          <td><?= date('d/m/Y H:i', strtotime($t['date_transaction'])) ?></td>
          <td><strong><?= htmlspecialchars($t['reference']) ?></strong></td>
          <td><?= htmlspecialchars($t['type_transaction']) ?></td>
          <td style="text-align:right" class="<?= $t['type_transaction'] === 'Retrait' ? 'neg' : 'pos' ?>">
            <?= $t['type_transaction'] === 'Retrait' ? '−' : '+' ?>
            <?= number_format((float)$t['montant'], 2, ',', ' ') ?>
          </td>
          <td><?= htmlspecialchars($t['devise'] ?? $client['devise'] ?? 'CDF') ?></td>
          <td style="text-align:right;font-weight:700">
            <?= number_format((float)$t['solde_apres'], 2, ',', ' ') ?>
          </td>
          <td><?= htmlspecialchars($t['mode']) ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
    <?php if (!empty($transactions)): ?>
    <tfoot>
      <tr>
        <td colspan="3" style="text-align:right">TOTAUX</td>
        <td style="text-align:right;color:#059669">+<?= number_format($total_depots, 0, ',', ' ') ?></td>
        <td style="text-align:right;color:#DC2626">−<?= number_format($total_retraits, 0, ',', ' ') ?></td>
        <td colspan="2"></td>
      </tr>
    </tfoot>
    <?php endif; ?>
  </table>

  <!-- ============================================================
       INTÉRÊTS HISTORIQUE
       ============================================================ -->
  <?php if (!empty($interets)): ?>
    <div class="section-title">📅 Historique des intérêts crédités (<?= count($interets) ?>)</div>
    <table class="data">
      <thead>
        <tr>
          <th>Date</th>
          <th>Placement</th>
          <th>Produit</th>
          <th>Devise</th>
          <th style="text-align:right">Intérêt</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($interets as $i): ?>
          <tr>
            <td><?= date('d/m/Y', strtotime($i['date_calcul'])) ?></td>
            <td><?= htmlspecialchars($i['placement_ref']) ?></td>
            <td><?= htmlspecialchars($i['produit_nom']) ?></td>
            <td><?= htmlspecialchars($i['devise'] ?? 'CDF') ?></td>
            <td style="text-align:right" class="pos">+<?= number_format((float)$i['montant_interet'], 2, ',', ' ') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="4" style="text-align:right">TOTAL INTÉRÊTS PÉRIODE</td>
          <td style="text-align:right;color:#059669">+<?= number_format($total_interets_periode, 2, ',', ' ') ?></td>
        </tr>
      </tfoot>
    </table>
  <?php endif; ?>

  <!-- ============================================================
       COMMISSIONS
       ============================================================ -->
  <?php if (!empty($commissions)): ?>
    <div class="section-title">💵 Commissions prélevées (<?= count($commissions) ?>)</div>
    <table class="data">
      <thead>
        <tr>
          <th>Date</th>
          <th style="text-align:right">Montant brut</th>
          <th style="text-align:right">Taux</th>
          <th style="text-align:right">Commission</th>
          <th style="text-align:right">Net versé</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($commissions as $c): ?>
          <tr>
            <td><?= date('d/m/Y H:i', strtotime($c['date_commission'])) ?></td>
            <td style="text-align:right"><?= number_format((float)$c['montant_brut'], 2, ',', ' ') ?></td>
            <td style="text-align:right"><?= number_format((float)$c['taux_commission'] * 100, 2) ?>%</td>
            <td style="text-align:right" class="neg"><?= number_format((float)$c['montant_commission'], 2, ',', ' ') ?></td>
            <td style="text-align:right"><?= number_format((float)$c['montant_net'], 2, ',', ' ') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="3" style="text-align:right">TOTAL COMMISSIONS</td>
          <td style="text-align:right;color:#DC2626"><?= number_format($total_commissions, 2, ',', ' ') ?></td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  <?php endif; ?>

  <!-- ============================================================
       PIED DE PAGE
       ============================================================ -->
  <div class="print-footer">
    <div class="footer-grid">
      <div class="footer-block">
        <strong>🏦 Coordonnées bancaires</strong>
        Rawbank — Agence BENI VILLE<br>
        Compte CDF : <strong>00012-12345-6789012-34</strong><br>
        Compte USD : <strong>00012-98765-4321098-76</strong><br>
        IBAN : CD12 0001 2123 4567 8901 2345<br>
        SWIFT : <strong>RAWBCDKI</strong>
      </div>
      <div class="footer-block">
        <strong>📞 Contact</strong>
        Avenue de la Paix n°45, Commune BEU<br>
        Kinshasa — République Démocratique du Congo<br>
        Tél : +243 992 863 230 / +243 975 272 623<br>
        Email : contact@africantrustfund.cd<br>
        Site : www.africantrustfund.cd
      </div>
    </div>
    <div class="footer-note">
      <strong style="color:#0D1B5E">Document officiel — AFRICAN TRUST FUND</strong><br>
      Généré automatiquement le <?= date('d/m/Y à H:i:s') ?> ·
      Réf. REL-<?= date('Ymd') ?>-<?= str_pad((string)$client_id, 4, '0', STR_PAD_LEFT) ?><br>
      Ce relevé fait foi des opérations effectuées pendant la période mentionnée.
      Toute reproduction non autorisée est strictement interdite.
    </div>
  </div>

</div>

<script>
/* ============================================================
   PÉRIODE
   ============================================================ */
function applyPeriod() {
  const d = document.getElementById('dateDebut').value;
  const f = document.getElementById('dateFin').value;
  if (d && f) {
    window.location.href = '?debut=' + d + '&fin=' + f;
  }
}

/* ============================================================
   🆕 EXPORT CSV
   ============================================================ */
function exportCSV() {
  const table = document.querySelector('.document');
  const rows = [];

  // En-tête
  rows.push(['Relevé de compte — AFRICAN TRUST FUND']);
  rows.push(['Client', '<?= addslashes($client['nom'] . ' ' . $client['postnom'] . ' ' . $client['prenom']) ?>']);
  rows.push(['Compte', '<?= addslashes($client['numero_compte']) ?>']);
  rows.push(['Période', '<?= $debut ?> → <?= $fin ?>']);
  rows.push([]);

  // Soldes
  rows.push(['=== SOLDES ===']);
  rows.push(['Solde disponible CDF', '<?= $solde_dispo_cdf ?>']);
  rows.push(['Solde disponible USD', '<?= $solde_dispo_usd ?>']);
  rows.push(['Capital investi CDF', '<?= $capital_cdf ?>']);
  rows.push(['Capital investi USD', '<?= $capital_usd ?>']);
  rows.push(['Intérêts cumulés', '<?= (float)$stats_pl['interets_cumules'] ?>']);
  rows.push(['Intérêts disponibles', '<?= $interets_disponibles ?>']);
  rows.push(['Intérêts bloqués', '<?= $interets_bloques ?>']);
  rows.push(['Patrimoine total (CDF équiv.)', '<?= $patrimoine_total_equiv_cdf ?>']);
  rows.push([]);

  // Transactions
  rows.push(['=== TRANSACTIONS ===']);
  rows.push(['Date', 'Référence', 'Type', 'Montant', 'Devise', 'Solde après', 'Mode']);
  <?php foreach ($transactions as $t): ?>
    rows.push([
      '<?= date('d/m/Y H:i', strtotime($t['date_transaction'])) ?>',
      '<?= addslashes($t['reference']) ?>',
      '<?= addslashes($t['type_transaction']) ?>',
      '<?= (float)$t['montant'] ?>',
      '<?= addslashes($t['devise'] ?? 'CDF') ?>',
      '<?= (float)$t['solde_apres'] ?>',
      '<?= addslashes($t['mode']) ?>'
    ]);
  <?php endforeach; ?>

  // CSV
  const csv = rows.map(r => r.map(c => `"${String(c).replace(/"/g, '""')}"`).join(',')).join('\n');
  const blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = 'releve_<?= $client['numero_compte'] ?>_<?= date('Ymd') ?>.csv';
  link.click();
  URL.revokeObjectURL(url);
}

/* ============================================================
   RACCOURCIS CLAVIER
   ============================================================ */
document.addEventListener('keydown', (e) => {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'p') {
    e.preventDefault();
    window.print();
  }
  if (e.key === 'Escape') {
    window.location.href = '<?= $base ?>/client/dashboard.php';
  }
});

console.log('%c📄 Relevé de compte ATF — Version enrichie', 'color:#F5B800;font-weight:bold;font-size:13px');
console.log('%c💎 Soldes classifiés · Intérêts détaillés · Export CSV', 'color:#A78BFA;font-size:12px');
</script>
</body>
</html>