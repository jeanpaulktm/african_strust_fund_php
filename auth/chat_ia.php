<?php
declare(strict_types=1);

/**
 * ============================================================
 * ENDPOINT API — Assistant IA ATF
 * Reçoit les messages du chat et retourne la réponse Gemini
 * ============================================================
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/gemini.php';

startSecureSession();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

/* ============================================================
   1. VÉRIFIER LA MÉTHODE HTTP
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Méthode non autorisée']);
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
if (!verifyCsrf($csrf_token)) {
    http_response_code(403);
    echo json_encode(['error' => 'Jeton de sécurité invalide. Rechargez la page.']);
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
    echo json_encode(['error' => 'Message trop long (maximum 1000 caractères)']);
    exit;
}

/* ============================================================
   5. RATE LIMITING (50 messages par jour par session)
   ============================================================ */
if (empty($_SESSION['ia_messages'])) {
    $_SESSION['ia_messages'] = [];
}

/* Nettoyer les messages de plus de 24h */
$limite_24h = time() - 86400;
$_SESSION['ia_messages'] = array_filter(
    $_SESSION['ia_messages'],
    fn($t) => $t > $limite_24h
);

if (count($_SESSION['ia_messages']) >= 50) {
    http_response_code(429);
    echo json_encode([
        'error' => 'Limite atteinte : 50 messages par jour. Réessayez demain.'
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
    $reponse = envoyerMessageGemini($message, $historique);

    echo json_encode([
        'success'   => true,
        'reponse'   => $reponse,
        'timestamp' => date('H:i:s')
    ], JSON_UNESCAPED_UNICODE);

} catch (Throwable $e) {
    error_log('[ATF IA] ' . $e->getMessage());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>