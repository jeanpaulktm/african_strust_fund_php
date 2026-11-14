<?php
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
requireRole('caissier');

$pdo = Database::getConnection();
$caissier_id = (int)$_SESSION['caissier_id'];

/* Filtres */
$type = in_array($_GET['type'] ?? '', ['Depot','Retrait'], true) ? $_GET['type'] : '';
$date = $_GET['date'] ?? '';

$sql = "SELECT t.*, c.nom, c.postnom, c.numero_compte
        FROM Transactions t
        JOIN Client c ON c.id_client = t.id_client
        WHERE t.id_caissier = ?";
$params = [$caissier_id];

if ($type !== '') {
    $sql .= " AND t.type_transaction = ?";
    $params[] = $type;
}
if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $sql .= " AND DATE(t.date_transaction) = ?";
    $params[] = $date;
}
$sql .= " ORDER BY t.date_transaction DESC LIMIT 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$ops = $stmt->fetchAll();

/* Totaux */
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) AS total,
        COALESCE(SUM(CASE WHEN type_transaction='Depot' THEN montant ELSE 0 END),0) AS depots,
        COALESCE(SUM(CASE WHEN type_transaction='Retrait' THEN montant ELSE 0 END),0) AS retraits
    FROM Transactions WHERE id_caissier = ?
");
$stmt->execute([$caissier_id]);
$stats = $stmt->fetch();

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mes opérations — ATF Caissier</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/admin-premium.css"></head>
<body class="dark-auth">
<?php require __DIR__ . '/../includes/header.php'; ?>

<div class="admin-layout">
  <aside class="admin-sidebar animate-left">
    <h3>💼 Guichet</h3>
    <a href="<?= $base ?>/caissier/dashboard.php"><span class="ico">🔍</span> Rechercher client</a>
    <a href="<?= $base ?>/caissier/historique.php" class="active"><span class="ico">📋</span> Mes opérations</a>
    <a href="<?= $base ?>/auth/logout.php"><span class="ico">🚪</span> Déconnexion</a>
  </aside>

  <main class="admin-main">
    <div class="welcome-head">
      <div>
        <h2>📋 Mes opérations</h2>
        <p class="sub"><?= count($ops) ?> opération(s) affichée(s)</p>
      </div>
      <a class="btn-ghost" href="<?= $base ?>/caissier/dashboard.php">← Retour</a>
    </div>

    <section class="kpi-grid">
      <div class="stat-block gold animate-in delay-1">
        <div class="ico">📊</div>
        <div class="meta"><strong><?= (int)$stats['total'] ?></strong><span>Total opérations</span></div>
      </div>
      <div class="stat-block green animate-in delay-2">
        <div class="ico">➕</div>
        <div class="meta"><strong><?= number_format((float)$stats['depots'], 0, ',', ' ') ?></strong><span>Total dépôts</span></div>
      </div>
      <div class="stat-block red animate-in delay-3">
        <div class="ico">➖</div>
        <div class="meta"><strong><?= number_format((float)$stats['retraits'], 0, ',', ' ') ?></strong><span>Total retraits</span></div>
      </div>
    </section>

    <section class="card animate-in delay-2" style="padding:18px">
      <form method="GET" style="display:flex;gap:14px;align-items:end;flex-wrap:wrap">
        <label class="field" style="flex:1;min-width:180px">
          <span>Type</span>
          <select name="type">
            <option value="">Tous</option>
            <option value="Depot" <?= $type === 'Depot' ? 'selected' : '' ?>>Dépôts</option>
            <option value="Retrait" <?= $type === 'Retrait' ? 'selected' : '' ?>>Retraits</option>
          </select>
        </label>
        <label class="field" style="flex:1;min-width:180px">
          <span>Date</span>
          <input type="date" name="date" value="<?= htmlspecialchars($date) ?>">
        </label>
        <button class="btn-gold" type="submit">Filtrer</button>
        <a class="btn-ghost" href="?">Réinitialiser</a>
      </form>
    </section>

    <section class="card animate-in delay-3">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Référence</th><th>Client</th><th>Type</th>
              <th style="text-align:right">Montant</th>
              <th style="text-align:right">Solde après</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$ops): ?>
              <tr><td colspan="6" class="muted center" style="padding:30px">Aucune opération.</td></tr>
            <?php else: foreach ($ops as $op): ?>
              <tr>
                <td><code><?= htmlspecialchars($op['reference']) ?></code></td>
                <td><?= htmlspecialchars($op['nom'] . ' ' . $op['postnom']) ?><br>
                    <small class="muted"><?= htmlspecialchars($op['numero_compte']) ?></small></td>
                <td><span class="tag <?= strtolower($op['type_transaction']) ?>"><?= htmlspecialchars($op['type_transaction']) ?></span></td>
                <td style="text-align:right;font-weight:700;color:<?= $op['type_transaction']==='Retrait' ? '#FCA5A5' : 'var(--green)' ?>">
                  <?= $op['type_transaction']==='Retrait' ? '−' : '+' ?>
                  <?= number_format((float)$op['montant'], 2, ',', ' ') ?>
                </td>
                <td style="text-align:right"><?= number_format((float)$op['solde_apres'], 2, ',', ' ') ?></td>
                <td><?= date('d/m/Y H:i', strtotime($op['date_transaction'])) ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>
</div>

<footer class="atf-footer">© <?= date('Y') ?> AFRICAN TRUST FUND</footer>

<script>
window.addEventListener('scroll', () => {
  const h = document.documentElement.scrollHeight - window.innerHeight;
  document.getElementById('topProgress') && (document.getElementById('topProgress').style.width = (window.scrollY / h) * 100 + '%');
});
</script>
<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>