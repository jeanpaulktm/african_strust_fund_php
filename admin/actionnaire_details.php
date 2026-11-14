<?php
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/actionnaires.php';
requireRole('admin');

$pdo = Database::getConnection();
$admin_id = (int)$_SESSION['admin_id'];

/* ============================================================
   RÉCUPÉRATION SÉCURISÉE
   ============================================================ */
$id_actionnaire = null;

if (!empty($_GET['token'])) {
    $data = consommerToken('actionnaire', $_GET['token']);
    if ($data) $id_actionnaire = (int)$data['id'];
}

if (!$id_actionnaire) {
    refuserAcces("Session expirée. Retournez à la liste.");
}

$stmt = $pdo->prepare("SELECT * FROM Actionnaires WHERE id_actionnaire = ?");
$stmt->execute([$id_actionnaire]);
$actionnaire = $stmt->fetch();

if (!$actionnaire) {
    refuserAcces("Actionnaire introuvable.");
}

/* Parts souscrites */
$stmt = $pdo->prepare("
    SELECT * FROM Parts_Sociales 
    WHERE id_actionnaire = ? 
    ORDER BY date_souscription DESC
");
$stmt->execute([$id_actionnaire]);
$parts = $stmt->fetchAll();

/* Dividendes */
$stmt = $pdo->prepare("
    SELECT * FROM Dividendes 
    WHERE id_actionnaire = ? 
    ORDER BY annee_fiscale DESC
");
$stmt->execute([$id_actionnaire]);
$dividendes = $stmt->fetchAll();

/* Paiements */
$stmt = $pdo->prepare("
    SELECT p.* FROM Paiements_Dividendes p
    WHERE p.id_actionnaire = ?
    ORDER BY p.date_paiement DESC
");
$stmt->execute([$id_actionnaire]);
$paiements = $stmt->fetchAll();

$pourcentage = getPourcentageDetention($pdo, (int)$actionnaire['nombre_parts']);

logAction($pdo, 'admin', $admin_id, 'ACTIONNAIRE_VIEW',
    "Consultation actionnaire #$id_actionnaire");

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Fiche actionnaire — ATF Admin</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/admin-premium.css">
<style>
.actionnaire-header {
  display: grid;
  grid-template-columns: auto 1fr auto;
  gap: 24px;
  align-items: center;
  padding: 28px;
  border-radius: 20px;
  background: linear-gradient(135deg, rgba(13,27,94,.6), rgba(30,41,59,.4));
  border: 1px solid var(--border-strong);
  margin-bottom: 28px;
  position: relative;
  overflow: hidden;
}
.photo-hero {
  width: 120px;
  height: 120px;
  border-radius: 50%;
  border: 4px solid var(--gold);
  padding: 4px;
  background: var(--bg-1);
  box-shadow: 0 0 40px rgba(245,184,0,.4);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 2.5rem;
  font-weight: 900;
  color: var(--gold);
}
.info-hero h1 { font-size: 1.6rem; font-weight: 800; margin-bottom: 6px; }
.info-hero .numero { color: var(--gold); font-family: monospace; font-size: 1rem; letter-spacing: 1px; }
.percent-box {
  text-align: right;
  z-index: 1;
}
.percent-box .pct {
  font-size: 2.8rem;
  font-weight: 900;
  color: var(--gold);
  text-shadow: 0 0 30px rgba(245,184,0,.5);
  line-height: 1;
}
.percent-box .lbl { color: var(--muted); font-size: .78rem; text-transform: uppercase; letter-spacing: 1px; margin-top: 4px; }
</style>
</head>
<body class="dark-auth">

<div class="top-progress" id="topProgress"></div>
<?php require __DIR__ . '/../includes/header.php'; ?>

<div class="admin-layout">
  <aside class="admin-sidebar animate-left">
    <h3>📊 Navigation</h3>
    <a href="<?= $base ?>/admin/dashboard.php"><span class="ico">📊</span> Tableau de bord</a>
    <a href="<?= $base ?>/admin/clients.php"><span class="ico">👥</span> Clients</a>
    <a href="<?= $base ?>/admin/caissiers.php"><span class="ico">💼</span> Caissiers</a>
    <a href="<?= $base ?>/admin/actionnaires.php" class="active"><span class="ico">🏛️</span> Actionnaires</a>
    <a href="<?= $base ?>/admin/parts_sociales.php"><span class="ico">📜</span> Parts sociales</a>
    <a href="<?= $base ?>/admin/dividendes.php"><span class="ico">💰</span> Dividendes</a>
    <a href="<?= $base ?>/admin/logs.php"><span class="ico">📜</span> Journal</a>
    <a href="<?= $base ?>/auth/logout.php"><span class="ico">🚪</span> Déconnexion</a>
  </aside>

  <main class="admin-main">

    <!-- EN-TÊTE -->
    <div class="actionnaire-header">
      <div class="photo-hero">
        <?= strtoupper(mb_substr($actionnaire['nom'], 0, 1) . mb_substr($actionnaire['prenom'], 0, 1)) ?>
      </div>
      <div class="info-hero">
        <h1><?= htmlspecialchars($actionnaire['nom'] . ' ' . $actionnaire['postnom'] . ' ' . $actionnaire['prenom']) ?></h1>
        <div class="numero"><?= htmlspecialchars($actionnaire['numero_actionnaire']) ?></div>
        <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
          <span class="badge <?= $actionnaire['statut'] === 'actif' ? 'green' : 'red' ?>">● <?= htmlspecialchars($actionnaire['statut']) ?></span>
          <span class="badge gold"><?= htmlspecialchars($actionnaire['type_actionnaire']) ?></span>
          <span class="badge blue">Adhésion : <?= date('d/m/Y', strtotime($actionnaire['date_adhesion'])) ?></span>
        </div>
      </div>
      <div class="percent-box">
        <div class="pct"><?= number_format($pourcentage, 2) ?>%</div>
        <div class="lbl">Détention</div>
      </div>
    </div>

    <!-- KPIs -->
    <section class="kpi-grid">
      <div class="stat-block gold animate-in delay-1">
        <div class="ico">📜</div>
        <div class="meta">
          <strong><?= number_format((int)$actionnaire['nombre_parts'], 0, ',', ' ') ?></strong>
          <span>Parts détenues</span>
        </div>
      </div>
      <div class="stat-block green animate-in delay-2">
        <div class="ico">💰</div>
        <div class="meta">
          <strong><?= number_format((float)$actionnaire['capital_investi_cdf'], 0, ',', ' ') ?></strong>
          <span>Capital investi (CDF)</span>
        </div>
      </div>
      <div class="stat-block blue animate-in delay-3">
        <div class="ico">📈</div>
        <div class="meta">
          <strong><?= number_format((float)$actionnaire['dividendes_recus_cdf'], 0, ',', ' ') ?></strong>
          <span>Dividendes reçus (CDF)</span>
        </div>
      </div>
      <div class="stat-block gold animate-in delay-4">
        <div class="ico">🎯</div>
        <div class="meta">
          <strong><?= count($parts) ?></strong>
          <span>Mouvements</span>
        </div>
      </div>
    </section>

    <!-- INFO PERSONNELLES -->
    <section class="card animate-in delay-1">
      <h3 style="margin-bottom:16px">👤 Informations personnelles</h3>
      <div class="grid-2">
        <div class="field"><span>Nom complet</span><input type="text" value="<?= htmlspecialchars($actionnaire['nom'] . ' ' . $actionnaire['postnom'] . ' ' . $actionnaire['prenom']) ?>" disabled></div>
        <div class="field"><span>Téléphone</span><input type="text" value="<?= htmlspecialchars($actionnaire['numero_phone']) ?>" disabled></div>
        <div class="field"><span>Email</span><input type="text" value="<?= htmlspecialchars($actionnaire['email'] ?? '—') ?>" disabled></div>
        <div class="field"><span>Adresse</span><input type="text" value="<?= htmlspecialchars($actionnaire['adresse_complete'] ?? '—') ?>" disabled></div>
        <div class="field"><span>Dernière connexion</span><input type="text" value="<?= $actionnaire['last_login'] ? date('d/m/Y H:i', strtotime($actionnaire['last_login'])) : 'Jamais' ?>" disabled></div>
        <div class="field"><span>Créé le</span><input type="text" value="<?= date('d/m/Y à H:i', strtotime($actionnaire['created_at'])) ?>" disabled></div>
      </div>
    </section>

    <!-- HISTORIQUE DES PARTS -->
    <section class="card animate-in delay-2">
      <h3 style="margin-bottom:16px">📜 Historique des souscriptions</h3>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Date</th>
              <th>Référence</th>
              <th style="text-align:right">Parts</th>
              <th style="text-align:right">Prix unitaire</th>
              <th style="text-align:right">Total</th>
              <th>Statut</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$parts): ?>
              <tr><td colspan="6" class="muted center" style="padding:20px">Aucune souscription.</td></tr>
            <?php else: foreach ($parts as $p): ?>
              <tr>
                <td><?= date('d/m/Y H:i', strtotime($p['date_souscription'])) ?></td>
                <td><code><?= htmlspecialchars($p['reference']) ?></code></td>
                <td style="text-align:right;font-weight:700"><?= number_format((int)$p['nombre_parts'], 0, ',', ' ') ?></td>
                <td style="text-align:right"><?= number_format((float)$p['prix_unitaire'], 0, ',', ' ') ?></td>
                <td style="text-align:right;color:var(--gold);font-weight:700"><?= number_format((float)$p['montant_total'], 0, ',', ' ') ?> <?= $p['devise'] ?></td>
                <td><span class="badge <?= $p['statut'] === 'valide' ? 'green' : 'gold' ?>"><?= htmlspecialchars($p['statut']) ?></span></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- DIVIDENDES -->
    <section class="card animate-in delay-3">
      <h3 style="margin-bottom:16px">💰 Historique des dividendes</h3>
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Année</th>
              <th>Référence</th>
              <th style="text-align:right">Parts</th>
              <th style="text-align:right">Montant</th>
              <th>Statut</th>
              <th>Payé le</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$dividendes): ?>
              <tr><td colspan="6" class="muted center" style="padding:20px">Aucun dividende calculé.</td></tr>
            <?php else: foreach ($dividendes as $d): ?>
              <tr>
                <td><strong><?= (int)$d['annee_fiscale'] ?></strong></td>
                <td><code><?= htmlspecialchars($d['reference']) ?></code></td>
                <td style="text-align:right"><?= number_format((int)$d['nombre_parts'], 0, ',', ' ') ?></td>
                <td style="text-align:right;color:var(--green);font-weight:700">+ <?= number_format((float)$d['montant_dividende'], 2, ',', ' ') ?> <?= $d['devise'] ?></td>
                <td><span class="badge <?= $d['statut'] === 'paye' ? 'green' : ($d['statut'] === 'calcule' ? 'gold' : 'blue') ?>"><?= htmlspecialchars($d['statut']) ?></span></td>
                <td><?= $d['date_paiement'] ? date('d/m/Y', strtotime($d['date_paiement'])) : '—' ?></td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <div style="text-align:center;margin-top:20px">
      <a class="btn-ghost" href="<?= $base ?>/admin/actionnaires.php">← Retour à la liste</a>
    </div>

  </main>
</div>

<footer class="atf-footer">© <?= date('Y') ?> AFRICAN TRUST FUND</footer>

<script>
window.addEventListener('scroll', () => {
  const h = document.documentElement.scrollHeight - window.innerHeight;
  document.getElementById('topProgress').style.width = (window.scrollY / h) * 100 + '%';
});
</script>
<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>