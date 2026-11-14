<?php
declare(strict_types=1);

/**
 * ============================================================
 * ENDPOINT — Génération du token CSRF
 * Utilisé par le widget de chat pour obtenir un token valide
 * ============================================================
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

startSecureSession();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

/* Seulement en POST pour éviter les abus */
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée']);
    exit;
}

try {
    $token = csrfToken();
    
    echo json_encode([
        'success' => true,
        'csrf' => $token,
        'timestamp' => time()
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}