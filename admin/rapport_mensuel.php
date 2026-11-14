<?php
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/parametres.php';
requireRole('admin');

$pdo = Database::getConnection();

/* ============================================================
   SÉLECTION DU MOIS & ANNÉE
   ============================================================ */
$mois_rapport  = (int)($_GET['mois']  ?? date('n'));
$annee_rapport = (int)($_GET['annee'] ?? date('Y'));

if ($mois_rapport < 1  || $mois_rapport > 12)  $mois_rapport  = (int)date('n');
if ($annee_rapport < 2020 || $annee_rapport > 2100) $annee_rapport = (int)date('Y');

/* Bornes du mois */
$debut = sprintf('%04d-%02d-01', $annee_rapport, $mois_rapport);
$fin   = date('Y-m-t', strtotime($debut)); // dernier jour du mois

/* Mois précédent (pour comparaison) */
$ts_mois_prec = strtotime($debut . ' -1 month');
$debut_prec   = date('Y-m-01', $ts_mois_prec);
$fin_prec     = date('Y-m-t',  $ts_mois_prec);

/* Noms des mois en français */
$noms_mois = [
    1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril',
    5 => 'Mai', 6 => 'Juin', 7 => 'Juillet', 8 => 'Août',
    9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre'
];
$libelle_mois = $noms_mois[$mois_rapport] . ' ' . $annee_rapport;

/* ============================================================
   STATISTIQUES DU MOIS
   ============================================================ */
$stats = [
    'depots'              => 0,
    'retraits'            => 0,
    'virements'           => 0,
    'nb_transactions'     => 0,
    'commissions'         => 0,
    'nb_placements'       => 0,
    'capital_investi'     => 0,
    'nb_nouveaux_clients' => 0,
];

$stmt = $pdo->prepare("
    SELECT
        type_transaction,
        COUNT(*) AS nb,
        COALESCE(SUM(montant), 0) AS total
    FROM Transactions
    WHERE DATE(date_transaction) BETWEEN ? AND ?
    GROUP BY type_transaction
");
$stmt->execute([$debut, $fin]);
foreach ($stmt->fetchAll() as $t) {
    $stats['nb_transactions'] += (int)$t['nb'];
    if ($t['type_transaction'] === 'Depot')    $stats['depots']    = (float)$t['total'];
    if ($t['type_transaction'] === 'Retrait')  $stats['retraits']  = (float)$t['total'];
    if ($t['type_transaction'] === 'Virement') $stats['virements'] = (float)$t['total'];
}

$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(montant_commission), 0)
    FROM Commissions
    WHERE DATE(date_commission) BETWEEN ? AND ?
");
$stmt->execute([$debut, $fin]);
$stats['commissions'] = (float)$stmt->fetchColumn();

$stmt = $pdo->prepare("
    SELECT COUNT(*), COALESCE(SUM(montant_initial), 0)
    FROM Placements
    WHERE DATE(created_at) BETWEEN ? AND ?
");
$stmt->execute([$debut, $fin]);
$pl = $stmt->fetch(PDO::FETCH_NUM);
$stats['nb_placements']   = (int)$pl[0];
$stats['capital_investi'] = (float)$pl[1];

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM Client
    WHERE DATE(created_at) BETWEEN ? AND ?
");
$stmt->execute([$debut, $fin]);
$stats['nb_nouveaux_clients'] = (int)$stmt->fetchColumn();

/* ============================================================
   STATS DU MOIS PRÉCÉDENT (pour comparaison)
   ============================================================ */
$stats_prec = [
    'depots' => 0, 'retraits' => 0, 'commissions' => 0, 'nb_transactions' => 0,
];

$stmt = $pdo->prepare("
    SELECT type_transaction, COUNT(*) AS nb, COALESCE(SUM(montant), 0) AS total
    FROM Transactions
    WHERE DATE(date_transaction) BETWEEN ? AND ?
    GROUP BY type_transaction
");
$stmt->execute([$debut_prec, $fin_prec]);
foreach ($stmt->fetchAll() as $t) {
    $stats_prec['nb_transactions'] += (int)$t['nb'];
    if ($t['type_transaction'] === 'Depot')   $stats_prec['depots']   = (float)$t['total'];
    if ($t['type_transaction'] === 'Retrait') $stats_prec['retraits'] = (float)$t['total'];
}

$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(montant_commission), 0)
    FROM Commissions
    WHERE DATE(date_commission) BETWEEN ? AND ?
");
$stmt->execute([$debut_prec, $fin_prec]);
$stats_prec['commissions'] = (float)$stmt->fetchColumn();

/* Calcul des variations en % */
function variation($actuel, $precedent) {
    if ($precedent <= 0) return null;
    return (($actuel - $precedent) / $precedent) * 100;
}

/* ============================================================
   DÉTAIL JOURNALIER DU MOIS
   ============================================================ */
$stmt = $pdo->prepare("
    SELECT
        DATE(date_transaction) AS jour,
        COUNT(*) AS nb,
        SUM(CASE WHEN type_transaction='Depot'    THEN montant ELSE 0 END) AS depots,
        SUM(CASE WHEN type_transaction='Retrait'  THEN montant ELSE 0 END) AS retraits,
        SUM(CASE WHEN type_transaction='Virement' THEN montant ELSE 0 END) AS virements
    FROM Transactions
    WHERE DATE(date_transaction) BETWEEN ? AND ?
    GROUP BY DATE(date_transaction)
    ORDER BY jour ASC
");
$stmt->execute([$debut, $fin]);
$detail_jours = $stmt->fetchAll();

/* ============================================================
   RÉPARTITION PAR TYPE (pour graphique simple)
   ============================================================ */
$repartition = [
    'Depot'    => ['nb' => 0, 'total' => 0],
    'Retrait'  => ['nb' => 0, 'total' => 0],
    'Virement' => ['nb' => 0, 'total' => 0],
];
$stmt = $pdo->prepare("
    SELECT type_transaction, COUNT(*) AS nb, COALESCE(SUM(montant), 0) AS total
    FROM Transactions
    WHERE DATE(date_transaction) BETWEEN ? AND ?
    GROUP BY type_transaction
");
$stmt->execute([$debut, $fin]);
foreach ($stmt->fetchAll() as $t) {
    if (isset($repartition[$t['type_transaction']])) {
        $repartition[$t['type_transaction']]['nb']    = (int)$t['nb'];
        $repartition[$t['type_transaction']]['total'] = (float)$t['total'];
    }
}
$total_volume = array_sum(array_column($repartition, 'total'));

/* ============================================================
   TOP 10 CLIENTS DU MOIS
   ============================================================ */
$stmt = $pdo->prepare("
    SELECT
        c.nom, c.postnom, c.prenom, c.numero_compte,
        COUNT(t.id_transaction) AS nb_transactions,
        COALESCE(SUM(t.montant), 0) AS total_volume
    FROM Client c
    JOIN Transactions t ON t.id_client = c.id_client
    WHERE DATE(t.date_transaction) BETWEEN ? AND ?
    GROUP BY c.id_client
    ORDER BY total_volume DESC
    LIMIT 10
");
$stmt->execute([$debut, $fin]);
$top_clients = $stmt->fetchAll();

/* ============================================================
   TOP 5 AGENTS / CAISSIERS DU MOIS (si table existe)
   ============================================================ */
$top_agents = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            effectue_par,
            COUNT(*) AS nb,
            COALESCE(SUM(montant), 0) AS total
        FROM Transactions
        WHERE DATE(date_transaction) BETWEEN ? AND ?
          AND effectue_par IS NOT NULL
          AND effectue_par != 'Admin'
        GROUP BY effectue_par
        ORDER BY total DESC
        LIMIT 5
    ");
    $stmt->execute([$debut, $fin]);
    $top_agents = $stmt->fetchAll();
} catch (Throwable $e) { /* silencieux */ }
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Rapport Mensuel <?= $libelle_mois ?> — ATF</title>
<style>
@page { size: A4; margin: 12mm; }
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: Arial, sans-serif; background: #fff; color: #1a1a1a; padding: 20px; font-size: 11px; }

.header {
  display: flex; justify-content: space-between; align-items: center;
  padding-bottom: 15px; border-bottom: 3px solid #F5B800; margin-bottom: 20px;
}
.header h1 { color: #0D1B5E; font-size: 22px; letter-spacing: 2px; }
.header .subtitle { color: #666; font-size: 12px; font-style: italic; margin-top: 4px; }
.header .ref { text-align: right; font-size: 10px; color: #444; line-height: 1.6; }
.header .ref strong { color: #0D1B5E; font-size: 15px; display: block; margin-bottom: 4px; }

.pickers {
  display: flex; gap: 10px; margin-bottom: 20px;
  padding: 14px 18px; background: #F7FAFC; border-radius: 10px;
  border-left: 4px solid #0D1B5E; align-items: center; flex-wrap: wrap;
}
.pickers label { font-weight: 700; color: #0D1B5E; font-size: 11px; margin-right: 4px; }
.pickers select {
  padding: 8px 14px; border-radius: 8px;
  border: 2px solid #F5B800; font-weight: 700; font-size: 12px;
  background: #fff; cursor: pointer;
}

.summary-box {
  display: grid; grid-template-columns: repeat(4, 1fr);
  gap: 12px; margin-bottom: 24px; padding: 16px;
  background: #F7FAFC; border-radius: 10px; border-left: 4px solid #F5B800;
}
.summary-item .lbl { font-size: 9px; color: #666; text-transform: uppercase; letter-spacing: 1px; }
.summary-item .val { font-size: 18px; font-weight: 800; color: #0D1B5E; margin-top: 4px; }
.summary-item .val.green { color: #059669; }
.summary-item .val.red   { color: #DC2626; }
.summary-item .val.gold  { color: #B8860B; }

/* Badges de variation */
.variation {
  display: inline-block; font-size: 9px; font-weight: 800;
  padding: 2px 6px; border-radius: 4px; margin-left: 6px;
  vertical-align: middle;
}
.variation.up   { background: rgba(5,150,105,.15);  color: #059669; }
.variation.down { background: rgba(220,38,38,.15);  color: #DC2626; }
.variation.flat { background: rgba(107,114,128,.15);color: #6B7280; }

.section { margin-bottom: 28px; }
.section-title {
  color: #0D1B5E; font-size: 14px; font-weight: 800;
  text-transform: uppercase; letter-spacing: 1.5px;
  padding-bottom: 8px; border-bottom: 2px solid #F5B800; margin-bottom: 14px;
}

table { width: 100%; border-collapse: collapse; font-size: 10px; }
thead { background: #0D1B5E; color: #fff; }
th { padding: 9px 8px; text-align: left; font-size: 9px; text-transform: uppercase; }
td { padding: 8px; border-bottom: 1px solid #e5e5e5; }
tbody tr:nth-child(even) { background: #fafafa; }

.amount { text-align: right; font-weight: 700; }
.amount.gold  { color: #B8860B; }
.amount.green { color: #059669; }
.amount.red   { color: #DC2626; }

.rank-badge {
  display: inline-block; width: 26px; height: 26px;
  border-radius: 50%; background: #F5B800; color: #0B0E14;
  line-height: 26px; text-align: center; font-weight: 800;
}

/* Barre de répartition */
.repartition-grid {
  display: grid; grid-template-columns: repeat(3, 1fr);
  gap: 14px; margin-bottom: 10px;
}
.repartition-card {
  padding: 16px; border-radius: 10px;
  background: #F7FAFC; border-top: 3px solid #0D1B5E;
}
.repartition-card.depot    { border-top-color: #059669; }
.repartition-card.retrait  { border-top-color: #DC2626; }
.repartition-card.virement { border-top-color: #B8860B; }
.repartition-card .head {
  display: flex; justify-content: space-between; align-items: center;
  margin-bottom: 8px;
}
.repartition-card .head .title { font-weight: 800; font-size: 11px; color: #0D1B5E; }
.repartition-card .head .pct   { font-weight: 800; font-size: 13px; color: #B8860B; }
.repartition-card .nb { font-size: 10px; color: #666; margin-bottom: 6px; }
.repartition-card .bar {
  height: 8px; background: #E5E7EB; border-radius: 4px; overflow: hidden;
}
.repartition-card .bar > div {
  height: 100%; border-radius: 4px;
  transition: width .3s;
}
.repartition-card.depot    .bar > div { background: #059669; }
.repartition-card.retrait  .bar > div { background: #DC2626; }
.repartition-card.virement .bar > div { background: #B8860B; }

.footer {
  margin-top: 40px; padding-top: 15px;
  border-top: 3px solid #F5B800; text-align: center;
  font-size: 9px; color: #666; line-height: 1.6;
}

.btn-print {
  position: fixed; top: 20px; right: 20px;
  padding: 12px 24px; background: #F5B800; color: #0B0E14;
  border: none; border-radius: 999px; font-weight: 800;
  cursor: pointer; font-size: 14px;
}
.btn-back {
  position: fixed; top: 20px; left: 20px;
  padding: 12px 20px; background: #0D1B5E; color: #F5B800;
  text-decoration: none; border: 2px solid #F5B800;
  border-radius: 999px; font-weight: 700; font-size: 13px;
}

@media print {
  body { padding: 0; }
  .btn-print, .btn-back, .pickers { display: none; }
  .header { margin-top: 0; }
}
</style>
</head>
<body>

<button class="btn-print" onclick="window.print()">🖨️ Imprimer / PDF</button>
<a class="btn-back" href="<?= '/african-trust-fund/admin/parametres.php' ?>">← Retour</a>

<!-- ============================================================
     EN-TÊTE
     ============================================================ -->
<div class="header">
  <div>
    <h1>AFRICAN TRUST FUND</h1>
    <div class="subtitle">Rapport Mensuel — Bilan d'activités</div>
  </div>
  <div class="ref">
    <strong><?= strtoupper($libelle_mois) ?></strong>
    Du <?= date('d/m/Y', strtotime($debut)) ?> au <?= date('d/m/Y', strtotime($fin)) ?><br>
    Édité le : <?= date('d/m/Y à H:i') ?>
  </div>
</div>

<!-- ============================================================
     SÉLECTEURS MOIS / ANNÉE (masqués à l'impression)
     ============================================================ -->
<form method="GET" class="pickers">
  <label>📅 Mois :</label>
  <select name="mois" onchange="this.form.submit()">
    <?php for ($m = 1; $m <= 12; $m++): ?>
      <option value="<?= $m ?>" <?= $m === $mois_rapport ? 'selected' : '' ?>>
        <?= $noms_mois[$m] ?>
      </option>
    <?php endfor; ?>
  </select>

  <label>Année :</label>
  <select name="annee" onchange="this.form.submit()">
    <?php for ($a = (int)date('Y'); $a >= 2020; $a--): ?>
      <option value="<?= $a ?>" <?= $a === $annee_rapport ? 'selected' : '' ?>><?= $a ?></option>
    <?php endfor; ?>
  </select>

  <label style="margin-left:auto;color:#666;font-weight:400;">
    Période : <?= date('d/m/Y', strtotime($debut)) ?> → <?= date('d/m/Y', strtotime($fin)) ?>
  </label>
</form>

<!-- ============================================================
     RÉSUMÉ (4 CARTES)
     ============================================================ -->
<div class="summary-box">
  <div class="summary-item">
    <div class="lbl">💰 Dépôts</div>
    <div class="val green">
      +<?= number_format($stats['depots'], 0, ',', ' ') ?>
      <?php $v = variation($stats['depots'], $stats_prec['depots']); ?>
      <?php if ($v !== null): ?>
        <span class="variation <?= $v > 0 ? 'up' : ($v < 0 ? 'down' : 'flat') ?>">
          <?= $v > 0 ? '▲' : ($v < 0 ? '▼' : '=') ?> <?= number_format(abs($v), 1) ?>%
        </span>
      <?php endif; ?>
    </div>
  </div>
  <div class="summary-item">
    <div class="lbl">💸 Retraits</div>
    <div class="val red">
      −<?= number_format($stats['retraits'], 0, ',', ' ') ?>
      <?php $v = variation($stats['retraits'], $stats_prec['retraits']); ?>
      <?php if ($v !== null): ?>
        <span class="variation <?= $v > 0 ? 'down' : ($v < 0 ? 'up' : 'flat') ?>">
          <?= $v > 0 ? '▲' : ($v < 0 ? '▼' : '=') ?> <?= number_format(abs($v), 1) ?>%
        </span>
      <?php endif; ?>
    </div>
  </div>
  <div class="summary-item">
    <div class="lbl">💵 Commissions</div>
    <div class="val gold">
      <?= number_format($stats['commissions'], 0, ',', ' ') ?>
      <?php $v = variation($stats['commissions'], $stats_prec['commissions']); ?>
      <?php if ($v !== null): ?>
        <span class="variation <?= $v > 0 ? 'up' : ($v < 0 ? 'down' : 'flat') ?>">
          <?= $v > 0 ? '▲' : ($v < 0 ? '▼' : '=') ?> <?= number_format(abs($v), 1) ?>%
        </span>
      <?php endif; ?>
    </div>
  </div>
  <div class="summary-item">
    <div class="lbl">📊 Transactions</div>
    <div class="val">
      <?= $stats['nb_transactions'] ?>
      <?php $v = variation($stats['nb_transactions'], $stats_prec['nb_transactions']); ?>
      <?php if ($v !== null): ?>
        <span class="variation <?= $v > 0 ? 'up' : ($v < 0 ? 'down' : 'flat') ?>">
          <?= $v > 0 ? '▲' : ($v < 0 ? '▼' : '=') ?> <?= number_format(abs($v), 1) ?>%
        </span>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- ============================================================
     INDICATEURS CLÉS
     ============================================================ -->
<div class="section">
  <div class="section-title">📊 Indicateurs clés — <?= $libelle_mois ?></div>
  <table>
    <tbody>
      <tr>
        <td><strong>Capital investi (nouveaux placements)</strong></td>
        <td class="amount gold"><?= number_format($stats['capital_investi'], 0, ',', ' ') ?> CDF</td>
        <td><strong>Nombre de placements</strong></td>
        <td class="amount"><?= $stats['nb_placements'] ?></td>
      </tr>
      <tr>
        <td><strong>Virements effectués</strong></td>
        <td class="amount"><?= number_format($stats['virements'], 0, ',', ' ') ?> CDF</td>
        <td><strong>Nouveaux clients</strong></td>
        <td class="amount"><?= $stats['nb_nouveaux_clients'] ?></td>
      </tr>
      <tr>
        <td><strong>Solde net du mois (Dépôts − Retraits)</strong></td>
        <td class="amount <?= ($stats['depots'] - $stats['retraits']) >= 0 ? 'green' : 'red' ?>">
          <?= ($stats['depots'] - $stats['retraits']) >= 0 ? '+' : '−' ?>
          <?= number_format(abs($stats['depots'] - $stats['retraits']), 0, ',', ' ') ?> CDF
        </td>
        <td><strong>Volume total traité</strong></td>
        <td class="amount"><?= number_format($total_volume, 0, ',', ' ') ?> CDF</td>
      </tr>
    </tbody>
  </table>
</div>

<!-- ============================================================
     RÉPARTITION PAR TYPE DE TRANSACTION
     ============================================================ -->
<?php if ($total_volume > 0): ?>
<div class="section">
  <div class="section-title">📈 Répartition par type de transaction</div>
  <div class="repartition-grid">
    <?php
      $pct_depot    = $total_volume > 0 ? ($repartition['Depot']['total']    / $total_volume) * 100 : 0;
      $pct_retrait  = $total_volume > 0 ? ($repartition['Retrait']['total']  / $total_volume) * 100 : 0;
      $pct_virement = $total_volume > 0 ? ($repartition['Virement']['total'] / $total_volume) * 100 : 0;
    ?>
    <div class="repartition-card depot">
      <div class="head">
        <span class="title">💰 Dépôts</span>
        <span class="pct"><?= number_format($pct_depot, 1) ?>%</span>
      </div>
      <div class="nb"><?= $repartition['Depot']['nb'] ?> opération(s)</div>
      <div class="nb"><strong><?= number_format($repartition['Depot']['total'], 0, ',', ' ') ?> CDF</strong></div>
      <div class="bar"><div style="width:<?= $pct_depot ?>%"></div></div>
    </div>

    <div class="repartition-card retrait">
      <div class="head">
        <span class="title">💸 Retraits</span>
        <span class="pct"><?= number_format($pct_retrait, 1) ?>%</span>
      </div>
      <div class="nb"><?= $repartition['Retrait']['nb'] ?> opération(s)</div>
      <div class="nb"><strong><?= number_format($repartition['Retrait']['total'], 0, ',', ' ') ?> CDF</strong></div>
      <div class="bar"><div style="width:<?= $pct_retrait ?>%"></div></div>
    </div>

    <div class="repartition-card virement">
      <div class="head">
        <span class="title">🔁 Virements</span>
        <span class="pct"><?= number_format($pct_virement, 1) ?>%</span>
      </div>
      <div class="nb"><?= $repartition['Virement']['nb'] ?> opération(s)</div>
      <div class="nb"><strong><?= number_format($repartition['Virement']['total'], 0, ',', ' ') ?> CDF</strong></div>
      <div class="bar"><div style="width:<?= $pct_virement ?>%"></div></div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- ============================================================
     DÉTAIL JOURNALIER
     ============================================================ -->
<div class="section">
  <div class="section-title">📅 Détail journalier du mois</div>
  <table>
    <thead>
      <tr>
        <th>Jour</th>
        <th class="amount">Nb transactions</th>
        <th class="amount">Dépôts</th>
        <th class="amount">Retraits</th>
        <th class="amount">Virements</th>
        <th class="amount">Solde net</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($detail_jours)): ?>
        <tr><td colspan="6" style="text-align:center;padding:30px;color:#999;font-style:italic">
          Aucune transaction pour ce mois.
        </td></tr>
      <?php else: foreach ($detail_jours as $j):
        $net = (float)$j['depots'] - (float)$j['retraits'];
        $jour_num = (int)date('d', strtotime($j['jour']));
        $jour_nom = ['Dimanche','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi'][(int)date('w', strtotime($j['jour']))];
      ?>
        <tr>
          <td>
            <strong><?= str_pad($jour_num, 2, '0', STR_PAD_LEFT) ?>/<?= str_pad($mois_rapport, 2, '0', STR_PAD_LEFT) ?></strong>
            <small style="color:#888;margin-left:6px;"><?= $jour_nom ?></small>
          </td>
          <td class="amount"><?= (int)$j['nb'] ?></td>
          <td class="amount green">+<?= number_format((float)$j['depots'], 0, ',', ' ') ?></td>
          <td class="amount red">−<?= number_format((float)$j['retraits'], 0, ',', ' ') ?></td>
          <td class="amount"><?= number_format((float)$j['virements'], 0, ',', ' ') ?></td>
          <td class="amount <?= $net >= 0 ? 'green' : 'red' ?>">
            <?= $net >= 0 ? '+' : '' ?><?= number_format($net, 0, ',', ' ') ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<!-- ============================================================
     TOP 10 CLIENTS
     ============================================================ -->
<?php if (!empty($top_clients)): ?>
<div class="section">
  <div class="section-title">🏆 Top 10 clients les plus actifs du mois</div>
  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Client</th>
        <th>Compte</th>
        <th class="amount">Nb transactions</th>
        <th class="amount">Volume total</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($top_clients as $i => $c): ?>
        <tr>
          <td><span class="rank-badge"><?= $i + 1 ?></span></td>
          <td>
            <strong><?= htmlspecialchars($c['nom'] . ' ' . $c['postnom']) ?></strong><br>
            <small style="color:#888"><?= htmlspecialchars($c['prenom']) ?></small>
          </td>
          <td><?= htmlspecialchars($c['numero_compte']) ?></td>
          <td class="amount"><?= (int)$c['nb_transactions'] ?></td>
          <td class="amount gold"><?= number_format((float)$c['total_volume'], 0, ',', ' ') ?> CDF</td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- ============================================================
     TOP AGENTS / CAISSIERS
     ============================================================ -->
<?php if (!empty($top_agents)): ?>
<div class="section">
  <div class="section-title">💼 Performance des agents ce mois</div>
  <table>
    <thead>
      <tr>
        <th>#</th>
        <th>Agent</th>
        <th class="amount">Nb opérations</th>
        <th class="amount">Volume traité</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($top_agents as $i => $a): ?>
        <tr>
          <td><span class="rank-badge"><?= $i + 1 ?></span></td>
          <td><strong><?= htmlspecialchars($a['effectue_par']) ?></strong></td>
          <td class="amount"><?= (int)$a['nb'] ?></td>
          <td class="amount gold"><?= number_format((float)$a['total'], 0, ',', ' ') ?> CDF</td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- ============================================================
     PIED DE PAGE
     ============================================================ -->
<div class="footer">
  <strong>AFRICAN TRUST FUND</strong> — L'argent de l'Afrique pour construire l'Afrique<br>
  Rapport mensuel <?= $libelle_mois ?> · Généré le <?= date('d/m/Y à H:i:s') ?><br>
  RCCM : CD/KIN/RCCM/24-B-01234 · NIF : A0123456Z · Agrément BCC n°045/2024<br>
  Avenue de la Paix n°45, Kinshasa-Gombe, RDC · +243 123 456 789 · contact@africantrustfund.cd
</div>

</body>
</html>