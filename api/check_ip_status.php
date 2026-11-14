<?php
declare(strict_types=1);
/**
 * ============================================================
 * API : VÉRIFICATION STATUT IP (POLLING CLIENT)
 * ============================================================
 * Appelée toutes les 2 secondes par la page login
 * Retourne {blocked: true/false}
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/paths.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/security_detector.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

/* Ne pas démarrer de session bloquante */
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

try {
    $pdo = Database::getConnection();
    $ip  = getClientIP();

    /* Vérifier blocage */
    $blocage = verifierIPBloquee($pdo, $ip);

    if ($blocage) {
        /* Détruire la session si connecté */
        if (!empty($_SESSION['client_id'])) {
            try {
                logAction($pdo, 'client', (int)$_SESSION['client_id'],
                    'SESSION_KILLED_BY_IP_BLOCK',
                    "IP bloquée : " . ($blocage['raison'] ?? 'N/A'));
            } catch (Throwable $e) {}

            $_SESSION = [];
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(), '',
                    time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );
            }
            @session_destroy();
        }

        echo json_encode([
            'ok'              => true,
            'blocked'         => true,
            'raison'          => $blocage['raison'] ?? 'Irrégularité détectée',
            'type'            => $blocage['type_blocage'] ?? 'temporaire',
            'date_blocage'    => $blocage['date_blocage'] ?? null,
            'date_expiration' => $blocage['date_expiration'] ?? null,
            'duree_minutes'   => (int)($blocage['duree_minutes'] ?? 0),
        ]);
    } else {
        echo json_encode([
            'ok'      => true,
            'blocked' => false,
            'ip'      => $ip,
        ]);
    }
} catch (Throwable $e) {
    error_log('[check_ip_status] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'blocked' => false, 'error' => 'server_error']);
}