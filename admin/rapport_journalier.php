<?php
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/parametres.php';
requireRole('admin');

$pdo = Database::getConnection();

/* Date du rapport (par défaut : aujourd'hui) */
$date_rapport = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_rapport)) {
    $date_rapport = date('Y-m-d');
}

/* ============================================================
   STATISTIQUES DU JOUR
   ============================================================ */
$stats = [
    'depots' => 0,
    'retraits' => 0,
    'virements' => 0,
    'nb_transactions' => 0,
    'commissions' => 0,
];

/* Transactions du jour */
$stmt = $pdo->prepare("
    SELECT t.*, c.nom, c.postnom, c.prenom, c.numero_compte, c.numero_phone
    FROM Transactions t
    JOIN Client c ON c.id_client = t.id_client
    WHERE DATE(t.date_transaction) = ?
    ORDER BY t.date_transaction ASC
");
$stmt->execute([$date_rapport]);
$transactions = $stmt->fetchAll();

foreach ($transactions as $t) {
    $montant = (float)$t['montant'];
    $stats['nb_transactions']++;
    if ($t['type_transaction'] === 'Depot') $stats['depots'] += $montant;
    if ($t['type_transaction'] === 'Retrait') $stats['retraits'] += $montant;
    if ($t['type_transaction'] === 'Virement') $stats['virements'] += $montant;
}

/* Commissions du jour */
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(montant_commission), 0)
    FROM Commissions
    WHERE DATE(date_commission) = ?
");
$stmt->execute([$date_rapport]);
$stats['commissions'] = (float)$stmt->fetchColumn();

/* Placements créés */
$stmt = $pdo->prepare("
    SELECT COUNT(*), COALESCE(SUM(montant_initial), 0)
    FROM Placements
    WHERE DATE(created_at) = ?
");
$stmt->execute([$date_rapport]);
$placements_jour = $stmt->fetch(PDO::FETCH_NUM);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Rapport Journalier — ATF</title>
<style>
@page { size: A4; margin: 12mm; }
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: Arial, sans-serif;
  background: #fff;
  color: #1a1a1a;
  padding: 20px;
  font-size: 11px;
}
.header {
  display: flex; justify-content: space-between; align-items: center;
  padding-bottom: 15px; border-bottom: 3px solid #F5B800; margin-bottom: 20px;
}
.header h1 { color: #0D1B5E; font-size: 22px; letter-spacing: 2px; }
.header .subtitle { color: #666; font-size: 12px; font-style: italic; margin-top: 4px; }
.header .ref { text-align: right; font-size: 10px; color: #444; line-height: 1.6; }
.header .ref strong { color: #0D1B5E; font-size: 13px; display: block; margin-bottom: 4px; }

.summary-box {
  display: grid; grid-template-columns: repeat(5, 1fr);
  gap: 12px; margin-bottom: 24px; padding: 16px;
  background: #F7FAFC; border-radius: 10px; border-left: 4px solid #F5B800;
}
.summary-item .lbl { font-size: 9px; color: #666; text-transform: uppercase; letter-spacing: 1px; }
.summary-item .val { font-size: 16px; font-weight: 800; color: #0D1B5E; margin-top: 4px; }
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
.date-picker {
  padding: 10px 16px; border-radius: 8px;
  border: 2px solid #F5B800; font-weight: 700;
  margin-left: 20px;
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
    <div class="subtitle">Rapport Journalier des Opérations</div>
  </div>
  <div class="ref">
    <strong>DATE : <?= date('d/m/Y', strtotime($date_rapport)) ?></strong>
    Édité le : <?= date('d/m/Y à H:i') ?><br>
    <input type="date" class="date-picker" value="<?= htmlspecialchars($date_rapport) ?>"
           onchange="location.href='?date=' + this.value">
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
    <div class="lbl">⇄ Virements</div>
    <div class="val"><?= number_format($stats['virements'], 0, ',', ' ') ?></div>
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
  <div class="section-title">📋 Détail des opérations (<?= count($transactions) ?>)</div>

  <table>
    <thead>
      <tr>
        <th>Heure</th>
        <th>Référence</th>
        <th>Client</th>
        <th>Compte</th>
        <th>Type</th>
        <th class="amount">Montant</th>
        <th class="amount">Solde après</th>
        <th>Mode</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($transactions)): ?>
        <tr>
          <td colspan="8" style="text-align:center; padding:30px; color:#999; font-style:italic">
            Aucune opération pour cette date.
          </td>
        </tr>
      <?php else: foreach ($transactions as $t): 
        $is_retrait = $t['type_transaction'] === 'Retrait';
      ?>
        <tr>
          <td><?= date('H:i:s', strtotime($t['date_transaction'])) ?></td>
          <td><strong><?= htmlspecialchars($t['reference']) ?></strong></td>
          <td><strong><?= htmlspecialchars($t['nom'] . ' ' . $t['postnom']) ?></strong><br>
              <small style="color:#888"><?= htmlspecialchars($t['prenom']) ?></small></td>
          <td><?= htmlspecialchars($t['numero_compte']) ?></td>
          <td><?= htmlspecialchars($t['type_transaction']) ?></td>
          <td class="amount <?= $is_retrait ? 'red' : 'green' ?>">
            <?= $is_retrait ? '−' : '+' ?>
            <?= number_format((float)$t['montant'], 2, ',', ' ') ?> <?= $t['devise'] ?? 'CDF' ?>
          </td>
          <td class="amount"><?= number_format((float)$t['solde_apres'], 2, ',', ' ') ?></td>
          <td><?= htmlspecialchars($t['mode']) ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
  </table>
</div>

<?php if ((int)$placements_jour[0] > 0): ?>
<div class="section">
  <div class="section-title">💰 Investissements du jour</div>
  <table>
    <thead>
      <tr>
        <th>Nombre de placements</th>
        <th class="amount">Capital total investi</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td><?= (int)$placements_jour[0] ?></td>
        <td class="amount gold"><?= number_format((float)$placements_jour[1], 0, ',', ' ') ?> CDF</td>
      </tr>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="footer">
  <strong>AFRICAN TRUST FUND</strong> — L'argent de l'Afrique pour construire l'Afrique<br>
  Document généré le <?= date('d/m/Y à H:i:s') ?> · RCCM : CD/KIN/RCCM/24-B-01234<br>
  Avenue de la Paix n°45, Kinshasa-Gombe, RDC · +243 123 456 789 · contact@africantrustfund.cd
</div>

</body>
</html>