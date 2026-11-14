<?php
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/parametres.php';
requireRole('admin');

$pdo = Database::getConnection();

/* Année du rapport (par défaut : année actuelle) */
$annee_rapport = $_GET['annee'] ?? date('Y');
$annee_rapport = (int)$annee_rapport;
if ($annee_rapport < 2020 || $annee_rapport > 2100) {
    $annee_rapport = (int)date('Y');
}

$debut = $annee_rapport . '-01-01';
$fin = $annee_rapport . '-12-31';

/* ============================================================
   STATISTIQUES ANNUELLES
   ============================================================ */
$stats = [
    'depots' => 0,
    'retraits' => 0,
    'virements' => 0,
    'nb_transactions' => 0,
    'commissions' => 0,
    'nb_placements' => 0,
    'capital_investi' => 0,
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
    if ($t['type_transaction'] === 'Depot') $stats['depots'] = (float)$t['total'];
    if ($t['type_transaction'] === 'Retrait') $stats['retraits'] = (float)$t['total'];
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
$stats['nb_placements'] = (int)$pl[0];
$stats['capital_investi'] = (float)$pl[1];

$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM Client
    WHERE DATE(created_at) BETWEEN ? AND ?
");
$stmt->execute([$debut, $fin]);
$stats['nb_nouveaux_clients'] = (int)$stmt->fetchColumn();

/* Détail par mois */
$stmt = $pdo->prepare("
    SELECT 
        DATE_FORMAT(date_transaction, '%Y-%m') AS mois,
        COUNT(*) AS nb,
        SUM(CASE WHEN type_transaction='Depot' THEN montant ELSE 0 END) AS depots,
        SUM(CASE WHEN type_transaction='Retrait' THEN montant ELSE 0 END) AS retraits,
        SUM(CASE WHEN type_transaction='Virement' THEN montant ELSE 0 END) AS virements
    FROM Transactions
    WHERE DATE(date_transaction) BETWEEN ? AND ?
    GROUP BY DATE_FORMAT(date_transaction, '%Y-%m')
    ORDER BY mois ASC
");
$stmt->execute([$debut, $fin]);
$detail_mois = $stmt->fetchAll();

/* Top clients de l'année */
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
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Rapport Annuel — ATF</title>
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
.header .ref strong { color: #0D1B5E; font-size: 14px; display: block; margin-bottom: 4px; }

.summary-box {
  display: grid; grid-template-columns: repeat(4, 1fr);
  gap: 12px; margin-bottom: 24px; padding: 16px;
  background: #F7FAFC; border-radius: 10px; border-left: 4px solid #F5B800;
}
.summary-item .lbl { font-size: 9px; color: #666; text-transform: uppercase; letter-spacing: 1px; }
.summary-item .val { font-size: 18px; font-weight: 800; color: #0D1B5E; margin-top: 4px; }
.summary-item .val.green { color: #059669; }
.summary-item .val.red { color: #DC2626; }
.summary-item .val.gold { color: #B8860B; }

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
.amount.gold { color: #B8860B; }
.amount.green { color: #059669; }
.amount.red { color: #DC2626; }

.rank-badge {
  display: inline-block; width: 26px; height: 26px;
  border-radius: 50%; background: #F5B800; color: #0B0E14;
  line-height: 26px; text-align: center; font-weight: 800;
}

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
.year-picker {
  padding: 10px 16px; border-radius: 8px;
  border: 2px solid #F5B800; font-weight: 700;
}
@media print {
  body { padding: 0; }
  .btn-print, .btn-back { display: none; }
}
</style>
</head>
<body>

<button class="btn-print" onclick="window.print()">🖨️ Imprimer / PDF</button>
<a class="btn-back" href="<?= '/african-trust-fund/admin/parametres.php' ?>">← Retour</a>

<div class="header">
  <div>
    <h1>AFRICAN TRUST FUND</h1>
    <div class="subtitle">Rapport Annuel — Bilan d'activités</div>
  </div>
  <div class="ref">
    <strong>ANNÉE <?= $annee_rapport ?></strong>
    Édité le : <?= date('d/m/Y à H:i') ?><br>
    <select class="year-picker" onchange="location.href='?annee=' + this.value">
      <?php for ($a = (int)date('Y'); $a >= 2020; $a--): ?>
        <option value="<?= $a ?>" <?= $a === $annee_rapport ? 'selected' : '' ?>><?= $a ?></option>
      <?php endfor; ?>
    </select>
  </div>
</div>

<div class="summary-box">
  <div class="summary-item">
    <div class="lbl">💰 Dépôts</div>
    <div class="val green">+<?= number_format($stats['depots'], 0, ',', ' ') ?></div>
  </div>
  <div class="summary-item">
    <div class="lbl">💸 Retraits</div>
    <div class="val red">−<?= number_format($stats['retraits'], 0, ',', ' ') ?></div>
  </div>
  <div class="summary-item">
    <div class="lbl">💵 Commissions</div>
    <div class="val gold"><?= number_format($stats['commissions'], 0, ',', ' ') ?></div>
  </div>
  <div class="summary-item">
    <div class="lbl">📊 Transactions</div>
    <div class="val"><?= $stats['nb_transactions'] ?></div>
  </div>
</div>

<div class="section">
  <div class="section-title">📊 Indicateurs clés <?= $annee_rapport ?></div>
  <table>
    <tbody>
      <tr>
        <td><strong>Capital total investi (nouveaux placements)</strong></td>
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
    </tbody>
  </table>
</div>

<div class="section">
  <div class="section-title">📅 Évolution mensuelle</div>
  <table>
    <thead>
      <tr>
        <th>Mois</th>
        <th class="amount">Nb transactions</th>
        <th class="amount">Dépôts</th>
        <th class="amount">Retraits</th>
        <th class="amount">Virements</th>
        <th class="amount">Solde net</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($detail_mois)): ?>
        <tr><td colspan="6" style="text-align:center;padding:30px;color:#999;font-style:italic">
          Aucune transaction pour cette année.
        </td></tr>
      <?php else: foreach ($detail_mois as $m): 
        $net = (float)$m['depots'] - (float)$m['retraits'];
      ?>
        <tr>
          <td><strong><?= date('F Y', strtotime($m['mois'] . '-01')) ?></strong></td>
          <td class="amount"><?= (int)$m['nb'] ?></td>
          <td class="amount green">+<?= number_format((float)$m['depots'], 0, ',', ' ') ?></td>
          <td class="amount red">−<?= number_format((float)$m['retraits'], 0, ',', ' ') ?></td>
          <td class="amount"><?= number_format((float)$m['virements'], 0, ',', ' ') ?></td>
          <td class="amount <?= $net >= 0 ? 'green' : 'red' ?>">
            <?= $net >= 0 ? '+' : '' ?><?= number_format($net, 0, ',', ' ') ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<?php if (!empty($top_clients)): ?>
<div class="section">
  <div class="section-title">🏆 Top 10 clients les plus actifs de l'année</div>
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
          <td><strong><?= htmlspecialchars($c['nom'] . ' ' . $c['postnom']) ?></strong><br>
              <small style="color:#888"><?= htmlspecialchars($c['prenom']) ?></small></td>
          <td><?= htmlspecialchars($c['numero_compte']) ?></td>
          <td class="amount"><?= (int)$c['nb_transactions'] ?></td>
          <td class="amount gold"><?= number_format((float)$c['total_volume'], 0, ',', ' ') ?> CDF</td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="footer">
  <strong>AFRICAN TRUST FUND</strong> — L'argent de l'Afrique pour construire l'Afrique<br>
  Rapport annuel <?= $annee_rapport ?> · Généré le <?= date('d/m/Y à H:i:s') ?><br>
  RCCM : CD/KIN/RCCM/24-B-01234 · NIF : A0123456Z · Agrément BCC n°045/2024<br>
  Avenue de la Paix n°45, Kinshasa-Gombe, RDC · +243 123 456 789 · contact@africantrustfund.cd
</div>

</body>
</html>