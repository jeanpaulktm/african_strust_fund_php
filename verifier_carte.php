<?php
require_once __DIR__ . '/config/database.php';

$pdo = Database::getConnection();

$token = $_GET['token'] ?? '';
$resultat = null;
$type_trouve = null;
$erreur = '';

if ($token === '') {
    $erreur = "Token manquant.";
} else {
    /* Chercher dans Clients */
    $stmt = $pdo->prepare("
        SELECT id_client AS id, nom, postnom, prenom, numero_compte, carte_numero, carte_statut, 
               carte_expire, photo_profil, 'client' AS type
        FROM Client WHERE carte_qr_token = ? LIMIT 1
    ");
    $stmt->execute([$token]);
    $resultat = $stmt->fetch();

    if (!$resultat) {
        /* Chercher dans Caissiers */
        $stmt = $pdo->prepare("
            SELECT id_caissier AS id, nom, postnom, post_prenom AS prenom, numero_phone AS numero_compte, 
                   carte_numero, carte_statut, carte_expire, photo_profil, 'caissier' AS type
            FROM Caissier WHERE carte_qr_token = ? LIMIT 1
        ");
        $stmt->execute([$token]);
        $resultat = $stmt->fetch();
    }

    if (!$resultat) {
        /* Chercher dans Actionnaires */
        $stmt = $pdo->prepare("
            SELECT id_actionnaire AS id, nom, postnom, prenom, numero_actionnaire AS numero_compte, 
                   carte_numero, carte_statut, carte_expire, photo_profil, 'actionnaire' AS type
            FROM Actionnaires WHERE carte_qr_token = ? LIMIT 1
        ");
        $stmt->execute([$token]);
        $resultat = $stmt->fetch();
    }

    if (!$resultat) {
        $erreur = "Carte non reconnue dans notre système.";
    }
}

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Vérification de carte — ATF</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<style>
.verif-wrapper {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 30px 20px;
}
.verif-card {
  max-width: 500px;
  width: 100%;
  background: linear-gradient(160deg, #1A2130, #121824);
  border-radius: 24px;
  padding: 40px 36px;
  border: 1px solid var(--border);
  box-shadow: 0 20px 60px rgba(0,0,0,.6);
  text-align: center;
  animation: fadeInUp .5s ease-out;
}
.verif-icon {
  width: 90px;height: 90px;
  border-radius: 50%;
  display: flex;align-items: center;justify-content: center;
  margin: 0 auto 20px;
  font-size: 3rem;
}
.verif-icon.ok {
  background: rgba(16,185,129,.15);
  border: 2px solid #10B981;
  box-shadow: 0 0 40px rgba(16,185,129,.4);
}
.verif-icon.ko {
  background: rgba(239,68,68,.15);
  border: 2px solid #EF4444;
  box-shadow: 0 0 40px rgba(239,68,68,.4);
}
.verif-title {
  font-size: 1.5rem;
  font-weight: 800;
  margin-bottom: 8px;
}
.verif-title.ok { color: #6EE7B7; }
.verif-title.ko { color: #FCA5A5; }
.verif-sub { color: var(--muted); font-size: .9rem; margin-bottom: 24px; }
.verif-info {
  background: rgba(11,14,20,.5);
  border-radius: 14px;
  padding: 20px;
  text-align: left;
  margin-bottom: 20px;
}
.verif-info .ligne {
  display: flex;
  justify-content: space-between;
  padding: 10px 0;
  border-bottom: 1px solid var(--border);
}
.verif-info .ligne:last-child { border-bottom: none; }
.verif-info .lbl { color: var(--muted); font-size: .85rem; }
.verif-info .val { font-weight: 700; color: var(--text); }
.verif-photo {
  width: 100px;height: 120px;
  border-radius: 12px;
  overflow: hidden;
  border: 3px solid #F5B800;
  margin: 0 auto 20px;
  background: var(--bg-1);
  display: flex;align-items: center; justify-content: center;
  font-size: 2rem;font-weight: 900;color: #F5B800;
}
.verif-photo img { width: 100%;height: 100%;object-fit: cover; }
</style>
</head>
<body class="dark-auth">

<div class="verif-wrapper">
  <div class="verif-card">

    <?php if ($resultat): ?>
      <div class="verif-icon ok">✓</div>
      <div class="verif-title ok">Carte authentique</div>
      <div class="verif-sub">Cette carte a été vérifiée avec succès</div>

      <?php if (!empty($resultat['photo_profil']) && file_exists(__DIR__ . '/' . $resultat['photo_profil'])): ?>
        <div class="verif-photo">
          <img src="<?= $base ?>/<?= htmlspecialchars($resultat['photo_profil']) ?>" alt="Photo">
        </div>
      <?php else: ?>
        <div class="verif-photo">
          <?= strtoupper(mb_substr($resultat['nom'], 0, 1) . mb_substr($resultat['prenom'], 0, 1)) ?>
        </div>
      <?php endif; ?>

      <div class="verif-info">
        <div class="ligne">
          <span class="lbl">Type</span>
          <span class="val" style="text-transform:uppercase;color:#F5B800"><?= htmlspecialchars($resultat['type']) ?></span>
        </div>
        <div class="ligne">
          <span class="lbl">Nom complet</span>
          <span class="val"><?= htmlspecialchars($resultat['nom'] . ' ' . $resultat['postnom'] . ' ' . $resultat['prenom']) ?></span>
        </div>
        <div class="ligne">
          <span class="lbl">N° de carte</span>
          <span class="val"><?= htmlspecialchars($resultat['carte_numero']) ?></span>
        </div>
        <div class="ligne">
          <span class="lbl">Statut</span>
          <span class="val" style="color:<?= $resultat['carte_statut'] === 'active' ? '#6EE7B7' : '#FCA5A5' ?>">
            <?= strtoupper(htmlspecialchars($resultat['carte_statut'])) ?>
          </span>
        </div>
        <div class="ligne">
          <span class="lbl">Valide jusqu'à</span>
          <span class="val"><?= date('d/m/Y', strtotime($resultat['carte_expire'])) ?></span>
        </div>
      </div>

      <?php if (strtotime($resultat['carte_expire']) < time()): ?>
        <div class="alert error" style="text-align:center">⚠️ Cette carte est EXPIRÉE</div>
      <?php endif; ?>

      <?php if ($resultat['carte_statut'] !== 'active'): ?>
        <div class="alert error" style="text-align:center">⚠️ Cette carte est <?= strtoupper($resultat['carte_statut']) ?></div>
      <?php endif; ?>

    <?php else: ?>
      <div class="verif-icon ko">✕</div>
      <div class="verif-title ko">Carte non reconnue</div>
      <div class="verif-sub"><?= htmlspecialchars($erreur) ?></div>
      <p class="muted" style="font-size:.85rem;margin-top:20px">
        Si vous pensez qu'il s'agit d'une erreur,<br>contactez AFRICAN TRUST FUND au +243 123 456 789
      </p>
    <?php endif; ?>

    <div style="margin-top:30px;padding-top:20px;border-top:1px solid var(--border)">
      <div style="color:#F5B800;font-weight:800;letter-spacing:2px;font-size:.9rem">AFRICAN TRUST FUND</div>
      <div style="color:var(--muted);font-size:.75rem;margin-top:4px;font-style:italic">
        « L'argent de l'Afrique pour construire l'Afrique »
      </div>
    </div>

  </div>
</div>

</body>
</html>