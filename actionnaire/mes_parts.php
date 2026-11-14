<?php
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/actionnaires.php';
requireActionnaire();

$pdo = Database::getConnection();
$id  = (int)$_SESSION['actionnaire_id'];

$stmt = $pdo->prepare("SELECT * FROM Actionnaires WHERE id_actionnaire = ?");
$stmt->execute([$id]);
$actionnaire = $stmt->fetch();

$pourcentage = getPourcentageDetention($pdo, (int)$actionnaire['nombre_parts']);
$capital = getCapitalCourant($pdo);

/* Historique complet */
$stmt = $pdo->prepare("
    SELECT * FROM Parts_Sociales 
    WHERE id_actionnaire = ? 
    ORDER BY date_souscription DESC
");
$stmt->execute([$id]);
$parts = $stmt->fetchAll();

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mes parts — ATF Actionnaire</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
</head>
<body class="dark-auth">

<?php require __DIR__ . '/../includes/header.php'; ?>

<main class="dash">

  <div class="welcome-head">
    <div>
      <h2>📜 Mes parts sociales</h2>
      <p class="sub">Détail complet de votre portefeuille</p>
    </div>
    <a class="btn-ghost" href="<?= $base ?>/actionnaire/dashboard.php">← Tableau de bord</a>
  </div>

  <section class="kpi-grid">
    <div class="kpi">
      <div class="label">Parts détenues</div>
      <div class="value gold"><?= number_format((int)$actionnaire['nombre_parts'], 0, ',', ' ') ?></div>
    </div>
    <div class="kpi">
      <div class="label">Détention</div>
      <div class="value"><?= number_format($pourcentage, 4) ?>%</div>
    </div>
    <div class="kpi">
      <div class="label">Capital investi</div>
      <div class="value" style="color:var(--green)">
        <?= number_format((float)$actionnaire['capital_investi_cdf'], 0, ',', ' ') ?> CDF
      </div>
    </div>
    <div class="kpi">
      <div class="label">Valeur actuelle</div>
      <div class="value gold">
        <?= $capital ? number_format((float)$capital['prix_unitaire'] * (int)$actionnaire['nombre_parts'], 0, ',', ' ') : '—' ?> CDF
      </div>
    </div>
  </section>

  <section class="card">
    <h3 style="margin-bottom:16px">📋 Historique des mouvements (<?= count($parts) ?>)</h3>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Date</th>
            <th>Référence</th>
            <th style="text-align:right">Parts</th>
            <th style="text-align:right">Prix unitaire</th>
            <th style="text-align:right">Montant total</th>
            <th>Type</th>
            <th>Statut</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$parts): ?>
            <tr><td colspan="7" class="muted center" style="padding:30px">Aucun mouvement.</td></tr>
          <?php else: foreach ($parts as $p): ?>
            <tr>
              <td><?= date('d/m/Y H:i', strtotime($p['date_souscription'])) ?></td>
              <td><code><?= htmlspecialchars($p['reference']) ?></code></td>
              <td style="text-align:right;font-weight:700"><?= number_format((int)$p['nombre_parts'], 0, ',', ' ') ?></td>
              <td style="text-align:right"><?= number_format((float)$p['prix_unitaire'], 0, ',', ' ') ?> <?= $p['devise'] ?></td>
              <td style="text-align:right;color:var(--gold);font-weight:700">
                <?= number_format((float)$p['montant_total'], 0, ',', ' ') ?> <?= $p['devise'] ?>
              </td>
              <td><span class="badge blue"><?= htmlspecialchars($p['type_mouvement']) ?></span></td>
              <td>
                <span class="badge <?= $p['statut']==='valide' ? 'green' : ($p['statut']==='en_attente' ? 'gold' : 'red') ?>">
                  <?= htmlspecialchars($p['statut']) ?>
                </span>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <section class="card" style="text-align:center">
    <h3 style="margin-bottom:16px">📄 Certificat de parts</h3>
    <p class="muted" style="margin-bottom:16px">
      Téléchargez votre certificat officiel avec toutes vos informations d'actionnaire.
    </p>
    <a class="btn-gold" 
       href="<?= $base ?>/actionnaire/certificat.php?token=<?= htmlspecialchars(genererToken('actionnaire', $id, ['action' => 'certificat'])) ?>"
       target="_blank">
      📥 Télécharger mon certificat PDF
    </a>
  </section>

</main>

<footer class="atf-footer">© <?= date('Y') ?> AFRICAN TRUST FUND</footer>
<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>