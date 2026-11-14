<?php
declare(strict_types=1);

/* ============================================================
   GARDE D'ACCÈS PAR RÔLE + TOKENS SÉCURISÉS
   ============================================================ */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/paths.php';
require_once __DIR__ . '/secure_tokens.php';

/* Initialisation session + headers (une seule fois) */
if (session_status() !== PHP_SESSION_ACTIVE) {
    if (function_exists('sendSecurityHeaders')) {
        sendSecurityHeaders();
    }
    if (function_exists('startSecureSession')) {
        startSecureSession();
    }
}

/* ============================================================
   Contrôle d'accès par rôle
   ============================================================ */
function requireRole(string $role): void
{
    if (empty($_SESSION['role']) || $_SESSION['role'] !== $role) {
        $redirect = match($role) {
            'admin'    => 'auth/connexion_admin.php',
            'caissier' => 'auth/connexion_caissier.php',
            'client'   => 'auth/connexion_client.php',
            default    => 'auth/connexion_client.php',
        };
        header('Location: ' . url($redirect));
        exit;
    }
}

function requireCaissierOrAdmin(): void
{
    $role = $_SESSION['role'] ?? '';
    if (!in_array($role, ['caissier', 'admin'], true)) {
        header('Location: ' . url('auth/connexion_caissier.php'));
        exit;
    }
}

/* ============================================================
   Utilisateur courant
   ============================================================ */
function currentUser(): array
{
    return match ($_SESSION['role'] ?? '') {
        'client'   => [
            'id'   => (int)($_SESSION['client_id'] ?? 0),
            'nom'  => $_SESSION['client_nom'] ?? '',
            'role' => 'client',
        ],
        'admin'    => [
            'id'   => (int)($_SESSION['admin_id'] ?? 0),
            'nom'  => $_SESSION['admin_nom'] ?? '',
            'role' => 'admin',
        ],
        'caissier' => [
            'id'   => (int)($_SESSION['caissier_id'] ?? 0),
            'nom'  => $_SESSION['caissier_nom'] ?? '',
            'role' => 'caissier',
        ],
        default    => ['id' => 0, 'nom' => '', 'role' => 'guest'],
    };
}

/* ============================================================
   Déconnexion sécurisée (utilitaire)
   ============================================================ */
function logoutUser(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}
function requireActionnaire(): void
{
    if (empty($_SESSION['role']) || $_SESSION['role'] !== 'actionnaire') {
        header('Location: ' . url('auth/connexion_actionnaire.php'));
        exit;
    }
}
?>