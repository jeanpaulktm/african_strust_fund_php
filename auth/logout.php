<?php
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';

startSecureSession();

/* Log si actionnaire */
if (($_SESSION['role'] ?? '') === 'actionnaire' && !empty($_SESSION['actionnaire_id'])) {
    try {
        $pdo = Database::getConnection();
        $pdo->prepare("
            INSERT INTO Actionnaires_Logs (id_actionnaire, action, details, ip_address)
            VALUES (?, 'LOGOUT', 'Déconnexion', ?)
        ")->execute([(int)$_SESSION['actionnaire_id'], $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Throwable $e) { /* silencieux */ }
}

/* Log si client */
if (!empty($_SESSION['role'])) {
    try {
        $pdo = Database::getConnection();
        $uid = $_SESSION['client_id'] ?? $_SESSION['admin_id'] ?? $_SESSION['caissier_id'] ?? null;
        if ($uid) logAction($pdo, $_SESSION['role'], (int)$uid, 'LOGOUT', 'Déconnexion');
    } catch (Throwable $e) { /* silencieux */ }
}

/* Destruction */
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

header('Location: ' . url('index.php'));
exit;