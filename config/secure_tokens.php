<?php
declare(strict_types=1);

/**
 * ============================================================
 * SYSTÈME DE TOKENS SÉCURISÉS (one-shot)
 * Remplace les IDs dans les URL par des tokens éphémères
 * ============================================================
 * 
 * Types supportés :
 * - client, caissier, actionnaire
 * - produit, placement, transaction, log
 * - dividende, part, carte, recu
 * - pret, echeance, entreprise
 */

/* ============================================================
   CONFIGURATION
   ============================================================ */
const TOKEN_LIFETIME = 300;  // 5 minutes

/* Préfixes de session par type de ressource */
const TOKEN_PREFIX = [
    'client'      => 'tok_client_',
    'caissier'    => 'tok_caissier_',
    'actionnaire' => 'tok_actionnaire_',
    'produit'     => 'tok_produit_',
    'placement'   => 'tok_placement_',
    'transaction' => 'tok_transaction_',
    'log'         => 'tok_log_',
    'dividende'   => 'tok_dividende_',
    'part'        => 'tok_part_',
    'carte'       => 'tok_carte_',
    'recu'        => 'tok_recu_',
    'pret'        => 'tok_pret_',
    'echeance'    => 'tok_echeance_',
    'entreprise'  => 'tok_entreprise_',
];

/* ============================================================
   DÉTECTION DE L'UTILISATEUR CONNECTÉ (multi-rôles)
   ============================================================ */
function _detecterUtilisateurCourant(): array
{
    if (!empty($_SESSION['admin_id'])) {
        return [(int)$_SESSION['admin_id'], 'admin'];
    }
    if (!empty($_SESSION['client_id'])) {
        return [(int)$_SESSION['client_id'], 'client'];
    }
    if (!empty($_SESSION['caissier_id'])) {
        return [(int)$_SESSION['caissier_id'], 'caissier'];
    }
    if (!empty($_SESSION['actionnaire_id'])) {
        return [(int)$_SESSION['actionnaire_id'], 'actionnaire'];
    }
    return [0, 'guest'];
}

/* ============================================================
   GÉNÉRATION DE TOKEN
   ============================================================ */
function genererToken(string $type, int $id, array $extra = []): string
{
    if (!isset(TOKEN_PREFIX[$type])) {
        throw new InvalidArgumentException("Type de token inconnu : $type");
    }

    $cle = TOKEN_PREFIX[$type];
    if (empty($_SESSION[$cle])) {
        $_SESSION[$cle] = [];
    }

    // Nettoyage des tokens expirés
    foreach ($_SESSION[$cle] as $tk => $data) {
        if ($data['expires'] < time()) {
            unset($_SESSION[$cle][$tk]);
        }
    }

    // Détection de l'utilisateur courant
    [$user_id, $user_role] = _detecterUtilisateurCourant();

    // Génération
    $token = bin2hex(random_bytes(24));
    $_SESSION[$cle][$token] = array_merge([
        'id'        => $id,
        'expires'   => time() + TOKEN_LIFETIME,
        'user_id'   => $user_id,
        'user_role' => $user_role,
        'ip'        => $_SERVER['REMOTE_ADDR'] ?? '',
        'created'   => time(),
    ], $extra);

    return $token;
}

/* ============================================================
   CONSOMMATION DE TOKEN (one-shot)
   ============================================================ */
function consommerToken(string $type, string $token, array $options = []): ?array
{
    if (!isset(TOKEN_PREFIX[$type])) return null;

    $cle = TOKEN_PREFIX[$type];
    if (empty($_SESSION[$cle][$token])) return null;

    $data = $_SESSION[$cle][$token];
    
    /* Ne pas consommer si keep = true */
    $keep = !empty($options['keep']);
    if (!$keep) {
        unset($_SESSION[$cle][$token]); // one-shot
    }

    // Vérification expiration
    if ($data['expires'] < time()) {
        if (!$keep) unset($_SESSION[$cle][$token]);
        return null;
    }

    // Détection de l'utilisateur courant
    [$current_user, $current_role] = _detecterUtilisateurCourant();

    // Vérification du propriétaire (si connecté)
    if ($current_user && (int)$data['user_id'] !== $current_user) {
        // Log de sécurité
        if (class_exists('Database')) {
            try {
                $pdo = Database::getConnection();
                if (function_exists('logAction')) {
                    logAction($pdo, $data['user_role'], $current_user, 'SECURITY_ALERT',
                        "Token volé : type=$type de user_id={$data['user_id']} par user_id=$current_user");
                }
            } catch (Throwable $e) { /* silencieux */ }
        }
        return null;
    }

    // Vérification IP (protection renforcée)
    if (!empty($data['ip']) && $data['ip'] !== ($_SERVER['REMOTE_ADDR'] ?? '')) {
        return null;
    }

    return $data;
}

/* ============================================================
   RÉCUPÉRATION SÉCURISÉE D'ID
   ============================================================ */
function recupererIdSecurise(string $type): ?int
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['token'])) {
        if (function_exists('verifyCsrf') && !verifyCsrf($_POST['csrf'] ?? null)) {
            return null;
        }
        $data = consommerToken($type, $_POST['token']);
        return $data ? (int)$data['id'] : null;
    }

    $session_key = "one_shot_{$type}_id";
    if (!empty($_SESSION[$session_key])) {
        $id = (int)$_SESSION[$session_key];
        unset($_SESSION[$session_key]);
        return $id;
    }

    return null;
}

/* ============================================================
   REDIRECTION SÉCURISÉE
   ============================================================ */
function redirigerSecurise(string $type, int $id, string $url): void
{
    $_SESSION["one_shot_{$type}_id"] = $id;
    header('Location: ' . $url);
    exit;
}

/* ============================================================
   REFUS D'ACCÈS (403)
   ============================================================ */
function refuserAcces(string $message = "Accès refusé."): void
{
    http_response_code(403);
    $base = defined('BASE_URL') ? BASE_URL : '/african-trust-fund';
    ?>
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>Accès refusé — ATF</title>
        <link rel="stylesheet" href="<?= htmlspecialchars($base) ?>/assets/css/style.css">
        <style>
            body {
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
                padding: 20px;
                background: radial-gradient(circle at 50% 0%, #1E293B 0%, #0B0E14 70%);
            }
            .refus-box {
                text-align: center;
                max-width: 520px;
                padding: 40px 32px;
                background: linear-gradient(145deg, #1A2130, #121824);
                border-radius: 20px;
                border: 1px solid rgba(239,68,68,.3);
                box-shadow: 0 30px 80px rgba(0,0,0,.6);
            }
            .refus-box .icon { font-size: 5rem; margin-bottom: 16px; }
            .refus-box h1 { color: #FCA5A5; font-size: 1.6rem; margin-bottom: 16px; font-weight: 800; }
            .refus-box p { color: #94A3B8; margin-bottom: 28px; line-height: 1.6; }
            .refus-box .btn-gold {
                display: inline-block;
                padding: 14px 28px;
                background: linear-gradient(135deg, #F5B800, #D4AF37);
                color: #0B0E14;
                text-decoration: none;
                border-radius: 999px;
                font-weight: 800;
                transition: transform .25s;
            }
            .refus-box .btn-gold:hover { transform: translateY(-2px); }
        </style>
    </head>
    <body class="dark-auth">
      <div class="refus-box">
        <div class="icon">🚫</div>
        <h1>Accès refusé</h1>
        <p><?= htmlspecialchars($message) ?></p>
        <a class="btn-gold" href="<?= htmlspecialchars($base) ?>/index.php">← Retour à l'accueil</a>
      </div>
    </body>
    </html>
    <?php
    exit;
}

/* ============================================================
   EXTENSION : Tokens pour opérations caissier
   ============================================================ */
function genererTokenOperation(int $id_client, string $type_op, float $montant_max = 0): string
{
    $token = bin2hex(random_bytes(24));

    if (empty($_SESSION['cashier_op_tokens'])) {
        $_SESSION['cashier_op_tokens'] = [];
    }

    foreach ($_SESSION['cashier_op_tokens'] as $tk => $d) {
        if ($d['expires'] < time()) unset($_SESSION['cashier_op_tokens'][$tk]);
    }

    $_SESSION['cashier_op_tokens'][$token] = [
        'id_client'    => $id_client,
        'type_op'      => $type_op,
        'montant_max'  => $montant_max,
        'caissier_id'  => (int)($_SESSION['caissier_id'] ?? 0),
        'expires'      => time() + 180,
        'ip'           => $_SERVER['REMOTE_ADDR'] ?? '',
    ];

    return $token;
}

function consommerTokenOperation(string $token): ?array
{
    if (empty($_SESSION['cashier_op_tokens'][$token])) return null;

    $data = $_SESSION['cashier_op_tokens'][$token];
    unset($_SESSION['cashier_op_tokens'][$token]);

    if ($data['expires'] < time()) return null;

    $current_caissier = (int)($_SESSION['caissier_id'] ?? 0);
    if ($current_caissier && $data['caissier_id'] !== $current_caissier) {
        try {
            if (class_exists('Database')) {
                $pdo = Database::getConnection();
                if (function_exists('logAction')) {
                    logAction($pdo, 'caissier', $current_caissier, 'SECURITY_ALERT',
                        "Token opération volé : id_client={$data['id_client']}, type={$data['type_op']}");
                }
            }
        } catch (Throwable $e) { /* */ }
        return null;
    }

    if (!empty($data['ip']) && $data['ip'] !== ($_SERVER['REMOTE_ADDR'] ?? '')) {
        return null;
    }

    return $data;
}
?>