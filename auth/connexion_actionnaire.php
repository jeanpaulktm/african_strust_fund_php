<?php
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/actionnaires.php';

sendSecurityHeaders();
startSecureSession();

if (($_SESSION['role'] ?? '') === 'actionnaire') {
    header('Location: ' . url('actionnaire/dashboard.php'));
    exit;
}

$error = '';
$pdo = Database::getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) throw new RuntimeException("Jeton CSRF invalide.");

        $identifiant = cleanString($_POST['identifiant'] ?? '', 30);
        $password    = (string)($_POST['mot_de_passe'] ?? '');

        if ($identifiant === '' || $password === '') {
            throw new RuntimeException("Veuillez remplir tous les champs.");
        }

        $stmt = $pdo->prepare("
            SELECT * FROM Actionnaires 
            WHERE (numero_actionnaire = ? OR numero_phone = ?) 
            LIMIT 1
        ");
        $stmt->execute([$identifiant, $identifiant]);
        $actionnaire = $stmt->fetch();

        if (!$actionnaire || isLocked($actionnaire['locked_until'])) {
            usleep(500000);
            throw new RuntimeException("Identifiants incorrects.");
        }

        if ($actionnaire['statut'] !== 'actif') {
            throw new RuntimeException("Compte « {$actionnaire['statut']} ».");
        }

        if (!password_verify($password, $actionnaire['mot_de_passe'])) {
            recordFailedAttempt($pdo, 'Actionnaires', 'id_actionnaire', (int)$actionnaire['id_actionnaire']);
            usleep(500000);
            throw new RuntimeException("Identifiants incorrects.");
        }

        /* Succès */
        $pdo->prepare("
            UPDATE Actionnaires 
            SET failed_attempts = 0, locked_until = NULL, last_login = NOW() 
            WHERE id_actionnaire = ?
        ")->execute([$actionnaire['id_actionnaire']]);

        session_regenerate_id(true);
        $_SESSION['actionnaire_id']     = (int)$actionnaire['id_actionnaire'];
        $_SESSION['actionnaire_numero'] = $actionnaire['numero_actionnaire'];
        $_SESSION['actionnaire_nom']    = $actionnaire['nom'] . ' ' . $actionnaire['postnom'];
        $_SESSION['role']               = 'actionnaire';

        /* Log */
        $pdo->prepare("
            INSERT INTO Actionnaires_Logs (id_actionnaire, action, details, ip_address, user_agent)
            VALUES (?, 'LOGIN_SUCCESS', 'Connexion réussie', ?, ?)
        ")->execute([
            $actionnaire['id_actionnaire'],
            $_SERVER['REMOTE_ADDR'] ?? null,
            mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)
        ]);

        header('Location: ' . url('actionnaire/dashboard.php'));
        exit;

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Connexion Actionnaire — AFRICAN TRUST FUND</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
</head>
<body class="dark-auth">

<?php require __DIR__ . '/../includes/header.php'; ?>

<main class="auth-wrapper">
  <div class="auth-card">
    <div class="auth-head">
      <span class="badge gold">🏛️ Espace Actionnaire</span>
      <h1>Connexion</h1>
      <p class="muted">Accédez à votre portefeuille</p>
    </div>

    <?php if ($error): ?>
      <div class="alert error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" novalidate autocomplete="off">
      <?= csrfField() ?>

      <label class="field">
        <span>Numéro d'actionnaire ou téléphone</span>
        <div class="input-wrap">
          <svg viewBox="0 0 24 24" class="ico"><path d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5Zm0 2c-4 0-8 2-8 4.5V21h16v-2.5C20 16 16 14 12 14Z"/></svg>
          <input type="text" name="identifiant" required maxlength="30" placeholder="ACT-2026-XXXXXX" autofocus>
        </div>
      </label>

      <label class="field">
        <span>Mot de passe</span>
        <div class="input-wrap">
          <svg viewBox="0 0 24 24" class="ico"><path d="M12 1a5 5 0 0 0-5 5v3H5v13h14V9h-2V6a5 5 0 0 0-5-5Zm-3 8V6a3 3 0 0 1 6 0v3Z"/></svg>
          <input type="password" id="pwd" name="mot_de_passe" required autocomplete="current-password">
          <button type="button" class="eye" data-target="pwd">👁</button>
        </div>
      </label>

      <button class="btn-gold full" type="submit">Accéder à mon portefeuille</button>

      <p class="center muted" style="margin-top:16px">
        <a href="<?= url('index.php') ?>">← Retour à l'accueil</a>
      </p>
    </form>
  </div>
</main>

<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>