<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';

/* Si déjà connecté → dashboard */
if (!empty($_SESSION['entreprise_id'])) {
    header('Location: /african-trust-fund/entreprise/dashboard.php');
    exit;
}

$errors = [];
$pdo = Database::getConnection();

/* ============================================================
   TRAITEMENT DU LOGIN
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) {
            throw new RuntimeException("Session expirée. Rechargez la page.");
        }

        $numero_compte = cleanString($_POST['numero_compte'] ?? '', 50);
        $mot_de_passe  = (string)($_POST['mot_de_passe'] ?? '');

        if ($numero_compte === '' || $mot_de_passe === '') {
            throw new RuntimeException("Veuillez remplir tous les champs.");
        }

        /* Récupérer l'entreprise */
        $stmt = $pdo->prepare("
            SELECT c.*, e.id_entreprise, e.raison_sociale, e.forme_juridique
            FROM Client c
            LEFT JOIN Entreprises e ON e.id_client = c.id_client
            WHERE c.numero_compte = ? 
              AND c.type_client = 'morale'
            LIMIT 1
        ");
        $stmt->execute([$numero_compte]);
        $entreprise = $stmt->fetch();

        if (!$entreprise) {
            throw new RuntimeException("Identifiants incorrects.");
        }

        if (empty($entreprise['mot_de_passe'])) {
            throw new RuntimeException("Ce compte n'a pas encore de mot de passe. Contactez l'administrateur.");
        }

        if (!password_verify($mot_de_passe, $entreprise['mot_de_passe'])) {
            // Log sécurité
            try {
                logAction($pdo, 'entreprise', (int)$entreprise['id_client'], 'LOGIN_FAILED',
                    "Mot de passe incorrect pour $numero_compte");
            } catch (Throwable $e) {}
            
            throw new RuntimeException("Identifiants incorrects.");
        }

        if ($entreprise['statut'] !== 'actif') {
            throw new RuntimeException("Votre compte est " . $entreprise['statut'] . ". Contactez l'administrateur.");
        }

        /* Connexion réussie */
        session_regenerate_id(true);

        $_SESSION['entreprise_id']       = (int)$entreprise['id_client'];
        $_SESSION['entreprise_role']     = 'entreprise';
        $_SESSION['entreprise_numero']   = $entreprise['numero_compte'];
        $_SESSION['entreprise_nom']      = $entreprise['raison_sociale'] ?? $entreprise['nom'];
        $_SESSION['entreprise_last_activity'] = time();

        /* Mise à jour last_login */
        $pdo->prepare("UPDATE Client SET last_login = NOW() WHERE id_client = ?")
            ->execute([(int)$entreprise['id_client']]);

        /* Log succès */
        try {
            logAction($pdo, 'entreprise', (int)$entreprise['id_client'], 'LOGIN_SUCCESS',
                "Connexion réussie");
        } catch (Throwable $e) {}

        /* Redirection */
        $redirect = $_SESSION['redirect_after_login'] ?? '/african-trust-fund/entreprise/dashboard.php';
        unset($_SESSION['redirect_after_login']);
        
        header('Location: ' . $redirect);
        exit;

    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Connexion Entreprise — ATF</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<style>
body {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: radial-gradient(circle at 50% 0%, #1E3A5F 0%, #0B0E14 70%);
    font-family: 'Inter', sans-serif;
}

.login-box {
    width: 100%;
    max-width: 440px;
    padding: 40px 32px;
    background: linear-gradient(145deg, #1A2130, #121824);
    border-radius: 24px;
    border: 1px solid rgba(139,92,246,.35);
    box-shadow: 0 30px 80px rgba(0,0,0,.7);
    position: relative;
    overflow: hidden;
}

.login-box::before {
    content: "";
    position: absolute;
    top: -50%; right: -20%;
    width: 300px; height: 300px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(139,92,246,.2), transparent 70%);
    pointer-events: none;
}

.login-logo {
    text-align: center;
    margin-bottom: 28px;
    position: relative;
    z-index: 1;
}

.login-logo .ico {
    width: 80px; height: 80px;
    border-radius: 50%;
    background: linear-gradient(135deg, #8B5CF6, #7C3AED);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 2.2rem;
    margin: 0 auto 16px;
    box-shadow: 0 10px 30px rgba(139,92,246,.4);
}

.login-logo h1 {
    color: #C4B5FD;
    font-size: 1.4rem;
    letter-spacing: 2px;
    font-weight: 900;
    margin-bottom: 6px;
}

.login-logo p {
    color: #94A3B8;
    font-size: .82rem;
}

.field {
    margin-bottom: 18px;
    position: relative;
    z-index: 1;
}

.field label {
    display: block;
    color: #94A3B8;
    font-size: .78rem;
    text-transform: uppercase;
    letter-spacing: 1px;
    font-weight: 700;
    margin-bottom: 8px;
}

.field input {
    width: 100%;
    padding: 14px 16px;
    background: #0F1520;
    border: 1px solid #2A3446;
    border-radius: 12px;
    color: #E8ECF3;
    font-size: .95rem;
    font-family: inherit;
    transition: all .2s;
}

.field input:focus {
    outline: none;
    border-color: #8B5CF6;
    box-shadow: 0 0 0 3px rgba(139,92,246,.15);
}

.btn-login {
    width: 100%;
    padding: 16px;
    border-radius: 999px;
    background: linear-gradient(135deg, #8B5CF6, #7C3AED);
    color: #fff;
    border: none;
    font-weight: 800;
    font-size: 1rem;
    cursor: pointer;
    font-family: inherit;
    box-shadow: 0 8px 24px rgba(139,92,246,.4);
    transition: all .25s;
    margin-top: 8px;
    position: relative;
    z-index: 1;
}

.btn-login:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 32px rgba(139,92,246,.6);
}

.btn-login:disabled {
    opacity: .5;
    cursor: not-allowed;
    transform: none;
}

.footer-login {
    text-align: center;
    margin-top: 24px;
    padding-top: 20px;
    border-top: 1px solid #1E293B;
    font-size: .82rem;
    color: #64748B;
    position: relative;
    z-index: 1;
}

.footer-login a {
    color: #A78BFA;
    text-decoration: none;
    font-weight: 700;
}

.footer-login a:hover { text-decoration: underline; }

.info-box {
    padding: 14px 16px;
    border-radius: 12px;
    background: rgba(139,92,246,.08);
    border: 1px solid rgba(139,92,246,.3);
    color: #C4B5FD;
    font-size: .82rem;
    line-height: 1.5;
    margin-bottom: 20px;
    position: relative;
    z-index: 1;
}

.info-box strong { color: #fff; }
</style>
</head>
<body>

<div class="login-box">
    <div class="login-logo">
        <div class="ico">🏢</div>
        <h1>ESPACE ENTREPRISE</h1>
        <p>AFRICAN TRUST FUND</p>
    </div>

    <?php if (!empty($_GET['expired'])): ?>
        <div class="info-box" style="background:rgba(245,184,0,.08);border-color:rgba(245,184,0,.3);color:#FCD34D">
            ⏱️ Votre session a expiré. Reconnectez-vous.
        </div>
    <?php endif; ?>

    <?php if (!empty($_GET['suspended'])): ?>
        <div class="info-box" style="background:rgba(239,68,68,.08);border-color:rgba(239,68,68,.3);color:#FCA5A5">
            ❌ Votre compte est suspendu. Contactez l'administrateur.
        </div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="alert error" style="position:relative;z-index:1;margin-bottom:18px">
            <ul style="margin-left:16px">
                <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars($e) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="POST" novalidate autocomplete="off">
        <?= csrfField() ?>

        <div class="field">
            <label>Numéro de compte entreprise</label>
            <input type="text" name="numero_compte" required autofocus
                   placeholder="ATF-PM-XXXXXX"
                   value="<?= htmlspecialchars($_POST['numero_compte'] ?? '') ?>">
        </div>

        <div class="field">
            <label>Mot de passe</label>
            <input type="password" name="mot_de_passe" required 
                   autocomplete="current-password" placeholder="••••••••">
        </div>

        <button class="btn-login" type="submit" id="btnSubmit">
            🔐 Se connecter
        </button>
    </form>

    <div class="footer-login">
        <p>Vous êtes un particulier ? <a href="<?= $base ?>/auth/connexion_client.php">Connexion client →</a></p>
        <p style="margin-top:10px;font-size:.75rem">
            © <?= date('Y') ?> AFRICAN TRUST FUND
        </p>
    </div>
</div>

<script>
document.querySelector('form').addEventListener('submit', function() {
    const btn = document.getElementById('btnSubmit');
    btn.disabled = true;
    btn.innerHTML = '⏳ Connexion en cours...';
});
</script>

</body>
</html>