<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/paths.php';

sendSecurityHeaders();
startSecureSession();

if (($_SESSION['role'] ?? '') === 'admin') {
    header('Location: ' . url('admin/dashboard.php'));
    exit;
}

$error = '';
$pdo = Database::getConnection();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) throw new RuntimeException("Jeton CSRF invalide.");

        $nom      = cleanString($_POST['nom'] ?? '', 60);
        $password = (string)($_POST['mot_de_passe'] ?? '');

        $stmt = $pdo->prepare("SELECT * FROM Admin_Principal WHERE nom = ? LIMIT 1");
        $stmt->execute([$nom]);
        $admin = $stmt->fetch();

        if (!$admin || isLocked($admin['locked_until'])) {
            usleep(500000);
            throw new RuntimeException("Identifiants incorrects.");
        }
        if ($admin['statut'] !== 'actif') throw new RuntimeException("Compte admin {$admin['statut']}.");
        if (!password_verify($password, $admin['mot_de_passe'])) {
            recordFailedAttempt($pdo, 'Admin_Principal', 'id_admin', (int)$admin['id_admin']);
            usleep(500000);
            throw new RuntimeException("Identifiants incorrects.");
        }

        $pdo->prepare("UPDATE Admin_Principal SET failed_attempts=0, locked_until=NULL, last_login=NOW() WHERE id_admin=?")
            ->execute([$admin['id_admin']]);

        session_regenerate_id(true);
        $_SESSION['admin_id']   = (int)$admin['id_admin'];
        $_SESSION['admin_nom']  = $admin['nom'];
        $_SESSION['admin_role'] = $admin['role'];
        $_SESSION['role']       = 'admin';

        logAction($pdo, 'admin', (int)$admin['id_admin'], 'LOGIN_SUCCESS', 'Connexion admin');
        header('Location: ' . url('admin/dashboard.php'));
        exit;

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$base = BASE_URL;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Connexion Admin — AFRICAN TRUST FUND</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
</head>
<body class="dark-auth">

<?php require __DIR__ . '/../includes/header.php'; ?>

<main class="auth-wrapper">
  <div class="auth-card">
    <div class="auth-head">
      <span class="badge blue">Administration</span>
      <h1>Espace Admin</h1>
      <p class="muted">Accès réservé au personnel autorisé.</p>
    </div>

    <?php if ($error): ?>
      <div class="alert error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" novalidate autocomplete="off">
      <?= csrfField() ?>

      <label class="field"><span>Nom</span>
        <div class="input-wrap">
          <svg viewBox="0 0 24 24" class="ico"><path d="M12 12a5 5 0 1 0-5-5 5 5 0 0 0 5 5Zm0 2c-4 0-8 2-8 4.5V21h16v-2.5C20 16 16 14 12 14Z"/></svg>
          <input type="text" name="nom" required maxlength="60" autofocus>
        </div>
      </label>

      <label class="field"><span>Mot de passe</span>
        <div class="input-wrap">
          <svg viewBox="0 0 24 24" class="ico"><path d="M12 1a5 5 0 0 0-5 5v3H5v13h14V9h-2V6a5 5 0 0 0-5-5Zm-3 8V6a3 3 0 0 1 6 0v3Z"/></svg>
          <input type="password" id="pwd" name="mot_de_passe" required autocomplete="current-password">
          <button type="button" class="eye" data-target="pwd">👁</button>
        </div>
      </label>

      <button class="btn-gold full" type="submit">Connexion Admin</button>

      <p class="center muted">
        
        <a href="<?= $base ?>/auth/connexion_caissier.php">Caissier</a>
      </p>
    </form>
  </div>
</main>

<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>