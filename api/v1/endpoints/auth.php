<?php
declare(strict_types=1);

/**
 * ============================================================
 * ENDPOINT: AUTHENTIFICATION CLIENT
 * ============================================================
 * POST   /api/v1/auth/login         - Connexion client
 * POST   /api/v1/auth/register      - Inscription client
 * POST   /api/v1/auth/logout        - Déconnexion
 * POST   /api/v1/auth/refresh       - Renouveler token
 * GET    /api/v1/auth/profile       - Profil actualisé
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/security.php';
require_once __DIR__ . '/../v1/config.php';

$request_method = $_SERVER['REQUEST_METHOD'];
$request_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$request_path = str_replace('/african-trust-fund/api/v1/auth/', '', $request_path);

try {
    $pdo = Database::getConnection();

    // ============================================================
    // POST /api/v1/auth/login
    // ============================================================
    if ($request_path === 'login' && $request_method === 'POST') {
        $body = getJsonBody();

        if (!$body || !validateRequired($body, ['telephone', 'password'])) {
            ApiResponse::error('Téléphone et mot de passe requis', 400);
        }

        $telephone = trim($body['telephone']);
        $password = trim($body['password']);

        if (!validatePhone($telephone)) {
            ApiResponse::error('Format de téléphone invalide', 400);
        }

        // Vérifier le client
        $stmt = $pdo->prepare("
            SELECT id_client, nom, prenom, telephone, email, password_hash, statut, photo_profil
            FROM Client 
            WHERE telephone = ? AND statut = 'actif'
        ");
        $stmt->execute([$telephone]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$client || !password_verify($password, $client['password_hash'])) {
            ApiResponse::error('Identifiants invalides', 401);
        }

        // Générer le token
        $token = ApiAuth::generateToken($client['id_client']);

        // Récupérer les soldes
        $stmt = $pdo->prepare("
            SELECT solde_cdf, solde_usd, solde_epargne_cdf, solde_epargne_usd
            FROM Client 
            WHERE id_client = ?
        ");
        $stmt->execute([$client['id_client']]);
        $soldes = $stmt->fetch(PDO::FETCH_ASSOC);

        $response = [
            'client_id' => $client['id_client'],
            'nom_complet' => "{$client['prenom']} {$client['nom']}",
            'telephone' => $client['telephone'],
            'email' => $client['email'],
            'photo_profil' => $client['photo_profil'],
            'token' => $token,
            'soldes' => [
                'cdf' => (float)$soldes['solde_cdf'],
                'usd' => (float)$soldes['solde_usd'],
                'epargne_cdf' => (float)$soldes['solde_epargne_cdf'],
                'epargne_usd' => (float)$soldes['solde_epargne_usd'],
            ],
            'expires_in' => 86400, // 24 heures
        ];

        ApiResponse::success($response, 'Connexion réussie', 200);
    }

    // ============================================================
    // POST /api/v1/auth/register
    // ============================================================
    else if ($request_path === 'register' && $request_method === 'POST') {
        $body = getJsonBody();

        $required = ['prenom', 'nom', 'telephone', 'email', 'password'];
        if (!$body || !validateRequired($body, $required)) {
            ApiResponse::error('Tous les champs sont requis', 400);
        }

        $prenom = trim($body['prenom']);
        $nom = trim($body['nom']);
        $telephone = trim($body['telephone']);
        $email = trim($body['email']);
        $password = trim($body['password']);

        // Validations
        if (strlen($prenom) < 2 || strlen($nom) < 2) {
            ApiResponse::error('Prénom et nom doivent contenir au moins 2 caractères', 400);
        }
        if (!validatePhone($telephone)) {
            ApiResponse::error('Format de téléphone invalide', 400);
        }
        if (!validateEmail($email)) {
            ApiResponse::error('Format d\'email invalide', 400);
        }
        if (strlen($password) < 8) {
            ApiResponse::error('Le mot de passe doit contenir au moins 8 caractères', 400);
        }

        // Vérifier l'unicité
        $stmt = $pdo->prepare("SELECT id_client FROM Client WHERE telephone = ? OR email = ?");
        $stmt->execute([$telephone, $email]);
        if ($stmt->fetch()) {
            ApiResponse::error('Ce téléphone ou email est déjà utilisé', 409);
        }

        // Créer le client
        try {
            $pdo->beginTransaction();

            $password_hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("
                INSERT INTO Client (prenom, nom, telephone, email, password_hash, statut, created_at)
                VALUES (?, ?, ?, ?, ?, 'actif', NOW())
            ");
            $stmt->execute([$prenom, $nom, $telephone, $email, $password_hash]);
            $client_id = (int)$pdo->lastInsertId();

            $pdo->commit();

            $token = ApiAuth::generateToken($client_id);

            $response = [
                'client_id' => $client_id,
                'nom_complet' => "{$prenom} {$nom}",
                'telephone' => $telephone,
                'email' => $email,
                'token' => $token,
                'message' => 'Compte créé avec succès',
            ];

            ApiResponse::success($response, 'Inscription réussie', 201);
        } catch (Exception $e) {
            $pdo->rollBack();
            ApiResponse::error('Erreur lors de l\'inscription: ' . $e->getMessage(), 500);
        }
    }

    // ============================================================
    // POST /api/v1/auth/logout
    // ============================================================
    else if ($request_path === 'logout' && $request_method === 'POST') {
        $token = ApiAuth::getTokenFromHeader();
        if (!$token || !$auth_data = ApiAuth::validateToken($token)) {
            ApiResponse::error('Token invalide', 401);
        }

        // Ici on pourrait ajouter le token dans une liste noire
        ApiResponse::success(null, 'Déconnexion réussie');
    }

    // ============================================================
    // POST /api/v1/auth/refresh
    // ============================================================
    else if ($request_path === 'refresh' && $request_method === 'POST') {
        $token = ApiAuth::getTokenFromHeader();
        if (!$token || !$auth_data = ApiAuth::validateToken($token)) {
            ApiResponse::error('Token invalide', 401);
        }

        $client_id = $auth_data['client_id'];

        // Vérifier que le client existe toujours
        $stmt = $pdo->prepare("SELECT id_client FROM Client WHERE id_client = ? AND statut = 'actif'");
        $stmt->execute([$client_id]);
        if (!$stmt->fetch()) {
            ApiResponse::error('Client introuvable ou inactif', 404);
        }

        $new_token = ApiAuth::generateToken($client_id);
        $response = [
            'token' => $new_token,
            'expires_in' => 86400,
        ];

        ApiResponse::success($response, 'Token renouvelé');
    }

    // ============================================================
    // GET /api/v1/auth/profile
    // ============================================================
    else if ($request_path === 'profile' && $request_method === 'GET') {
        $token = ApiAuth::getTokenFromHeader();
        if (!$token || !$auth_data = ApiAuth::validateToken($token)) {
            ApiResponse::error('Token invalide', 401);
        }

        $client_id = $auth_data['client_id'];

        $stmt = $pdo->prepare("
            SELECT 
                id_client,
                prenom,
                nom,
                telephone,
                email,
                photo_profil,
                date_naissance,
                profession,
                adresse,
                ville,
                pays,
                document_type,
                document_numero,
                document_date_expiration,
                statut,
                created_at,
                solde_cdf,
                solde_usd,
                solde_epargne_cdf,
                solde_epargne_usd
            FROM Client 
            WHERE id_client = ?
        ");
        $stmt->execute([$client_id]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$client) {
            ApiResponse::error('Client introuvable', 404);
        }

        $response = [
            'id' => $client['id_client'],
            'nom_complet' => "{$client['prenom']} {$client['nom']}",
            'prenom' => $client['prenom'],
            'nom' => $client['nom'],
            'telephone' => $client['telephone'],
            'email' => $client['email'],
            'photo_profil' => $client['photo_profil'],
            'date_naissance' => $client['date_naissance'],
            'profession' => $client['profession'],
            'adresse' => $client['adresse'],
            'ville' => $client['ville'],
            'pays' => $client['pays'],
            'document' => [
                'type' => $client['document_type'],
                'numero' => $client['document_numero'],
                'date_expiration' => $client['document_date_expiration'],
            ],
            'statut' => $client['statut'],
            'soldes' => [
                'cdf' => (float)$client['solde_cdf'],
                'usd' => (float)$client['solde_usd'],
                'epargne_cdf' => (float)$client['solde_epargne_cdf'],
                'epargne_usd' => (float)$client['solde_epargne_usd'],
            ],
            'created_at' => $client['created_at'],
        ];

        ApiResponse::success($response, 'Profil récupéré');
    }

    else {
        ApiResponse::error('Endpoint non trouvé', 404);
    }

} catch (PDOException $e) {
    error_log('DB Error: ' . $e->getMessage());
    ApiResponse::error('Erreur base de données', 500);
} catch (Exception $e) {
    error_log('Error: ' . $e->getMessage());
    ApiResponse::error('Erreur serveur', 500);
}
?>
