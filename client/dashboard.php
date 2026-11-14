<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/interets.php';
requireRole('client');

$pdo = Database::getConnection();
$id  = (int)$_SESSION['client_id'];

/* ============================================================
   INFORMATIONS CLIENT
   ============================================================ */
$stmt = $pdo->prepare("SELECT * FROM Client WHERE id_client = ?");
$stmt->execute([$id]);
$client = $stmt->fetch();

$solde_cdf = (float)($client['solde_cdf'] ?? 0);
$solde_usd = (float)($client['solde_usd'] ?? 0);
$epargne_cdf = (float)($client['solde_epargne_cdf'] ?? 0);
$epargne_usd = (float)($client['solde_epargne_usd'] ?? 0);

/* ============================================================
   🆕 STATISTIQUES DÉTAILLÉES DES PLACEMENTS
   ============================================================ */
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) AS nb_placements,
        COALESCE(SUM(montant_initial),0) AS total_capital,
        COALESCE(SUM(capital_retire),0) AS total_capital_retire,
        COALESCE(SUM(interets_cumules),0) AS total_interets,
        COALESCE(SUM(interets_retires),0) AS total_interets_retires,
        COUNT(CASE WHEN statut='actif' THEN 1 END) AS placements_actifs,
        COUNT(CASE WHEN statut='cloture' THEN 1 END) AS placements_clotures,
        COUNT(CASE WHEN statut='anticipe' THEN 1 END) AS placements_anticipe
    FROM Placements
    WHERE id_client = ?
");
$stmt->execute([$id]);
$stats_placements = $stmt->fetch();

/* 🆕 Répartition capital par devise */
$stmt = $pdo->prepare("
    SELECT 
        devise,
        COUNT(*) AS nb,
        COALESCE(SUM(montant_initial), 0) AS capital,
        COALESCE(SUM(interets_cumules), 0) AS interets,
        COALESCE(SUM(interets_cumules - interets_retires), 0) AS interets_restants
    FROM Placements
    WHERE id_client = ? AND statut = 'actif'
    GROUP BY devise
");
$stmt->execute([$id]);
$capital_par_devise = [];
foreach ($stmt->fetchAll() as $r) {
    $capital_par_devise[$r['devise']] = $r;
}

$capital_cdf = (float)($capital_par_devise['CDF']['capital'] ?? 0);
$capital_usd = (float)($capital_par_devise['USD']['capital'] ?? 0);

/* 🆕 Répartition capital par produit (pour graphique) */
$stmt = $pdo->prepare("
    SELECT 
        pr.nom AS produit_nom,
        pr.taux_annuel,
        COUNT(p.id_placement) AS nb,
        COALESCE(SUM(p.montant_initial), 0) AS capital,
        COALESCE(SUM(p.interets_cumules), 0) AS interets
    FROM Placements p
    JOIN Produits_Epargne pr ON pr.id_produit = p.id_produit
    WHERE p.id_client = ? AND p.statut = 'actif'
    GROUP BY pr.id_produit
    ORDER BY capital DESC
");
$stmt->execute([$id]);
$capital_par_produit = $stmt->fetchAll();

/* 🆕 Détail des placements actifs (pour barres de progression) */
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
    WHERE p.id_client = ? AND p.statut = 'actif'
    ORDER BY p.montant_initial DESC
");
$stmt->execute([$id]);
$placements_actifs = $stmt->fetchAll();

/* Intérêts disponibles */
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(interets_cumules - interets_retires), 0)
    FROM Placements
    WHERE id_client = ? AND statut = 'actif' AND date_deblocage_interets <= CURDATE()
");
$stmt->execute([$id]);
$interets_disponibles = (float)$stmt->fetchColumn();

/* Intérêts bloqués */
$stmt = $pdo->prepare("
    SELECT 
        COALESCE(SUM(interets_cumules - interets_retires), 0) AS total_bloque,
        MIN(DATEDIFF(date_deblocage_interets, CURDATE())) AS jours_min
    FROM Placements
    WHERE id_client = ? AND statut = 'actif' AND date_deblocage_interets > CURDATE()
");
$stmt->execute([$id]);
$interets_info = $stmt->fetch();
$interets_bloques = (float)($interets_info['total_bloque'] ?? 0);
$jours_prochain_deblocage = (int)($interets_info['jours_min'] ?? 0);

/* 🆕 Intérêts générés : aujourd'hui / ce mois / cette année */
$stmt = $pdo->prepare("
    SELECT 
        COALESCE(SUM(CASE WHEN DATE(date_calcul) = CURDATE() THEN montant_interet ELSE 0 END), 0) AS jour,
        COALESCE(SUM(CASE WHEN MONTH(date_calcul) = MONTH(CURDATE()) AND YEAR(date_calcul) = YEAR(CURDATE()) THEN montant_interet ELSE 0 END), 0) AS mois,
        COALESCE(SUM(CASE WHEN YEAR(date_calcul) = YEAR(CURDATE()) THEN montant_interet ELSE 0 END), 0) AS annee
    FROM Interets_Historique ih
    JOIN Placements p ON p.id_placement = ih.id_placement
    WHERE p.id_client = ?
");
$stmt->execute([$id]);
$interets_periode = $stmt->fetch();

/* ============================================================
   STATISTIQUES DES TRANSACTIONS
   ============================================================ */
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) AS total,
        COALESCE(SUM(CASE WHEN type_transaction='Depot' AND devise='CDF' THEN montant ELSE 0 END),0) AS depots_cdf,
        COALESCE(SUM(CASE WHEN type_transaction='Depot' AND devise='USD' THEN montant ELSE 0 END),0) AS depots_usd,
        COALESCE(SUM(CASE WHEN type_transaction='Retrait' AND devise='CDF' THEN montant ELSE 0 END),0) AS retraits_cdf,
        COALESCE(SUM(CASE WHEN type_transaction='Retrait' AND devise='USD' THEN montant ELSE 0 END),0) AS retraits_usd,
        COALESCE(SUM(CASE WHEN type_transaction='Virement' THEN montant ELSE 0 END),0) AS total_virements
    FROM Transactions WHERE id_client = ?
");
$stmt->execute([$id]);
$stats_transactions = $stmt->fetch();

/* Évolution du solde 30j */
$stmt = $pdo->prepare("
    SELECT 
        DATE(date_transaction) AS jour,
        SUM(CASE WHEN type_transaction='Depot' THEN montant ELSE -montant END) AS variation
    FROM Transactions
    WHERE id_client = ? AND devise = 'CDF'
      AND date_transaction >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    GROUP BY DATE(date_transaction)
    ORDER BY jour ASC
");
$stmt->execute([$id]);
$evolution_data = $stmt->fetchAll();

$labels_30j = [];
$data_solde_30j = [];
for ($i = 29; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $labels_30j[] = date('d/m', strtotime($date));
    $data_solde_30j[$date] = 0;
}
foreach ($evolution_data as $row) {
    if (isset($data_solde_30j[$row['jour']])) {
        $data_solde_30j[$row['jour']] = (float)$row['variation'];
    }
}
$valeurs_30j = [];
$solde_courant = 0;
foreach ($data_solde_30j as $v) {
    $solde_courant += $v;
    $valeurs_30j[] = $solde_courant;
}

/* Répartition */
$repartition_tx = [
    'depots'    => (float)$stats_transactions['depots_cdf'] + (float)$stats_transactions['depots_usd'] * 2800,
    'retraits'  => (float)$stats_transactions['retraits_cdf'] + (float)$stats_transactions['retraits_usd'] * 2800,
    'virements' => (float)$stats_transactions['total_virements'],
];

/* Intérêts 12 mois */
$stmt = $pdo->prepare("
    SELECT 
        DATE_FORMAT(date_calcul, '%Y-%m') AS mois,
        SUM(montant_interet) AS total
    FROM Interets_Historique ih
    JOIN Placements p ON p.id_placement = ih.id_placement
    WHERE p.id_client = ? AND date_calcul >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
    GROUP BY DATE_FORMAT(date_calcul, '%Y-%m')
");
$stmt->execute([$id]);
$interets_mois = $stmt->fetchAll();

$labels_12m = [];
$data_interets_12m = [];
for ($i = 11; $i >= 0; $i--) {
    $mois = date('Y-m', strtotime("-$i months"));
    $labels_12m[] = date('M Y', strtotime("-$i months"));
    $val = 0;
    foreach ($interets_mois as $r) {
        if ($r['mois'] === $mois) $val = (float)$r['total'];
    }
    $data_interets_12m[] = $val;
}

/* Dernières transactions */
$stmt = $pdo->prepare("
    SELECT * FROM Transactions 
    WHERE id_client = ? 
    ORDER BY date_transaction DESC 
    LIMIT 8
");
$stmt->execute([$id]);
$transactions = $stmt->fetchAll();

$tokens_recu = [];
foreach ($transactions as $t) {
    $tokens_recu[(int)$t['id_transaction']] = genererToken('transaction', (int)$t['id_transaction'], [
        'action'    => 'view_recu',
        'client_id' => $id,
    ]);
}

/* Top placements */
$stmt = $pdo->prepare("
    SELECT p.*, pr.nom AS produit_nom, pr.taux_annuel
    FROM Placements p
    JOIN Produits_Epargne pr ON pr.id_produit = p.id_produit
    WHERE p.id_client = ? AND p.statut = 'actif'
    ORDER BY p.interets_cumules DESC
    LIMIT 3
");
$stmt->execute([$id]);
$top_placements = $stmt->fetchAll();

$token_releve = genererToken('client', $id, ['action' => 'view_releve']);

/* 🆕 Calcul du total investi toutes devises confondues (CDF équivalent) */
$taux_change_usd = 2800; // à ajuster selon ton système
$total_investi_equiv_cdf = (float)$stats_placements['total_capital'] 
    + ((float)$capital_usd * $taux_change_usd);

/* 🆕 Rendement moyen pondéré */
$poids_capital = (float)$stats_placements['total_capital'];
$rendement_moyen = $poids_capital > 0 
    ? ((float)$stats_placements['total_interets'] / $poids_capital) * 100 
    : 0;

$base = '/african-trust-fund';

logAction($pdo, 'client', $id, 'DASHBOARD_VIEW', 'Consultation tableau de bord');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0,user-scalable=no">
<meta name="theme-color" content="#0B0E14">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title>Mon Tableau de bord — AFRICAN TRUST FUND</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
/* ============================================================
   DASHBOARD CLIENT — Version Mobile App-like
   ============================================================ */
:root {
  --nav-height: 76px;
  --nav-safe-area: env(safe-area-inset-bottom, 0px);
}

body.app-mode {
  padding-bottom: calc(var(--nav-height) + var(--nav-safe-area) + 20px);
}

@media (max-width: 900px) {
  .atf-header .atf-nav { display: none; }
}

/* ============================================================
   SOLDES PRINCIPAUX
   ============================================================ */
.soldes-premium {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 18px;
  margin-bottom: 26px;
}

.solde-premium {
  padding: 26px 28px;
  border-radius: 20px;
  position: relative;
  overflow: hidden;
  border: 1px solid var(--border);
  transition: all .35s;
}
.solde-premium::before {
  content: "";
  position: absolute;
  top: -30%; right: -10%;
  width: 220px; height: 220px;
  border-radius: 50%;
  background: radial-gradient(circle, rgba(255,255,255,.1), transparent 70%);
  transition: transform .4s;
}
.solde-premium:hover { transform: translateY(-4px); }
.solde-premium.usd {
  background: linear-gradient(135deg, rgba(59,130,246,.15), rgba(30,64,175,.08));
  border-color: rgba(59,130,246,.4);
}
.solde-premium.cdf {
  background: linear-gradient(135deg, rgba(245,184,0,.15), rgba(180,120,0,.08));
  border-color: rgba(245,184,0,.4);
}
.solde-premium .head {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 12px;
  position: relative;
  z-index: 1;
}
.solde-premium .flag { font-size: 1.8rem; }
.solde-premium .head .lbl {
  color: var(--muted);
  font-size: .78rem;
  text-transform: uppercase;
  letter-spacing: 1.5px;
  font-weight: 700;
}
.solde-premium .amount {
  font-size: 2rem;
  font-weight: 800;
  line-height: 1.1;
  position: relative;
  z-index: 1;
  letter-spacing: -.5px;
}
.solde-premium.usd .amount { color: #93B4FF; text-shadow: 0 0 30px rgba(59,130,246,.35); }
.solde-premium.cdf .amount { color: var(--gold); text-shadow: 0 0 30px rgba(245,184,0,.35); }
.solde-premium .devise-badge {
  display: inline-block;
  padding: 4px 12px;
  border-radius: 999px;
  background: rgba(0,0,0,.25);
  color: #fff;
  font-size: .72rem;
  font-weight: 700;
  margin-left: 8px;
  vertical-align: middle;
}

/* ============================================================
   🆕 BLOC CAPITAL INVESTI (ÉPARGNE)
   ============================================================ */
.capital-bloc {
  padding: 26px 28px;
  border-radius: 20px;
  background: linear-gradient(135deg, rgba(139,92,246,.15), rgba(76,29,149,.08)),
              radial-gradient(circle at 90% 20%, rgba(167,139,250,.18), transparent 60%);
  border: 1px solid rgba(139,92,246,.35);
  margin-bottom: 26px;
  position: relative;
  overflow: hidden;
  animation: fadeInUp .6s ease-out both;
}
.capital-bloc::before {
  content: "";
  position: absolute;
  top: -30%; right: -5%;
  width: 300px; height: 300px;
  border-radius: 50%;
  background: radial-gradient(circle, rgba(139,92,246,.25), transparent 70%);
  animation: float 8s ease-in-out infinite;
}
.capital-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 22px;
  position: relative;
  z-index: 1;
  flex-wrap: wrap;
  gap: 12px;
}
.capital-header h3 {
  font-size: 1.15rem;
  color: #C4B5FD;
  display: flex;
  align-items: center;
  gap: 10px;
  margin: 0;
}
.capital-total {
  display: flex;
  align-items: baseline;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 22px;
  position: relative;
  z-index: 1;
}
.capital-total .val {
  font-size: 2.4rem;
  font-weight: 900;
  color: #A78BFA;
  text-shadow: 0 0 40px rgba(167,139,250,.4);
  letter-spacing: -1px;
  line-height: 1;
}
.capital-total .dev {
  font-size: 1rem;
  color: #C4B5FD;
  font-weight: 700;
}
.capital-total .equiv {
  font-size: .82rem;
  color: var(--muted);
  margin-left: 8px;
}

.capital-kpis {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 12px;
  position: relative;
  z-index: 1;
}
.capital-kpi {
  padding: 16px 18px;
  border-radius: 14px;
  background: rgba(11,14,20,.55);
  border: 1px solid rgba(139,92,246,.25);
  transition: all .3s;
}
.capital-kpi:hover {
  border-color: rgba(167,139,250,.6);
  transform: translateY(-2px);
}
.capital-kpi .lbl {
  font-size: .68rem;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 1px;
  font-weight: 700;
  display: flex;
  align-items: center;
  gap: 6px;
}
.capital-kpi .val {
  font-size: 1.25rem;
  font-weight: 800;
  color: #C4B5FD;
  margin-top: 6px;
  line-height: 1.1;
}
.capital-kpi .sub {
  font-size: .72rem;
  color: var(--muted);
  margin-top: 4px;
}
.capital-kpi.gold .val { color: var(--gold); }
.capital-kpi.blue .val { color: #93B4FF; }
.capital-kpi.green .val { color: #6EE7B7; }
.capital-kpi.blue { border-color: rgba(59,130,246,.25); }
.capital-kpi.green { border-color: rgba(16,185,129,.25); }
.capital-kpi.gold { border-color: rgba(245,184,0,.25); }

/* ============================================================
   BLOC INTÉRÊTS
   ============================================================ */
.interets-bloc {
  padding: 24px 28px;
  border-radius: 20px;
  background: linear-gradient(135deg, rgba(16,185,129,.12), rgba(16,185,129,.02)),
              radial-gradient(circle at 90% 20%, rgba(16,185,129,.15), transparent 60%);
  border: 1px solid rgba(16,185,129,.3);
  margin-bottom: 26px;
  position: relative;
  overflow: hidden;
  animation: fadeInUp .6s ease-out both;
}
.interets-bloc::before {
  content: "";
  position: absolute;
  top: -30%; right: -5%;
  width: 280px; height: 280px;
  border-radius: 50%;
  background: radial-gradient(circle, rgba(16,185,129,.2), transparent 70%);
  animation: float 7s ease-in-out infinite;
}
.interets-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
  position: relative;
  z-index: 1;
  flex-wrap: wrap;
  gap: 12px;
}
.interets-header h3 {
  font-size: 1.15rem;
  color: #6EE7B7;
  display: flex;
  align-items: center;
  gap: 10px;
  margin: 0;
}
.interets-kpis {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 12px;
  position: relative;
  z-index: 1;
}
.interet-kpi {
  padding: 16px 18px;
  border-radius: 14px;
  background: rgba(11,14,20,.5);
  border: 1px solid rgba(16,185,129,.2);
  text-align: center;
}
.interet-kpi .lbl {
  font-size: .68rem;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 1px;
  font-weight: 700;
}
.interet-kpi .val {
  font-size: 1.3rem;
  font-weight: 800;
  color: #6EE7B7;
  margin-top: 6px;
  line-height: 1.1;
}
.interet-kpi.bloque .val { color: #FCD34D; }
.interet-kpi.bloque { border-color: rgba(252,211,77,.3); }

/* 🆕 Sous-KPIs intérêts période */
.interets-periodes {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 12px;
  margin-top: 16px;
  position: relative;
  z-index: 1;
}
.interet-periode {
  padding: 12px 14px;
  border-radius: 12px;
  background: rgba(11,14,20,.4);
  border: 1px solid rgba(16,185,129,.15);
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.interet-periode .lbl {
  font-size: .65rem;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 1px;
  font-weight: 700;
}
.interet-periode .val {
  font-size: 1.05rem;
  font-weight: 800;
  color: #6EE7B7;
}
@media (max-width: 600px) {
  .interets-periodes { grid-template-columns: 1fr 1fr 1fr; }
  .interet-periode .val { font-size: .9rem; }
}

/* ============================================================
   🆕 LISTE PLACEMENTS AVEC PROGRESSION
   ============================================================ */
.placement-progress-item {
  padding: 18px;
  border-radius: 14px;
  background: linear-gradient(145deg, var(--card), var(--card-2));
  border: 1px solid var(--border);
  margin-bottom: 12px;
  transition: all .3s;
}
.placement-progress-item:hover {
  border-color: var(--gold);
  transform: translateY(-2px);
}
.ppi-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
  margin-bottom: 12px;
  flex-wrap: wrap;
}
.ppi-title {
  font-weight: 800;
  font-size: .95rem;
  color: var(--text);
}
.ppi-ref {
  font-family: monospace;
  font-size: .72rem;
  color: var(--gold);
  margin-top: 2px;
}
.ppi-montant {
  text-align: right;
}
.ppi-montant .cap {
  font-weight: 800;
  color: #A78BFA;
  font-size: 1.05rem;
}
.ppi-montant .int {
  font-size: .78rem;
  color: #6EE7B7;
  font-weight: 700;
}
.ppi-bar {
  height: 8px;
  background: rgba(11,14,20,.6);
  border-radius: 4px;
  overflow: hidden;
  margin-bottom: 8px;
  position: relative;
}
.ppi-bar > div {
  height: 100%;
  background: linear-gradient(90deg, #8B5CF6, #A78BFA);
  border-radius: 4px;
  transition: width .6s cubic-bezier(.4,0,.2,1);
  position: relative;
}
.ppi-bar > div::after {
  content: "";
  position: absolute;
  top: 0; right: 0; bottom: 0;
  width: 40px;
  background: linear-gradient(90deg, transparent, rgba(255,255,255,.3));
  animation: shine 2s infinite;
}
.ppi-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: .72rem;
  color: var(--muted);
  flex-wrap: wrap;
  gap: 8px;
}
.ppi-footer .jours { color: #93B4FF; font-weight: 700; }
.ppi-footer .taux { 
  background: rgba(167,139,250,.15);
  color: #A78BFA;
  padding: 2px 8px;
  border-radius: 6px;
  font-weight: 700;
  font-family: monospace;
}
.ppi-badge-debloque {
  background: rgba(16,185,129,.15);
  color: #6EE7B7;
  padding: 2px 8px;
  border-radius: 6px;
  font-weight: 700;
  font-size: .68rem;
}
.ppi-badge-bloque {
  background: rgba(252,211,77,.15);
  color: #FCD34D;
  padding: 2px 8px;
  border-radius: 6px;
  font-weight: 700;
  font-size: .68rem;
}

/* ============================================================
   GRAPHIQUES
   ============================================================ */
.charts-grid {
  display: grid;
  gap: 22px;
  margin-bottom: 26px;
}
.charts-grid.two { grid-template-columns: 1.4fr 1fr; }
.charts-grid.three { grid-template-columns: 1fr 1fr 1fr; }

@media (max-width: 1024px) {
  .charts-grid.two,
  .charts-grid.three { grid-template-columns: 1fr; }
}

.chart-card {
  background: linear-gradient(145deg, var(--card), var(--card-2));
  border: 1px solid var(--border);
  border-radius: 20px;
  padding: 22px;
  position: relative;
  overflow: hidden;
  animation: fadeInUp .6s ease-out both;
}
.chart-card::before {
  content: "";
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 3px;
  background: linear-gradient(90deg, transparent, var(--gold), transparent);
  background-size: 200% 100%;
  animation: shine 3s infinite;
}
.chart-card h3 {
  font-size: 1rem;
  font-weight: 700;
  margin-bottom: 16px;
  display: flex;
  align-items: center;
  gap: 10px;
}
.chart-card h3 .ico {
  width: 32px;
  height: 32px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--gold-soft);
  color: var(--gold);
  font-size: 1rem;
}
.chart-wrap {
  position: relative;
  height: 280px;
}

/* ============================================================
   ACTIONS RAPIDES (Desktop)
   ============================================================ */
.actions-banner {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
  gap: 14px;
  margin-bottom: 26px;
}
.action-card {
  padding: 22px 20px;
  border-radius: 16px;
  background: linear-gradient(145deg, var(--card), var(--card-2));
  border: 1px solid var(--border);
  text-decoration: none;
  color: var(--text);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  transition: all .3s cubic-bezier(.4,0,.2,1);
  position: relative;
  overflow: hidden;
}
.action-card::before {
  content: "";
  position: absolute;
  top: 0; left: -100%;
  width: 100%; height: 100%;
  background: linear-gradient(90deg, transparent, rgba(245,184,0,.12), transparent);
  transition: left .6s ease;
}
.action-card:hover::before { left: 100%; }
.action-card:hover {
  transform: translateY(-4px);
  border-color: var(--gold);
  box-shadow: 0 16px 40px rgba(245,184,0,.2);
}
.action-card .ico-big {
  width: 52px; height: 52px;
  border-radius: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.5rem;
  background: var(--gold-soft);
  color: var(--gold);
  transition: transform .4s ease;
}
.action-card:hover .ico-big { transform: scale(1.15) rotate(-8deg); }
.action-card.depot .ico-big { background: rgba(16,185,129,.15); color: var(--green); }
.action-card.retrait .ico-big { background: rgba(239,68,68,.15); color: #FCA5A5; }
.action-card.virement .ico-big { background: rgba(59,130,246,.15); color: #93B4FF; }
.action-card.epargne .ico-big { background: rgba(139,92,246,.15); color: #A78BFA; }
.action-card .label { font-weight: 700; font-size: .92rem; text-align: center; }
.action-card .hint { font-size: .72rem; color: var(--muted); text-align: center; }

/* ============================================================
   BANDEAU RELEVÉ
   ============================================================ */
.releve-banner {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 20px;
  padding: 24px 28px;
  background: linear-gradient(135deg, rgba(139,92,246,.15), rgba(139,92,246,.03)),
              radial-gradient(circle at 90% 50%, rgba(139,92,246,.15), transparent 60%);
  border: 1px solid rgba(139,92,246,.3);
  border-radius: 18px;
  margin: 26px 0;
  flex-wrap: wrap;
  position: relative;
  overflow: hidden;
}
.releve-banner::before {
  content: "";
  position: absolute;
  top: -30%; right: -5%;
  width: 220px; height: 220px;
  border-radius: 50%;
  background: radial-gradient(circle, rgba(139,92,246,.2), transparent 70%);
  animation: float 6s ease-in-out infinite;
}
.releve-banner .txt { position: relative; z-index: 1; }
.releve-banner h3 { font-size: 1.15rem; margin-bottom: 6px; color: #A78BFA; }
.releve-banner p { color: var(--muted); font-size: .88rem; }
.btn-releve {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  padding: 14px 26px;
  border-radius: 999px;
  background: linear-gradient(135deg, #8B5CF6, #7C3AED);
  color: #fff;
  font-weight: 800;
  font-size: .92rem;
  text-decoration: none;
  position: relative;
  z-index: 1;
  box-shadow: 0 8px 24px rgba(139,92,246,.4);
  transition: all .25s;
}
.btn-releve:hover {
  transform: translateY(-3px);
  box-shadow: 0 14px 32px rgba(139,92,246,.6);
  color: #fff;
}

/* ============================================================
   TABLE TRANSACTIONS
   ============================================================ */
.tx-table {
  width: 100%;
  border-collapse: collapse;
  font-size: .88rem;
}
.tx-table th {
  padding: 12px;
  text-align: left;
  color: var(--muted);
  font-size: .72rem;
  text-transform: uppercase;
  letter-spacing: 1px;
  font-weight: 700;
  border-bottom: 1px solid var(--border);
  background: rgba(11,14,20,.4);
}
.tx-table td {
  padding: 14px 12px;
  border-bottom: 1px solid var(--border);
  vertical-align: middle;
}
.tx-table tbody tr { transition: background .2s; }
.tx-table tbody tr:hover { background: rgba(245,184,0,.04); }
.tx-table .ref {
  font-family: 'Courier New', monospace;
  font-size: .78rem;
  background: var(--bg-1);
  padding: 3px 8px;
  border-radius: 6px;
  color: var(--gold);
  font-weight: 700;
}
.btn-recu-mini {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 6px 12px;
  border-radius: 8px;
  background: linear-gradient(135deg, var(--gold), var(--gold-2));
  color: #0B0E14;
  font-weight: 700;
  font-size: .72rem;
  text-decoration: none;
  transition: all .2s;
  white-space: nowrap;
}
.btn-recu-mini:hover {
  transform: translateY(-2px);
  box-shadow: 0 6px 16px rgba(245,184,0,.4);
  color: #0B0E14;
}

/* ============================================================
   TOP PLACEMENTS
   ============================================================ */
.top-placement {
  padding: 16px 18px;
  border-radius: 14px;
  background: linear-gradient(145deg, var(--card), var(--card-2));
  border: 1px solid var(--border);
  display: flex;
  align-items: center;
  gap: 14px;
  margin-bottom: 12px;
  transition: all .3s;
}
.top-placement:hover {
  transform: translateX(4px);
  border-color: var(--gold);
}
.top-placement-info { flex: 1; }
.top-placement-info .name { font-weight: 700; font-size: .9rem; }
.top-placement-info .sub { font-size: .72rem; color: var(--gold); font-family: monospace; }
.top-placement-stats { text-align: right; }
.top-placement-stats .val {
  font-weight: 800;
  color: #6EE7B7;
  font-size: 1rem;
}
.top-placement-stats .lbl { font-size: .7rem; color: var(--muted); }

/* ============================================================
   BARRE DE NAVIGATION MOBILE
   ============================================================ */
.mobile-nav {
  display: none;
  position: fixed;
  bottom: 0;
  left: 0;
  right: 0;
  height: calc(var(--nav-height) + var(--nav-safe-area));
  padding-bottom: var(--nav-safe-area);
  background: rgba(11,14,20,.95);
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
  border-top: 1px solid rgba(245,184,0,.15);
  z-index: 9000;
  box-shadow: 0 -8px 30px rgba(0,0,0,.5);
}
.mobile-nav-inner {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr 1fr 1fr;
  height: var(--nav-height);
  align-items: center;
  padding: 0 8px;
  max-width: 600px;
  margin: 0 auto;
}
.nav-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
  padding: 10px 6px;
  text-decoration: none;
  color: var(--muted);
  font-size: .65rem;
  font-weight: 700;
  letter-spacing: .3px;
  transition: all .25s cubic-bezier(.4,0,.2,1);
  border-radius: 14px;
  position: relative;
  cursor: pointer;
  background: none;
  border: none;
  font-family: inherit;
  text-transform: uppercase;
}
.nav-item .nav-icon {
  font-size: 1.5rem;
  line-height: 1;
  transition: transform .3s cubic-bezier(.34,1.56,.64,1);
  position: relative;
}
.nav-item .nav-label { font-size: .62rem; line-height: 1; font-weight: 700; }
.nav-item.active { color: var(--gold); }
.nav-item.active .nav-icon {
  transform: scale(1.15) translateY(-2px);
  filter: drop-shadow(0 0 12px rgba(245,184,0,.6));
}
.nav-item.active::before {
  content: "";
  position: absolute;
  top: 0;
  left: 50%;
  transform: translateX(-50%);
  width: 32px;
  height: 3px;
  background: linear-gradient(90deg, #F5B800, #D4AF37);
  border-radius: 0 0 3px 3px;
  box-shadow: 0 0 12px rgba(245,184,0,.8);
}
.nav-item:active .nav-icon { transform: scale(.9); }

.nav-fab-wrapper {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  height: 100%;
}
.nav-fab {
  position: absolute;
  top: -22px;
  width: 60px;
  height: 60px;
  border-radius: 50%;
  background: linear-gradient(135deg, #F5B800, #D4AF37);
  color: #0B0E14;
  border: 4px solid #0B0E14;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.8rem;
  font-weight: 900;
  box-shadow:
    0 10px 30px rgba(245,184,0,.5),
    0 0 0 6px rgba(245,184,0,.15);
  transition: all .3s cubic-bezier(.34,1.56,.64,1);
  z-index: 2;
}
.nav-fab:active { transform: scale(.9); }
.nav-fab.active { transform: rotate(45deg); }

.fab-menu {
  display: none;
  position: fixed;
  bottom: calc(var(--nav-height) + var(--nav-safe-area) + 20px);
  left: 50%;
  transform: translateX(-50%) translateY(20px);
  background: linear-gradient(180deg, #1A2130, #121824);
  border-radius: 20px;
  padding: 16px;
  box-shadow: 0 20px 60px rgba(0,0,0,.7);
  border: 1px solid rgba(245,184,0,.25);
  z-index: 8999;
  opacity: 0;
  transition: all .3s cubic-bezier(.4,0,.2,1);
  min-width: 240px;
}
.fab-menu.open {
  display: block;
  opacity: 1;
  transform: translateX(-50%) translateY(0);
}
.fab-menu-item {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 14px 18px;
  border-radius: 12px;
  color: var(--text);
  text-decoration: none;
  font-size: .9rem;
  font-weight: 700;
  transition: all .2s;
  border: 1px solid transparent;
  background: transparent;
  width: 100%;
  cursor: pointer;
  font-family: inherit;
  text-align: left;
}
.fab-menu-item:hover,
.fab-menu-item:active {
  background: rgba(245,184,0,.1);
  border-color: rgba(245,184,0,.3);
  color: var(--gold);
  transform: translateX(4px);
}
.fab-menu-item .ico {
  width: 40px;
  height: 40px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.3rem;
  flex-shrink: 0;
  background: var(--gold-soft);
  color: var(--gold);
}
.fab-menu-item.depot .ico { background: rgba(16,185,129,.15); color: var(--green); }
.fab-menu-item.retrait .ico { background: rgba(239,68,68,.15); color: #FCA5A5; }
.fab-menu-item.virement .ico { background: rgba(59,130,246,.15); color: #93B4FF; }
.fab-menu-item.epargne .ico { background: rgba(139,92,246,.15); color: #A78BFA; }

.fab-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.5);
  backdrop-filter: blur(4px);
  z-index: 8998;
  opacity: 0;
  transition: opacity .3s;
}
.fab-overlay.open { display: block; opacity: 1; }

/* ============================================================
   MOBILE
   ============================================================ */
@media (max-width: 900px) {
  .actions-banner { display: none; }
  .mobile-nav { display: block; }

  .soldes-premium {
    grid-template-columns: 1fr;
    gap: 14px;
  }
  .solde-premium { padding: 20px 22px; }
  .solde-premium .amount { font-size: 1.7rem; }

  .capital-bloc { padding: 20px 18px; }
  .capital-total .val { font-size: 1.9rem; }
  .capital-kpis { grid-template-columns: 1fr 1fr; }

  .interets-kpis { grid-template-columns: 1fr 1fr; }
  .interet-kpi { padding: 14px; }
  .interet-kpi .val { font-size: 1.1rem; }

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
    padding: 5px 0;
    border: none;
    text-align: right;
  }
  .tx-table td::before {
    content: attr(data-label);
    color: var(--muted);
    font-size: .7rem;
    text-transform: uppercase;
    font-weight: 700;
    text-align: left;
    flex: 1;
  }

  .chart-wrap { height: 240px; }

  .releve-banner {
    flex-direction: column;
    align-items: flex-start;
    padding: 20px;
  }
  .btn-releve { width: 100%; justify-content: center; }
  .dash { padding: 16px 14px !important; }
  .welcome-head h2 { font-size: 1.3rem; }
}

@media (max-width: 480px) {
  .solde-premium .amount { font-size: 1.5rem; }
  .capital-total .val { font-size: 1.7rem; }
  .interets-header h3 { font-size: 1rem; }
  .nav-item .nav-icon { font-size: 1.3rem; }
  .nav-item .nav-label { font-size: .58rem; }
  .nav-fab { width: 54px; height: 54px; font-size: 1.6rem; }
}

@keyframes float {
  0%, 100% { transform: translateY(0) }
  50% { transform: translateY(-15px) }
}
@keyframes shine {
  0% { background-position: -200% 0; }
  100% { background-position: 200% 0; }
}
@keyframes fadeInUp {
  from { opacity: 0; transform: translateY(20px); }
  to { opacity: 1; transform: translateY(0); }
}
</style>
</head>
<body class="dark-auth app-mode">

<?php require __DIR__ . '/../includes/header.php'; ?>

<main class="dash">

  <!-- EN-TÊTE -->
  <div class="welcome-head">
    <div>
      <h2>Bonjour, <?= htmlspecialchars($client['prenom'] ?: $client['nom']) ?> 👋</h2>
      <p class="sub">Bienvenue dans votre espace sécurisé</p>
    </div>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <span class="badge gold">💼 Multi-devises</span>
      <span class="badge green">● <?= htmlspecialchars($client['statut']) ?></span>
    </div>
  </div>

  <!-- SOLDES DISPONIBLES -->
  <section class="soldes-premium">
    <div class="solde-premium usd animate-in delay-1">
      <div class="head">
        <span class="flag">🇺🇸</span>
        <span class="lbl">Solde disponible USD</span>
      </div>
      <div class="amount">
        $ <?= number_format($solde_usd, 2, '.', ',') ?>
        <span class="devise-badge">USD</span>
      </div>
    </div>
    <div class="solde-premium cdf animate-in delay-2">
      <div class="head">
        <span class="flag">🇨🇩</span>
        <span class="lbl">Solde disponible CDF</span>
      </div>
      <div class="amount">
        <?= number_format($solde_cdf, 2, ',', ' ') ?>
        <span class="devise-badge">CDF</span>
      </div>
    </div>
  </section>

  <!-- 🆕 BLOC CAPITAL INVESTI DANS L'ÉPARGNE -->
  <section class="capital-bloc animate-in delay-1">
    <div class="capital-header">
      <h3>💎 Mon capital investi</h3>
      <span class="badge" style="background:rgba(139,92,246,.2);color:#C4B5FD;border:1px solid rgba(139,92,246,.4)">
        <?= (int)$stats_placements['placements_actifs'] ?> placement(s) actif(s)
      </span>
    </div>

    <!-- Montant total investi -->
    <div class="capital-total">
      <span class="val"><?= number_format($total_investi_equiv_cdf, 0, ',', ' ') ?></span>
      <span class="dev">CDF</span>
      <?php if ($capital_usd > 0): ?>
        <span class="equiv">≈ $ <?= number_format($total_investi_equiv_cdf / $taux_change_usd, 0, '.', ',') ?> USD</span>
      <?php endif; ?>
    </div>

    <!-- KPIs détaillés -->
    <div class="capital-kpis">
      <div class="capital-kpi">
        <div class="lbl">💰 Capital CDF</div>
        <div class="val"><?= number_format($capital_cdf, 0, ',', ' ') ?></div>
        <div class="sub"><?= (int)($capital_par_devise['CDF']['nb'] ?? 0) ?> placement(s)</div>
      </div>
      <div class="capital-kpi blue">
        <div class="lbl">💵 Capital USD</div>
        <div class="val">$ <?= number_format($capital_usd, 2, '.', ',') ?></div>
        <div class="sub"><?= (int)($capital_par_devise['USD']['nb'] ?? 0) ?> placement(s)</div>
      </div>
      <div class="capital-kpi green">
        <div class="lbl">📈 Intérêts cumulés</div>
        <div class="val">+<?= number_format((float)$stats_placements['total_interets'], 0, ',', ' ') ?></div>
        <div class="sub">Depuis le début</div>
      </div>
      <div class="capital-kpi gold">
        <div class="lbl">🎯 Rendement moyen</div>
        <div class="val"><?= number_format($rendement_moyen, 2, ',', ' ') ?>%</div>
        <div class="sub">Sur capital investi</div>
      </div>
    </div>
  </section>

  <!-- INTÉRÊTS -->
  <section class="interets-bloc">
    <div class="interets-header">
      <h3>📈 Mes intérêts</h3>
      <span class="badge <?= $jours_prochain_deblocage > 0 ? 'gold' : 'green' ?>">
        <?php if ($jours_prochain_deblocage > 0): ?>
          🔒 <?= $jours_prochain_deblocage ?> j avant déblocage
        <?php else: ?>
          ✅ Débloqués
        <?php endif; ?>
      </span>
    </div>

    <div class="interets-kpis">
      <div class="interet-kpi">
        <div class="lbl">💰 Cumulés</div>
        <div class="val">+ <?= number_format((float)$stats_placements['total_interets'], 0, ',', ' ') ?></div>
      </div>
      <div class="interet-kpi">
        <div class="lbl">✅ Disponibles</div>
        <div class="val"><?= number_format($interets_disponibles, 0, ',', ' ') ?></div>
      </div>
      <div class="interet-kpi bloque">
        <div class="lbl">🔒 Bloqués</div>
        <div class="val"><?= number_format($interets_bloques, 0, ',', ' ') ?></div>
      </div>
      <div class="interet-kpi">
        <div class="lbl">💸 Retirés</div>
        <div class="val"><?= number_format((float)$stats_placements['total_interets_retires'], 0, ',', ' ') ?></div>
      </div>
    </div>

    <!-- 🆕 Intérêts générés par période -->
    <div class="interets-periodes">
      <div class="interet-periode">
        <div class="lbl">📅 Aujourd'hui</div>
        <div class="val">+<?= number_format((float)$interets_periode['jour'], 0, ',', ' ') ?></div>
      </div>
      <div class="interet-periode">
        <div class="lbl">🗓️ Ce mois</div>
        <div class="val">+<?= number_format((float)$interets_periode['mois'], 0, ',', ' ') ?></div>
      </div>
      <div class="interet-periode">
        <div class="lbl">📊 Cette année</div>
        <div class="val">+<?= number_format((float)$interets_periode['annee'], 0, ',', ' ') ?></div>
      </div>
    </div>
  </section>

  <!-- 🆕 DÉTAIL DES PLACEMENTS ACTIFS -->
  <?php if (!empty($placements_actifs)): ?>
    <section class="card" style="margin-bottom:26px">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px">
        <h3 style="margin:0">🎯 Mes placements actifs (<?= count($placements_actifs) ?>)</h3>
        <a class="btn-ghost btn-sm" href="<?= $base ?>/client/epargne.php">Voir tous →</a>
      </div>

      <?php foreach ($placements_actifs as $p):
        $jours_ecoules = max(0, (int)$p['jours_ecoules']);
        $jours_total   = max(1, (int)$p['jours_total']);
        $progress      = min(100, ($jours_ecoules / $jours_total) * 100);
        $debloque      = (int)$p['jours_avant_deblocage'] <= 0;
        $interets_restants = (float)$p['interets_cumules'] - (float)$p['interets_retires'];
      ?>
        <div class="placement-progress-item">
          <div class="ppi-head">
            <div>
              <div class="ppi-title"><?= htmlspecialchars($p['produit_nom']) ?></div>
              <div class="ppi-ref"><?= htmlspecialchars($p['reference']) ?></div>
            </div>
            <div class="ppi-montant">
              <div class="cap">
                <?= $p['devise'] === 'USD' ? '$ ' : '' ?>
                <?= number_format((float)$p['montant_initial'], 2, $p['devise'] === 'USD' ? '.' : ',', $p['devise'] === 'USD' ? ',' : ' ') ?>
                <?= $p['devise'] === 'USD' ? '' : ' CDF' ?>
              </div>
              <div class="int">+<?= number_format((float)$p['interets_cumules'], 2, ',', ' ') ?> d'intérêts</div>
            </div>
          </div>

          <div class="ppi-bar">
            <div style="width:<?= $progress ?>%"></div>
          </div>

          <div class="ppi-footer">
            <span>📅 <?= $jours_ecoules ?> j / <?= $jours_total ?> j</span>
            <span class="taux"><?= number_format((float)$p['taux_applique'] * 100, 2, ',', ' ') ?>%/mois</span>
            <?php if ($debloque): ?>
              <span class="ppi-badge-debloque">✅ Intérêts débloqués</span>
            <?php else: ?>
              <span class="ppi-badge-bloque">🔒 <?= (int)$p['jours_avant_deblocage'] ?> j restants</span>
            <?php endif; ?>
            <span>💰 Dispo : <?= number_format($interets_restants, 0, ',', ' ') ?></span>
          </div>
        </div>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>

  <!-- ACTIONS RAPIDES (Desktop seulement) -->
  <section class="actions-banner">
    <a href="<?= $base ?>/client/depot.php" class="action-card depot">
      <div class="ico-big">＋</div>
      <div class="label">Dépôt</div>
      <div class="hint">Alimenter mon compte</div>
    </a>
    <a href="<?= $base ?>/client/retrait.php" class="action-card retrait">
      <div class="ico-big">－</div>
      <div class="label">Retrait</div>
      <div class="hint">Retirer de l'argent</div>
    </a>
    <a href="<?= $base ?>/client/virement.php" class="action-card virement">
      <div class="ico-big">⇄</div>
      <div class="label">Virement</div>
      <div class="hint">Transférer à un tiers</div>
    </a>
    <a href="<?= $base ?>/client/epargne.php" class="action-card epargne">
      <div class="ico-big">💰</div>
      <div class="label">Mon épargne</div>
      <div class="hint"><?= (int)$stats_placements['placements_actifs'] ?> placement(s)</div>
    </a>
    <a href="<?= $base ?>/client/profil.php" class="action-card">
      <div class="ico-big">👤</div>
      <div class="label">Mon profil</div>
      <div class="hint">Modifier mes infos</div>
    </a>
    <a href="<?= $base ?>/client/transactions.php" class="action-card">
      <div class="ico-big">📋</div>
      <div class="label">Transactions</div>
      <div class="hint"><?= number_format((int)$stats_transactions['total'], 0, ',', ' ') ?> au total</div>
    </a>
  </section>

  <!-- GRAPHIQUES -->
  <div class="charts-grid two">
    <div class="chart-card">
      <h3><span class="ico">📈</span> Évolution du solde (30j)</h3>
      <div class="chart-wrap">
        <canvas id="chartSolde"></canvas>
      </div>
    </div>

    <div class="chart-card">
      <h3><span class="ico">🎯</span> Répartition</h3>
      <div class="chart-wrap">
        <canvas id="chartRepartition"></canvas>
      </div>
    </div>
  </div>

  <div class="charts-grid three">
    <div class="chart-card">
      <h3><span class="ico">💵</span> Dépôts/Retraits</h3>
      <div class="chart-wrap">
        <canvas id="chartDepRet"></canvas>
      </div>
    </div>

    <div class="chart-card">
      <h3><span class="ico">📊</span> Intérêts (12m)</h3>
      <div class="chart-wrap">
        <canvas id="chartInterets"></canvas>
      </div>
    </div>

    <div class="chart-card">
      <h3><span class="ico">💼</span> Capital par produit</h3>
      <div class="chart-wrap">
        <canvas id="chartPlacements"></canvas>
      </div>
    </div>
  </div>

  <!-- RELEVÉ -->
  <section class="releve-banner">
    <div class="txt">
      <h3>📄 Relevé de compte</h3>
      <p>Votre relevé officiel imprimable</p>
    </div>
    <a class="btn-releve"
       href="<?= $base ?>/client/releve.php?token=<?= htmlspecialchars($token_releve) ?>"
       target="_blank">
      📥 Générer
    </a>
  </section>

  <!-- TOP PLACEMENTS -->
  <?php if (!empty($top_placements)): ?>
    <section class="card">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
        <h3 style="margin:0">🏆 Top intérêts</h3>
        <a class="btn-ghost btn-sm" href="<?= $base ?>/client/epargne.php">Voir →</a>
      </div>
      <?php foreach ($top_placements as $p): ?>
        <div class="top-placement">
          <div style="font-size:1.8rem">💰</div>
          <div class="top-placement-info">
            <div class="name"><?= htmlspecialchars($p['produit_nom']) ?></div>
            <div class="sub"><?= htmlspecialchars($p['reference']) ?></div>
          </div>
          <div class="top-placement-stats">
            <div class="val">+ <?= number_format((float)$p['interets_cumules'], 0, ',', ' ') ?></div>
            <div class="lbl">Intérêts</div>
          </div>
        </div>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>

  <!-- HISTORIQUE -->
  <section class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:12px">
      <h3 style="margin:0">Dernières transactions</h3>
      <a class="btn-ghost" style="padding:8px 16px;font-size:.82rem"
         href="<?= $base ?>/client/transactions.php">
        Voir tout →
      </a>
    </div>

    <?php if (empty($transactions)): ?>
      <div class="muted center" style="padding:40px">
        <div style="font-size:3rem;opacity:.5;margin-bottom:12px">📭</div>
        <p>Aucune transaction pour le moment.</p>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <table class="tx-table">
          <thead>
            <tr>
              <th>Référence</th>
              <th>Type</th>
              <th style="text-align:right">Montant</th>
              <th style="text-align:right">Solde</th>
              <th>Mode</th>
              <th>Date</th>
              <th style="text-align:center">Reçu</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($transactions as $t):
              $is_retrait = $t['type_transaction'] === 'Retrait';
              $token = $tokens_recu[(int)$t['id_transaction']] ?? '';
              $devise_tx = $t['devise'] ?? 'CDF';
              $is_usd = $devise_tx === 'USD';
            ?>
              <tr>
                <td data-label="Réf"><span class="ref"><?= htmlspecialchars($t['reference']) ?></span></td>
                <td data-label="Type">
                  <span class="tag <?= strtolower($t['type_transaction']) ?>">
                    <?php if ($t['type_transaction'] === 'Depot'): ?>➕
                    <?php elseif ($t['type_transaction'] === 'Retrait'): ?>➖
                    <?php else: ?>⇄<?php endif; ?>
                    <?= htmlspecialchars($t['type_transaction']) ?>
                  </span>
                </td>
                <td data-label="Montant" style="text-align:right">
                  <strong style="color:<?= $is_retrait ? '#FCA5A5' : 'var(--green)' ?>">
                    <?= $is_retrait ? '−' : '+' ?>
                    <?= $is_usd ? '$ ' : '' ?>
                    <?= number_format((float)$t['montant'], 2, $is_usd ? '.' : ',', $is_usd ? ',' : ' ') ?>
                    <?= !$is_usd ? ' CDF' : '' ?>
                  </strong>
                </td>
                <td data-label="Solde" style="text-align:right">
                  <strong><?= number_format((float)$t['solde_apres'], 2, ',', ' ') ?></strong>
                </td>
                <td data-label="Mode">
                  <?php if ($t['mode'] === 'En ligne'): ?>
                    <span class="badge blue" style="font-size:.65rem">🌐</span>
                  <?php else: ?>
                    <span class="badge gold" style="font-size:.65rem">🏢</span>
                  <?php endif; ?>
                </td>
                <td data-label="Date">
                  <strong><?= date('d/m/Y', strtotime($t['date_transaction'])) ?></strong>
                  <br><small class="muted"><?= date('H:i', strtotime($t['date_transaction'])) ?></small>
                </td>
                <td data-label="Reçu" style="text-align:center">
                  <a class="btn-recu-mini"
                     href="<?= $base ?>/client/recu.php?token=<?= htmlspecialchars($token) ?>"
                     target="_blank">🧾</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

</main>

<footer class="atf-footer" style="padding-bottom: 20px">
  © <?= date('Y') ?> AFRICAN TRUST FUND
</footer>

<!-- ============================================================
     BARRE DE NAVIGATION MOBILE
     ============================================================ -->
<nav class="mobile-nav" id="mobileNav">
  <div class="mobile-nav-inner">
    <a href="<?= $base ?>/client/dashboard.php" class="nav-item active">
      <span class="nav-icon">🏠</span>
      <span class="nav-label">Accueil</span>
    </a>
    <a href="<?= $base ?>/client/epargne.php" class="nav-item">
      <span class="nav-icon">💰</span>
      <span class="nav-label">Épargne</span>
    </a>
    <div class="nav-fab-wrapper">
      <button class="nav-fab" id="navFab" type="button" aria-label="Nouvelle opération">＋</button>
    </div>
    <a href="<?= $base ?>/client/transactions.php" class="nav-item">
      <span class="nav-icon">📋</span>
      <span class="nav-label">Historique</span>
    </a>
    <a href="<?= $base ?>/client/profil.php" class="nav-item">
      <span class="nav-icon">👤</span>
      <span class="nav-label">Profil</span>
    </a>
    
  </div>
</nav>

<!-- MENU POPUP DU FAB -->
<div class="fab-overlay" id="fabOverlay"></div>

<div class="fab-menu" id="fabMenu">
  <a href="<?= $base ?>/client/depot.php" class="fab-menu-item depot">
    <span class="ico">＋</span><span>Dépôt</span>
  </a>
  <a href="<?= $base ?>/client/retrait.php" class="fab-menu-item retrait">
    <span class="ico">－</span><span>Retrait</span>
  </a>
  <a href="<?= $base ?>/client/virement.php" class="fab-menu-item virement">
    <span class="ico">⇄</span><span>Virement</span>
  </a>
  <a href="<?= $base ?>/client/epargne.php" class="fab-menu-item epargne">
    <span class="ico">💰</span><span>Épargne</span>
  </a>
</div>

<script>
/* ============================================================
   CONFIGURATION CHART.JS
   ============================================================ */
Chart.defaults.color = '#8A93A6';
Chart.defaults.font.family = "'Inter','Segoe UI',sans-serif";
Chart.defaults.font.size = 12;
Chart.defaults.borderColor = 'rgba(255,255,255,.06)';

const GOLD = '#F5B800';
const GREEN = '#10B981';
const BLUE = '#3B82F6';
const RED = '#EF4444';
const PURPLE = '#8B5CF6';

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
};

/* Graphique 1 : Évolution du solde */
(function() {
  const ctx = document.getElementById('chartSolde').getContext('2d');
  new Chart(ctx, {
    type: 'line',
    data: {
      labels: <?= json_encode($labels_30j) ?>,
      datasets: [{
        label: 'Solde CDF',
        data: <?= json_encode($valeurs_30j) ?>,
        borderColor: GOLD,
        backgroundColor: makeGradient(ctx, 'rgba(245,184,0,.35)'),
        borderWidth: 3,
        tension: 0.4,
        fill: true,
        pointBackgroundColor: GOLD,
        pointBorderColor: '#0B0E14',
        pointBorderWidth: 2,
        pointRadius: 3,
        pointHoverRadius: 6,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: { ...tooltipDefaults, callbacks: { label: (c) => ' ' + c.parsed.y.toLocaleString('fr-FR') + ' CDF' } }
      },
      scales: axisOptions
    }
  });
})();

/* Graphique 2 : Répartition */
(function() {
  const data = [
    <?= (float)$repartition_tx['depots'] ?>,
    <?= (float)$repartition_tx['retraits'] ?>,
    <?= (float)$repartition_tx['virements'] ?>
  ];
  const total = data.reduce((a, b) => a + b, 0);

  if (total === 0) {
    document.getElementById('chartRepartition').parentElement.innerHTML =
      '<p class="muted center" style="line-height:280px">Aucune donnée</p>';
    return;
  }

  new Chart(document.getElementById('chartRepartition'), {
    type: 'doughnut',
    data: {
      labels: ['Dépôts', 'Retraits', 'Virements'],
      datasets: [{
        data: data,
        backgroundColor: [GREEN, RED, BLUE],
        borderColor: '#1A2130',
        borderWidth: 4,
        hoverOffset: 12,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      cutout: '62%',
      plugins: {
        legend: { position: 'bottom', labels: { usePointStyle: true, padding: 14, color: '#E8ECF3', font: { size: 12, weight: '700' } } },
        tooltip: { ...tooltipDefaults }
      }
    }
  });
})();

/* Graphique 3 : Dépôts vs Retraits */
(function() {
  new Chart(document.getElementById('chartDepRet'), {
    type: 'bar',
    data: {
      labels: ['Dépôts', 'Retraits'],
      datasets: [{
        data: [
          <?= (float)$stats_transactions['depots_cdf'] ?>,
          <?= (float)$stats_transactions['retraits_cdf'] ?>
        ],
        backgroundColor: ['rgba(16,185,129,.6)', 'rgba(239,68,68,.6)'],
        borderColor: [GREEN, RED],
        borderWidth: 2,
        borderRadius: 10,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false }, tooltip: { ...tooltipDefaults } },
      scales: axisOptions
    }
  });
})();

/* Graphique 4 : Évolution des intérêts */
(function() {
  const ctx = document.getElementById('chartInterets').getContext('2d');
  new Chart(ctx, {
    type: 'line',
    data: {
      labels: <?= json_encode($labels_12m) ?>,
      datasets: [{
        label: 'Intérêts',
        data: <?= json_encode($data_interets_12m) ?>,
        borderColor: GREEN,
        backgroundColor: makeGradient(ctx, 'rgba(16,185,129,.4)'),
        borderWidth: 3,
        tension: 0.4,
        fill: true,
        pointBackgroundColor: GREEN,
        pointBorderColor: '#0B0E14',
        pointBorderWidth: 2,
        pointRadius: 4,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false }, tooltip: { ...tooltipDefaults } },
      scales: axisOptions
    }
  });
})();

/* 🆕 Graphique 5 : Capital par produit */
(function() {
  <?php
    $produits_labels = [];
    $produits_capital = [];
    $produits_couleurs = [];
    $palette = ['#8B5CF6', '#3B82F6', '#10B981', '#F5B800', '#EF4444', '#EC4899', '#06B6D4'];
    foreach ($capital_par_produit as $i => $prod) {
        $produits_labels[] = $prod['produit_nom'];
        $produits_capital[] = (float)$prod['capital'];
        $produits_couleurs[] = $palette[$i % count($palette)];
    }
  ?>
  const data = <?= json_encode($produits_capital) ?>;
  const total = data.reduce((a, b) => a + b, 0);

  if (total === 0) {
    document.getElementById('chartPlacements').parentElement.innerHTML =
      '<p class="muted center" style="line-height:280px">Aucun placement actif</p>';
    return;
  }

  new Chart(document.getElementById('chartPlacements'), {
    type: 'doughnut',
    data: {
      labels: <?= json_encode($produits_labels) ?>,
      datasets: [{
        data: data,
        backgroundColor: <?= json_encode($produits_couleurs) ?>,
        borderColor: '#1A2130',
        borderWidth: 4,
        hoverOffset: 12,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      cutout: '55%',
      plugins: {
        legend: { position: 'bottom', labels: { usePointStyle: true, padding: 12, color: '#E8ECF3', font: { size: 11, weight: '700' } } },
        tooltip: {
          ...tooltipDefaults,
          callbacks: {
            label: (c) => ' ' + c.parsed.toLocaleString('fr-FR') + ' CDF'
          }
        }
      }
    }
  });
})();

/* ============================================================
   MENU FAB
   ============================================================ */
(function() {
  const fab = document.getElementById('navFab');
  const menu = document.getElementById('fabMenu');
  const overlay = document.getElementById('fabOverlay');

  if (!fab || !menu || !overlay) return;

  function toggleMenu() {
    const isOpen = menu.classList.contains('open');
    if (isOpen) {
      menu.classList.remove('open');
      overlay.classList.remove('open');
      fab.classList.remove('active');
    } else {
      menu.classList.add('open');
      overlay.classList.add('open');
      fab.classList.add('active');
    }
  }

  fab.addEventListener('click', toggleMenu);
  overlay.addEventListener('click', toggleMenu);

  menu.querySelectorAll('a').forEach(link => {
    link.addEventListener('click', () => {
      menu.classList.remove('open');
      overlay.classList.remove('open');
      fab.classList.remove('active');
    });
  });
})();

/* Effet "tap" sur les items de nav */
document.querySelectorAll('.nav-item').forEach(item => {
  item.addEventListener('touchstart', function() {
    this.style.transform = 'scale(0.95)';
  }, { passive: true });
  item.addEventListener('touchend', function() {
    this.style.transform = '';
  });
});

console.log('%c📱 Dashboard Client ATF — Premium', 'color:#F5B800;font-weight:bold;font-size:14px');
console.log('%c💎 Affichage capital investi & intérêts cumulés activé', 'color:#A78BFA;font-size:12px');
</script>

<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>