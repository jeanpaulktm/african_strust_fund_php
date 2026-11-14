<?php
declare(strict_types=1);

/**
 * ============================================================
 * CONFIGURATION API V1 - MOBILE (FLUTTER/DART)
 * ============================================================
 * Version: 1.0.0
 * Date: 14 Novembre 2026
 */

/* ============================================================
   1. CONSTANTES API
   ============================================================ */
define('API_VERSION', '1.0.0');
define('API_NAME', 'African Trust Fund Mobile API');
define('API_TIMEOUT', 30);

/* ============================================================
   2. CONFIGURATION CORS
   ============================================================ */
$allowed_origins = [
    'http://localhost:3000',
    'http://localhost:8000',
    'http://127.0.0.1:3000',
    'http://127.0.0.1:8000',
];

/* ============================================================
   3. HEADERS CORS
   ============================================================ */
function corsHeaders(): void
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, PATCH, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-CSRF-Token');
    header('Access-Control-Max-Age: 86400');
    header('Content-Type: application/json; charset=utf-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    corsHeaders();
    http_response_code(200);
    exit();
}

corsHeaders();

/* ============================================================
   4. RESPONSE FACTORY
   ============================================================ */
class ApiResponse
{
    public static function success(mixed $data = null, string $message = 'Success', int $code = 200): void
    {
        http_response_code($code);
        echo json_encode([
            'success' => true,
            'code' => $code,
            'message' => $message,
            'data' => $data ?? (object)[],
            'timestamp' => time(),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }

    public static function error(string $message, int $code = 400, array $errors = []): void
    {
        http_response_code($code);
        echo json_encode([
            'success' => false,
            'code' => $code,
            'message' => $message,
            'errors' => $errors,
            'timestamp' => time(),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }

    public static function paginated(array $data, int $page, int $per_page, int $total): void
    {
        $total_pages = (int)ceil($total / $per_page);
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'code' => 200,
            'data' => $data,
            'pagination' => [
                'page' => $page,
                'per_page' => $per_page,
                'total' => $total,
                'total_pages' => $total_pages,
                'has_next' => $page < $total_pages,
                'has_prev' => $page > 1,
            ],
            'timestamp' => time(),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }
}

/* ============================================================
   5. AUTHENTIFICATION API
   ============================================================ */
class ApiAuth
{
    public static function getTokenFromHeader(): ?string
    {
        $headers = getallheaders();
        if (!isset($headers['Authorization'])) {
            return null;
        }

        if (preg_match('/Bearer\s+(.+)/', $headers['Authorization'], $matches)) {
            return $matches[1];
        }

        return null;
    }

    public static function validateToken(string $token): ?array
    {
        try {
            // Format: {client_id}:{timestamp}:{hash}
            $parts = explode(':', $token);
            if (count($parts) !== 3) {
                return null;
            }

            list($client_id, $timestamp, $hash) = $parts;
            $client_id = (int)$client_id;
            $timestamp = (int)$timestamp;

            // Vérifier l'expiration (24 heures)
            if (time() - $timestamp > 86400) {
                return null;
            }

            // Vérifier le hash
            $expected_hash = hash_hmac(
                'sha256',
                "{$client_id}:{$timestamp}",
                getenv('CIPHER_KEY') ?: 'default_secret_key'
            );

            if (!hash_equals($hash, $expected_hash)) {
                return null;
            }

            return ['client_id' => $client_id, 'timestamp' => $timestamp];
        } catch (Exception $e) {
            return null;
        }
    }

    public static function generateToken(int $client_id): string
    {
        $timestamp = time();
        $hash = hash_hmac(
            'sha256',
            "{$client_id}:{$timestamp}",
            getenv('CIPHER_KEY') ?: 'default_secret_key'
        );
        return "{$client_id}:{$timestamp}:{$hash}";
    }
}

/* ============================================================
   6. HELPERS
   ============================================================ */
function getJsonBody(): ?array
{
    $input = file_get_contents('php://input');
    return json_decode($input, true);
}

function validateRequired(array $data, array $required): bool
{
    foreach ($required as $field) {
        if (!isset($data[$field]) || $data[$field] === '') {
            return false;
        }
    }
    return true;
}

function validateEmail(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function validatePhone(string $phone): bool
{
    return preg_match('/^\+?[0-9]{1,15}$/', $phone) === 1;
}

function validateAmount(float $amount): bool
{
    return $amount > 0 && $amount <= 999999999.99;
}
?>
