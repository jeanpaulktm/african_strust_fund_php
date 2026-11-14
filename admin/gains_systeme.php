<?php
declare(strict_types=1);

/* ============================================================
   🔒 SÉCURITÉ
   ============================================================ */
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
requireRole('admin');

if (empty($_SESSION['admin_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: /african-trust-fund/auth/login_admin.php');
    exit;
}

$pdo = Database::getConnection();
$admin_id = (int)$_SESSION['admin_id'];

/* ============================================================
   🛡️ HELPERS SÉCURISÉS
   ============================================================ */
function safeDate(?string $date, string $fallback): string
{
    if ($date === null || $date === '' || !is_string($date)) return $fallback;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return $fallback;
    $ts = strtotime($date);
    if ($ts === false || $ts < 0) return $fallback;
    return date('Y-m-d', $ts);
}

function safeTimestamp(?string $date): int
{
    if ($date === null || $date === '') return time();
    $ts = strtotime($date);
    return ($ts === false) ? time() : $ts;
}

function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/* ============================================================
   FILTRES PÉRIODE
   ============================================================ */
$periode = $_GET['periode'] ?? 'mois';
$allowed = ['jour', 'semaine', 'mois', 'trimestre', 'annee', 'personnalise'];
if (!in_array($periode, $allowed, true)) $periode = 'mois';

$aujourdhui = date('Y-m-d');
$debut = $fin = $aujourdhui;
$periode_label = '';

switch ($periode) {
    case 'jour':
        $debut = $fin = $aujourdhui;
        $periode_label = "Aujourd'hui · " . date('d/m/Y');
        break;
    case 'semaine':
        $ts_mon = strtotime('monday this week') ?: time();
        $ts_sun = strtotime('sunday this week') ?: time();
        $debut = date('Y-m-d', $ts_mon);
        $fin   = date('Y-m-d', $ts_sun);
        $periode_label = "Cette semaine · " . date('d/m', strtotime($debut)) . " → " . date('d/m', strtotime($fin));
        break;
    case 'mois':
        $debut = date('Y-m-01');
        $fin   = date('Y-m-t');
        $periode_label = "Ce mois · " . date('F Y', strtotime($debut));
        break;
    case 'trimestre':
        $mois = (int)date('m');
        $tri = (int)ceil($mois / 3);
        $pm = ($tri - 1) * 3 + 1;
        $debut = date('Y-') . str_pad((string)$pm, 2, '0', STR_PAD_LEFT) . '-01';
        $fin_ts = strtotime($debut . ' +2 months') ?: time();
        $fin = date('Y-m-t', $fin_ts);
        $periode_label = "T{$tri} " . date('Y') . " · " . date('d/m', strtotime($debut)) . " → " . date('d/m', strtotime($fin));
        break;
    case 'annee':
        $debut = date('Y-01-01');
        $fin   = date('Y-12-31');
        $periode_label = "Année " . date('Y');
        break;
    case 'personnalise':
        $debut = safeDate($_GET['debut'] ?? '', date('Y-m-01'));
        $fin   = safeDate($_GET['fin']   ?? '', date('Y-m-d'));
        if ($debut > $fin) [$debut, $fin] = [$fin, $debut];
        $periode_label = date('d/m/Y', strtotime($debut)) . " → " . date('d/m/Y', strtotime($fin));
        break;
}

$debut = safeDate($debut, date('Y-m-01'));
$fin   = safeDate($fin, date('Y-m-d'));

$TAUX_USD_CDF = 2800;

/* ============================================================
   💸 SOURCE 1 : COMMISSIONS RETRAIT
   ============================================================ */
$s_retraits = ['usd' => 0.0, 'cdf' => 0.0, 'nb_usd' => 0, 'nb_cdf' => 0];
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
        $dev = strtoupper((string)$r['devise']);
        if ($dev === 'USD') {
            $s_retraits['usd'] = (float)$r['total'];
            $s_retraits['nb_usd'] = (int)$r['nb'];
        } else {
            $s_retraits['cdf'] = (float)$r['total'];
            $s_retraits['nb_cdf'] = (int)$r['nb'];
        }
    }
} catch (Throwable $e) { error_log('[gains] ' . $e->getMessage()); }

/* ============================================================
   💵 SOURCE 2 : INTÉRÊTS PRÊTS
   ============================================================ */
$s_prets = ['usd' => 0.0, 'cdf' => 0.0, 'nb_usd' => 0, 'nb_cdf' => 0];
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
        $dev = strtoupper((string)$r['devise']);
        if ($dev === 'USD') {
            $s_prets['usd'] = (float)$r['total'];
            $s_prets['nb_usd'] = (int)$r['nb'];
        } else {
            $s_prets['cdf'] = (float)$r['total'];
            $s_prets['nb_cdf'] = (int)$r['nb'];
        }
    }
} catch (Throwable $e) { error_log('[gains] ' . $e->getMessage()); }

/* ============================================================
   📈 SOURCE 3 : COMMISSIONS PLACEMENTS
   ============================================================ */
$s_placements = ['usd' => 0.0, 'cdf' => 0.0, 'nb_usd' => 0, 'nb_cdf' => 0];
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
        $dev = strtoupper((string)$r['devise']);
        if ($dev === 'USD') {
            $s_placements['usd'] = (float)$r['total'];
            $s_placements['nb_usd'] = (int)$r['nb'];
        } else {
            $s_placements['cdf'] = (float)$r['total'];
            $s_placements['nb_cdf'] = (int)$r['nb'];
        }
    }
} catch (Throwable $e) { error_log('[gains] ' . $e->getMessage()); }

/* ============================================================
   🔄 SOURCE 4 : COMMISSIONS VIREMENTS
   ============================================================ */
$s_virements = ['usd' => 0.0, 'cdf' => 0.0, 'nb_usd' => 0, 'nb_cdf' => 0];
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
        $dev = strtoupper((string)$r['devise']);
        if ($dev === 'USD') {
            $s_virements['usd'] = (float)$r['total'];
            $s_virements['nb_usd'] = (int)$r['nb'];
        } else {
            $s_virements['cdf'] = (float)$r['total'];
            $s_virements['nb_cdf'] = (int)$r['nb'];
        }
    }
} catch (Throwable $e) { error_log('[gains] ' . $e->getMessage()); }

/* ============================================================
   🏦 SOURCE 5 : AUTRES FRAIS
   ============================================================ */
$s_autres = ['usd' => 0.0, 'cdf' => 0.0, 'nb_usd' => 0, 'nb_cdf' => 0];
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
        $dev = strtoupper((string)$r['devise']);
        if ($dev === 'USD') {
            $s_autres['usd'] = (float)$r['total'];
            $s_autres['nb_usd'] = (int)$r['nb'];
        } else {
            $s_autres['cdf'] = (float)$r['total'];
            $s_autres['nb_cdf'] = (int)$r['nb'];
        }
    }
} catch (Throwable $e) { error_log('[gains] ' . $e->getMessage()); }

/* ============================================================
   TOTAUX GAINS
   ============================================================ */
$total_usd = $s_retraits['usd'] + $s_prets['usd'] + $s_placements['usd'] + $s_virements['usd'] + $s_autres['usd'];
$total_cdf = $s_retraits['cdf'] + $s_prets['cdf'] + $s_placements['cdf'] + $s_virements['cdf'] + $s_autres['cdf'];
$total_eq_cdf = $total_cdf + ($total_usd * $TAUX_USD_CDF);
$nb_usd = $s_retraits['nb_usd'] + $s_prets['nb_usd'] + $s_placements['nb_usd'] + $s_virements['nb_usd'] + $s_autres['nb_usd'];
$nb_cdf = $s_retraits['nb_cdf'] + $s_prets['nb_cdf'] + $s_placements['nb_cdf'] + $s_virements['nb_cdf'] + $s_autres['nb_cdf'];

/* ============================================================
   💸 DÉPENSES DE LA PÉRIODE (NOUVEAU)
   ============================================================ */
$depenses_usd = 0.0;
$depenses_cdf = 0.0;
$nb_depenses = 0;
$depenses_par_cat = [];

try {
    $stmt = $pdo->prepare("
        SELECT categorie, devise, COUNT(*) AS nb, COALESCE(SUM(montant), 0) AS total
        FROM Depenses
        WHERE DATE(date_depense) BETWEEN ? AND ?
        GROUP BY categorie, devise
    ");
    $stmt->execute([$debut, $fin]);
    foreach ($stmt->fetchAll() as $r) {
        $dev = strtoupper((string)$r['devise']);
        $montant = (float)$r['total'];
        $nb = (int)$r['nb'];

        if ($dev === 'USD') {
            $depenses_usd += $montant;
        } else {
            $depenses_cdf += $montant;
        }
        $nb_depenses += $nb;

        /* Détail par catégorie */
        $cat = (string)$r['categorie'];
        if (!isset($depenses_par_cat[$cat])) {
            $depenses_par_cat[$cat] = ['usd' => 0, 'cdf' => 0, 'nb' => 0];
        }
        if ($dev === 'USD') {
            $depenses_par_cat[$cat]['usd'] += $montant;
        } else {
            $depenses_par_cat[$cat]['cdf'] += $montant;
        }
        $depenses_par_cat[$cat]['nb'] += $nb;
    }
} catch (Throwable $e) {
    error_log('[gains depenses] ' . $e->getMessage());
}

$total_depenses_eq = $depenses_cdf + ($depenses_usd * $TAUX_USD_CDF);

/* ============================================================
   🏆 BÉNÉFICE NET (GAINS − DÉPENSES)
   ============================================================ */
$benefice_usd = $total_usd - $depenses_usd;
$benefice_cdf = $total_cdf - $depenses_cdf;
$benefice_eq = $benefice_cdf + ($benefice_usd * $TAUX_USD_CDF);

/* Marge bénéficiaire (%) */
$marge_pct = ($total_eq_cdf > 0) ? (($benefice_eq / $total_eq_cdf) * 100) : 0;

/* Indicateur de rentabilité */
if ($benefice_eq > 0) {
    $benefice_statut = ['label' => 'RENTABLE', 'color' => '#10B981', 'icon' => '📈'];
} elseif ($benefice_eq < 0) {
    $benefice_statut = ['label' => 'DÉFICITAIRE', 'color' => '#EF4444', 'icon' => '📉'];
} else {
    $benefice_statut = ['label' => 'ÉQUILIBRE', 'color' => '#F5B800', 'icon' => '⚖️'];
}

/* ============================================================
   ÉVOLUTION MENSUELLE 12 MOIS (Gains - Dépenses)
   ============================================================ */
$evolution = [];
$base_ts = safeTimestamp($fin);

for ($i = 11; $i >= 0; $i--) {
    $ts = strtotime("-$i months", $base_ts);
    if ($ts === false) {
        $ts = strtotime("-$i months", time());
        if ($ts === false) $ts = time();
    }
    $mois = date('Y-m', $ts);
    $evolution[$mois] = [
        'mois' => $mois,
        'label_court' => date('M', strtotime($mois . '-01')),
        'gains' => 0,
        'depenses' => 0,
        'benefice' => 0,
    ];
}

$ts_12m = strtotime('-12 months', $base_ts) ?: time();
$debut_evol = date('Y-m-01', $ts_12m);

/* Gains par mois */
try {
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(date_commission, '%Y-%m') AS mois, devise, SUM(montant_commission) AS total
        FROM Commissions
        WHERE date_commission >= ?
        GROUP BY DATE_FORMAT(date_commission, '%Y-%m'), devise
    ");
    $stmt->execute([$debut_evol]);
    foreach ($stmt->fetchAll() as $r) {
        $m = (string)$r['mois'];
        if (!isset($evolution[$m])) continue;
        $dev = strtoupper((string)$r['devise']);
        $val = (float)$r['total'] * (($dev === 'USD') ? $TAUX_USD_CDF : 1);
        $evolution[$m]['gains'] += $val;
    }
} catch (Throwable $e) {}

/* Prêts par mois */
try {
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(date_demande, '%Y-%m') AS mois, devise,
               SUM(GREATEST(0, montant_paye - montant_accorde)) AS total
        FROM Prets
        WHERE statut IN ('decaisse','en_cours','en_retard','solde')
          AND date_demande >= ?
        GROUP BY DATE_FORMAT(date_demande, '%Y-%m'), devise
    ");
    $stmt->execute([$debut_evol]);
    foreach ($stmt->fetchAll() as $r) {
        $m = (string)$r['mois'];
        if (!isset($evolution[$m])) continue;
        $dev = strtoupper((string)$r['devise']);
        $val = (float)$r['total'] * (($dev === 'USD') ? $TAUX_USD_CDF : 1);
        $evolution[$m]['gains'] += $val;
    }
} catch (Throwable $e) {}

/* Dépenses par mois */
try {
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(date_depense, '%Y-%m') AS mois, devise, SUM(montant) AS total
        FROM Depenses
        WHERE date_depense >= ?
        GROUP BY DATE_FORMAT(date_depense, '%Y-%m'), devise
    ");
    $stmt->execute([$debut_evol]);
    foreach ($stmt->fetchAll() as $r) {
        $m = (string)$r['mois'];
        if (!isset($evolution[$m])) continue;
        $dev = strtoupper((string)$r['devise']);
        $val = (float)$r['total'] * (($dev === 'USD') ? $TAUX_USD_CDF : 1);
        $evolution[$m]['depenses'] += $val;
    }
} catch (Throwable $e) {}

/* Calculer bénéfice mensuel */
foreach ($evolution as &$m) {
    $m['benefice'] = $m['gains'] - $m['depenses'];
}
unset($m);

/* ============================================================
   TOP 10 CLIENTS GÉNÉRATEURS
   ============================================================ */
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
            COALESCE(SUM(CASE WHEN cm.devise='USD' THEN cm.montant_commission ELSE 0 END), 0) * ?
            + COALESCE(SUM(CASE WHEN cm.devise='CDF' THEN cm.montant_commission ELSE 0 END), 0)
        ) DESC
        LIMIT 10
    ");
    $stmt->execute([$debut, $fin, $TAUX_USD_CDF]);
    $top_clients = $stmt->fetchAll();
} catch (Throwable $e) {}

try { logAction($pdo, 'admin', $admin_id, 'GAINS_VIEW', "Période: $debut → $fin | Bénéfice: $benefice_eq FC"); } catch (Throwable $e) {}

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>💰 Gains & Bénéfice Net — ATF Admin</title>
<link rel="stylesheet" href="<?= h($base) ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= h($base) ?>/assets/css/admin-premium.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
/* ============================================================
   TOOLBAR PÉRIODE
   ============================================================ */
.periode-toolbar{padding:18px 22px;background:linear-gradient(145deg,var(--card),var(--card-2));border:1px solid var(--border);border-radius:16px;margin-bottom:24px;display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.periode-toolbar .label{color:var(--muted);font-size:.78rem;font-weight:800;text-transform:uppercase;letter-spacing:1px;margin-right:6px;display:flex;align-items:center;gap:6px}
.periode-btn{padding:9px 16px;border-radius:10px;background:rgba(255,255,255,.04);border:1px solid var(--border);color:var(--muted);font-size:.8rem;font-weight:700;text-decoration:none;transition:all .2s;white-space:nowrap}
.periode-btn:hover{color:var(--gold);border-color:rgba(245,184,0,.5);background:rgba(245,184,0,.1)}
.periode-btn.active{background:linear-gradient(135deg,var(--gold),#D4AF37);color:#0B0E14;border-color:var(--gold);box-shadow:0 6px 18px rgba(245,184,0,.4)}
.periode-toolbar .date-form{margin-left:auto;display:flex;gap:6px;align-items:center;flex-wrap:wrap}
.periode-toolbar input[type="date"]{padding:9px 11px;background:var(--bg-1);border:1px solid var(--border);border-radius:8px;color:var(--text);font-family:inherit;font-size:.8rem}
.periode-toolbar button[type="submit"]{padding:9px 16px;border-radius:8px;background:linear-gradient(135deg,#3B82F6,#2563EB);color:#fff;font-weight:800;border:none;cursor:pointer;font-family:inherit;font-size:.8rem}

/* ============================================================
   HERO GAINS
   ============================================================ */
.hero-totaux{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px}
.hero-card{padding:32px 30px;border-radius:22px;position:relative;overflow:hidden;box-shadow:0 20px 50px rgba(0,0,0,.4);transition:transform .3s;border:2px solid transparent}
.hero-card:hover{transform:translateY(-4px)}
.hero-card::before{content:"";position:absolute;top:-60px;right:-60px;width:220px;height:220px;border-radius:50%;opacity:.15}
.hero-card.usd{background:linear-gradient(135deg,#065F46 0%,#059669 60%,#10B981 100%);color:#fff;border-color:rgba(16,185,129,.5)}
.hero-card.usd::before{background:#fff}
.hero-card.cdf{background:linear-gradient(135deg,#0D1B5E 0%,#1E3A8A 60%,#2563EB 100%);color:#fff;border-color:rgba(59,130,246,.5)}
.hero-card.cdf::before{background:#F5B800}
.hero-card .flag-row{display:flex;align-items:center;gap:12px;margin-bottom:16px;position:relative;z-index:1}
.hero-card .flag-icon{font-size:2.2rem;line-height:1}
.hero-card .flag-label{font-size:.82rem;font-weight:800;text-transform:uppercase;letter-spacing:2px;opacity:.95}
.hero-card .devise-tag{margin-left:auto;padding:4px 12px;border-radius:999px;background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.3);font-size:.7rem;font-weight:900;letter-spacing:2px}
.hero-card .amount{font-size:2.8rem;font-weight:900;letter-spacing:-1.5px;line-height:1;margin-bottom:8px;position:relative;z-index:1;font-variant-numeric:tabular-nums;text-shadow:0 2px 10px rgba(0,0,0,.2)}
.hero-card .amount-unit{font-size:1.3rem;opacity:.85;margin-left:8px;font-weight:800}
.hero-card .sub-label{font-size:.82rem;opacity:.85;font-weight:600;position:relative;z-index:1;margin-bottom:20px}
.hero-card .meta-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;padding-top:18px;border-top:1px solid rgba(255,255,255,.25);position:relative;z-index:1}
.hero-card .meta-item .m-lbl{font-size:.68rem;opacity:.75;text-transform:uppercase;letter-spacing:1px;font-weight:800;margin-bottom:4px}
.hero-card .meta-item .m-val{font-size:1rem;font-weight:900;font-variant-numeric:tabular-nums}
.hero-card.usd .meta-item .m-val{color:#D1FAE5}
.hero-card.cdf .meta-item .m-val{color:#FCD34D}

/* ============================================================
   BANNER ÉQUIVALENT
   ============================================================ */
.equivalent-banner{padding:18px 24px;background:linear-gradient(135deg,rgba(245,184,0,.15),rgba(245,184,0,.05));border:2px solid rgba(245,184,0,.4);border-radius:16px;margin-bottom:26px;display:flex;align-items:center;justify-content:center;gap:16px;flex-wrap:wrap;text-align:center}
.equivalent-banner .ico{font-size:1.8rem}
.equivalent-banner .text{color:#FCD34D;font-size:1rem;font-weight:700}
.equivalent-banner .text strong{color:var(--gold);font-size:1.3rem;font-weight:900;display:block;margin-top:4px;letter-spacing:-.5px}

/* ============================================================
   🏆 BÉNÉFICE NET (SECTION NOUVELLE)
   ============================================================ */
.benefice-title{font-size:1.1rem;color:var(--gold);font-weight:900;text-transform:uppercase;letter-spacing:1.5px;margin:30px 0 18px;padding-bottom:12px;border-bottom:2px solid rgba(245,184,0,.3);display:flex;align-items:center;gap:12px}
.benefice-title .ico{width:38px;height:38px;border-radius:12px;background:linear-gradient(135deg,rgba(245,184,0,.2),rgba(245,184,0,.05));color:var(--gold);display:flex;align-items:center;justify-content:center;font-size:1.2rem}

.benefice-flow{display:grid;grid-template-columns:1fr auto 1fr auto 1fr;gap:14px;align-items:stretch;margin-bottom:30px}
.flow-card{padding:24px 22px;border-radius:18px;position:relative;overflow:hidden;transition:transform .3s;display:flex;flex-direction:column;justify-content:center}
.flow-card:hover{transform:translateY(-4px)}
.flow-card .card-label{font-size:.72rem;font-weight:900;text-transform:uppercase;letter-spacing:1.5px;margin-bottom:10px;display:flex;align-items:center;gap:8px}
.flow-card .card-amount{font-size:1.9rem;font-weight:900;letter-spacing:-1px;line-height:1;font-variant-numeric:tabular-nums;margin-bottom:6px}
.flow-card .card-sub{font-size:.78rem;opacity:.85;font-weight:600}

.flow-card.gains{background:linear-gradient(135deg,rgba(16,185,129,.18),rgba(16,185,129,.05));border:2px solid rgba(16,185,129,.5);color:#6EE7B7}
.flow-card.gains .card-label{color:#6EE7B7}
.flow-card.gains .card-amount{color:#6EE7B7}
.flow-card.gains .card-amount.dual{font-size:1rem;margin-top:4px}

.flow-card.depenses{background:linear-gradient(135deg,rgba(239,68,68,.18),rgba(239,68,68,.05));border:2px solid rgba(239,68,68,.5);color:#FCA5A5}
.flow-card.depenses .card-label{color:#FCA5A5}
.flow-card.depenses .card-amount{color:#FCA5A5}
.flow-card.depenses .card-amount.dual{font-size:1rem;margin-top:4px}

.flow-card.benefice{background:linear-gradient(135deg,rgba(245,184,0,.25),rgba(245,184,0,.08));border:3px solid var(--gold);color:var(--gold);box-shadow:0 15px 40px rgba(245,184,0,.25)}
.flow-card.benefice .card-label{color:var(--gold)}
.flow-card.benefice .card-amount{color:var(--gold);font-size:2.2rem;text-shadow:0 2px 10px rgba(245,184,0,.3)}
.flow-card.benefice .card-amount.dual{font-size:1.1rem;margin-top:4px;color:#FCD34D}
.flow-card.benefice .marge-badge{display:inline-flex;align-items:center;gap:6px;padding:5px 14px;border-radius:999px;font-size:.72rem;font-weight:900;letter-spacing:1.5px;margin-top:12px;background:rgba(245,184,0,.2);border:1px solid rgba(245,184,0,.4)}

.flow-operator{display:flex;align-items:center;justify-content:center;font-size:2.5rem;font-weight:900;color:var(--gold);padding:0 6px;opacity:.6}
.flow-operator.equals{color:var(--gold);opacity:1}

@media (max-width: 900px) {
    .benefice-flow{grid-template-columns:1fr;}
    .flow-operator{transform:rotate(90deg);padding:6px 0}
}

/* Statut badge */
.benefice-status{display:inline-flex;align-items:center;gap:8px;padding:10px 20px;border-radius:12px;font-size:.85rem;font-weight:900;letter-spacing:1px;text-transform:uppercase;margin-top:14px}
.benefice-status.rentable{background:rgba(16,185,129,.15);color:#6EE7B7;border:2px solid rgba(16,185,129,.5)}
.benefice-status.deficitaire{background:rgba(239,68,68,.15);color:#FCA5A5;border:2px solid rgba(239,68,68,.5)}
.benefice-status.equilibre{background:rgba(245,184,0,.15);color:var(--gold);border:2px solid rgba(245,184,0,.5)}

/* Détail dépenses par catégorie */
.dep-cat-list{display:flex;flex-direction:column;gap:8px;padding:20px;background:linear-gradient(145deg,rgba(239,68,68,.05),rgba(11,14,20,.4));border:1px solid rgba(239,68,68,.3);border-radius:16px;margin-bottom:30px}
.dep-cat-item{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 16px;border-radius:10px;background:rgba(11,14,20,.5);border:1px solid var(--border);transition:all .2s}
.dep-cat-item:hover{border-color:rgba(239,68,68,.5);transform:translateX(4px)}
.dep-cat-item .cat-name{font-weight:800;color:var(--text);display:flex;align-items:center;gap:10px}
.dep-cat-item .cat-values{text-align:right;font-family:'Courier New',monospace;font-size:.82rem}
.dep-cat-item .cat-values .usd{color:#FCA5A5;font-weight:800}
.dep-cat-item .cat-values .cdf{color:#FCD34D;font-weight:800}
.dep-cat-item .cat-values .nb{color:var(--muted);font-size:.72rem}

/* ============================================================
   SOURCES DE GAINS
   ============================================================ */
.sources-title{font-size:1.1rem;color:var(--gold);font-weight:900;text-transform:uppercase;letter-spacing:1.5px;margin:30px 0 18px;padding-bottom:12px;border-bottom:2px solid rgba(245,184,0,.3);display:flex;align-items:center;gap:12px}
.sources-title .ico{width:38px;height:38px;border-radius:12px;background:linear-gradient(135deg,rgba(245,184,0,.2),rgba(245,184,0,.05));color:var(--gold);display:flex;align-items:center;justify-content:center;font-size:1.2rem}
.sources-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px;margin-bottom:30px}
.source-card{background:linear-gradient(145deg,var(--card),var(--card-2));border-radius:20px;border:2px solid var(--border);overflow:hidden;transition:all .3s}
.source-card:hover{transform:translateY(-4px);box-shadow:0 20px 50px rgba(0,0,0,.4)}
.source-card.retrait{border-color:rgba(239,68,68,.3)}
.source-card.pret{border-color:rgba(16,185,129,.3)}
.source-card.placement{border-color:rgba(139,92,246,.3)}
.source-card.virement{border-color:rgba(59,130,246,.3)}
.source-card.autre{border-color:rgba(107,114,128,.3)}
.source-card .card-head{padding:20px 24px;display:flex;align-items:center;gap:14px;border-bottom:1px solid var(--border)}
.source-card.retrait .card-head{background:linear-gradient(135deg,rgba(239,68,68,.12),transparent)}
.source-card.pret .card-head{background:linear-gradient(135deg,rgba(16,185,129,.12),transparent)}
.source-card.placement .card-head{background:linear-gradient(135deg,rgba(139,92,246,.12),transparent)}
.source-card.virement .card-head{background:linear-gradient(135deg,rgba(59,130,246,.12),transparent)}
.source-card.autre .card-head{background:linear-gradient(135deg,rgba(107,114,128,.12),transparent)}
.source-card .card-icon{width:54px;height:54px;border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:1.6rem;flex-shrink:0}
.source-card.retrait .card-icon{background:rgba(239,68,68,.2);color:#FCA5A5}
.source-card.pret .card-icon{background:rgba(16,185,129,.2);color:#6EE7B7}
.source-card.placement .card-icon{background:rgba(139,92,246,.2);color:#A78BFA}
.source-card.virement .card-icon{background:rgba(59,130,246,.2);color:#93B4FF}
.source-card.autre .card-icon{background:rgba(107,114,128,.2);color:#9CA3AF}
.source-card .card-title{flex:1;min-width:0}
.source-card .card-title h4{font-size:.95rem;font-weight:900;color:var(--text);margin-bottom:4px}
.source-card .card-title p{font-size:.74rem;color:var(--muted);font-weight:600;line-height:1.4}
.source-card .card-body{padding:20px 24px 24px}
.devises-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.devise-block{padding:16px;border-radius:12px}
.devise-block.usd{background:linear-gradient(135deg,rgba(16,185,129,.15),rgba(16,185,129,.04));border:1px solid rgba(16,185,129,.35)}
.devise-block.cdf{background:linear-gradient(135deg,rgba(59,130,246,.15),rgba(59,130,246,.04));border:1px solid rgba(59,130,246,.35)}
.devise-block .db-head{display:flex;align-items:center;gap:6px;margin-bottom:10px;font-size:.7rem;font-weight:900;text-transform:uppercase;letter-spacing:1px}
.devise-block.usd .db-head{color:#6EE7B7}
.devise-block.cdf .db-head{color:#93B4FF}
.devise-block .db-amount{font-size:1.4rem;font-weight:900;line-height:1.1;font-variant-numeric:tabular-nums;margin-bottom:6px}
.devise-block.usd .db-amount{color:#6EE7B7}
.devise-block.cdf .db-amount{color:#93B4FF}
.devise-block .db-count{font-size:.7rem;color:var(--muted);font-weight:700}
.devise-block .db-count strong{color:var(--text);font-weight:900}
.card-total{margin-top:16px;padding-top:16px;border-top:1px dashed var(--border);display:flex;justify-content:space-between;align-items:center}
.card-total .ct-label{font-size:.72rem;color:var(--muted);text-transform:uppercase;letter-spacing:1px;font-weight:800}
.card-total .ct-value{font-size:.95rem;color:var(--gold);font-weight:900;font-variant-numeric:tabular-nums}

/* ============================================================
   GRAPHIQUE & TABLEAU
   ============================================================ */
.chart-card{padding:24px;border-radius:18px;background:linear-gradient(145deg,var(--card),var(--card-2));border:1px solid var(--border);margin-bottom:24px}
.chart-card h3{font-size:1rem;color:var(--gold);margin-bottom:18px;display:flex;align-items:center;gap:10px}
.chart-wrap{position:relative;height:340px}

.top-table-card{padding:22px;border-radius:18px;background:linear-gradient(145deg,var(--card),var(--card-2));border:1px solid var(--border);margin-bottom:24px}
.top-table-card h3{font-size:1rem;color:var(--gold);margin-bottom:16px}
table.top-table{width:100%;border-collapse:collapse;font-size:.85rem}
table.top-table thead{background:rgba(11,14,20,.5)}
table.top-table thead th{padding:12px;text-align:left;font-size:.68rem;color:var(--muted);text-transform:uppercase;letter-spacing:1px;font-weight:800;border-bottom:2px solid var(--border);white-space:nowrap}
table.top-table tbody tr{border-bottom:1px solid rgba(255,255,255,.04);transition:background .2s}
table.top-table tbody tr:hover{background:rgba(245,184,0,.05)}
table.top-table td{padding:12px;color:var(--text);vertical-align:middle}
table.top-table .rank{width:32px;height:32px;border-radius:50%;background:rgba(107,114,128,.3);color:var(--text);display:flex;align-items:center;justify-content:center;font-weight:900;font-size:.8rem}
table.top-table .rank.gold{background:linear-gradient(135deg,#F5B800,#D4AF37);color:#0B0E14}
table.top-table .rank.silver{background:linear-gradient(135deg,#C0C0C0,#94A3B8);color:#0B0E14}
table.top-table .rank.bronze{background:linear-gradient(135deg,#CD7F32,#92400E);color:#fff}
table.top-table code{font-family:'Courier New',monospace;color:var(--gold);font-weight:700;font-size:.76rem}
table.top-table .usd-value{color:#6EE7B7;font-weight:800;text-align:right;font-variant-numeric:tabular-nums}
table.top-table .cdf-value{color:#93B4FF;font-weight:800;text-align:right;font-variant-numeric:tabular-nums}

/* Print button */
.btn-print{display:inline-flex;align-items:center;gap:8px;padding:12px 22px;border-radius:12px;background:linear-gradient(135deg,#F5B800,#D4AF37);color:#0B0E14;text-decoration:none;font-weight:800;font-size:.85rem;box-shadow:0 8px 24px rgba(245,184,0,.35);transition:all .25s;border:none;cursor:pointer;font-family:inherit}
.btn-print:hover{transform:translateY(-2px);box-shadow:0 12px 32px rgba(245,184,0,.5)}

/* Responsive */
@media (max-width:768px){
    .hero-totaux{grid-template-columns:1fr}
    .hero-card .amount{font-size:2rem}
    .periode-toolbar{flex-direction:column;align-items:stretch}
    .periode-toolbar .date-form{margin-left:0}
    .chart-wrap{height:260px}
    .devises-grid{grid-template-columns:1fr}
}
</style>
</head>
<body class="dark-auth">

<div class="top-progress" id="topProgress"></div>
<?php require __DIR__ . '/../includes/header.php'; ?>

<div class="admin-layout">
  <aside class="admin-sidebar animate-left">
    <h3>📊 Navigation</h3>
    <a href="<?= h($base) ?>/admin/dashboard.php"><span class="ico">📊</span> Tableau de bord</a>
    <a href="<?= h($base) ?>/admin/clients.php"><span class="ico">👥</span> Clients</a>
    <a href="<?= h($base) ?>/admin/gains_systeme.php" class="active"><span class="ico">💰</span> Gains système</a>
    <a href="<?= h($base) ?>/admin/depenses.php"><span class="ico">💸</span> Dépenses</a>
    <a href="<?= h($base) ?>/admin/caissiers.php"><span class="ico">💼</span> Caissiers</a>
    <a href="<?= h($base) ?>/admin/produits.php"><span class="ico">📈</span> Produits</a>
    <a href="<?= h($base) ?>/admin/placements.php"><span class="ico">💰</span> Placements</a>
    <a href="<?= h($base) ?>/admin/commissions.php"><span class="ico">💵</span> Commissions</a>
    <a href="<?= h($base) ?>/admin/statistiques.php"><span class="ico">📈</span> Statistiques</a>
    <a href="<?= h($base) ?>/admin/transactions.php"><span class="ico">💳</span> Transactions</a>
    <a href="<?= h($base) ?>/admin/logs.php"><span class="ico">📜</span> Journal</a>
    <a href="<?= h($base) ?>/auth/logout.php"><span class="ico">🚪</span> Déconnexion</a>
  </aside>

  <main class="admin-main">

    <div class="welcome-head">
      <div>
        <h2>💰 Gains & Bénéfice Net du Système</h2>
        <p class="sub">Analyse financière complète · <?= h($periode_label) ?></p>
      </div>
      <a href="<?= h($base) ?>/admin/gains_systeme_imprimer.php?periode=<?= urlencode($periode) ?>&debut=<?= urlencode($debut) ?>&fin=<?= urlencode($fin) ?>"
         target="_blank" rel="noopener noreferrer" class="btn-print">
        🖨️ Imprimer / PDF
      </a>
    </div>

    <div class="periode-toolbar">
      <span class="label">📅 Période :</span>
      <a href="?periode=jour" class="periode-btn <?= $periode === 'jour' ? 'active' : '' ?>">Aujourd'hui</a>
      <a href="?periode=semaine" class="periode-btn <?= $periode === 'semaine' ? 'active' : '' ?>">Semaine</a>
      <a href="?periode=mois" class="periode-btn <?= $periode === 'mois' ? 'active' : '' ?>">Mois</a>
      <a href="?periode=trimestre" class="periode-btn <?= $periode === 'trimestre' ? 'active' : '' ?>">Trimestre</a>
      <a href="?periode=annee" class="periode-btn <?= $periode === 'annee' ? 'active' : '' ?>">Année</a>
      <form method="GET" class="date-form">
        <input type="hidden" name="periode" value="personnalise">
        <input type="date" name="debut" value="<?= h($debut) ?>" max="<?= h($aujourdhui) ?>">
        <span style="color:var(--muted);font-weight:800">→</span>
        <input type="date" name="fin" value="<?= h($fin) ?>" max="<?= h($aujourdhui) ?>">
        <button type="submit">🔍 Filtrer</button>
      </form>
    </div>

    <!-- ============================================================
         HERO GAINS USD & CDF
         ============================================================ -->
    <div class="hero-totaux">
      <div class="hero-card usd">
        <div class="flag-row">
          <span class="flag-icon">🇺🇸</span>
          <span class="flag-label">Gains USD</span>
          <span class="devise-tag">USD</span>
        </div>
        <div class="amount">$ <?= number_format($total_usd, 2, ',', ' ') ?><span class="amount-unit">USD</span></div>
        <div class="sub-label">Total des revenus en dollars américains</div>
        <div class="meta-grid">
          <div class="meta-item"><div class="m-lbl">📊 Opérations</div><div class="m-val"><?= (int)$nb_usd ?></div></div>
          <div class="meta-item"><div class="m-lbl">💰 Moyenne/op.</div><div class="m-val">$ <?= $nb_usd > 0 ? number_format($total_usd / $nb_usd, 2, ',', ' ') : '0,00' ?></div></div>
        </div>
      </div>
      <div class="hero-card cdf">
        <div class="flag-row">
          <span class="flag-icon">🇨🇩</span>
          <span class="flag-label">Gains CDF</span>
          <span class="devise-tag">CDF</span>
        </div>
        <div class="amount"><?= number_format($total_cdf, 0, ',', ' ') ?><span class="amount-unit">FC</span></div>
        <div class="sub-label">Total des revenus en francs congolais</div>
        <div class="meta-grid">
          <div class="meta-item"><div class="m-lbl">📊 Opérations</div><div class="m-val"><?= (int)$nb_cdf ?></div></div>
          <div class="meta-item"><div class="m-lbl">💰 Moyenne/op.</div><div class="m-val"><?= $nb_cdf > 0 ? number_format($total_cdf / $nb_cdf, 0, ',', ' ') : '0' ?> FC</div></div>
        </div>
      </div>
    </div>

    <div class="equivalent-banner">
      <span class="ico">💱</span>
      <div class="text">
        Gains consolidés (taux 1 USD = <?= number_format($TAUX_USD_CDF, 0, ',', ' ') ?> FC)
        <strong><?= number_format($total_eq_cdf, 0, ',', ' ') ?> FC</strong>
      </div>
      <span class="ico">📊</span>
    </div>

    <!-- ============================================================
         🏆 BÉNÉFICE NET DU SYSTÈME (SECTION NOUVELLE)
         ============================================================ -->
    <h2 class="benefice-title">
      <span class="ico">🏆</span>
      Bénéfice Net du Système
    </h2>

    <div class="benefice-flow">

      <!-- GAINS -->
      <div class="flow-card gains">
        <div class="card-label">💰 Gains totaux</div>
        <div class="card-amount">$ <?= number_format($total_usd, 2, ',', ' ') ?></div>
        <div class="card-amount dual" style="color:#93B4FF">
          + <?= number_format($total_cdf, 0, ',', ' ') ?> FC
        </div>
        <div class="card-sub" style="margin-top:12px">
          <?= (int)($nb_usd + $nb_cdf) ?> opération(s) génératrice(s)
        </div>
      </div>

      <!-- OPÉRATEUR -->
      <div class="flow-operator">−</div>

      <!-- DÉPENSES -->
      <div class="flow-card depenses">
        <div class="card-label">💸 Dépenses</div>
        <div class="card-amount">$ <?= number_format($depenses_usd, 2, ',', ' ') ?></div>
        <div class="card-amount dual" style="color:#FCD34D">
          − <?= number_format($depenses_cdf, 0, ',', ' ') ?> FC
        </div>
        <div class="card-sub" style="margin-top:12px">
          <?= (int)$nb_depenses ?> dépense(s) enregistrée(s)
        </div>
      </div>

      <!-- OPÉRATEUR -->
      <div class="flow-operator equals">=</div>

      <!-- BÉNÉFICE NET -->
      <div class="flow-card benefice">
        <div class="card-label">🏆 Bénéfice net</div>
        <div class="card-amount">$ <?= number_format($benefice_usd, 2, ',', ' ') ?></div>
        <div class="card-amount dual" style="color:#FCD34D">
          + <?= number_format($benefice_cdf, 0, ',', ' ') ?> FC
        </div>
        <div style="margin-top:8px;font-size:.78rem;color:#FCD34D;font-weight:700">
          Équivalent : <?= number_format($benefice_eq, 0, ',', ' ') ?> FC
        </div>
        <div>
          <span class="marge-badge">
            📊 Marge : <?= number_format($marge_pct, 1) ?>%
          </span>
        </div>
      </div>

    </div>

    <!-- STATUT RENTABILITÉ -->
    <div style="text-align:center;margin-bottom:30px">
      <?php
        $status_class = $benefice_eq > 0 ? 'rentable' : ($benefice_eq < 0 ? 'deficitaire' : 'equilibre');
      ?>
      <div class="benefice-status <?= $status_class ?>">
        <span><?= $benefice_statut['icon'] ?></span>
        <span>Système <?= h($benefice_statut['label']) ?></span>
        <?php if ($benefice_eq > 0): ?>
          <span style="margin-left:8px;font-size:1.1rem">+<?= number_format($benefice_eq, 0, ',', ' ') ?> FC</span>
        <?php elseif ($benefice_eq < 0): ?>
          <span style="margin-left:8px;font-size:1.1rem"><?= number_format($benefice_eq, 0, ',', ' ') ?> FC</span>
        <?php endif; ?>
      </div>
    </div>

    <!-- ============================================================
         DÉPENSES PAR CATÉGORIE (si présentes)
         ============================================================ -->
    <?php if (!empty($depenses_par_cat)): ?>
    <h2 class="benefice-title" style="margin-top:20px">
      <span class="ico">💸</span>
      Détail des dépenses par catégorie
    </h2>

    <div class="dep-cat-list">
      <?php foreach ($depenses_par_cat as $cat => $data): 
          $cat_labels = [
              'salaires' => ['💼', 'Salaires & Primes'],
              'loyer' => ['🏢', 'Loyer & Charges'],
              'electricite' => ['💡', 'Électricité & Eau'],
              'internet' => ['🌐', 'Internet & Télécom'],
              'fournitures' => ['📎', 'Fournitures de bureau'],
              'maintenance' => ['🔧', 'Maintenance'],
              'transport' => ['🚗', 'Transport & Carburant'],
              'securite' => ['🛡️', 'Sécurité'],
              'marketing' => ['📢', 'Marketing & Communication'],
              'formation' => ['🎓', 'Formation'],
              'frais_bancaires' => ['🏦', 'Frais bancaires'],
              'impots' => ['📋', 'Impôts & Taxes'],
              'assurance' => ['📄', 'Assurance'],
              'informatique' => ['💻', 'Informatique'],
              'juridique' => ['⚖️', 'Frais juridiques'],
              'divers' => ['📁', 'Dépenses diverses'],
          ];
          $info = $cat_labels[$cat] ?? ['📁', ucfirst(str_replace('_', ' ', $cat))];
      ?>
        <div class="dep-cat-item">
          <div class="cat-name">
            <span style="font-size:1.3rem"><?= h($info[0]) ?></span>
            <span><?= h($info[1]) ?></span>
          </div>
          <div class="cat-values">
            <?php if ($data['usd'] > 0): ?>
              <div class="usd">🇺🇸 $ <?= number_format($data['usd'], 2, ',', ' ') ?></div>
            <?php endif; ?>
            <?php if ($data['cdf'] > 0): ?>
              <div class="cdf">🇨🇩 <?= number_format($data['cdf'], 0, ',', ' ') ?> FC</div>
            <?php endif; ?>
            <div class="nb"><?= (int)$data['nb'] ?> op.</div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ============================================================
         SOURCES DE REVENUS
         ============================================================ -->
    <h2 class="sources-title"><span class="ico">🎯</span> Sources de revenus du système</h2>

    <div class="sources-grid">

      <div class="source-card retrait">
        <div class="card-head"><div class="card-icon">💸</div><div class="card-title"><h4>Commissions de retrait</h4><p>Frais prélevés sur chaque retrait</p></div></div>
        <div class="card-body">
          <div class="devises-grid">
            <div class="devise-block usd"><div class="db-head"><span>🇺🇸</span> USD</div><div class="db-amount">$ <?= number_format($s_retraits['usd'], 2, ',', ' ') ?></div><div class="db-count"><strong><?= (int)$s_retraits['nb_usd'] ?></strong> retrait(s)</div></div>
            <div class="devise-block cdf"><div class="db-head"><span>🇨🇩</span> CDF</div><div class="db-amount"><?= number_format($s_retraits['cdf'], 0, ',', ' ') ?> FC</div><div class="db-count"><strong><?= (int)$s_retraits['nb_cdf'] ?></strong> retrait(s)</div></div>
          </div>
          <div class="card-total"><span class="ct-label">Total équivalent</span><span class="ct-value"><?= number_format($s_retraits['cdf'] + $s_retraits['usd'] * $TAUX_USD_CDF, 0, ',', ' ') ?> FC</span></div>
        </div>
      </div>

      <div class="source-card pret">
        <div class="card-head"><div class="card-icon">💵</div><div class="card-title"><h4>Intérêts de prêts</h4><p>Revenus des crédits accordés</p></div></div>
        <div class="card-body">
          <div class="devises-grid">
            <div class="devise-block usd"><div class="db-head"><span>🇺🇸</span> USD</div><div class="db-amount">$ <?= number_format($s_prets['usd'], 2, ',', ' ') ?></div><div class="db-count"><strong><?= (int)$s_prets['nb_usd'] ?></strong> prêt(s)</div></div>
            <div class="devise-block cdf"><div class="db-head"><span>🇨🇩</span> CDF</div><div class="db-amount"><?= number_format($s_prets['cdf'], 0, ',', ' ') ?> FC</div><div class="db-count"><strong><?= (int)$s_prets['nb_cdf'] ?></strong> prêt(s)</div></div>
          </div>
          <div class="card-total"><span class="ct-label">Total équivalent</span><span class="ct-value"><?= number_format($s_prets['cdf'] + $s_prets['usd'] * $TAUX_USD_CDF, 0, ',', ' ') ?> FC</span></div>
        </div>
      </div>

      <div class="source-card placement">
        <div class="card-head"><div class="card-icon">📈</div><div class="card-title"><h4>Commissions sur placements</h4><p>Frais de gestion épargne</p></div></div>
        <div class="card-body">
          <div class="devises-grid">
            <div class="devise-block usd"><div class="db-head"><span>🇺🇸</span> USD</div><div class="db-amount">$ <?= number_format($s_placements['usd'], 2, ',', ' ') ?></div><div class="db-count"><strong><?= (int)$s_placements['nb_usd'] ?></strong> opération(s)</div></div>
            <div class="devise-block cdf"><div class="db-head"><span>🇨🇩</span> CDF</div><div class="db-amount"><?= number_format($s_placements['cdf'], 0, ',', ' ') ?> FC</div><div class="db-count"><strong><?= (int)$s_placements['nb_cdf'] ?></strong> opération(s)</div></div>
          </div>
          <div class="card-total"><span class="ct-label">Total équivalent</span><span class="ct-value"><?= number_format($s_placements['cdf'] + $s_placements['usd'] * $TAUX_USD_CDF, 0, ',', ' ') ?> FC</span></div>
        </div>
      </div>

      <div class="source-card virement">
        <div class="card-head"><div class="card-icon">🔄</div><div class="card-title"><h4>Commissions de virement</h4><p>Frais de transfert entre comptes</p></div></div>
        <div class="card-body">
          <div class="devises-grid">
            <div class="devise-block usd"><div class="db-head"><span>🇺🇸</span> USD</div><div class="db-amount">$ <?= number_format($s_virements['usd'], 2, ',', ' ') ?></div><div class="db-count"><strong><?= (int)$s_virements['nb_usd'] ?></strong> virement(s)</div></div>
            <div class="devise-block cdf"><div class="db-head"><span>🇨🇩</span> CDF</div><div class="db-amount"><?= number_format($s_virements['cdf'], 0, ',', ' ') ?> FC</div><div class="db-count"><strong><?= (int)$s_virements['nb_cdf'] ?></strong> virement(s)</div></div>
          </div>
          <div class="card-total"><span class="ct-label">Total équivalent</span><span class="ct-value"><?= number_format($s_virements['cdf'] + $s_virements['usd'] * $TAUX_USD_CDF, 0, ',', ' ') ?> FC</span></div>
        </div>
      </div>

      <div class="source-card autre">
        <div class="card-head"><div class="card-icon">🏦</div><div class="card-title"><h4>Autres frais</h4><p>Tenue de compte, pénalités...</p></div></div>
        <div class="card-body">
          <div class="devises-grid">
            <div class="devise-block usd"><div class="db-head"><span>🇺🇸</span> USD</div><div class="db-amount">$ <?= number_format($s_autres['usd'], 2, ',', ' ') ?></div><div class="db-count"><strong><?= (int)$s_autres['nb_usd'] ?></strong> opération(s)</div></div>
            <div class="devise-block cdf"><div class="db-head"><span>🇨🇩</span> CDF</div><div class="db-amount"><?= number_format($s_autres['cdf'], 0, ',', ' ') ?> FC</div><div class="db-count"><strong><?= (int)$s_autres['nb_cdf'] ?></strong> opération(s)</div></div>
          </div>
          <div class="card-total"><span class="ct-label">Total équivalent</span><span class="ct-value"><?= number_format($s_autres['cdf'] + $s_autres['usd'] * $TAUX_USD_CDF, 0, ',', ' ') ?> FC</span></div>
        </div>
      </div>

    </div>

    <!-- ============================================================
         GRAPHIQUE ÉVOLUTION (Gains vs Dépenses)
         ============================================================ -->
    <div class="chart-card">
      <h3>📈 Évolution mensuelle : Gains vs Dépenses vs Bénéfice (12 mois)</h3>
      <div class="chart-wrap"><canvas id="chartEvolution"></canvas></div>
    </div>

    <!-- ============================================================
         TOP 10 CLIENTS
         ============================================================ -->
    <?php if (!empty($top_clients)): ?>
    <div class="top-table-card">
      <h3>🏆 Top 10 clients générateurs de revenus</h3>
      <div style="overflow-x:auto">
        <table class="top-table">
          <thead>
            <tr>
              <th style="width:60px">#</th><th>Client</th><th>Compte</th>
              <th style="text-align:right">Nb op.</th>
              <th style="text-align:right">🇺🇸 Gains USD</th>
              <th style="text-align:right">🇨🇩 Gains CDF</th>
              <th style="text-align:right">Total équiv.</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($top_clients as $i => $t):
              $eq = (float)$t['gains_cdf'] + (float)$t['gains_usd'] * $TAUX_USD_CDF;
              $rc = $i === 0 ? 'gold' : ($i === 1 ? 'silver' : ($i === 2 ? 'bronze' : ''));
            ?>
              <tr>
                <td><div class="rank <?= $rc ?>"><?= $i + 1 ?></div></td>
                <td><strong><?= h($t['nom'] . ' ' . $t['postnom']) ?></strong></td>
                <td><code><?= h($t['numero_compte']) ?></code></td>
                <td style="text-align:right;color:var(--gold);font-weight:800"><?= (int)$t['nb_commissions'] ?></td>
                <td class="usd-value">$ <?= number_format((float)$t['gains_usd'], 2, ',', ' ') ?></td>
                <td class="cdf-value"><?= number_format((float)$t['gains_cdf'], 0, ',', ' ') ?> FC</td>
                <td style="text-align:right;color:#FCD34D;font-weight:900"><?= number_format($eq, 0, ',', ' ') ?> FC</td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

  </main>
</div>

<footer class="atf-footer">© <?= date('Y') ?> AFRICAN TRUST FUND</footer>

<script>
/* ============================================================
   GRAPHIQUE : Gains vs Dépenses vs Bénéfice
   ============================================================ */
(function() {
    const canvas = document.getElementById('chartEvolution');
    if (!canvas) return;
    
    const labels = <?= json_encode(array_column($evolution, 'label_court')) ?>;
    const dataGains = <?= json_encode(array_column($evolution, 'gains')) ?>;
    const dataDepenses = <?= json_encode(array_column($evolution, 'depenses')) ?>;
    const dataBenefice = <?= json_encode(array_column($evolution, 'benefice')) ?>;

    new Chart(canvas.getContext('2d'), {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                {
                    label: '💰 Gains',
                    data: dataGains,
                    backgroundColor: 'rgba(16,185,129,.85)',
                    borderColor: '#10B981',
                    borderWidth: 1,
                    borderRadius: 6,
                },
                {
                    label: '💸 Dépenses',
                    data: dataDepenses,
                    backgroundColor: 'rgba(239,68,68,.85)',
                    borderColor: '#EF4444',
                    borderWidth: 1,
                    borderRadius: 6,
                },
                {
                    label: '🏆 Bénéfice net',
                    data: dataBenefice,
                    type: 'line',
                    borderColor: '#F5B800',
                    backgroundColor: 'rgba(245,184,0,.2)',
                    borderWidth: 3,
                    tension: 0.4,
                    fill: false,
                    pointBackgroundColor: '#F5B800',
                    pointBorderColor: '#0B0E14',
                    pointBorderWidth: 2,
                    pointRadius: 6,
                    pointHoverRadius: 8,
                    order: 0,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { position: 'top', labels: { usePointStyle: true, padding: 16, color: '#E8ECF3', font: { size: 12, weight: '700' } } },
                tooltip: {
                    backgroundColor: '#1A2130',
                    titleColor: '#F5B800',
                    bodyColor: '#E8ECF3',
                    borderColor: '#F5B800',
                    borderWidth: 1,
                    padding: 12,
                    callbacks: {
                        label: (c) => ' ' + c.dataset.label + ' : ' + c.parsed.y.toLocaleString('fr-FR') + ' FC'
                    }
                }
            },
            scales: {
                x: { grid: { color: 'rgba(255,255,255,.04)' }, ticks: { color: '#8A93A6' } },
                y: {
                    grid: { color: 'rgba(255,255,255,.04)' },
                    ticks: {
                        color: '#8A93A6',
                        callback: (v) => v >= 1000000 ? (v/1000000).toFixed(1) + 'M' : (v >= 1000 ? (v/1000).toFixed(0) + 'K' : v)
                    }
                }
            }
        }
    });
})();

/* Barre progression */
window.addEventListener('scroll', () => {
    const h = document.documentElement.scrollHeight - window.innerHeight;
    const p = h > 0 ? (window.scrollY / h) * 100 : 0;
    const bar = document.getElementById('topProgress');
    if (bar) bar.style.width = p + '%';
});

console.log('%c💰 Gains & Bénéfice Net ATF', 'color:#F5B800;font-weight:bold;font-size:14px');
console.log('Gains USD :', <?= json_encode($total_usd) ?>);
console.log('Gains CDF :', <?= json_encode($total_cdf) ?>);
console.log('Dépenses USD :', <?= json_encode($depenses_usd) ?>);
console.log('Dépenses CDF :', <?= json_encode($depenses_cdf) ?>);
console.log('Bénéfice net (CDF équiv.) :', <?= json_encode($benefice_eq) ?>);
console.log('Marge :', <?= json_encode(round($marge_pct, 2)) ?>, '%');
</script>

<script src="<?= h($base) ?>/assets/js/main.js"></script>
</body>
</html>