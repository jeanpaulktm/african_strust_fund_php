<?php
declare(strict_types=1);

/**
 * ============================================================
 * ENDPOINT API — Assistant IA ATF
 * Reçoit les messages du chat et retourne la réponse Gemini
 * ============================================================
 */

/* ============================================================
   GESTION DES ERREURS → Toujours renvoyer du JSON
   ============================================================ */
ini_set('display_errors', '0');
error_reporting(E_ALL);

/* Buffer pour capturer tout output parasite */
ob_start();

/* Handler pour convertir les erreurs PHP en exceptions */
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

/* Handler de shutdown pour les erreurs fatales */
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (ob_get_level() > 0) ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error'   => 'Erreur serveur : ' . $error['message'],
            'file'    => basename($error['file']),
            'line'    => $error['line']
        ], JSON_UNESCAPED_UNICODE);
    }
});

/* Nettoyer le buffer avant de commencer */
ob_clean();

/* ============================================================
   INCLUSIONS
   ============================================================ */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/gemini.php';

/* ============================================================
   INITIALISATION
   ============================================================ */
startSecureSession();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

/* ============================================================
   1. VÉRIFIER LA MÉTHODE HTTP
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée (POST requis)']);
    exit;
}

/* ============================================================
   2. LIRE LE JSON ENTRANT
   ============================================================ */
$raw_input = file_get_contents('php://input');
$input = json_decode($raw_input, true);

if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['error' => 'Format JSON invalide']);
    exit;
}

/* ============================================================
   3. VÉRIFIER LE TOKEN CSRF
   ============================================================ */
$csrf_token = $input['csrf'] ?? '';

if (empty($csrf_token)) {
    http_response_code(403);
    echo json_encode(['error' => 'Token CSRF manquant']);
    exit;
}

if (!verifyCsrf($csrf_token)) {
    http_response_code(403);
    echo json_encode(['error' => 'Token CSRF invalide ou expiré']);
    exit;
}

/* ============================================================
   4. VALIDER LE MESSAGE
   ============================================================ */
$message = trim((string)($input['message'] ?? ''));

if ($message === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Message vide']);
    exit;
}

if (mb_strlen($message) > 1000) {
    http_response_code(400);
    echo json_encode(['error' => 'Message trop long (max 1000 caractères)']);
    exit;
}

/* ============================================================
   5. RATE LIMITING
   ============================================================ */
if (empty($_SESSION['ia_messages'])) {
    $_SESSION['ia_messages'] = [];
}

$limite_24h = time() - 86400;
$_SESSION['ia_messages'] = array_filter(
    $_SESSION['ia_messages'],
    fn($t) => $t > $limite_24h
);

if (count($_SESSION['ia_messages']) >= 50) {
    http_response_code(429);
    echo json_encode([
        'error' => 'Limite de 50 messages par jour atteinte. Réessayez demain.'
    ]);
    exit;
}

$_SESSION['ia_messages'][] = time();

/* ============================================================
   6. CONSTRUIRE L'HISTORIQUE
   ============================================================ */
$historique = [];

if (isset($input['historique']) && is_array($input['historique'])) {
    foreach ($input['historique'] as $h) {
        if (!isset($h['role'], $h['contenu'])) continue;
        if (mb_strlen($h['contenu']) > 2000) continue;

        $role = in_array($h['role'], ['user', 'model'], true) ? $h['role'] : 'user';

        $historique[] = [
            'role'    => $role,
            'contenu' => (string)$h['contenu']
        ];

        if (count($historique) >= 10) break;
    }
}

/* ============================================================
   7. APPEL À GEMINI
   ============================================================ */
try {
    if (!function_exists('envoyerMessageGemini')) {
        throw new RuntimeException("Fonction envoyerMessageGemini manquante");
    }

    $reponse = envoyerMessageGemini($message, $historique);

    /* Nettoyer le buffer avant d'envoyer le JSON */
    if (ob_get_level() > 0) ob_clean();

    echo json_encode([
        'success'   => true,
        'reponse'   => $reponse,
        'timestamp' => date('H:i:s')
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('[ATF IA] ' . $e->getMessage());

    /* Nettoyer le buffer */
    if (ob_get_level() > 0) ob_clean();

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

/* ============================================================
   FIN — Le buffer sera automatiquement vidé
   ============================================================ */
if (ob_get_level() > 0) {
    ob_end_flush();
}