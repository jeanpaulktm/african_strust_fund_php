<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/prets.php';
requireRole('admin');

$pdo = Database::getConnection();
$admin_id = (int)$_SESSION['admin_id'];

/* Période */
$annee = (int)($_GET['annee'] ?? date('Y'));
if ($annee < 2020 || $annee > 2100) $annee = (int)date('Y');
$debut = "$annee-01-01";
$fin = "$annee-12-31";

$base = '/african-trust-fund';

/* ============================================================
   STATISTIQUES GLOBALES
   ============================================================ */
$stats = [
    'total_prets' => 0, 'prets_actifs' => 0, 'prets_soldes' => 0,
    'prets_rejetes' => 0, 'prets_retard' => 0,
    'montant_decaisse_cdf' => 0, 'montant_decaisse_usd' => 0,
    'encours_cdf' => 0, 'encours_usd' => 0,
    'penalites_percues_cdf' => 0, 'penalites_percues_usd' => 0,
    'taux_rejet' => 0, 'taux_recouvrement' => 0, 'par' => 0,
];

try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) AS total,
            COUNT(CASE WHEN statut IN ('decaisse','en_cours') THEN 1 END) AS actifs,
            COUNT(CASE WHEN statut = 'solde' THEN 1 END) AS soldes,
            COUNT(CASE WHEN statut = 'rejete' THEN 1 END) AS rejetes,
            COUNT(CASE WHEN statut IN ('en_retard','contentieux') THEN 1 END) AS retard,
            COALESCE(SUM(CASE WHEN devise='CDF' THEN montant_accorde ELSE 0 END), 0) AS total_decaisse_cdf,
            COALESCE(SUM(CASE WHEN devise='USD' THEN montant_accorde ELSE 0 END), 0) AS total_decaisse_usd,
            COALESCE(SUM(CASE WHEN statut IN ('decaisse','en_cours','en_retard') AND devise='CDF' THEN montant_restant_du ELSE 0 END), 0) AS encours_cdf,
            COALESCE(SUM(CASE WHEN statut IN ('decaisse','en_cours','en_retard') AND devise='USD' THEN montant_restant_du ELSE 0 END), 0) AS encours_usd,
            COALESCE(SUM(CASE WHEN devise='CDF' THEN penalites_cumulees ELSE 0 END), 0) AS pen_cdf,
            COALESCE(SUM(CASE WHEN devise='USD' THEN penalites_cumulees ELSE 0 END), 0) AS pen_usd
        FROM Prets
        WHERE DATE(date_demande) BETWEEN ? AND ?
    ");
    $stmt->execute([$debut, $fin]);
    $row = $stmt->fetch() ?: [];

    $stats['total_prets'] = (int)($row['total'] ?? 0);
    $stats['prets_actifs'] = (int)($row['actifs'] ?? 0);
    $stats['prets_soldes'] = (int)($row['soldes'] ?? 0);
    $stats['prets_rejetes'] = (int)($row['rejetes'] ?? 0);
    $stats['prets_retard'] = (int)($row['retard'] ?? 0);
    $stats['montant_decaisse_cdf'] = (float)($row['total_decaisse_cdf'] ?? 0);
    $stats['montant_decaisse_usd'] = (float)($row['total_decaisse_usd'] ?? 0);
    $stats['encours_cdf'] = (float)($row['encours_cdf'] ?? 0);
    $stats['encours_usd'] = (float)($row['encours_usd'] ?? 0);
    $stats['penalites_percues_cdf'] = (float)($row['pen_cdf'] ?? 0);
    $stats['penalites_percues_usd'] = (float)($row['pen_usd'] ?? 0);
} catch (Throwable $e) {
    error_log('[rapport_prets - stats] ' . $e->getMessage());
}

/* Taux de rejet */
try {
    $total_demandes = (int)$pdo->query("
        SELECT COUNT(*) FROM Prets 
        WHERE DATE(date_demande) BETWEEN '$debut' AND '$fin'
    ")->fetchColumn();
    $stats['taux_rejet'] = $total_demandes > 0 
        ? ($stats['prets_rejetes'] / $total_demandes) * 100 
        : 0;
} catch (Throwable $e) {}

/* Taux recouvrement */
try {
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(montant_paye), 0), COALESCE(SUM(montant_total_du), 0)
        FROM Prets
        WHERE DATE(date_demande) BETWEEN ? AND ?
          AND statut IN ('decaisse','en_cours','en_retard','solde')
    ");
    $stmt->execute([$debut, $fin]);
    [$total_paye, $total_du] = $stmt->fetch(PDO::FETCH_NUM) ?: [0, 0];
    $stats['taux_recouvrement'] = (float)$total_du > 0 
        ? ((float)$total_paye / (float)$total_du) * 100 
        : 0;
} catch (Throwable $e) {}

/* PAR */
try {
    $encours_total = $stats['encours_cdf'] + ($stats['encours_usd'] * 2800);
    $stmt = $pdo->prepare("
        SELECT COALESCE(SUM(CASE WHEN devise='CDF' THEN montant_restant_du 
                                 ELSE montant_restant_du * 2800 END), 0)
        FROM Prets
        WHERE DATE(date_demande) BETWEEN ? AND ?
          AND statut IN ('en_retard','contentieux')
    ");
    $stmt->execute([$debut, $fin]);
    $encours_risque = (float)$stmt->fetchColumn();
    $stats['par'] = $encours_total > 0 ? ($encours_risque / $encours_total) * 100 : 0;
} catch (Throwable $e) {}

/* ============================================================
   ÉVOLUTION MENSUELLE
   ============================================================ */
$par_mois = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            DATE_FORMAT(date_demande, '%Y-%m') AS mois,
            COUNT(*) AS nb_demandes,
            COUNT(CASE WHEN statut NOT IN ('rejete','annule') THEN 1 END) AS nb_approuves,
            COUNT(CASE WHEN statut = 'rejete' THEN 1 END) AS nb_rejetes,
            COALESCE(SUM(CASE WHEN devise='CDF' AND montant_accorde > 0 THEN montant_accorde ELSE 0 END), 0) AS montant_cdf,
            COALESCE(SUM(CASE WHEN devise='USD' AND montant_accorde > 0 THEN montant_accorde ELSE 0 END), 0) AS montant_usd
        FROM Prets
        WHERE DATE(date_demande) BETWEEN ? AND ?
        GROUP BY DATE_FORMAT(date_demande, '%Y-%m')
        ORDER BY mois ASC
    ");
    $stmt->execute([$debut, $fin]);
    $par_mois = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {}

/* ============================================================
   ✅ TOP 10 CLIENTS EMPRUNTEURS (CORRIGÉ - ORDER BY sans alias)
   ============================================================ */
$top_clients = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            c.id_client,
            c.nom, c.postnom, c.prenom, c.numero_compte, c.numero_phone,
            COUNT(p.id_pret) AS nb_prets,
            COALESCE(SUM(CASE WHEN p.devise='CDF' THEN p.montant_accorde ELSE 0 END), 0) AS total_cdf,
            COALESCE(SUM(CASE WHEN p.devise='USD' THEN p.montant_accorde ELSE 0 END), 0) AS total_usd,
            COALESCE(SUM(CASE WHEN p.devise='CDF' THEN p.montant_paye ELSE 0 END), 0) AS paye_cdf,
            COALESCE(SUM(CASE WHEN p.devise='USD' THEN p.montant_paye ELSE 0 END), 0) AS paye_usd,
            COALESCE(SUM(CASE WHEN p.devise='CDF' THEN p.montant_restant_du ELSE 0 END), 0) AS restant_cdf,
            COALESCE(SUM(CASE WHEN p.devise='USD' THEN p.montant_restant_du ELSE 0 END), 0) AS restant_usd
        FROM Client c
        JOIN Prets p ON p.id_client = c.id_client
        WHERE DATE(p.date_demande) BETWEEN ? AND ?
          AND p.statut NOT IN ('rejete','annule')
        GROUP BY c.id_client, c.nom, c.postnom, c.prenom, c.numero_compte, c.numero_phone
        ORDER BY (
            COALESCE(SUM(CASE WHEN p.devise='CDF' THEN p.montant_accorde ELSE 0 END), 0)
            + COALESCE(SUM(CASE WHEN p.devise='USD' THEN p.montant_accorde ELSE 0 END), 0) * 2800
        ) DESC
        LIMIT 10
    ");
    $stmt->execute([$debut, $fin]);
    $top_clients = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {
    error_log('[rapport_prets - top_clients] ' . $e->getMessage());
}

/* ============================================================
   RÉPARTITION PAR PRODUIT (CORRIGÉ - GROUP BY complet)
   ============================================================ */
$par_produit = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            pr.id_produit,
            pr.nom AS produit_nom, 
            pr.taux_annuel, 
            pr.devise,
            COUNT(p.id_pret) AS nb,
            COALESCE(SUM(p.montant_accorde), 0) AS total,
            COALESCE(SUM(p.montant_paye), 0) AS rembourse
        FROM Produits_Pret pr
        LEFT JOIN Prets p ON p.id_produit = pr.id_produit 
            AND DATE(p.date_demande) BETWEEN ? AND ?
            AND p.statut NOT IN ('rejete','annule')
        GROUP BY pr.id_produit, pr.nom, pr.taux_annuel, pr.devise
        HAVING nb > 0
        ORDER BY total DESC
    ");
    $stmt->execute([$debut, $fin]);
    $par_produit = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {
    error_log('[rapport_prets - par_produit] ' . $e->getMessage());
}

/* Répartition par statut */
$par_statut = [];
try {
    $stmt = $pdo->prepare("
        SELECT statut, COUNT(*) AS nb
        FROM Prets
        WHERE DATE(date_demande) BETWEEN ? AND ?
        GROUP BY statut
    ");
    $stmt->execute([$debut, $fin]);
    foreach ($stmt->fetchAll() as $r) {
        $par_statut[$r['statut']] = (int)$r['nb'];
    }
} catch (Throwable $e) {}

try {
    logAction($pdo, 'admin', $admin_id, 'RAPPORT_PRETS_VIEW', "Année: $annee");
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Rapport Prêts <?= $annee ?> — ATF Admin</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/admin-premium.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
.kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 24px; }
.stat-block { padding: 18px; border-radius: 16px; background: linear-gradient(145deg, var(--card), var(--card-2)); border: 1px solid var(--border); display: flex; gap: 12px; align-items: center; }
.stat-block .ico { font-size: 1.8rem; }
.stat-block .meta strong { display: block; font-size: 1.4rem; color: var(--gold); }
.stat-block .meta span { font-size: .72rem; color: var(--muted); text-transform: uppercase; letter-spacing: 1px; }
.stat-block.green .meta strong { color: #6EE7B7; }
.stat-block.blue .meta strong { color: #93B4FF; }
.stat-block.red .meta strong { color: #FCA5A5; }
.stat-block.purple .meta strong { color: #A78BFA; }

.chart-card { background: linear-gradient(145deg, var(--card), var(--card-2)); border: 1px solid var(--border); border-radius: 20px; padding: 22px; margin-bottom: 22px; }
.chart-card h3 { font-size: 1rem; color: var(--gold); margin-bottom: 16px; }
.chart-wrap { position: relative; height: 320px; }

table.data { width: 100%; border-collapse: collapse; font-size: .85rem; background: linear-gradient(145deg, var(--card), var(--card-2)); border-radius: 14px; overflow: hidden; }
table.data thead { background: #0D1B5E; color: #fff; }
table.data th { padding: 12px; text-align: left; font-size: .72rem; text-transform: uppercase; letter-spacing: .5px; }
table.data td { padding: 12px; border-bottom: 1px solid var(--border); }
table.data tbody tr:hover { background: rgba(245,184,0,.05); cursor: pointer; }
table.data .right { text-align: right; }
table.data .pos { color: #6EE7B7; font-weight: 700; }
table.data .neg { color: #FCA5A5; font-weight: 700; }

.btn-view {
    padding: 6px 12px; border-radius: 8px; font-size: .72rem; font-weight: 800;
    background: rgba(245,184,0,.15); color: var(--gold);
    border: 1px solid rgba(245,184,0,.4); text-decoration: none;
    display: inline-flex; align-items: center; gap: 4px; transition: all .2s; white-space: nowrap;
}
.btn-view:hover { background: var(--gold); color: #0B0E14; transform: translateY(-1px); }

.btn-print-global {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 12px 22px; border-radius: 12px;
    background: linear-gradient(135deg, #F5B800, #D4AF37);
    color: #0B0E14; text-decoration: none; font-weight: 800; font-size: .88rem;
    box-shadow: 0 8px 24px rgba(245,184,0,.35); transition: all .25s;
    border: none; cursor: pointer; font-family: inherit;
}
.btn-print-global:hover { transform: translateY(-2px); box-shadow: 0 12px 32px rgba(245,184,0,.55); }

.report-toolbar {
    display: flex; gap: 10px; flex-wrap: wrap; align-items: center;
    margin-bottom: 20px; padding: 16px 20px;
    background: linear-gradient(145deg, var(--card), var(--card-2));
    border: 1px solid var(--border); border-radius: 14px;
}
.report-toolbar .spacer { flex: 1; }

@media (max-width: 768px) {
    table.data thead { display: none; }
    table.data tbody tr { display: block; margin-bottom: 12px; padding: 12px; border: 1px solid var(--border); border-radius: 10px; }
    table.data td { display: flex; justify-content: space-between; padding: 6px 0; border: none; }
    table.data td::before { content: attr(data-label); color: var(--muted); font-size: .7rem; font-weight: 700; text-transform: uppercase; }
    .chart-wrap { height: 240px; }
    .report-toolbar { flex-direction: column; align-items: stretch; }
}
</style>
</head>
<body class="dark-auth">

<?php require __DIR__ . '/../includes/header.php'; ?>

<div class="admin-layout">
  <aside class="admin-sidebar animate-left">
    <h3>📊 Navigation</h3>
    <a href="<?= $base ?>/admin/dashboard.php"><span class="ico">📊</span> Tableau de bord</a>
    <a href="<?= $base ?>/admin/prets.php"><span class="ico">💳</span> Prêts</a>
    <a href="<?= $base ?>/admin/prets_produits.php"><span class="ico">📋</span> Produits prêt</a>
    <a href="<?= $base ?>/admin/prets_echeances.php"><span class="ico">📅</span> Échéances</a>
    <a href="<?= $base ?>/admin/rapport_prets.php" class="active"><span class="ico">📈</span> Rapport prêts</a>
    <a href="<?= $base ?>/admin/logs.php"><span class="ico">📜</span> Journal</a>
    <a href="<?= $base ?>/auth/logout.php"><span class="ico">🚪</span> Déconnexion</a>
  </aside>

  <main class="admin-main">
    <div class="welcome-head">
      <div>
        <h2>📈 Rapport Prêts <?= $annee ?></h2>
        <p class="sub">Analyse complète du portefeuille</p>
      </div>
      <form method="GET">
        <select name="annee" onchange="this.form.submit()" 
                style="padding:10px 16px;background:var(--bg-1);border:1px solid var(--border);border-radius:10px;color:var(--text);font-weight:700">
          <?php for ($a = (int)date('Y'); $a >= 2020; $a--): ?>
            <option value="<?= $a ?>" <?= $a === $annee ? 'selected' : '' ?>><?= $a ?></option>
          <?php endfor; ?>
        </select>
      </form>
    </div>

    <!-- TOOLBAR ACTIONS -->
    <div class="report-toolbar">
      <strong style="color:var(--gold);font-size:.9rem">🛠️ Actions rapides :</strong>
      
      <a href="<?= $base ?>/admin/impression_prets.php?annee=<?= $annee ?>" 
         target="_blank" class="btn-print-global">
        🖨️ Imprimer tous les prêts (<?= $annee ?>)
      </a>
      
      <a href="<?= $base ?>/admin/impression_prets.php?annee=<?= $annee ?>&format=compact" 
         target="_blank"
         style="padding:12px 20px;border-radius:12px;background:rgba(59,130,246,.15);color:#93B4FF;border:1px solid rgba(59,130,246,.4);text-decoration:none;font-weight:800;font-size:.85rem;display:inline-flex;align-items:center;gap:6px">
        📋 Version compacte
      </a>
      
      <div class="spacer"></div>
      
      <span style="color:var(--muted);font-size:.8rem">
        📅 <?= $stats['total_prets'] ?> prêt(s) · <?= count($top_clients) ?> client(s) actif(s)
      </span>
    </div>

    <!-- KPIs -->
    <section class="kpi-grid">
      <div class="stat-block">
        <div class="ico">📋</div>
        <div class="meta"><strong><?= $stats['total_prets'] ?></strong><span>Total demandes</span></div>
      </div>
      <div class="stat-block green">
        <div class="ico">🔄</div>
        <div class="meta"><strong><?= $stats['prets_actifs'] ?></strong><span>Prêts actifs</span></div>
      </div>
      <div class="stat-block purple">
        <div class="ico">🎉</div>
        <div class="meta"><strong><?= $stats['prets_soldes'] ?></strong><span>Prêts soldés</span></div>
      </div>
      <div class="stat-block red">
        <div class="ico">⚠️</div>
        <div class="meta"><strong><?= $stats['prets_retard'] ?></strong><span>En retard</span></div>
      </div>
    </section>

    <section class="kpi-grid">
      <div class="stat-block">
        <div class="ico">💰</div>
        <div class="meta">
          <strong><?= number_format($stats['montant_decaisse_cdf'], 0, ',', ' ') ?> FC</strong>
          <span>Décaissé CDF</span>
        </div>
      </div>
      <div class="stat-block blue">
        <div class="ico">💵</div>
        <div class="meta">
          <strong>$ <?= number_format($stats['montant_decaisse_usd'], 0, ',', ' ') ?></strong>
          <span>Décaissé USD</span>
        </div>
      </div>
      <div class="stat-block green">
        <div class="ico">📊</div>
        <div class="meta">
          <strong><?= number_format($stats['taux_recouvrement'], 1) ?>%</strong>
          <span>Taux recouvrement</span>
        </div>
      </div>
      <div class="stat-block <?= $stats['par'] > 10 ? 'red' : 'green' ?>">
        <div class="ico">📉</div>
        <div class="meta">
          <strong><?= number_format($stats['par'], 1) ?>%</strong>
          <span>PAR (Portfolio à risque)</span>
        </div>
      </div>
    </section>

    <!-- Évolution mensuelle -->
    <?php if (!empty($par_mois)): ?>
    <div class="chart-card">
      <h3>📅 Évolution mensuelle <?= $annee ?></h3>
      <div class="chart-wrap">
        <canvas id="chartMois"></canvas>
      </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($par_statut)): ?>
    <div class="chart-card">
      <h3>📊 Répartition des statuts</h3>
      <div class="chart-wrap" style="height:300px">
        <canvas id="chartStatut"></canvas>
      </div>
    </div>
    <?php endif; ?>

    <!-- TOP CLIENTS -->
    <?php if (!empty($top_clients)): ?>
      <h3 style="margin:24px 0 14px;color:var(--gold);display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px">
        <span>🏆 Top 10 emprunteurs <?= $annee ?></span>
        <span style="font-size:.75rem;color:var(--muted);font-weight:500">
          👆 Cliquez sur une ligne pour voir le détail
        </span>
      </h3>
      <table class="data">
        <thead>
          <tr>
            <th>#</th>
            <th>Client</th>
            <th>Compte</th>
            <th class="right">Nb prêts</th>
            <th class="right">Total CDF</th>
            <th class="right">Total USD</th>
            <th class="right">Reste CDF</th>
            <th class="right">Reste USD</th>
            <th style="text-align:center">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($top_clients as $i => $tc): ?>
            <tr onclick="window.location.href='<?= $base ?>/admin/detail_client_pret.php?id=<?= (int)$tc['id_client'] ?>&annee=<?= $annee ?>'">
              <td data-label="#"><strong style="color:var(--gold)"><?= $i + 1 ?></strong></td>
              <td data-label="Client">
                <strong><?= htmlspecialchars($tc['prenom'] . ' ' . $tc['nom']) ?></strong><br>
                <small style="color:var(--muted)"><?= htmlspecialchars($tc['numero_phone'] ?? '—') ?></small>
              </td>
              <td data-label="Compte"><code><?= htmlspecialchars($tc['numero_compte']) ?></code></td>
              <td data-label="Nb prêts" class="right"><?= (int)$tc['nb_prets'] ?></td>
              <td data-label="CDF" class="right pos"><?= number_format((float)$tc['total_cdf'], 0, ',', ' ') ?> FC</td>
              <td data-label="USD" class="right pos">$ <?= number_format((float)$tc['total_usd'], 0, ',', ' ') ?></td>
              <td data-label="Reste CDF" class="right <?= (float)$tc['restant_cdf'] > 0 ? 'neg' : 'pos' ?>">
                <?= number_format((float)$tc['restant_cdf'], 0, ',', ' ') ?> FC
              </td>
              <td data-label="Reste USD" class="right <?= (float)$tc['restant_usd'] > 0 ? 'neg' : 'pos' ?>">
                $ <?= number_format((float)$tc['restant_usd'], 0, ',', ' ') ?>
              </td>
              <td data-label="Action" style="text-align:center" onclick="event.stopPropagation()">
                <a href="<?= $base ?>/admin/detail_client_pret.php?id=<?= (int)$tc['id_client'] ?>&annee=<?= $annee ?>" 
                   class="btn-view">
                  👁️ Voir détail
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <!-- Répartition par produit -->
    <?php if (!empty($par_produit)): ?>
      <h3 style="margin:24px 0 14px;color:var(--gold)">📋 Répartition par produit</h3>
      <table class="data">
        <thead>
          <tr>
            <th>Produit</th>
            <th>Taux</th>
            <th class="right">Nb prêts</th>
            <th class="right">Montant décaissé</th>
            <th class="right">Remboursé</th>
            <th class="right">Taux remb.</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($par_produit as $pr): 
            $total_pr = (float)$pr['total'];
            $remb_pr = (float)$pr['rembourse'];
            $taux_remb = $total_pr > 0 ? ($remb_pr / $total_pr) * 100 : 0;
          ?>
            <tr>
              <td data-label="Produit"><strong><?= htmlspecialchars($pr['produit_nom']) ?></strong></td>
              <td data-label="Taux"><?= number_format((float)$pr['taux_annuel'] * 100, 2) ?>%/an</td>
              <td data-label="Nb" class="right"><?= (int)$pr['nb'] ?></td>
              <td data-label="Décaissé" class="right">
                <?= number_format($total_pr, 0, ',', ' ') ?> <?= htmlspecialchars($pr['devise']) ?>
              </td>
              <td data-label="Remboursé" class="right pos">
                <?= number_format($remb_pr, 0, ',', ' ') ?> <?= htmlspecialchars($pr['devise']) ?>
              </td>
              <td data-label="Taux" class="right"><strong><?= number_format($taux_remb, 1) ?>%</strong></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

  </main>
</div>

<footer class="atf-footer">© <?= date('Y') ?> AFRICAN TRUST FUND</footer>

<script>
Chart.defaults.color = '#8A93A6';
Chart.defaults.font.family = "'Inter',sans-serif";
Chart.defaults.borderColor = 'rgba(255,255,255,.06)';

<?php if (!empty($par_mois)): ?>
(function() {
  const ctx = document.getElementById('chartMois').getContext('2d');
  const labels = <?= json_encode(array_map(fn($r) => date('M Y', strtotime($r['mois'] . '-01')), $par_mois)) ?>;
  const dataDemandes = <?= json_encode(array_map(fn($r) => (int)$r['nb_demandes'], $par_mois)) ?>;
  const dataApprouves = <?= json_encode(array_map(fn($r) => (int)$r['nb_approuves'], $par_mois)) ?>;
  const dataRejetes = <?= json_encode(array_map(fn($r) => (int)$r['nb_rejetes'], $par_mois)) ?>;
  
  new Chart(ctx, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [
        { label: 'Demandes', data: dataDemandes, backgroundColor: 'rgba(245,184,0,.7)', borderRadius: 6 },
        { label: 'Approuvés', data: dataApprouves, backgroundColor: 'rgba(16,185,129,.7)', borderRadius: 6 },
        { label: 'Rejetés', data: dataRejetes, backgroundColor: 'rgba(239,68,68,.7)', borderRadius: 6 },
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { position: 'top', labels: { color: '#E8ECF3', usePointStyle: true, padding: 14 } },
        tooltip: { backgroundColor: '#1A2130', titleColor: '#F5B800', borderColor: '#F5B800', borderWidth: 1, padding: 12 }
      },
      scales: {
        x: { grid: { color: 'rgba(255,255,255,.04)' }, ticks: { color: '#8A93A6' } },
        y: { grid: { color: 'rgba(255,255,255,.04)' }, ticks: { color: '#8A93A6' } }
      }
    }
  });
})();
<?php endif; ?>

<?php if (!empty($par_statut)): ?>
(function() {
  const statuts = <?= json_encode($par_statut) ?>;
  const labels = Object.keys(statuts).map(s => s.replace('_', ' ').toUpperCase());
  const data = Object.values(statuts);
  const couleurs = {
    'brouillon': '#6B7280', 'soumis': '#3B82F6', 'en_analyse': '#8B5CF6',
    'approuve': '#10B981', 'signe': '#06B6D4', 'decaisse': '#F5B800',
    'en_cours': '#F5B800', 'en_retard': '#EF4444', 'solde': '#10B981',
    'rejete': '#DC2626', 'annule': '#6B7280', 'contentieux': '#991B1B'
  };
  const bgColors = Object.keys(statuts).map(s => couleurs[s] || '#6B7280');
  
  new Chart(document.getElementById('chartStatut'), {
    type: 'doughnut',
    data: {
      labels: labels,
      datasets: [{ data: data, backgroundColor: bgColors, borderColor: '#1A2130', borderWidth: 4, hoverOffset: 12 }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      cutout: '60%',
      plugins: {
        legend: { position: 'bottom', labels: { color: '#E8ECF3', usePointStyle: true, padding: 14, font: { size: 11, weight: '700' } } },
        tooltip: { backgroundColor: '#1A2130', titleColor: '#F5B800', borderColor: '#F5B800', borderWidth: 1, padding: 12 }
      }
    }
  });
})();
<?php endif; ?>
</script>
<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>