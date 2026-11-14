<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/actionnaires.php';
requireRole('admin');

$pdo = Database::getConnection();
$admin_id = (int)$_SESSION['admin_id'];

/* ============================================================
   FONCTION HELPER — Compter les clients en ligne
   ============================================================ */
function countOnlineClients(PDO $pdo): int {
    try {
        return (int)$pdo->query("
            SELECT COUNT(*) FROM Client 
            WHERE last_login >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
        ")->fetchColumn();
    } catch (Throwable $e) { return 0; }
}

/* ============================================================
   STATISTIQUES GÉNÉRALES
   ============================================================ */
$stats = [
    'clients_total'     => (int)$pdo->query("SELECT COUNT(*) FROM Client")->fetchColumn(),
    'clients_actifs'    => (int)$pdo->query("SELECT COUNT(*) FROM Client WHERE statut='actif'")->fetchColumn(),
    'clients_online'    => countOnlineClients($pdo),
    'clients_nouveaux_mois' => (int)$pdo->query("SELECT COUNT(*) FROM Client WHERE MONTH(created_at)=MONTH(NOW()) AND YEAR(created_at)=YEAR(NOW())")->fetchColumn(),
    'caissiers'         => (int)$pdo->query("SELECT COUNT(*) FROM Caissier WHERE statut='actif'")->fetchColumn(),
    'produits'          => (int)$pdo->query("SELECT COUNT(*) FROM Produits_Epargne WHERE actif=1")->fetchColumn(),
    'placements_actifs' => (int)$pdo->query("SELECT COUNT(*) FROM Placements WHERE statut='actif'")->fetchColumn(),
    'capital_place'     => (float)$pdo->query("SELECT COALESCE(SUM(montant_actuel),0) FROM Placements WHERE statut='actif'")->fetchColumn(),
    'interets_verses'   => (float)$pdo->query("SELECT COALESCE(SUM(montant_interet),0) FROM Interets_Historique")->fetchColumn(),
    'commissions'       => (float)$pdo->query("SELECT COALESCE(SUM(montant_commission),0) FROM Commissions")->fetchColumn(),
    'solde_global_cdf'  => (float)$pdo->query("SELECT COALESCE(SUM(solde_cdf),0) FROM Client")->fetchColumn(),
    'solde_global_usd'  => (float)$pdo->query("SELECT COALESCE(SUM(solde_usd),0) FROM Client")->fetchColumn(),
    'transactions'      => (int)$pdo->query("SELECT COUNT(*) FROM Transactions")->fetchColumn(),
    'volume_total'      => (float)$pdo->query("SELECT COALESCE(SUM(montant),0) FROM Transactions")->fetchColumn(),
    'logs_24h'          => (int)$pdo->query("SELECT COUNT(*) FROM Audit_Logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn(),
];

/* ============================================================
   STATISTIQUES PRÊTS
   ============================================================ */
$stats_prets = [
    'total' => 0, 'en_attente' => 0, 'actifs' => 0, 'en_retard' => 0,
    'encours_cdf' => 0, 'encours_usd' => 0, 'montant_decaisse' => 0,
];
try {
    $row = $pdo->query("
        SELECT 
            COUNT(*) AS total,
            COUNT(CASE WHEN statut IN ('soumis','en_analyse') THEN 1 END) AS en_attente,
            COUNT(CASE WHEN statut IN ('decaisse','en_cours') THEN 1 END) AS actifs,
            COUNT(CASE WHEN statut IN ('en_retard','contentieux') THEN 1 END) AS en_retard,
            COALESCE(SUM(CASE WHEN statut IN ('decaisse','en_cours','en_retard') AND devise='CDF' THEN montant_restant_du ELSE 0 END), 0) AS encours_cdf,
            COALESCE(SUM(CASE WHEN statut IN ('decaisse','en_cours','en_retard') AND devise='USD' THEN montant_restant_du ELSE 0 END), 0) AS encours_usd,
            COALESCE(SUM(montant_accorde), 0) AS montant_decaisse
        FROM Prets
    ")->fetch();
    $stats_prets = $row;
} catch (Throwable $e) { /* table absente */ }

/* ============================================================
   STATISTIQUES ENTREPRISES
   ============================================================ */
$stats_entreprises = ['total' => 0, 'verifiees' => 0, 'en_attente' => 0, 'capital' => 0];
try {
    $row = $pdo->query("
        SELECT 
            COUNT(*) AS total,
            COUNT(CASE WHEN statut_verification = 'verifie' THEN 1 END) AS verifiees,
            COUNT(CASE WHEN statut_verification = 'en_attente' THEN 1 END) AS en_attente,
            COALESCE(SUM(capital_social), 0) AS capital
        FROM Entreprises
    ")->fetch();
    $stats_entreprises = $row;
} catch (Throwable $e) { /* table absente */ }

/* ============================================================
   STATISTIQUES ACTIONNAIRES
   ============================================================ */
$stats_actionnaires = [
    'total' => (int)$pdo->query("SELECT COUNT(*) FROM Actionnaires")->fetchColumn(),
    'actifs' => (int)$pdo->query("SELECT COUNT(*) FROM Actionnaires WHERE statut='actif'")->fetchColumn(),
    'total_parts' => (int)$pdo->query("SELECT COALESCE(SUM(nombre_parts),0) FROM Actionnaires")->fetchColumn(),
    'capital_total' => (float)$pdo->query("SELECT COALESCE(SUM(capital_investi_cdf),0) FROM Actionnaires")->fetchColumn(),
    'dividendes_payes' => 0,
    'parts_en_attente' => 0,
];
try {
    $stats_actionnaires['dividendes_payes'] = (float)$pdo->query("SELECT COALESCE(SUM(montant_dividende),0) FROM Dividendes WHERE statut='paye'")->fetchColumn();
    $stats_actionnaires['parts_en_attente'] = (int)$pdo->query("SELECT COUNT(*) FROM Parts_Sociales WHERE statut='en_attente'")->fetchColumn();
} catch (Throwable $e) { /* */ }

/* ============================================================
   TOP CLIENTS
   ============================================================ */
$top_clients = $pdo->query("
    SELECT c.*,
        (SELECT COALESCE(SUM(montant),0) FROM Transactions t WHERE t.id_client = c.id_client) AS volume_total
    FROM Client c
    WHERE c.statut = 'actif'
    ORDER BY volume_total DESC
    LIMIT 5
")->fetchAll();

/* ============================================================
   TOP ACTIONNAIRES
   ============================================================ */
$top_actionnaires = $pdo->query("
    SELECT a.*, 
        ROUND((a.nombre_parts / (SELECT COALESCE(SUM(nombre_parts),1) FROM Actionnaires)) * 100, 2) AS pourcentage
    FROM Actionnaires a
    WHERE a.statut = 'actif' AND a.nombre_parts > 0
    ORDER BY a.nombre_parts DESC
    LIMIT 5
")->fetchAll();

/* ============================================================
   CLIENTS EN LIGNE
   ============================================================ */
$clients_online = $pdo->query("
    SELECT id_client, nom, postnom, prenom, numero_compte, solde_cdf, solde_usd, last_login
    FROM Client
    WHERE last_login >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
    ORDER BY last_login DESC
    LIMIT 8
")->fetchAll();

/* ============================================================
   DERNIÈRES TRANSACTIONS
   ============================================================ */
$transactions = $pdo->query("
    SELECT t.*, c.nom, c.postnom, c.numero_compte
    FROM Transactions t
    JOIN Client c ON c.id_client = t.id_client
    ORDER BY t.date_transaction DESC
    LIMIT 8
")->fetchAll();

/* ============================================================
   DERNIERS LOGS
   ============================================================ */
$logs = [];
try {
    $logs = $pdo->query("
        SELECT * FROM Audit_Logs
        ORDER BY created_at DESC
        LIMIT 8
    ")->fetchAll();
} catch (Throwable $e) { /* */ }

/* ============================================================
   DONNÉES GRAPHIQUES — 30 jours
   ============================================================ */
$labels_30j = [];
$data_depots_30j = [];
$data_retraits_30j = [];
$data_placements_30j = [];

for ($i = 29; $i >= 0; $i--) {
    $date = date('Y-m-d', strtotime("-$i days"));
    $labels_30j[] = date('d/m', strtotime($date));

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant),0) FROM Transactions WHERE type_transaction='Depot' AND DATE(date_transaction)=?");
    $stmt->execute([$date]);
    $data_depots_30j[] = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant),0) FROM Transactions WHERE type_transaction='Retrait' AND DATE(date_transaction)=?");
    $stmt->execute([$date]);
    $data_retraits_30j[] = (float)$stmt->fetchColumn();
}

/* ============================================================
   ÉVOLUTION CLIENTS 12 MOIS
   ============================================================ */
$labels_12m = [];
$data_clients_12m = [];
$data_parts_12m = [];

for ($i = 11; $i >= 0; $i--) {
    $mois = date('Y-m', strtotime("-$i months"));
    $labels_12m[] = date('M Y', strtotime("-$i months"));

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM Client WHERE DATE_FORMAT(created_at, '%Y-%m') = ?");
    $stmt->execute([$mois]);
    $data_clients_12m[] = (int)$stmt->fetchColumn();

    try {
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(nombre_parts),0) FROM Actionnaires WHERE DATE_FORMAT(created_at, '%Y-%m') <= ?");
        $stmt->execute([$mois]);
        $data_parts_12m[] = (int)$stmt->fetchColumn();
    } catch (Throwable $e) { $data_parts_12m[] = 0; }
}

/* Répartition types actionnaires */
$repartition_types = [];
try {
    $repartition_types = $pdo->query("
        SELECT type_actionnaire, COUNT(*) AS nb, COALESCE(SUM(nombre_parts),0) AS total_parts
        FROM Actionnaires
        WHERE statut = 'actif'
        GROUP BY type_actionnaire
    ")->fetchAll();
} catch (Throwable $e) { /* */ }

/* Répartition soldes par devise */
$repartition_soldes = [
    'CDF' => $stats['solde_global_cdf'],
    'USD' => $stats['solde_global_usd'] * 2800, // équivalent CDF
];

$base = '/african-trust-fund';

logAction($pdo, 'admin', $admin_id, 'DASHBOARD_VIEW', 'Consultation tableau de bord analytique');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dashboard Analytique — ATF Admin</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/admin-premium.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
/* ============================================================
   VARIABLES
   ============================================================ */
:root {
  --gradient-gold: linear-gradient(135deg, #F5B800 0%, #D4AF37 100%);
  --gradient-purple: linear-gradient(135deg, #8B5CF6 0%, #7C3AED 100%);
  --gradient-green: linear-gradient(135deg, #10B981 0%, #059669 100%);
  --gradient-blue: linear-gradient(135deg, #3B82F6 0%, #2563EB 100%);
  --gradient-red: linear-gradient(135deg, #EF4444 0%, #DC2626 100%);
}

/* ============================================================
   HERO SECTION — Bannière analytics
   ============================================================ */
.analytics-hero {
  padding: 32px 36px;
  border-radius: 24px;
  background: linear-gradient(135deg, rgba(245,184,0,.12), rgba(139,92,246,.08)),
              radial-gradient(circle at 80% 20%, rgba(245,184,0,.18), transparent 60%);
  border: 1px solid rgba(245,184,0,.3);
  margin-bottom: 28px;
  position: relative;
  overflow: hidden;
}
.analytics-hero::before {
  content: "";
  position: absolute;
  top: -50%; right: -10%;
  width: 400px; height: 400px;
  border-radius: 50%;
  background: radial-gradient(circle, rgba(245,184,0,.15), transparent 70%);
  animation: float 8s ease-in-out infinite;
}
.analytics-hero h1 {
  color: #fff;
  font-size: 1.8rem;
  margin-bottom: 8px;
  position: relative;
  z-index: 1;
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}
.analytics-hero h1 span { color: var(--gold); }
.analytics-hero p {
  color: var(--muted);
  font-size: .95rem;
  position: relative;
  z-index: 1;
}

.hero-badges {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  margin-top: 18px;
  position: relative;
  z-index: 1;
}
.hero-badge {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 8px 14px;
  border-radius: 999px;
  background: rgba(11,14,20,.6);
  border: 1px solid var(--border);
  font-size: .8rem;
  font-weight: 700;
  color: var(--text);
  backdrop-filter: blur(10px);
}
.hero-badge.gold { background: rgba(245,184,0,.15); border-color: rgba(245,184,0,.4); color: var(--gold); }
.hero-badge.green { background: rgba(16,185,129,.15); border-color: rgba(16,185,129,.4); color: #6EE7B7; }
.hero-badge.purple { background: rgba(139,92,246,.15); border-color: rgba(139,92,246,.4); color: #A78BFA; }
.hero-badge.blue { background: rgba(59,130,246,.15); border-color: rgba(59,130,246,.4); color: #93B4FF; }

.live-dot {
  display: inline-block;
  width: 8px; height: 8px;
  border-radius: 50%;
  background: #10B981;
  box-shadow: 0 0 0 0 rgba(16,185,129,.7);
  animation: pulse-live 2s infinite;
}
@keyframes pulse-live {
  0% { box-shadow: 0 0 0 0 rgba(16,185,129,.7); }
  70% { box-shadow: 0 0 0 10px rgba(16,185,129,0); }
  100% { box-shadow: 0 0 0 0 rgba(16,185,129,0); }
}

/* ============================================================
   KPI CARDS — Nouvelle génération
   ============================================================ */
.kpi-analytics-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 16px;
  margin-bottom: 24px;
}

.kpi-analytics {
  padding: 22px;
  border-radius: 18px;
  background: linear-gradient(145deg, var(--card), var(--card-2));
  border: 1px solid var(--border);
  position: relative;
  overflow: hidden;
  transition: all .35s cubic-bezier(.4,0,.2,1);
}
.kpi-analytics::before {
  content: "";
  position: absolute;
  top: 0; left: 0;
  width: 4px; height: 100%;
  background: var(--accent, var(--gold));
}
.kpi-analytics:hover {
  transform: translateY(-4px);
  border-color: var(--accent, var(--gold));
  box-shadow: 0 20px 50px rgba(0,0,0,.3);
}
.kpi-analytics .kpi-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 14px;
}
.kpi-analytics .kpi-icon {
  width: 46px; height: 46px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.5rem;
  background: rgba(245,184,0,.15);
  color: var(--gold);
  transition: transform .3s;
}
.kpi-analytics:hover .kpi-icon {
  transform: scale(1.1) rotate(-6deg);
}
.kpi-analytics.gold .kpi-icon { background: rgba(245,184,0,.15); color: var(--gold); }
.kpi-analytics.green .kpi-icon { background: rgba(16,185,129,.15); color: #6EE7B7; }
.kpi-analytics.blue .kpi-icon { background: rgba(59,130,246,.15); color: #93B4FF; }
.kpi-analytics.purple .kpi-icon { background: rgba(139,92,246,.15); color: #A78BFA; }
.kpi-analytics.red .kpi-icon { background: rgba(239,68,68,.15); color: #FCA5A5; }

.kpi-analytics.gold { --accent: #F5B800; }
.kpi-analytics.green { --accent: #10B981; }
.kpi-analytics.blue { --accent: #3B82F6; }
.kpi-analytics.purple { --accent: #8B5CF6; }
.kpi-analytics.red { --accent: #EF4444; }

.kpi-analytics .kpi-value {
  font-size: 1.9rem;
  font-weight: 900;
  color: #fff;
  line-height: 1;
  letter-spacing: -1px;
  word-break: break-word;
}
.kpi-analytics .kpi-label {
  color: var(--muted);
  font-size: .78rem;
  text-transform: uppercase;
  letter-spacing: 1px;
  font-weight: 700;
  margin-top: 8px;
}
.kpi-analytics .kpi-sub {
  color: var(--muted);
  font-size: .75rem;
  margin-top: 6px;
  display: flex;
  align-items: center;
  gap: 6px;
}
.kpi-analytics .kpi-trend {
  padding: 3px 10px;
  border-radius: 999px;
  font-size: .7rem;
  font-weight: 800;
}
.kpi-trend.up { background: rgba(16,185,129,.15); color: #6EE7B7; }
.kpi-trend.down { background: rgba(239,68,68,.15); color: #FCA5A5; }
.kpi-trend.stable { background: rgba(107,114,128,.15); color: #9CA3AF; }

/* ============================================================
   SECTIONS DE SECTION
   ============================================================ */
.section-divider {
  display: flex;
  align-items: center;
  gap: 16px;
  margin: 32px 0 20px;
}
.section-divider h2 {
  color: var(--gold);
  font-size: 1.15rem;
  margin: 0;
  display: flex;
  align-items: center;
  gap: 10px;
  white-space: nowrap;
}
.section-divider .line {
  flex: 1;
  height: 1px;
  background: linear-gradient(90deg, rgba(245,184,0,.4), transparent);
}
.section-divider .badge-count {
  padding: 4px 12px;
  border-radius: 999px;
  background: rgba(245,184,0,.15);
  color: var(--gold);
  font-size: .75rem;
  font-weight: 800;
}

/* ============================================================
   BLOCS DE SYNTHÈSE
   ============================================================ */
.synthesis-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
  gap: 18px;
  margin-bottom: 28px;
}

.synthesis-card {
  padding: 22px;
  border-radius: 18px;
  background: linear-gradient(145deg, var(--card), var(--card-2));
  border: 1px solid var(--border);
  position: relative;
  overflow: hidden;
  transition: all .3s;
}
.synthesis-card:hover { border-color: rgba(245,184,0,.4); }
.synthesis-card.pret::before,
.synthesis-card.entreprise::before,
.synthesis-card.actionnaire::before,
.synthesis-card.epargne::before {
  content: "";
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 3px;
}
.synthesis-card.pret::before { background: var(--gradient-blue); }
.synthesis-card.entreprise::before { background: var(--gradient-purple); }
.synthesis-card.actionnaire::before { background: var(--gradient-green); }
.synthesis-card.epargne::before { background: var(--gradient-gold); }

.synthesis-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 18px;
}
.synthesis-header h3 {
  color: var(--text);
  font-size: 1rem;
  margin: 0;
  display: flex;
  align-items: center;
  gap: 10px;
}
.synthesis-header .icon-badge {
  width: 38px; height: 38px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.2rem;
}
.synthesis-card.pret .icon-badge { background: rgba(59,130,246,.15); color: #93B4FF; }
.synthesis-card.entreprise .icon-badge { background: rgba(139,92,246,.15); color: #A78BFA; }
.synthesis-card.actionnaire .icon-badge { background: rgba(16,185,129,.15); color: #6EE7B7; }
.synthesis-card.epargne .icon-badge { background: rgba(245,184,0,.15); color: var(--gold); }

.synthesis-main-value {
  font-size: 2rem;
  font-weight: 900;
  color: #fff;
  line-height: 1;
  letter-spacing: -1px;
}
.synthesis-main-label {
  color: var(--muted);
  font-size: .75rem;
  text-transform: uppercase;
  letter-spacing: 1px;
  font-weight: 700;
  margin-top: 6px;
}

.synthesis-stats {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
  margin-top: 16px;
  padding-top: 14px;
  border-top: 1px solid var(--border);
}
.synthesis-stat {
  text-align: center;
}
.synthesis-stat .val {
  font-size: 1.15rem;
  font-weight: 800;
  color: var(--gold);
}
.synthesis-stat .val.green { color: #6EE7B7; }
.synthesis-stat .val.red { color: #FCA5A5; }
.synthesis-stat .val.blue { color: #93B4FF; }
.synthesis-stat .lbl {
  font-size: .68rem;
  color: var(--muted);
  text-transform: uppercase;
  font-weight: 700;
  margin-top: 2px;
}

.synthesis-actions {
  display: flex;
  gap: 8px;
  margin-top: 14px;
  flex-wrap: wrap;
}
.btn-mini {
  padding: 8px 14px;
  border-radius: 999px;
  font-size: .75rem;
  font-weight: 700;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  border: 1px solid;
  transition: all .2s;
}
.btn-mini.gold { background: rgba(245,184,0,.1); color: var(--gold); border-color: rgba(245,184,0,.3); }
.btn-mini.gold:hover { background: rgba(245,184,0,.2); }
.btn-mini.blue { background: rgba(59,130,246,.1); color: #93B4FF; border-color: rgba(59,130,246,.3); }
.btn-mini.blue:hover { background: rgba(59,130,246,.2); }
.btn-mini.purple { background: rgba(139,92,246,.1); color: #A78BFA; border-color: rgba(139,92,246,.3); }
.btn-mini.purple:hover { background: rgba(139,92,246,.2); }
.btn-mini.green { background: rgba(16,185,129,.1); color: #6EE7B7; border-color: rgba(16,185,129,.3); }
.btn-mini.green:hover { background: rgba(16,185,129,.2); }

/* ============================================================
   GRAPHIQUES
   ============================================================ */
.charts-grid {
  display: grid;
  grid-template-columns: 1.6fr 1fr;
  gap: 20px;
  margin-bottom: 28px;
}
.charts-grid.three {
  grid-template-columns: 1fr 1fr 1fr;
}
@media (max-width: 1100px) {
  .charts-grid, .charts-grid.three { grid-template-columns: 1fr; }
}

.chart-analytics {
  padding: 22px;
  border-radius: 18px;
  background: linear-gradient(145deg, var(--card), var(--card-2));
  border: 1px solid var(--border);
  position: relative;
  overflow: hidden;
}
.chart-analytics h3 {
  color: var(--gold);
  font-size: .98rem;
  margin-bottom: 18px;
  display: flex;
  align-items: center;
  gap: 10px;
}
.chart-analytics .chart-wrap {
  position: relative;
  height: 300px;
}
.chart-analytics.compact .chart-wrap { height: 240px; }

/* ============================================================
   TABLES PREMIUM
   ============================================================ */
.table-analytics {
  width: 100%;
  border-collapse: collapse;
}
.table-analytics thead th {
  padding: 12px;
  text-align: left;
  font-size: .72rem;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: .5px;
  font-weight: 800;
  border-bottom: 2px solid var(--border);
}
.table-analytics tbody td {
  padding: 14px 12px;
  border-bottom: 1px solid var(--border);
  font-size: .88rem;
}
.table-analytics tbody tr {
  transition: background .2s;
}
.table-analytics tbody tr:hover {
  background: rgba(245,184,0,.03);
}
.table-analytics .rank {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 30px; height: 30px;
  border-radius: 50%;
  background: var(--gradient-gold);
  color: #0B0E14;
  font-weight: 900;
  font-size: .82rem;
}
.table-analytics .rank.silver { background: linear-gradient(135deg, #C0C0C0, #A0A0A0); }
.table-analytics .rank.bronze { background: linear-gradient(135deg, #CD7F32, #B87333); }

.progress-bar-mini {
  height: 6px;
  background: rgba(11,14,20,.6);
  border-radius: 3px;
  overflow: hidden;
  margin-top: 6px;
}
.progress-bar-mini > div {
  height: 100%;
  background: var(--gradient-gold);
  border-radius: 3px;
  transition: width .6s;
}

/* ============================================================
   ACTIVITÉ RECENTE
   ============================================================ */
.activity-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.activity-item {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 12px 14px;
  border-radius: 12px;
  background: rgba(11,14,20,.4);
  border: 1px solid var(--border);
  transition: all .2s;
}
.activity-item:hover {
  border-color: rgba(245,184,0,.3);
  transform: translateX(4px);
}
.activity-icon {
  width: 34px; height: 34px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1rem;
  flex-shrink: 0;
}
.activity-icon.gold { background: rgba(245,184,0,.15); color: var(--gold); }
.activity-icon.green { background: rgba(16,185,129,.15); color: #6EE7B7; }
.activity-icon.blue { background: rgba(59,130,246,.15); color: #93B4FF; }
.activity-icon.purple { background: rgba(139,92,246,.15); color: #A78BFA; }
.activity-icon.red { background: rgba(239,68,68,.15); color: #FCA5A5; }

.activity-content { flex: 1; min-width: 0; }
.activity-content .title { font-weight: 700; color: var(--text); font-size: .85rem; }
.activity-content .sub { color: var(--muted); font-size: .75rem; margin-top: 2px; }
.activity-content .sub code { color: #93B4FF; font-size: .72rem; }
.activity-time { color: var(--muted); font-size: .72rem; flex-shrink: 0; }

/* ============================================================
   RACCOURCIS DE NAVIGATION
   ============================================================ */
.nav-shortcuts {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
  gap: 12px;
  margin-bottom: 28px;
}
.nav-shortcut {
  padding: 18px;
  border-radius: 14px;
  background: linear-gradient(145deg, var(--card), var(--card-2));
  border: 1px solid var(--border);
  text-decoration: none;
  color: var(--text);
  text-align: center;
  transition: all .3s;
  position: relative;
  overflow: hidden;
}
.nav-shortcut::before {
  content: "";
  position: absolute;
  top: 0; left: -100%;
  width: 100%; height: 100%;
  background: linear-gradient(90deg, transparent, rgba(245,184,0,.15), transparent);
  transition: left .6s;
}
.nav-shortcut:hover::before { left: 100%; }
.nav-shortcut:hover {
  transform: translateY(-4px);
  border-color: var(--gold);
  box-shadow: 0 16px 40px rgba(245,184,0,.15);
}
.nav-shortcut .ico {
  font-size: 1.8rem;
  display: block;
  margin-bottom: 8px;
}
.nav-shortcut .lbl {
  font-weight: 700;
  font-size: .85rem;
  display: block;
}
.nav-shortcut .sub {
  color: var(--muted);
  font-size: .72rem;
  margin-top: 4px;
  display: block;
}

/* ============================================================
   MOBILE
   ============================================================ */
@media (max-width: 768px) {
  .analytics-hero { padding: 22px 20px; }
  .analytics-hero h1 { font-size: 1.35rem; }
  .kpi-analytics-grid { grid-template-columns: 1fr 1fr; }
  .synthesis-grid { grid-template-columns: 1fr; }
  .chart-analytics .chart-wrap { height: 240px; }
  .table-analytics thead { display: none; }
  .table-analytics tbody tr { display: block; padding: 14px; margin-bottom: 10px; border: 1px solid var(--border); border-radius: 12px; }
  .table-analytics tbody td { display: flex; justify-content: space-between; padding: 6px 0; border: none; text-align: right; }
  .table-analytics tbody td::before { content: attr(data-label); color: var(--muted); font-size: .72rem; text-transform: uppercase; font-weight: 700; text-align: left; flex: 1; }
}

@media (max-width: 480px) {
  .kpi-analytics-grid { grid-template-columns: 1fr; }
  .kpi-analytics .kpi-value { font-size: 1.5rem; }
  .synthesis-main-value { font-size: 1.5rem; }
}

/* ============================================================
   ANIMATIONS
   ============================================================ */
@keyframes float {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-20px); }
}

/* Barre de progression top */
.top-progress {
  position: fixed;
  top: 0; left: 0;
  height: 3px;
  background: var(--gradient-gold);
  z-index: 9999;
  transition: width .1s;
  width: 0;
}
</style>
</head>
<body class="dark-auth">

<div class="top-progress" id="topProgress"></div>

<?php require __DIR__ . '/../includes/header.php'; ?>

<div class="admin-layout">
  <aside class="admin-sidebar animate-left">
    <h3>📊 Vue d'ensemble</h3>
    <a href="<?= $base ?>/admin/dashboard.php" class="active">
      <span class="ico">📊</span> Dashboard
    </a>

    <h3 style="margin-top:20px">👥 Gestion</h3>
    <a href="<?= $base ?>/admin/clients.php">
      <span class="ico">👥</span> Clients
      <span class="badge-count gold"><?= $stats['clients_total'] ?></span>
    </a>
    <a href="<?= $base ?>/admin/caissiers.php">
      <span class="ico">💼</span> Caissiers
    </a>
    <a href="<?= $base ?>/admin/entreprises.php">
      <span class="ico">🏢</span> Entreprises
      <?php if ((int)$stats_entreprises['total'] > 0): ?>
        <span class="badge-count purple"><?= $stats_entreprises['total'] ?></span>
      <?php endif; ?>
    </a>

    <h3 style="margin-top:20px">💳 Prêts & Crédits</h3>
    <a href="<?= $base ?>/admin/prets.php">
      <span class="ico">💳</span> Prêts
      <?php if ((int)$stats_prets['en_attente'] > 0): ?>
        <span class="badge-count"><?= $stats_prets['en_attente'] ?></span>
      <?php endif; ?>
    </a>
    <a href="<?= $base ?>/admin/prets_produits.php">
      <span class="ico">📋</span> Produits prêt
    </a>
    <a href="<?= $base ?>/admin/prets_echeances.php">
      <span class="ico">📅</span> Échéances
    </a>
    <a href="<?= $base ?>/admin/rapport_prets.php">
      <span class="ico">📈</span> Rapport prêts
    </a>
    <a href="<?= $base ?>/admin/gains_systeme.php"><span class="ico">💰</span> Gains système</a>

    <h3 style="margin-top:20px">🏛️ Actionnaires</h3>
    <a href="<?= $base ?>/admin/actionnaires.php">
      <span class="ico">🏛️</span> Actionnaires
      <?php if ($stats_actionnaires['total'] > 0): ?>
        <span class="badge-count purple"><?= $stats_actionnaires['total'] ?></span>
      <?php endif; ?>
    </a>
    <a href="<?= $base ?>/admin/parts_sociales.php">
      <span class="ico">📜</span> Parts sociales
    </a>
    <a href="<?= $base ?>/admin/dividendes.php">
      <span class="ico">💰</span> Dividendes
    </a>

    <h3 style="margin-top:20px">💰 Épargne</h3>
    <a href="<?= $base ?>/admin/produits.php">
      <span class="ico">📈</span> Produits
    </a>
    <a href="<?= $base ?>/admin/placements.php">
      <span class="ico">💵</span> Placements
    </a>
    <a href="<?= $base ?>/admin/commissions.php">
      <span class="ico">💵</span> Commissions
    </a>

    <h3 style="margin-top:20px">📊 Analyse</h3>
    <a href="<?= $base ?>/admin/statistiques.php">
      <span class="ico">📈</span> Statistiques
    </a>
    <a href="<?= $base ?>/admin/transactions.php">
      <span class="ico">💳</span> Transactions
    </a>
    <a href="<?= $base ?>/admin/cartes.php">
      <span class="ico">💳</span> Cartes membres
    </a>

    <a href="<?= $base ?>/admin/parametres.php">
      <span class="ico">⚙️</span> Paramettres
    </a>
    <a href="<?= $base ?>/admin/tests_flux.php">
      <span class="ico">🧪</span> Tests flux
    </a>

    <h3 style="margin-top:20px">🔒 Sécurité</h3>
    <a href="<?= $base ?>/admin/logs.php">
      <span class="ico">📜</span> Journal
      <?php if ($stats['logs_24h'] > 0): ?>
        <span class="badge-count red"><?= $stats['logs_24h'] ?></span>
      <?php endif; ?>
    </a>
    <a href="<?= $base ?>/admin/securite_appareils.php">
    <span class="ico">🛡️</span> Sécurité
</a>
    <a href="<?= $base ?>/auth/logout.php">
      <span class="ico">🚪</span> Déconnexion
    </a>
  </aside>

  <main class="admin-main">

    <!-- ============================================================
         HERO ANALYTICS
         ============================================================ -->
    <section class="analytics-hero">
      <h1>
        <span>📊</span>
        Tableau de bord — <span><?= htmlspecialchars($_SESSION['admin_nom'] ?? 'Admin') ?></span>
      </h1>
      <p>Vue d'ensemble complète et temps réel de votre institution financière</p>

      <div class="hero-badges">
        <span class="hero-badge green">
          <span class="live-dot"></span>
          <span id="onlineCount"><?= $stats['clients_online'] ?></span> en ligne
        </span>
        <span class="hero-badge gold">👥 <?= $stats['clients_total'] ?> clients</span>
        <span class="hero-badge purple">🏛️ <?= $stats_actionnaires['total'] ?> actionnaires</span>
        <span class="hero-badge blue">💳 <?= (int)$stats_prets['actifs'] ?> prêts actifs</span>
        <span class="hero-badge">💰 <?= number_format($stats['solde_global_cdf'], 0, ',', ' ') ?> CDF</span>
        <span class="hero-badge">🕐 <?= date('d/m/Y H:i') ?></span>
      </div>
    </section>

    <!-- ============================================================
         NAVIGATION RAPIDE
         ============================================================ -->
    <div class="nav-shortcuts">
      
      <a href="<?= $base ?>/admin/clients.php" class="nav-shortcut">
        <span class="ico">👥</span>
        <span class="lbl">Clients</span>
        <span class="sub"><?= $stats['clients_actifs'] ?> actifs</span>
      </a>
      <a href="<?= $base ?>/admin/prets.php" class="nav-shortcut">
        <span class="ico">💳</span>
        <span class="lbl">Prêts</span>
        <span class="sub"><?= (int)$stats_prets['total'] ?> dossiers</span>
      </a>
      <a href="<?= $base ?>/admin/entreprises.php" class="nav-shortcut">
        <span class="ico">🏢</span>
        <span class="lbl">Entreprises</span>
        <span class="sub"><?= (int)$stats_entreprises['total'] ?> PM</span>
      </a>
      <a href="<?= $base ?>/admin/actionnaires.php" class="nav-shortcut">
        <span class="ico">🏛️</span>
        <span class="lbl">Actionnaires</span>
        <span class="sub"><?= $stats_actionnaires['actifs'] ?> actifs</span>
      </a>
      <a href="<?= $base ?>/admin/placements.php" class="nav-shortcut">
        <span class="ico">💰</span>
        <span class="lbl">Placements</span>
        <span class="sub"><?= $stats['placements_actifs'] ?> en cours</span>
      </a>
      <a href="<?= $base ?>/admin/transactions.php" class="nav-shortcut">
        <span class="ico">📋</span>
        <span class="lbl">Transactions</span>
        <span class="sub"><?= number_format($stats['transactions'], 0, ',', ' ') ?></span>
      </a>

      <a href="<?= $base ?>/admin/statistiques.php" class="nav-shortcut">
        <span class="ico">📈</span>
        <span class="lbl">Statistiques</span>
        <span class="sub">Analyses 12 mois</span>
      </a>
      <a href="<?= $base ?>/admin/logs.php" class="nav-shortcut">
        <span class="ico">📜</span>
        <span class="lbl">Journal</span>
        <span class="sub"><?= $stats['logs_24h'] ?> / 24h</span>
      </a>

      
    </div>

    <!-- ============================================================
         SECTION 1 : SYNTHÈSE MODULES
         ============================================================ -->
    <div class="section-divider">
      <h2>🎯 Vue Synthétique</h2>
      <div class="line"></div>
    </div>

    <div class="synthesis-grid">

      <!-- Module Prêts -->
      <div class="synthesis-card pret">
        <div class="synthesis-header">
          <h3><span class="icon-badge">💳</span> Prêts & Crédits</h3>
          <a class="btn-mini blue" href="<?= $base ?>/admin/prets.php">Voir →</a>
        </div>
        <div class="synthesis-main-value"><?= number_format((float)$stats_prets['montant_decaisse'], 0, ',', ' ') ?></div>
        <div class="synthesis-main-label">Total décaissé (CDF)</div>

        <div class="synthesis-stats">
          <div class="synthesis-stat">
            <div class="val blue"><?= (int)$stats_prets['actifs'] ?></div>
            <div class="lbl">Actifs</div>
          </div>
          <div class="synthesis-stat">
            <div class="val red"><?= (int)$stats_prets['en_retard'] ?></div>
            <div class="lbl">Retards</div>
          </div>
        </div>

        <div class="synthesis-actions">
          <a class="btn-mini blue" href="<?= $base ?>/admin/prets.php">📋 Liste</a>
          <a class="btn-mini blue" href="<?= $base ?>/admin/prets_echeances.php">📅 Échéances</a>
        </div>
      </div>

      <!-- Module Entreprises -->
      <div class="synthesis-card entreprise">
        <div class="synthesis-header">
          <h3><span class="icon-badge">🏢</span> Entreprises</h3>
          <a class="btn-mini purple" href="<?= $base ?>/admin/entreprises.php">Voir →</a>
        </div>
        <div class="synthesis-main-value"><?= number_format((float)$stats_entreprises['capital'], 0, ',', ' ') ?></div>
        <div class="synthesis-main-label">Capital social total (CDF)</div>

        <div class="synthesis-stats">
          <div class="synthesis-stat">
            <div class="val green"><?= (int)$stats_entreprises['verifiees'] ?></div>
            <div class="lbl">Vérifiées</div>
          </div>
          <div class="synthesis-stat">
            <div class="val" style="color:#FCD34D"><?= (int)$stats_entreprises['en_attente'] ?></div>
            <div class="lbl">En attente</div>
          </div>
        </div>

        <div class="synthesis-actions">
          <a class="btn-mini purple" href="<?= $base ?>/admin/entreprises.php">🏢 Liste</a>
          <a class="btn-mini purple" href="<?= $base ?>/admin/entreprise_nouvelle.php">➕ Ajouter</a>
        </div>
      </div>

      <!-- Module Actionnaires -->
      <div class="synthesis-card actionnaire">
        <div class="synthesis-header">
          <h3><span class="icon-badge">🏛️</span> Actionnaires</h3>
          <a class="btn-mini green" href="<?= $base ?>/admin/actionnaires.php">Voir →</a>
        </div>
        <div class="synthesis-main-value"><?= number_format($stats_actionnaires['capital_total'], 0, ',', ' ') ?></div>
        <div class="synthesis-main-label">Capital investi (CDF)</div>

        <div class="synthesis-stats">
          <div class="synthesis-stat">
            <div class="val green"><?= number_format($stats_actionnaires['total_parts'], 0, ',', ' ') ?></div>
            <div class="lbl">Parts</div>
          </div>
          <div class="synthesis-stat">
            <div class="val"><?= $stats_actionnaires['actifs'] ?></div>
            <div class="lbl">Actifs</div>
          </div>
        </div>

        <div class="synthesis-actions">
          <a class="btn-mini green" href="<?= $base ?>/admin/actionnaires.php">🏛️ Liste</a>
          <a class="btn-mini green" href="<?= $base ?>/admin/dividendes.php">💰 Dividendes</a>
        </div>
      </div>

      <!-- Module Épargne -->
      <div class="synthesis-card epargne">
        <div class="synthesis-header">
          <h3><span class="icon-badge">💰</span> Épargne</h3>
          <a class="btn-mini gold" href="<?= $base ?>/admin/placements.php">Voir →</a>
        </div>
        <div class="synthesis-main-value"><?= number_format($stats['capital_place'], 0, ',', ' ') ?></div>
        <div class="synthesis-main-label">Capital placé (CDF)</div>

        <div class="synthesis-stats">
          <div class="synthesis-stat">
            <div class="val green">+<?= number_format($stats['interets_verses'], 0, ',', ' ') ?></div>
            <div class="lbl">Intérêts</div>
          </div>
          <div class="synthesis-stat">
            <div class="val"><?= $stats['placements_actifs'] ?></div>
            <div class="lbl">Placements</div>
          </div>
        </div>

        <div class="synthesis-actions">
          <a class="btn-mini gold" href="<?= $base ?>/admin/placements.php">💵 Placements</a>
          <a class="btn-mini gold" href="<?= $base ?>/admin/commissions.php">💰 Commissions</a>
        </div>
      </div>

    </div>

    <!-- ============================================================
         SECTION 2 : KPIs FINANCIERS
         ============================================================ -->
    <div class="section-divider">
      <h2>💰 Indicateurs Financiers</h2>
      <div class="line"></div>
    </div>

    <div class="kpi-analytics-grid">
      <div class="kpi-analytics gold">
        <div class="kpi-header">
          <div class="kpi-icon">💰</div>
        </div>
        <div class="kpi-value"><?= number_format($stats['solde_global_cdf'], 0, ',', ' ') ?></div>
        <div class="kpi-label">Soldes CDF clients</div>
        <div class="kpi-sub">
          <span class="kpi-trend up">+<?= $stats['clients_nouveaux_mois'] ?></span>
          <span>nouveaux ce mois</span>
        </div>
      </div>

      <div class="kpi-analytics blue">
        <div class="kpi-header">
          <div class="kpi-icon">💵</div>
        </div>
        <div class="kpi-value">$ <?= number_format($stats['solde_global_usd'], 0, ',', ' ') ?></div>
        <div class="kpi-label">Soldes USD clients</div>
        <div class="kpi-sub">
          <span>≈ <?= number_format($stats['solde_global_usd'] * 2800, 0, ',', ' ') ?> CDF</span>
        </div>
      </div>

      <div class="kpi-analytics green">
        <div class="kpi-header">
          <div class="kpi-icon">📈</div>
        </div>
        <div class="kpi-value"><?= number_format($stats['interets_verses'], 0, ',', ' ') ?></div>
        <div class="kpi-label">Intérêts versés</div>
        <div class="kpi-sub">
          <span>Depuis le début</span>
        </div>
      </div>

      <div class="kpi-analytics purple">
        <div class="kpi-header">
          <div class="kpi-icon">💵</div>
        </div>
        <div class="kpi-value"><?= number_format($stats['commissions'], 0, ',', ' ') ?></div>
        <div class="kpi-label">Commissions perçues</div>
        <div class="kpi-sub">
          <span>Revenus ATF</span>
        </div>
      </div>
    </div>

    <!-- ============================================================
         SECTION 3 : GRAPHIQUES PRINCIPAUX
         ============================================================ -->
    <div class="section-divider">
      <h2>📊 Analyses Graphiques</h2>
      <div class="line"></div>
    </div>

    <div class="charts-grid">
      <div class="chart-analytics">
        <h3>📈 Flux financiers — 30 derniers jours</h3>
        <div class="chart-wrap">
          <canvas id="chartFlux30j"></canvas>
        </div>
      </div>

      <div class="chart-analytics compact">
        <h3>💱 Répartition des soldes</h3>
        <div class="chart-wrap">
          <canvas id="chartSoldes"></canvas>
        </div>
      </div>
    </div>

    <div class="charts-grid three">
      <div class="chart-analytics compact">
        <h3>📊 Évolution clients (12 mois)</h3>
        <div class="chart-wrap">
          <canvas id="chartClients12m"></canvas>
        </div>
      </div>

      <div class="chart-analytics compact">
        <h3>🏛️ Répartition actionnaires</h3>
        <div class="chart-wrap">
          <canvas id="chartActionnaires"></canvas>
        </div>
      </div>

      <div class="chart-analytics compact">
        <h3>💳 Statuts des prêts</h3>
        <div class="chart-wrap">
          <canvas id="chartPrets"></canvas>
        </div>
      </div>
    </div>

    <!-- ============================================================
         SECTION 4 : TOP CLASSEMENTS
         ============================================================ -->
    <div class="section-divider">
      <h2>🏆 Top Classements</h2>
      <div class="line"></div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:28px" class="charts-grid">

      <!-- Top clients -->
      <div class="chart-analytics">
        <h3>👥 Top 5 clients (volume)</h3>
        <?php if (empty($top_clients)): ?>
          <p class="muted center" style="padding:30px">Aucun client enregistré.</p>
        <?php else: ?>
          <table class="table-analytics">
            <thead>
              <tr>
                <th>#</th>
                <th>Client</th>
                <th style="text-align:right">Volume</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($top_clients as $i => $c): ?>
                <tr>
                  <td data-label="#">
                    <span class="rank <?= $i === 1 ? 'silver' : ($i === 2 ? 'bronze' : '') ?>">
                      <?= $i + 1 ?>
                    </span>
                  </td>
                  <td data-label="Client">
                    <strong><?= htmlspecialchars($c['nom'] . ' ' . $c['postnom']) ?></strong><br>
                    <small class="muted"><?= htmlspecialchars($c['numero_compte']) ?></small>
                  </td>
                  <td data-label="Volume" style="text-align:right">
                    <strong style="color:var(--gold)"><?= number_format((float)$c['volume_total'], 0, ',', ' ') ?></strong>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <!-- Top actionnaires -->
      <div class="chart-analytics">
        <h3>🏛️ Top 5 actionnaires</h3>
        <?php if (empty($top_actionnaires)): ?>
          <p class="muted center" style="padding:30px">Aucun actionnaire.</p>
        <?php else: ?>
          <table class="table-analytics">
            <thead>
              <tr>
                <th>#</th>
                <th>Actionnaire</th>
                <th style="text-align:right">Parts</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($top_actionnaires as $i => $a): ?>
                <tr>
                  <td data-label="#">
                    <span class="rank <?= $i === 1 ? 'silver' : ($i === 2 ? 'bronze' : '') ?>">
                      <?= $i + 1 ?>
                    </span>
                  </td>
                  <td data-label="Actionnaire">
                    <strong><?= htmlspecialchars($a['nom'] . ' ' . $a['postnom']) ?></strong><br>
                    <small class="muted"><?= htmlspecialchars($a['numero_actionnaire']) ?></small>
                  </td>
                  <td data-label="Parts" style="text-align:right">
                    <strong style="color:#A78BFA"><?= number_format((int)$a['nombre_parts'], 0, ',', ' ') ?></strong>
                    <div class="progress-bar-mini">
                      <div style="width:<?= min(100, (float)$a['pourcentage']) ?>%"></div>
                    </div>
                    <small class="muted"><?= number_format((float)$a['pourcentage'], 2) ?>%</small>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

    </div>

    <!-- ============================================================
         SECTION 5 : ACTIVITÉ EN TEMPS RÉEL
         ============================================================ -->
    <div class="section-divider">
      <h2>⚡ Activité en Temps Réel</h2>
      <div class="line"></div>
    </div>

    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:28px" class="charts-grid">

      <!-- Dernières transactions -->
      <div class="chart-analytics">
        <h3>💳 Dernières transactions</h3>
        <div class="activity-list">
          <?php if (empty($transactions)): ?>
            <p class="muted center" style="padding:20px">Aucune transaction.</p>
          <?php else: foreach (array_slice($transactions, 0, 6) as $t):
            $is_retrait = $t['type_transaction'] === 'Retrait';
            $icon_class = $is_retrait ? 'red' : 'green';
            $icon = $is_retrait ? '➖' : '➕';
          ?>
            <div class="activity-item">
              <div class="activity-icon <?= $icon_class ?>"><?= $icon ?></div>
              <div class="activity-content">
                <div class="title">
                  <?= htmlspecialchars($t['nom'] . ' ' . $t['postnom']) ?>
                  <span style="color:<?= $is_retrait ? '#FCA5A5' : '#6EE7B7' ?>;font-weight:800">
                    <?= $is_retrait ? '−' : '+' ?><?= number_format((float)$t['montant'], 0, ',', ' ') ?>
                    <?= htmlspecialchars($t['devise'] ?? 'CDF') ?>
                  </span>
                </div>
                <div class="sub">
                  <code><?= htmlspecialchars($t['reference']) ?></code> · <?= htmlspecialchars($t['mode']) ?>
                </div>
              </div>
              <div class="activity-time"><?= date('H:i', strtotime($t['date_transaction'])) ?></div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

      <!-- Derniers logs -->
      <div class="chart-analytics">
        <h3>📜 Journal d'activité</h3>
        <div class="activity-list">
          <?php if (empty($logs)): ?>
            <p class="muted center" style="padding:20px">Aucun log récent.</p>
          <?php else: foreach (array_slice($logs, 0, 6) as $l):
            $icon_class = 'gold';
            if (str_contains($l['action'], 'SUCCESS')) $icon_class = 'green';
            elseif (str_contains($l['action'], 'FAILED')) $icon_class = 'red';
            elseif (str_contains($l['action'], 'PLACEMENT')) $icon_class = 'purple';
            elseif (str_contains($l['action'], 'ACTIONNAIRE')) $icon_class = 'blue';
          ?>
            <div class="activity-item">
              <div class="activity-icon <?= $icon_class ?>">🔐</div>
              <div class="activity-content">
                <div class="title"><?= htmlspecialchars($l['action']) ?></div>
                <div class="sub">
                  <?= htmlspecialchars($l['user_type'] ?? 'user') ?>
                  <?= $l['user_id'] ? '#' . (int)$l['user_id'] : '' ?>
                  <?php if ($l['ip_address']): ?> · <code><?= htmlspecialchars($l['ip_address']) ?></code><?php endif; ?>
                </div>
              </div>
              <div class="activity-time"><?= date('H:i', strtotime($l['created_at'])) ?></div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

    </div>

    <!-- ============================================================
         SECTION 6 : CLIENTS EN LIGNE
         ============================================================ -->
    <div class="section-divider">
      <h2>🟢 Clients Connectés</h2>
      <div class="line"></div>
      <span class="badge-count" id="onlineCountBadge"><?= count($clients_online) ?> en ligne</span>
    </div>

    <div class="chart-analytics">
      <?php if (empty($clients_online)): ?>
        <p class="muted center" style="padding:30px">
          <span style="font-size:2rem;display:block;margin-bottom:12px;opacity:.5">💤</span>
          Aucun client connecté dans les 5 dernières minutes.
        </p>
      <?php else: ?>
        <table class="table-analytics">
          <thead>
            <tr>
              <th>Client</th>
              <th>Compte</th>
              <th style="text-align:right">CDF</th>
              <th style="text-align:right">USD</th>
              <th style="text-align:right">Activité</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($clients_online as $c): ?>
              <tr>
                <td data-label="Client">
                  <strong><?= htmlspecialchars($c['nom'] . ' ' . $c['postnom']) ?></strong>
                  <br><small class="muted"><?= htmlspecialchars($c['prenom']) ?></small>
                </td>
                <td data-label="Compte"><code><?= htmlspecialchars($c['numero_compte']) ?></code></td>
                <td data-label="CDF" style="text-align:right">
                  <strong style="color:var(--gold)">
                    <?= number_format((float)$c['solde_cdf'], 0, ',', ' ') ?>
                  </strong>
                </td>
                <td data-label="USD" style="text-align:right">
                  <strong style="color:#93B4FF">
                    $ <?= number_format((float)$c['solde_usd'], 2, '.', ',') ?>
                  </strong>
                </td>
                <td data-label="Activité" style="text-align:right">
                  <span style="color:#6EE7B7;font-size:.75rem">
                    🟢 <?= date('H:i:s', strtotime($c['last_login'])) ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

  </main>
</div>

<footer class="atf-footer">
  © <?= date('Y') ?> AFRICAN TRUST FUND — Dashboard Analytique v2.0
</footer>

<script>
/* ============================================================
   Chart.js defaults
   ============================================================ */
Chart.defaults.color = '#8A93A6';
Chart.defaults.font.family = "'Inter','Segoe UI',sans-serif";
Chart.defaults.borderColor = 'rgba(255,255,255,.06)';

const COLORS = {
  gold: '#F5B800',
  green: '#10B981',
  blue: '#3B82F6',
  red: '#EF4444',
  purple: '#8B5CF6',
};

const tooltipDefaults = {
  backgroundColor: '#1A2130',
  titleColor: '#F5B800',
  bodyColor: '#E8ECF3',
  borderColor: '#F5B800',
  borderWidth: 1,
  padding: 14,
  titleFont: { size: 13, weight: '800' },
  bodyFont: { size: 12 },
};

/* ============================================================
   1. Graphique flux 30 jours
   ============================================================ */
(function() {
  const ctx = document.getElementById('chartFlux30j').getContext('2d');

  const gradientGold = ctx.createLinearGradient(0, 0, 0, 300);
  gradientGold.addColorStop(0, 'rgba(245,184,0,.35)');
  gradientGold.addColorStop(1, 'rgba(245,184,0,0)');

  const gradientRed = ctx.createLinearGradient(0, 0, 0, 300);
  gradientRed.addColorStop(0, 'rgba(239,68,68,.35)');
  gradientRed.addColorStop(1, 'rgba(239,68,68,0)');

  new Chart(ctx, {
    type: 'line',
    data: {
      labels: <?= json_encode($labels_30j) ?>,
      datasets: [
        {
          label: 'Dépôts',
          data: <?= json_encode($data_depots_30j) ?>,
          borderColor: COLORS.gold,
          backgroundColor: gradientGold,
          borderWidth: 3,
          tension: 0.4,
          fill: true,
          pointBackgroundColor: COLORS.gold,
          pointBorderColor: '#0B0E14',
          pointBorderWidth: 2,
          pointRadius: 0,
          pointHoverRadius: 6,
        },
        {
          label: 'Retraits',
          data: <?= json_encode($data_retraits_30j) ?>,
          borderColor: COLORS.red,
          backgroundColor: gradientRed,
          borderWidth: 3,
          tension: 0.4,
          fill: true,
          pointBackgroundColor: COLORS.red,
          pointBorderColor: '#0B0E14',
          pointBorderWidth: 2,
          pointRadius: 0,
          pointHoverRadius: 6,
        },
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: {
          labels: { usePointStyle: true, padding: 16, color: '#E8ECF3', font: { size: 12, weight: '700' } }
        },
        tooltip: tooltipDefaults,
      },
      scales: {
        x: { grid: { color: 'rgba(255,255,255,.04)', drawBorder: false }, ticks: { color: '#8A93A6', font: { size: 10 } } },
        y: {
          grid: { color: 'rgba(255,255,255,.04)', drawBorder: false },
          ticks: { color: '#8A93A6', callback: (v) => v.toLocaleString('fr-FR') }
        }
      }
    }
  });
})();

/* ============================================================
   2. Graphique soldes par devise
   ============================================================ */
(function() {
  const data = [
    <?= (float)$repartition_soldes['CDF'] ?>,
    <?= (float)$repartition_soldes['USD'] ?>,
  ];
  const total = data.reduce((a, b) => a + b, 0);
  if (total === 0) {
    document.getElementById('chartSoldes').parentElement.innerHTML = '<p class="muted center" style="line-height:240px">Aucune donnée</p>';
    return;
  }
  new Chart(document.getElementById('chartSoldes'), {
    type: 'doughnut',
    data: {
      labels: ['CDF', 'USD (équiv. CDF)'],
      datasets: [{
        data: data,
        backgroundColor: [COLORS.gold, COLORS.blue],
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
        tooltip: {
          ...tooltipDefaults,
          callbacks: { label: (c) => ' ' + c.parsed.toLocaleString('fr-FR') + ' CDF' }
        }
      }
    }
  });
})();

/* ============================================================
   3. Évolution clients 12 mois
   ============================================================ */
(function() {
  const ctx = document.getElementById('chartClients12m').getContext('2d');
  const gradient = ctx.createLinearGradient(0, 0, 0, 240);
  gradient.addColorStop(0, 'rgba(59,130,246,.4)');
  gradient.addColorStop(1, 'rgba(59,130,246,0)');

  new Chart(ctx, {
    type: 'line',
    data: {
      labels: <?= json_encode($labels_12m) ?>,
      datasets: [{
        label: 'Nouveaux clients',
        data: <?= json_encode($data_clients_12m) ?>,
        borderColor: COLORS.blue,
        backgroundColor: gradient,
        borderWidth: 3,
        tension: 0.4,
        fill: true,
        pointBackgroundColor: COLORS.blue,
        pointBorderColor: '#0B0E14',
        pointBorderWidth: 2,
        pointRadius: 4,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false }, tooltip: tooltipDefaults },
      scales: {
        x: { grid: { display: false }, ticks: { color: '#8A93A6', font: { size: 10 } } },
        y: { grid: { color: 'rgba(255,255,255,.04)' }, ticks: { color: '#8A93A6' } }
      }
    }
  });
})();

/* ============================================================
   4. Répartition actionnaires
   ============================================================ */
<?php if (!empty($repartition_types)): ?>
(function() {
  const labels = <?= json_encode(array_map(fn($r) => ucfirst($r['type_actionnaire']), $repartition_types)) ?>;
  const data = <?= json_encode(array_map(fn($r) => (int)$r['nb'], $repartition_types)) ?>;

  new Chart(document.getElementById('chartActionnaires'), {
    type: 'doughnut',
    data: {
      labels: labels,
      datasets: [{
        data: data,
        backgroundColor: [COLORS.purple, COLORS.gold, COLORS.green, COLORS.blue, COLORS.red],
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
        legend: { position: 'bottom', labels: { usePointStyle: true, padding: 12, color: '#E8ECF3', font: { size: 11, weight: '700' } } },
        tooltip: tooltipDefaults,
      }
    }
  });
})();
<?php else: ?>
document.getElementById('chartActionnaires').parentElement.innerHTML = '<p class="muted center" style="line-height:240px">Aucun actionnaire</p>';
<?php endif; ?>

/* ============================================================
   5. Statuts des prêts
   ============================================================ */
(function() {
  const data = [
    <?= (int)$stats_prets['actifs'] ?>,
    <?= (int)$stats_prets['en_retard'] ?>,
    <?= (int)$stats_prets['en_attente'] ?>,
  ];
  const total = data.reduce((a, b) => a + b, 0);
  if (total === 0) {
    document.getElementById('chartPrets').parentElement.innerHTML = '<p class="muted center" style="line-height:240px">Aucun prêt</p>';
    return;
  }
  new Chart(document.getElementById('chartPrets'), {
    type: 'doughnut',
    data: {
      labels: ['Actifs', 'En retard', 'En attente'],
      datasets: [{
        data: data,
        backgroundColor: [COLORS.green, COLORS.red, COLORS.gold],
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
        legend: { position: 'bottom', labels: { usePointStyle: true, padding: 12, color: '#E8ECF3', font: { size: 11, weight: '700' } } },
        tooltip: tooltipDefaults,
      }
    }
  });
})();

/* ============================================================
   6. Barre de progression scroll
   ============================================================ */
window.addEventListener('scroll', () => {
  const h = document.documentElement.scrollHeight - window.innerHeight;
  document.getElementById('topProgress').style.width = ((window.scrollY / h) * 100) + '%';
});

/* ============================================================
   7. Refresh online count
   ============================================================ */
setInterval(async () => {
  try {
    const res = await fetch('<?= $base ?>/api/heartbeat.php', { method: 'POST' });
    const data = await res.json();
    if (data.online !== undefined) {
      document.getElementById('onlineCount').textContent = data.online;
      const badge = document.getElementById('onlineCountBadge');
      if (badge) badge.textContent = data.online + ' en ligne';
    }
  } catch (e) { /* silencieux */ }
}, 30000);

console.log('%c📊 Dashboard Analytique ATF', 'color:#F5B800;font-weight:bold;font-size:15px');
console.log('%c✨ Vue d\'ensemble professionnelle chargée', 'color:#10B981;font-size:12px');
</script>

<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>