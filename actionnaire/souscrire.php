<?php
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/actionnaires.php';
requireActionnaire();

$pdo = Database::getConnection();
$id  = (int)$_SESSION['actionnaire_id'];

$errors = [];
$success = '';

/* Souscription */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) throw new RuntimeException("Jeton invalide.");

        $nb_parts = (int)($_POST['nombre_parts'] ?? 0);
        $devise   = in_array($_POST['devise'] ?? '', ['CDF','USD']) ? $_POST['devise'] : 'CDF';

        if ($nb_parts < 1)    throw new RuntimeException("Minimum 1 part.");
        if ($nb_parts > 1000) throw new RuntimeException("Maximum 1000 parts par souscription.");

        $capital = getCapitalCourant($pdo);
        if (!$capital) throw new RuntimeException("Capital non configuré.");

        $disponibles = (int)$capital['nombre_parts_emises'] - (int)$capital['nombre_parts_vendues'];
        if ($nb_parts > $disponibles) {
            throw new RuntimeException("Seulement $disponibles parts disponibles.");
        }

        /* Créer une souscription EN ATTENTE de validation admin */
        $pdo->beginTransaction();

        $prix = (float)$capital['prix_unitaire'];
        $total = $prix * $nb_parts;
        $reference = 'PRT-' . strtoupper(bin2hex(random_bytes(6)));

        $pdo->prepare("
            INSERT INTO Parts_Sociales 
                (id_actionnaire, nombre_parts, prix_unitaire, montant_total, devise,
                 type_mouvement, statut, reference, description)
            VALUES (?, ?, ?, ?, ?, 'augmentation', 'en_attente', ?, ?)
        ")->execute([
            $id, $nb_parts, $prix, $total, $devise, $reference,
            "Nouvelle souscription en attente de validation"
        ]);

        $pdo->commit();

        $_SESSION['flash_success'] = "✅ Souscription enregistrée !<br>
            <strong>Référence :</strong> $reference<br>
            <strong>Parts :</strong> " . number_format($nb_parts, 0, ',', ' ') . "<br>
            <strong>Montant :</strong> " . number_format($total, 2, ',', ' ') . " $devise<br>
            <em style='color:var(--muted)'>En attente de validation par l'administration.</em>";

        header('Location: ' . url('actionnaire/souscrire.php'));
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = $e->getMessage();
    }
}

$success = '';
if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

$stmt = $pdo->prepare("SELECT * FROM Actionnaires WHERE id_actionnaire = ?");
$stmt->execute([$id]);
$actionnaire = $stmt->fetch();

$capital = getCapitalCourant($pdo);
$disponibles = $capital 
    ? (int)$capital['nombre_parts_emises'] - (int)$capital['nombre_parts_vendues']
    : 0;

/* Souscriptions en attente */
$stmt = $pdo->prepare("
    SELECT * FROM Parts_Sociales 
    WHERE id_actionnaire = ? AND statut = 'en_attente'
    ORDER BY date_souscription DESC
");
$stmt->execute([$id]);
$en_attente = $stmt->fetchAll();

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Souscrire des parts — ATF</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
</head>
<body class="dark-auth">

<?php require __DIR__ . '/../includes/header.php'; ?>

<div class="op-wrapper" style="max-width:640px">

  <h1>📜 Souscrire de nouvelles parts</h1>
  <p class="op-sub">Augmentez votre participation dans AFRICAN TRUST FUND</p>

  <?php if ($success): ?>
    <div class="alert success"><?= $success ?></div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="alert error">
      <ul><?php foreach ($errors as $e) echo "<li>" . htmlspecialchars($e) . "</li>"; ?></ul>
    </div>
  <?php endif; ?>

  <div class="info-banner">
    <strong>Votre situation actuelle :</strong><br>
    Parts détenues : <strong style="color:var(--gold)"><?= number_format((int)$actionnaire['nombre_parts'], 0, ',', ' ') ?></strong><br>
    Capital investi : <strong><?= number_format((float)$actionnaire['capital_investi_cdf'], 0, ',', ' ') ?> CDF</strong>
  </div>

  <?php if ($capital): ?>
    <div class="info-banner" style="background:rgba(16,185,129,.08);border-color:rgba(16,185,129,.25);color:#6EE7B7">
      💰 <strong>Prix par part :</strong> <?= number_format((float)$capital['prix_unitaire'], 0, ',', ' ') ?> <?= $capital['devise'] ?><br>
      📊 <strong>Parts disponibles :</strong> <?= number_format($disponibles, 0, ',', ' ') ?> sur <?= number_format((int)$capital['nombre_parts_emises'], 0, ',', ' ') ?>
    </div>
  <?php endif; ?>

  <form method="POST" novalidate autocomplete="off">
    <?= csrfField() ?>

    <label class="field">
      <span>Nombre de parts à souscrire *</span>
      <input type="number" name="nombre_parts" id="nbParts" required min="1" max="<?= $disponibles ?>" autofocus>
    </label>

    <div class="quick-amounts" style="margin-bottom:16px">
      <button type="button" onclick="setParts(10)">10</button>
      <button type="button" onclick="setParts(50)">50</button>
      <button type="button" onclick="setParts(100)">100</button>
      <button type="button" onclick="setParts(500)">500</button>
      <button type="button" onclick="setParts(1000)">1000</button>
    </div>

    <label class="field">
      <span>Devise *</span>
      <select name="devise" required>
        <option value="CDF" selected>🇨🇩 Franc Congolais (CDF)</option>
        <option value="USD">🇺🇸 Dollar Américain (USD)</option>
      </select>
    </label>

    <div id="simuBox" class="info-banner" style="display:none;background:rgba(245,184,0,.08);border-color:rgba(245,184,0,.3);color:var(--gold);margin-top:16px">
      <strong>💡 Montant total :</strong><br>
      <span id="simuText" style="font-size:1.2rem;font-weight:800">—</span>
    </div>

    <button class="btn-gold full" type="submit" style="margin-top:20px">
      📝 Soumettre ma souscription
    </button>

    <p class="center muted" style="margin-top:16px">
      <a href="<?= $base ?>/actionnaire/dashboard.php">← Retour au tableau de bord</a>
    </p>
  </form>

  <?php if ($en_attente): ?>
    <section class="card" style="margin-top:24px">
      <h3 style="margin-bottom:16px">⏳ Souscriptions en attente (<?= count($en_attente) ?>)</h3>
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Date</th><th>Référence</th><th style="text-align:right">Parts</th><th style="text-align:right">Montant</th></tr>
          </thead>
          <tbody>
            <?php foreach ($en_attente as $p): ?>
              <tr>
                <td><?= date('d/m/Y', strtotime($p['date_souscription'])) ?></td>
                <td><code><?= htmlspecialchars($p['reference']) ?></code></td>
                <td style="text-align:right"><?= number_format((int)$p['nombre_parts'], 0, ',', ' ') ?></td>
                <td style="text-align:right;color:var(--gold)"><?= number_format((float)$p['montant_total'], 0, ',', ' ') ?> <?= $p['devise'] ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
  <?php endif; ?>

  <div class="card" style="margin-top:24px;background:rgba(139,92,246,.06);border-color:rgba(139,92,246,.25)">
    <h3 style="margin-bottom:12px;color:#A78BFA">ℹ️ Comment ça marche ?</h3>
    <ol style="color:var(--muted);font-size:.88rem;line-height:1.9;margin-left:20px">
      <li>Soumettez votre demande de souscription</li>
      <li>L'administration valide votre souscription</li>
      <li>Vos parts sont ajoutées à votre portefeuille</li>
      <li>Vous recevez un nouveau certificat mis à jour</li>
    </ol>
  </div>

</div>

<script>
const PRIX_PART_CDF = <?= $capital ? (float)$capital['prix_unitaire'] : 0 ?>;
const TAUX_USD_CDF  = 2800;

function setParts(n) {
  document.getElementById('nbParts').value = n;
  updateSimulation();
}

function updateSimulation() {
  const n = parseInt(document.getElementById('nbParts').value) || 0;
  const devise = document.querySelector('select[name="devise"]').value;
  const box = document.getElementById('simuBox');
  const txt = document.getElementById('simuText');

  if (n > 0) {
    let prix = PRIX_PART_CDF;
    if (devise === 'USD') prix = PRIX_PART_CDF / TAUX_USD_CDF;
    const total = prix * n;
    txt.textContent = n.toLocaleString('fr-FR') + ' parts × ' + 
      prix.toLocaleString('fr-FR', {maximumFractionDigits: 2}) + ' ' + devise + 
      ' = ' + total.toLocaleString('fr-FR', {maximumFractionDigits: 2}) + ' ' + devise;
    box.style.display = 'block';
  } else {
    box.style.display = 'none';
  }
}

document.getElementById('nbParts').addEventListener('input', updateSimulation);
document.querySelector('select[name="devise"]').addEventListener('change', updateSimulation);
</script>

<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>