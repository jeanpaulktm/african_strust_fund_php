<?php
declare(strict_types=1);

/**
 * ============================================================
 * SÉCURITÉ — AFRICAN TRUST FUND
 * Couche de protection : CSP, sessions, CSRF, anti-XSS, etc.
 * ============================================================
 */

/* ============================================================
   1. EN-TÊTES HTTP DE SÉCURITÉ
   ============================================================ */
function sendSecurityHeaders(): void
{
    if (headers_sent()) return;

    /* Anti-clickjacking */
    header('X-Frame-Options: DENY');

    /* Anti-MIME sniffing */
    header('X-Content-Type-Options: nosniff');

    /* Anti-XSS (navigateurs anciens) */
    header('X-XSS-Protection: 1; mode=block');

    /* Politique de référent */
    header('Referrer-Policy: strict-origin-when-cross-origin');

    /* Politique de permissions */
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');

    /* HSTS (HTTPS uniquement) */
    if (isset($_SERVER['HTTPS'])) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }

    /* ============================================================
       CONTENT SECURITY POLICY — VERSION CORRIGÉE
       
       Autorise :
       - 'self'              : ressources locales (CSS, JS, images)
       - 'unsafe-inline'     : scripts et styles inline (nécessaire pour Chart.js)
       - cdn.jsdelivr.net    : CDN pour Chart.js et bibliothèques
       - fonts.googleapis.com : Google Fonts
       - fonts.gstatic.com   : Google Fonts static
       - data: et blob:      : images en base64 et SVG inline
       ============================================================ */
    header("Content-Security-Policy: "
        . "default-src 'self'; "
        . "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com; "
        . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.jsdelivr.net; "
        . "font-src 'self' data: https://fonts.gstatic.com; "
        . "img-src 'self' data: blob: https:; "
        . "connect-src 'self' https://cdn.jsdelivr.net; "
        . "media-src 'self'; "
        . "object-src 'none'; "
        . "frame-ancestors 'none'; "
        . "base-uri 'self'; "
        . "form-action 'self'; "
        . "worker-src 'self' blob:; "
        . "manifest-src 'self'");
}

/* ============================================================
   2. SESSION SÉCURISÉE
   ============================================================ */
function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.gc_maxlifetime', '1800');

    session_name('ATF_SESSION');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();

    /* Régénération périodique contre la fixation de session */
    if (!isset($_SESSION['_created'])) {
        $_SESSION['_created'] = time();
    } elseif (time() - $_SESSION['_created'] > 900) {
        session_regenerate_id(true);
        $_SESSION['_created'] = time();
    }

    /* Vérification empreinte navigateur */
    $fp = hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? '') . '|' . ($_SERVER['REMOTE_ADDR'] ?? ''));
    if (!isset($_SESSION['_fp'])) {
        $_SESSION['_fp'] = $fp;
    } elseif (!hash_equals($_SESSION['_fp'], $fp)) {
        session_unset();
        session_destroy();
        header('Location: ' . (defined('BASE_URL') ? BASE_URL : '') . '/auth/connexion_client.php?err=session');
        exit;
    }

    /* Expiration par inactivité (30 min) */
    if (isset($_SESSION['_last'])) {
        if (time() - $_SESSION['_last'] > 1800) {
            session_unset();
            session_destroy();
            header('Location: ' . (defined('BASE_URL') ? BASE_URL : '') . '/auth/connexion_client.php?err=timeout');
            exit;
        }
    }
    $_SESSION['_last'] = time();
}

/* ============================================================
   3. CSRF TOKEN
   ============================================================ */
function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrfField(): string
{
    return '<input type="hidden" name="csrf" value="' . csrfToken() . '">';
}

function verifyCsrf(?string $token): bool
{
    return isset($_SESSION['csrf'], $token) && hash_equals($_SESSION['csrf'], $token);
}

/* ============================================================
   4. NETTOYAGE / VALIDATION
   ============================================================ */
function sanitize(string $data): string
{
    return htmlspecialchars(trim($data), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function cleanString(string $s, int $max = 255): string
{
    $s = preg_replace('/[\x00-\x1F\x7F]/u', '', $s);
    return mb_substr(trim($s), 0, $max);
}

/* ============================================================
   5. VALIDATION MOT DE PASSE
   ============================================================ */
function validerMotDePasse(string $pwd): bool
{
    return (bool) preg_match(
        '/^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[\W_])[A-Za-z\d\W_]{8,}$/',
        $pwd
    );
}

/* ============================================================
   6. ANTI BRUTE-FORCE
   ============================================================ */
function recordFailedAttempt(PDO $pdo, string $table, string $idCol, int $id): void
{
    $stmt = $pdo->prepare(
        "UPDATE $table
         SET failed_attempts = failed_attempts + 1,
             locked_until = IF(failed_attempts + 1 >= 5, DATE_ADD(NOW(), INTERVAL 15 MINUTE), locked_until)
         WHERE $idCol = ?"
    );
    $stmt->execute([$id]);
}

function isLocked(?string $lockedUntil): bool
{
    return $lockedUntil !== null && strtotime($lockedUntil) > time();
}

/* ============================================================
   7. AUDIT LOGS
   ============================================================ */
function logAction(PDO $pdo, string $userType, ?int $userId, string $action, string $details = ''): void
{
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO Audit_Logs (user_type, user_id, action, details, ip_address, user_agent)
             VALUES (?,?,?,?,?,?)"
        );
        $stmt->execute([
            $userType,
            $userId,
            $action,
            mb_substr($details, 0, 1000),
            $_SERVER['REMOTE_ADDR'] ?? null,
            mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)
        ]);
    } catch (Throwable $e) {
        error_log('[ATF LOG] ' . $e->getMessage());
    }
}

/* ============================================================
   8. UPLOAD SÉCURISÉ
   ============================================================ */
function handleSecureUpload(string $field, string $prefix, string $uploadDir): ?string
{
    if (empty($_FILES[$field]['name']) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException("Erreur upload ($field).");
    }
    if ($f['size'] > 3 * 1024 * 1024) {
        throw new RuntimeException("Fichier trop volumineux ($field) — max 3 Mo.");
    }

    $allowedMimes = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
        'application/pdf' => 'pdf',
    ];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($f['tmp_name']);

    if (!isset($allowedMimes[$mime])) {
        throw new RuntimeException("Type de fichier non autorisé ($field).");
    }

    /* Vérification image valide */
    if (str_starts_with($mime, 'image/') && @getimagesize($f['tmp_name']) === false) {
        throw new RuntimeException("Image corrompue ($field).");
    }

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
        throw new RuntimeException("Impossible de créer le dossier uploads.");
    }

    $name = $prefix . bin2hex(random_bytes(12)) . '.' . $allowedMimes[$mime];
    $dest = rtrim($uploadDir, '/') . '/' . $name;

    if (!move_uploaded_file($f['tmp_name'], $dest)) {
        throw new RuntimeException("Échec du déplacement ($field).");
    }
    @chmod($dest, 0644);

    return 'uploads/' . $name;
}

/* ============================================================
   9. GÉNÉRATION NUMÉRO DE COMPTE
   ============================================================ */
function genererNumeroCompte(PDO $pdo): string
{
    for ($i = 0; $i < 10; $i++) {
        $numero = 'ATF-' . date('Y') . '-' . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare("SELECT 1 FROM Client WHERE numero_compte = ? LIMIT 1");
        $stmt->execute([$numero]);
        if (!$stmt->fetchColumn()) return $numero;
    }
    throw new RuntimeException("Impossible de générer un numéro de compte.");
}

/* ============================================================
   10. GÉNÉRATION RÉFÉRENCE TRANSACTION
   ============================================================ */
function genererReference(): string
{
    return 'TRX-' . strtoupper(bin2hex(random_bytes(6))) . '-' . time();
}

/* ============================================================
   11. HELPER : Obtenir le nonce CSP pour les scripts inline
   (Alternative sécurisée si vous voulez retirer 'unsafe-inline')
   ============================================================ */
function scriptNonce(): string
{
    if (empty($_SESSION['csp_nonce'])) {
        $_SESSION['csp_nonce'] = base64_encode(random_bytes(16));
    }
    return $_SESSION['csp_nonce'];
}