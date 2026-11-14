<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';

/* Log de la déconnexion */
if (!empty($_SESSION['entreprise_id'])) {
    try {
        $pdo = Database::getConnection();
        logAction($pdo, 'entreprise', (int)$_SESSION['entreprise_id'], 'LOGOUT',
            'Déconnexion volontaire');
    } catch (Throwable $e) { /* */ }
}

/* Nettoyer la session entreprise */
unset(
    $_SESSION['entreprise_id'],
    $_SESSION['entreprise_role'],
    $_SESSION['entreprise_numero'],
    $_SESSION['entreprise_nom'],
    $_SESSION['entreprise_last_activity']
);

session_regenerate_id(true);

header('Location: /african-trust-fund/entreprise/login.php');
exit;