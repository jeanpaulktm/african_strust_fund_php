<?php
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/actionnaires.php';
requireActionnaire();

$pdo = Database::getConnection();
$id  = (int)$_SESSION['actionnaire_id'];

/* Charger actionnaire */
$stmt = $pdo->prepare("SELECT * FROM Actionnaires WHERE id_actionnaire = ?");
$stmt->execute([$id]);
$actionnaire = $stmt->fetch();

$pourcentage = getPourcentageDetention($pdo, (int)$actionnaire['nombre_parts']);

/* Capital ATF courant */
$capital = getCapitalCourant($pdo);
$valeur_portefeuille = $capital 
    ? (float)$capital['prix_unitaire'] * (int)$actionnaire['nombre_parts']
    : 0;

/* Derniers dividendes */
$stmt = $pdo->prepare("
    SELECT * FROM Dividendes 
    WHERE id_actionnaire = ? 
    ORDER BY annee_fiscale DESC
    LIMIT 5
");
$stmt->execute([$id]);
$dividendes = $stmt->fetchAll();

/* Dernières souscriptions */
$stmt = $pdo->prepare("
    SELECT * FROM Parts_Sociales 
    WHERE id_actionnaire = ? 
    ORDER BY date_souscription DESC
    LIMIT 5
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
<title>Mon Portefeuille — ATF Actionnaire</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<style>
.hero-actionnaire {
  display: grid;
  grid-template-columns: auto 1fr auto;
  gap: 24px;
  align-items: center;
  padding: 32px;
  border-radius: 24px;
  background: linear-gradient(135deg, rgba(13,27,94,.7), rgba(30,41,59,.4)),
              radial-gradient(circle at 90% 20%, rgba(245,184,0,.2), transparent 60%);
  border: 1px solid var(--border-strong);
  margin-bottom: 26px;
  position: relative;
  overflow: hidden;
  animation: fadeInUp .6s ease-out both;
}
.hero-actionnaire::before {
  content: "";
  position: absolute;
  top: -30%; right: -5%;
  width: 300px; height: 300px;
  border-radius: 50%;
  background: radial-gradient(circle, rgba(245,184,0,.2), transparent 70%);
  animation: float 6s ease-in-out infinite;
}
.hero-photo {
  width: 100px;
  height: 100px;
  border-radius: 50%;
  border: 4px solid var(--gold);
  padding: 4px;
  background: var(--bg-1);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 2rem;
  font-weight: 900;
  color: var(--gold);
  box-shadow: 0 0 40px rgba(245,184,0,.4);
  position: relative;
  z-index: 1;
}
.hero-info { position: relative; z-index: 1; }
.hero-info h1 { font-size: 1.6rem; font-weight: 800; margin-bottom: 6px; }
.hero-info .numero { color: var(--gold); font-family: monospace; font-size: 1rem; }
.hero-percent {
  text-align: right;
  position: relative;
  z-index: 1;
}
.hero-percent .pct {
  font-size: 3rem;
  font-weight: 900;
  color: var(--gold);
  text-shadow: 0 0 40px rgba(245,184,0,.6);
  line-height: 1;
}
.hero-percent .lbl {
  color: var(--muted);
  font-size: .78rem;
  text-transform: uppercase;
  letter-spacing: 1.5px;
  margin-top: 6px;
}

.valeur-card {
  padding: 28px;
  border-radius: 20px;
  background: linear-gradient(135deg, rgba(16,185,129,.15), rgba(16,185,129,.03));
  border: 1px solid rgba(16,185,129,.35);
  text-align: center;
  margin-bottom: 24px;
  position: relative;
  overflow: hidden;
}
.valeur-card::before {
  content: "";
  position: absolute;
  top: -40%; right: -10%;
  width: 250px; height: 250px;
  background: radial-gradient(circle, rgba(16,185,129,.2), transparent 70%);
  border-radius: 50%;
}
.valeur-card .lbl {
  color: #6EE7B7;
  font-size: .82rem;
  text-transform: uppercase;
  letter-spacing: 2px;
  font-weight: 800;
  position: relative;
  z-index: 1;
}
.valeur-card .val {
  font-size: 2.6rem;
  font-weight: 900;
  color: #6EE7B7;
  margin-top: 12px;
  line-height: 1;
  position: relative;
  z-index: 1;
  text-shadow: 0 0 40px rgba(16,185,129,.4);
}
.valeur-card .sub {
  color: var(--muted);
  font-size: .82rem;
  margin-top: 10px;
  position: relative;
  z-index: 1;
}
</style>
</head>
<body class="dark-auth">

<?php require __DIR__ . '/../includes/header.php'; ?>

<main class="dash">

  <!-- HERO -->
  <div class="hero-actionnaire">
    <div class="hero-photo">
      <?= strtoupper(mb_substr($actionnaire['nom'], 0, 1) . mb_substr($actionnaire['prenom'], 0, 1)) ?>
    </div>
    <div class="hero-info">
      <h1><?= htmlspecialchars($actionnaire['nom'] . ' ' . $actionnaire['postnom'] . ' ' . $actionnaire['prenom']) ?></h1>
      <div class="numero"><?= htmlspecialchars($actionnaire['numero_actionnaire']) ?></div>
      <div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
        <span class="badge gold">🏛️ <?= htmlspecialchars($actionnaire['type_actionnaire']) ?></span>
        <span class="badge <?= $actionnaire['statut'] === 'actif' ? 'green' : 'red' ?>">● <?= htmlspecialchars($actionnaire['statut']) ?></span>
        <span class="badge blue">Adhésion : <?= date('d/m/Y', strtotime($actionnaire['date_adhesion'])) ?></span>
      </div>
    </div>
    <div class="hero-percent">
      <div class="pct"><?= number_format($pourcentage, 2) ?>%</div>
      <div class="lbl">Détention ATF</div>
    </div>
  </div>

  <!-- VALEUR PORTEFEUILLE -->
  <div class="valeur-card">
    <div class="lbl">💎 Valeur totale de votre portefeuille</div>
    <div class="val"><?= number_format($valeur_portefeuille, 0, ',', ' ') ?> CDF</div>
    <div class="sub">
      <?= number_format((int)$actionnaire['nombre_parts'], 0, ',', ' ') ?> parts × 
      <?= $capital ? number_format((float)$capital['prix_unitaire'], 0, ',', ' ') : '—' ?> CDF / part
    </div>
  </div>

  <!-- KPIs -->
  <section class="kpi-grid">
    <div class="stat-block gold">
      <div class="ico">📜</div>
      <div class="meta">
        <strong><?= number_format((int)$actionnaire['nombre_parts'], 0, ',', ' ') ?></strong>
        <span>Parts détenues</span>
      </div>
    </div>
    <div class="stat-block green">
      <div class="ico">💰</div>
      <div class="meta">
        <strong><?= number_format((float)$actionnaire['capital_investi_cdf'], 0, ',', ' ') ?></strong>
        <span>Capital investi (CDF)</span>
      </div>
    </div>
    <div class="stat-block blue">
      <div class="ico">📈</div>
      <div class="meta">
        <strong><?= number_format((float)$actionnaire['dividendes_recus_cdf'], 0, ',', ' ') ?></strong>
        <span>Dividendes reçus</span>
      </div>
    </div>
    <!-- Navigation Actionnaire -->
<div class="admin-tabs" style="margin-bottom:24px">
  <a href="<?= $base ?>/actionnaire/dashboard.php" class="admin-tab <?= basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'active' : '' ?>">
    📊 Tableau de bord
  </a>
  <a href="<?= $base ?>/actionnaire/mes_parts.php" class="admin-tab <?= basename($_SERVER['PHP_SELF']) === 'mes_parts.php' ? 'active' : '' ?>">
    📜 Mes parts
  </a>
  <a href="<?= $base ?>/actionnaire/souscrire.php" class="admin-tab <?= basename($_SERVER['PHP_SELF']) === 'souscrire.php' ? 'active' : '' ?>">
    ➕ Souscrire
  </a>
  <a href="<?= $base ?>/actionnaire/mes_dividendes.php" class="admin-tab">
    💰 Mes dividendes
  </a>
  <?php
$token_carte = genererToken('actionnaire', $id, ['action' => 'view_carte']);
?>
<a class="btn-gold" 
   href="<?= $base ?>/carte_membre.php?type=actionnaire&token=<?= htmlspecialchars($token_carte) ?>"
   target="_blank"
   style="display:inline-flex;align-items:center;gap:8px">
   💳 Ma carte membre
</a>
</div>
    <div class="stat-block gold">
      <div class="ico">🎯</div>
      <div class="meta">
        <strong><?= count($dividendes) ?></strong>
        <span>Années de dividendes</span>
      </div>
    </div>
  </section>

  <!-- DIVIDENDES RÉCENTS -->
  <section class="card">
    <h3 style="margin-bottom:16px">💰 Mes derniers dividendes</h3>
    <?php if (!$dividendes): ?>
      <p class="muted center" style="padding:20px">Aucun dividende pour le moment.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Année</th><th>Référence</th><th style="text-align:right">Parts</th><th style="text-align:right">Montant</th><th>Statut</th></tr>
          </thead>
          <tbody>
            <?php foreach ($dividendes as $d): ?>
              <tr>
                <td><strong><?= (int)$d['annee_fiscale'] ?></strong></td>
                <td><code><?= htmlspecialchars($d['reference']) ?></code></td>
                <td style="text-align:right"><?= number_format((int)$d['nombre_parts'], 0, ',', ' ') ?></td>
                <td style="text-align:right;color:var(--green);font-weight:700">+ <?= number_format((float)$d['montant_dividende'], 2, ',', ' ') ?> <?= $d['devise'] ?></td>
                <td><span class="badge <?= $d['statut'] === 'paye' ? 'green' : 'gold' ?>"><?= htmlspecialchars($d['statut']) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <!-- SOUSCRIPTIONS -->
  <section class="card">
    <h3 style="margin-bottom:16px">📜 Mes dernières souscriptions</h3>
    <?php if (!$parts): ?>
      <p class="muted center" style="padding:20px">Aucune souscription.</p>
    <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Date</th><th>Référence</th><th style="text-align:right">Parts</th><th style="text-align:right">Montant</th><th>Statut</th></tr>
          </thead>
          <tbody>
            <?php foreach ($parts as $p): ?>
              <tr>
                <td><?= date('d/m/Y', strtotime($p['date_souscription'])) ?></td>
                <td><code><?= htmlspecialchars($p['reference']) ?></code></td>
                <td style="text-align:right"><?= number_format((int)$p['nombre_parts'], 0, ',', ' ') ?></td>
                <td style="text-align:right;color:var(--gold);font-weight:700"><?= number_format((float)$p['montant_total'], 0, ',', ' ') ?> <?= $p['devise'] ?></td>
                <td><span class="badge <?= $p['statut'] === 'valide' ? 'green' : 'gold' ?>"><?= htmlspecialchars($p['statut']) ?></span></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </section>

  <!-- INFO CAPITAL -->
  <?php if ($capital): ?>
    <section class="card" style="background:linear-gradient(145deg,rgba(245,184,0,.06),transparent);border-color:rgba(245,184,0,.25)">
      <h3 style="margin-bottom:14px">📊 Capital ATF <?= $capital['annee_fiscale'] ?></h3>
      <div class="grid-2">
        <div class="field"><span>Capital social total</span><input type="text" value="<?= number_format((float)$capital['capital_social'], 0, ',', ' ') ?> <?= $capital['devise'] ?>" disabled></div>
        <div class="field"><span>Parts émises</span><input type="text" value="<?= number_format((int)$capital['nombre_parts_emises'], 0, ',', ' ') ?>" disabled></div>
        <div class="field"><span>Prix par part</span><input type="text" value="<?= number_format((float)$capital['prix_unitaire'], 0, ',', ' ') ?> <?= $capital['devise'] ?>" disabled></div>
        <div class="field"><span>Taux de distribution</span><input type="text" value="<?= number_format((float)$capital['taux_distribution'], 2) ?>%" disabled></div>
      </div>
    </section>
  <?php endif; ?>

</main>

<footer class="atf-footer">© <?= date('Y') ?> AFRICAN TRUST FUND — Espace Actionnaire</footer>

<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>