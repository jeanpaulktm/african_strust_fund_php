<?php
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/parametres.php';
requireRole('admin');

$pdo = Database::getConnection();

$taux_actuel = getTauxInteretDefaut($pdo);
$commission = getCommissionRetrait($pdo);

/* ============================================================
   PRODUITS AVEC STATISTIQUES
   ============================================================ */
$produits = $pdo->query("
    SELECT 
        pr.*,
        COUNT(p.id_placement) AS nb_investissements,
        COALESCE(SUM(p.montant_initial), 0) AS total_investi,
        COALESCE(SUM(p.interets_cumules), 0) AS total_interets,
        COUNT(DISTINCT p.id_client) AS nb_clients
    FROM Produits_Epargne pr
    LEFT JOIN Placements p ON p.id_produit = pr.id_produit
    GROUP BY pr.id_produit
    ORDER BY total_investi DESC
")->fetchAll();

$total_global = 0;
$total_interets_global = 0;
$total_clients_global = 0;

foreach ($produits as $p) {
    $total_global += (float)$p['total_investi'];
    $total_interets_global += (float)$p['total_interets'];
    $total_clients_global += (int)$p['nb_clients'];
}

/* ============================================================
   DÉTAIL DES INVESTISSEMENTS PAR PRODUIT
   ============================================================ */
$investissements_par_produit = [];
foreach ($produits as $prod) {
    $stmt = $pdo->prepare("
        SELECT 
            p.*,
            c.nom, c.postnom, c.prenom, c.numero_compte, c.numero_phone,
            DATEDIFF(CURDATE(), p.date_debut) AS jours_ecoules
        FROM Placements p
        JOIN Client c ON c.id_client = p.id_client
        WHERE p.id_produit = ?
        ORDER BY p.created_at DESC
        LIMIT 50
    ");
    $stmt->execute([(int)$prod['id_produit']]);
    $investissements_par_produit[(int)$prod['id_produit']] = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<title>Rapport Produits Investis — ATF</title>
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
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding-bottom: 15px;
  border-bottom: 3px solid #F5B800;
  margin-bottom: 20px;
}

.header h1 { color: #0D1B5E; font-size: 22px; letter-spacing: 2px; }
.header .subtitle { color: #666; font-size: 12px; font-style: italic; margin-top: 4px; }
.header .ref { text-align: right; font-size: 10px; color: #444; line-height: 1.6; }
.header .ref strong { color: #0D1B5E; font-size: 13px; display: block; margin-bottom: 4px; }

.summary-box {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12px;
  margin-bottom: 24px;
  padding: 16px;
  background: #F7FAFC;
  border-radius: 10px;
  border-left: 4px solid #F5B800;
}

.summary-item .lbl { font-size: 9px; color: #666; text-transform: uppercase; letter-spacing: 1px; }
.summary-item .val { font-size: 18px; font-weight: 800; color: #0D1B5E; margin-top: 4px; }
.summary-item .val.gold { color: #B8860B; }
.summary-item .val.green { color: #059669; }

.section {
  margin-bottom: 28px;
  page-break-inside: avoid;
}

.section-title {
  color: #0D1B5E;
  font-size: 14px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 1.5px;
  padding-bottom: 8px;
  border-bottom: 2px solid #F5B800;
  margin-bottom: 14px;
}

table {
  width: 100%;
  border-collapse: collapse;
  font-size: 10px;
  margin-bottom: 20px;
}

thead {
  background: #0D1B5E;
  color: #fff;
}

th {
  padding: 9px 8px;
  text-align: left;
  font-size: 9px;
  text-transform: uppercase;
  letter-spacing: .5px;
}

td {
  padding: 8px;
  border-bottom: 1px solid #e5e5e5;
}

tbody tr:nth-child(even) { background: #fafafa; }

.amount { text-align: right; font-weight: 700; }
.amount.gold { color: #B8860B; }
.amount.green { color: #059669; }
.amount.red { color: #DC2626; }

.badge {
  display: inline-block;
  padding: 3px 8px;
  border-radius: 999px;
  font-size: 8px;
  font-weight: 700;
  text-transform: uppercase;
}
.badge.green { background: #D1FAE5; color: #059669; }
.badge.red { background: #FEE2E2; color: #DC2626; }
.badge.gold { background: #FEF3C7; color: #B8860B; }

.footer {
  margin-top: 40px;
  padding-top: 15px;
  border-top: 3px solid #F5B800;
  text-align: center;
  font-size: 9px;
  color: #666;
  line-height: 1.6;
}

.footer strong { color: #0D1B5E; }

.btn-print {
  position: fixed;
  top: 20px;
  right: 20px;
  padding: 12px 24px;
  background: #F5B800;
  color: #0B0E14;
  border: none;
  border-radius: 999px;
  font-weight: 800;
  cursor: pointer;
  font-size: 14px;
  box-shadow: 0 8px 20px rgba(245,184,0,.4);
}

.btn-back {
  position: fixed;
  top: 20px;
  left: 20px;
  padding: 12px 20px;
  background: #0D1B5E;
  color: #F5B800;
  text-decoration: none;
  border: 2px solid #F5B800;
  border-radius: 999px;
  font-weight: 700;
  font-size: 13px;
}

@media print {
  body { padding: 0; }
  .btn-print, .btn-back { display: none; }
  .section { page-break-inside: avoid; }
}
</style>
</head>
<body>

<button class="btn-print" onclick="window.print()">🖨️ Imprimer / PDF</button>
<a class="btn-back" href="<?= '/african-trust-fund/admin/parametres.php' ?>">← Retour</a>

<div class="header">
  <div>
    <h1>AFRICAN TRUST FUND</h1>
    <div class="subtitle">Rapport des Produits d'Épargne Investis</div>
  </div>
  <div class="ref">
    <strong>RAPPORT PRODUITS</strong>
    Édité le : <?= date('d/m/Y à H:i') ?><br>
    Taux mensuel : <?= number_format($taux_actuel, 2) ?>%<br>
    Commission : <?= number_format($commission, 2) ?>%
  </div>
</div>

<div class="summary-box">
  <div class="summary-item">
    <div class="lbl">📦 Produits</div>
    <div class="val"><?= count($produits) ?></div>
  </div>
  <div class="summary-item">
    <div class="lbl">💰 Capital total investi</div>
    <div class="val gold"><?= number_format($total_global, 0, ',', ' ') ?></div>
  </div>
  <div class="summary-item">
    <div class="lbl">📈 Intérêts générés</div>
    <div class="val green"><?= number_format($total_interets_global, 0, ',', ' ') ?></div>
  </div>
  <div class="summary-item">
    <div class="lbl">👥 Clients investisseurs</div>
    <div class="val"><?= $total_clients_global ?></div>
  </div>
</div>

<!-- ============================================================
     SECTION 1 : VUE GLOBALE DES PRODUITS
     ============================================================ -->
<div class="section">
  <div class="section-title">📦 Vue globale des produits</div>

  <table>
    <thead>
      <tr>
        <th>Produit</th>
        <th>Devise</th>
        <th>Taux mensuel</th>
        <th class="amount">Investissements</th>
        <th class="amount">Clients</th>
        <th class="amount">Capital investi</th>
        <th class="amount">Intérêts générés</th>
        <th>Statut</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($produits as $p): ?>
        <tr>
          <td><strong><?= htmlspecialchars($p['nom']) ?></strong></td>
          <td><?= htmlspecialchars($p['devise']) ?></td>
          <td><strong style="color:#B8860B"><?= number_format((float)$p['taux_annuel'] * 100, 2) ?>%</strong></td>
          <td class="amount"><?= (int)$p['nb_investissements'] ?></td>
          <td class="amount"><?= (int)$p['nb_clients'] ?></td>
          <td class="amount gold"><?= number_format((float)$p['total_investi'], 0, ',', ' ') ?></td>
          <td class="amount green">+<?= number_format((float)$p['total_interets'], 0, ',', ' ') ?></td>
          <td>
            <span class="badge <?= $p['actif'] ? 'green' : 'red' ?>">
              <?= $p['actif'] ? 'Actif' : 'Inactif' ?>
            </span>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- ============================================================
     SECTION 2 : DÉTAIL DES INVESTISSEMENTS PAR PRODUIT
     ============================================================ -->
<?php foreach ($produits as $prod): 
  $invs = $investissements_par_produit[(int)$prod['id_produit']] ?? [];
  if (empty($invs)) continue;
?>
  <div class="section">
    <div class="section-title">
      💰 Investissements — <?= htmlspecialchars($prod['nom']) ?> 
      (<?= count($invs) ?>)
    </div>

    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Client</th>
          <th>Compte</th>
          <th>Date début</th>
          <th class="amount">Capital</th>
          <th class="amount">Intérêts cumulés</th>
          <th>Statut</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($invs as $i => $inv): ?>
          <tr>
            <td><?= $i + 1 ?></td>
            <td>
              <strong><?= htmlspecialchars($inv['nom'] . ' ' . $inv['postnom']) ?></strong><br>
              <small style="color:#888"><?= htmlspecialchars($inv['prenom']) ?></small>
            </td>
            <td><?= htmlspecialchars($inv['numero_compte']) ?></td>
            <td><?= date('d/m/Y', strtotime($inv['date_debut'])) ?></td>
            <td class="amount gold"><?= number_format((float)$inv['montant_initial'], 0, ',', ' ') ?> <?= $inv['devise'] ?></td>
            <td class="amount green">+<?= number_format((float)$inv['interets_cumules'], 0, ',', ' ') ?></td>
            <td>
              <span class="badge <?= $inv['statut'] === 'actif' ? 'green' : 'gold' ?>">
                <?= htmlspecialchars($inv['statut']) ?>
              </span>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endforeach; ?>

<div class="footer">
  <strong>AFRICAN TRUST FUND</strong> — L'argent de l'Afrique pour construire l'Afrique<br>
  Document généré le <?= date('d/m/Y à H:i:s') ?> · RCCM : CD/KIN/RCCM/24-B-01234<br>
  Avenue de la Paix n°45, Kinshasa-Gombe, RDC · +243 123 456 789 · contact@africantrustfund.cd
</div>

<script>
if (new URLSearchParams(location.search).has('auto')) {
  window.addEventListener('load', () => setTimeout(() => window.print(), 700));
}
</script>

</body>
</html>