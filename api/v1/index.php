<?php
declare(strict_types=1);

/**
 * ============================================================
 * ROUTER API V1 - POINT D'ENTRÉE PRINCIPAL
 * ============================================================
 * Toutes les requêtes API passent par ce fichier
 * 
 * Utilisation:
 * GET    /api/v1/auth/login
 * POST   /api/v1/auth/register
 * GET    /api/v1/dashboard
 * GET    /api/v1/prets
 * GET    /api/v1/transactions
 * GET    /api/v1/placements
 * GET    /api/v1/profil
 */

require_once __DIR__ . '/config.php';

$request_method = $_SERVER['REQUEST_METHOD'];
$request_uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Supprimer le préfixe du base path
$request_uri = str_replace('/african-trust-fund/api/v1', '', $request_uri);

// Parser les segments de l'URI
$segments = array_filter(explode('/', $request_uri));
$segments = array_values($segments);

// Router
try {
    // ============================================================
    // Authentification (pas de token requis pour login/register)
    // ============================================================
    if (isset($segments[0]) && $segments[0] === 'auth') {
        require_once __DIR__ . '/endpoints/auth.php';
    }

    // ============================================================
    // Routes authentifiées
    // ============================================================
    else {
        // Vérifier le token
        $token = ApiAuth::getTokenFromHeader();
        if (!$token || !$auth_data = ApiAuth::validateToken($token)) {
            ApiResponse::error('Token invalide ou expiré', 401);
        }

        // Dashboard
        if (isset($segments[0]) && $segments[0] === 'dashboard') {
            require_once __DIR__ . '/endpoints/dashboard.php';
        }

        // Prêts
        else if (isset($segments[0]) && $segments[0] === 'prets') {
            require_once __DIR__ . '/endpoints/prets.php';
        }

        // Transactions
        else if (isset($segments[0]) && $segments[0] === 'transactions') {
            require_once __DIR__ . '/endpoints/transactions.php';
        }

        // Placements
        else if (isset($segments[0]) && $segments[0] === 'placements') {
            require_once __DIR__ . '/endpoints/placements.php';
        }

        // Profil
        else if (isset($segments[0]) && $segments[0] === 'profil') {
            require_once __DIR__ . '/endpoints/profil.php';
        }

        // Health check
        else if (isset($segments[0]) && $segments[0] === 'health') {
            ApiResponse::success(['status' => 'ok', 'version' => API_VERSION], 'API is running');
        }

        else {
            ApiResponse::error('Endpoint non trouvé', 404);
        }
    }

} catch (Exception $e) {
    error_log('Router Error: ' . $e->getMessage());
    ApiResponse::error('Erreur serveur interne', 500);
}
?>
