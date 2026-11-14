<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/paths.php';

sendSecurityHeaders();
startSecureSession();

if (!empty($_SESSION['role'])) {
    header('Location: ' . url($_SESSION['role'] . '/dashboard.php'));
    exit;
}

$errors = [];
$success = '';
$pdo = Database::getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) {
            throw new RuntimeException("Jeton de sécurité invalide.");
        }

        $nom     = cleanString($_POST['nom']    ?? '', 60);
        $postnom = cleanString($_POST['postnom'] ?? '', 60);
        $prenom  = cleanString($_POST['prenom']  ?? '', 60);
        $adresse = cleanString($_POST['adresse'] ?? '', 500);
        $phone   = cleanString($_POST['numero_phone'] ?? '', 25);
        $password  = (string)($_POST['mot_de_passe']  ?? '');
        $password2 = (string)($_POST['mot_de_passe2'] ?? '');

        $nom_mand     = cleanString($_POST['nom_mandataire']     ?? '', 60);
        $postnom_mand = cleanString($_POST['postnom_mandataire'] ?? '', 60);
        $num_mand     = cleanString($_POST['numero_mandataire']  ?? '', 25);
        $adr_mand     = cleanString($_POST['adresse_mandataire'] ?? '', 500);

        if ($nom === '' || $postnom === '' || $prenom === '') $errors[] = "Nom, post-nom et prénom obligatoires.";
        if (!preg_match('/^\+?[0-9 ]{9,20}$/', $phone))       $errors[] = "Numéro de téléphone invalide.";
        if ($adresse === '')                                   $errors[] = "Adresse obligatoire.";
        if (!validerMotDePasse($password))                     $errors[] = "Mot de passe faible (8+, maj, min, chiffre, symbole).";
        if ($password !== $password2)                          $errors[] = "Les mots de passe ne correspondent pas.";

        if (!$errors) {
            $chk = $pdo->prepare("SELECT 1 FROM Client WHERE numero_phone = ? LIMIT 1");
            $chk->execute([$phone]);
            if ($chk->fetchColumn()) $errors[] = "Ce numéro est déjà utilisé.";
        }

        if (!$errors) {
            $uploadDir = __DIR__ . '/../uploads';
            $cni = handleSecureUpload('file_carte_identite', 'cni_', $uploadDir);
            $ce  = handleSecureUpload('file_carte_electeur', 'ce_',  $uploadDir);
            $pp  = handleSecureUpload('photo_profil',        'pp_',  $uploadDir);
            $ppm = handleSecureUpload('photo_profil_mandataire', 'ppm_', $uploadDir);

            $numeroCompte = genererNumeroCompte($pdo);
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

            $stmt = $pdo->prepare(
                "INSERT INTO Client
                 (nom, postnom, prenom, adresse_complete, numero_phone,
                  file_carte_identite, file_carte_electeur, photo_profil,
                  numero_compte, nom_mandataire, postnom_mandataire,
                  numero_mandataire, adresse_compte_mandataire,
                  photo_profil_mandataire, mot_de_passe, statut)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'actif')"
            );
            $stmt->execute([
                $nom, $postnom, $prenom, $adresse, $phone,
                $cni, $ce, $pp, $numeroCompte,
                $nom_mand ?: null, $postnom_mand ?: null,
                $num_mand ?: null, $adr_mand ?: null,
                $ppm, $hash
            ]);

            logAction($pdo, 'guest', null, 'INSCRIPTION_CLIENT', "Compte $numeroCompte créé");
            $success = "✅ Compte créé avec succès !<br>Votre numéro de compte : <strong>$numeroCompte</strong>";
        }
    } catch (Throwable $e) {
        error_log('[ATF INSCRIPTION] ' . $e->getMessage());
        $errors[] = $e instanceof RuntimeException ? $e->getMessage() : "Erreur serveur.";
    }
}

$base = BASE_URL;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Inscription — AFRICAN TRUST FUND</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
</head>
<body class="dark-auth">

<?php require __DIR__ . '/../includes/header.php'; ?>

<main class="auth-wrapper wide">
  <div class="auth-card">
    <div class="auth-head">
      <span class="badge">Nouveau client</span>
      <h1>Ouvrir un compte</h1>
      <p class="muted">Rejoignez AFRICAN TRUST FUND en toute sécurité.</p>
    </div>

    <?php if ($success): ?>
      <div class="alert success"><?= $success ?></div>
      <a class="btn-gold full" href="<?= $base ?>/auth/connexion_client.php">Se connecter</a>
    <?php else: ?>

      <?php if ($errors): ?>
        <div class="alert error">
          <ul><?php foreach ($errors as $e) echo "<li>" . htmlspecialchars($e) . "</li>"; ?></ul>
        </div>
      <?php endif; ?>

      <form method="POST" enctype="multipart/form-data" novalidate autocomplete="off">
        <?= csrfField() ?>

        <fieldset>
          <legend>Identité</legend>
          <div class="grid-2">
            <label class="field"><span>Nom *</span><input type="text" name="nom" required maxlength="60"></label>
            <label class="field"><span>Post-nom *</span><input type="text" name="postnom" required maxlength="60"></label>
            <label class="field"><span>Prénom *</span><input type="text" name="prenom" required maxlength="60"></label>
            <label class="field"><span>Téléphone *</span><input type="text" name="numero_phone" required maxlength="25" placeholder="+243 ..."></label>
          </div>
          <label class="field"><span>Adresse complète *</span><textarea name="adresse" rows="2" required maxlength="500"></textarea></label>
        </fieldset>

        <fieldset>
          <legend>Documents (max 3 Mo)</legend>
          <div class="grid-2">
            <label class="field"><span>Carte d'identité</span><input type="file" name="file_carte_identite" accept=".pdf,.jpg,.jpeg,.png,.webp"></label>
            <label class="field"><span>Carte d'électeur</span><input type="file" name="file_carte_electeur" accept=".pdf,.jpg,.jpeg,.png,.webp"></label>
            <label class="field"><span>Photo de profil</span><input type="file" name="photo_profil" accept="image/*"></label>
          </div>
        </fieldset>

        <fieldset>
          <legend>Mandataire (optionnel)</legend>
          <div class="grid-2">
            <label class="field"><span>Nom</span><input type="text" name="nom_mandataire" maxlength="60"></label>
            <label class="field"><span>Post-nom</span><input type="text" name="postnom_mandataire" maxlength="60"></label>
            <label class="field"><span>Téléphone</span><input type="text" name="numero_mandataire" maxlength="25"></label>
            <label class="field"><span>Photo</span><input type="file" name="photo_profil_mandataire" accept="image/*"></label>
          </div>
          <label class="field"><span>Adresse</span><textarea name="adresse_mandataire" rows="2" maxlength="500"></textarea></label>
        </fieldset>

        <fieldset>
          <legend>Sécurité</legend>
          <label class="field"><span>Mot de passe *</span>
            <input type="password" id="pwd1" name="mot_de_passe" required minlength="8" autocomplete="new-password"></label>
          <div class="pwd-meter"><div id="pwdBar"></div></div>
          <label class="field"><span>Confirmer *</span>
            <input type="password" id="pwd2" name="mot_de_passe2" required minlength="8" autocomplete="new-password"></label>
          <p class="hint">8 car. min. — 1 majuscule, 1 minuscule, 1 chiffre, 1 symbole.</p>
        </fieldset>

        <button class="btn-gold full" type="submit">Créer mon compte</button>
        <p class="center muted">Déjà client ? <a href="<?= $base ?>/auth/connexion_client.php">Se connecter</a></p>
      </form>
    <?php endif; ?>
  </div>
</main>

<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>