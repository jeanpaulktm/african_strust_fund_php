<?php
declare(strict_types=1);

/**
 * ============================================================
 * GUARD AUTHENTIFICATION — ENTREPRISES
 * Vérifie que l'utilisateur connecté est une ENTREPRISE
 * ============================================================
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* Vérifier la connexion */
if (empty($_SESSION['entreprise_id']) || empty($_SESSION['entreprise_role'])) {
    $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '';
    header('Location: /african-trust-fund/entreprise/login.php');
    exit;
}

/* Vérifier le rôle */
if ($_SESSION['entreprise_role'] !== 'entreprise') {
    session_destroy();
    header('Location: /african-trust-fund/entreprise/login.php');
    exit;
}

/* Vérifier la session expirée (2 heures) */
$timeout = 7200;
if (isset($_SESSION['entreprise_last_activity'])) {
    if (time() - $_SESSION['entreprise_last_activity'] > $timeout) {
        session_destroy();
        header('Location: /african-trust-fund/entreprise/login.php?expired=1');
        exit;
    }
}
$_SESSION['entreprise_last_activity'] = time();

/* Charger la classe Database */
require_once __DIR__ . '/database.php';

/* Vérifier que l'entreprise existe toujours en base */
try {
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare("
        SELECT id_client, statut, type_client 
        FROM Client 
        WHERE id_client = ? AND type_client = 'morale'
        LIMIT 1
    ");
    $stmt->execute([(int)$_SESSION['entreprise_id']]);
    $entreprise = $stmt->fetch();
    
    if (!$entreprise) {
        session_destroy();
        header('Location: /african-trust-fund/entreprise/login.php');
        exit;
    }
    
    if ($entreprise['statut'] !== 'actif') {
        $_SESSION['entreprise_suspended'] = true;
        session_destroy();
        header('Location: /african-trust-fund/entreprise/login.php?suspended=1');
        exit;
    }
} catch (Throwable $e) {
    // En cas d'erreur DB, on continue mais on log
    error_log('[ATF ENTREPRISE GUARD] ' . $e->getMessage());
}
?>