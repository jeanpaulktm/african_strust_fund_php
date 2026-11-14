<?php
declare(strict_types=1);

/**
 * ============================================================
 * ENDPOINT: PROFIL CLIENT
 * ============================================================
 * GET    /api/v1/profil         - Récupérer le profil
 * PUT    /api/v1/profil         - Mettre à jour le profil
 * POST   /api/v1/profil/avatar  - Uploader un avatar
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../v1/config.php';

$request_method = $_SERVER['REQUEST_METHOD'];
$request_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$request_path = str_replace('/african-trust-fund/api/v1/profil', '', $request_path);

try {
    $token = ApiAuth::getTokenFromHeader();
    if (!$token || !$auth_data = ApiAuth::validateToken($token)) {
        ApiResponse::error('Token invalide', 401);
    }

    $client_id = $auth_data['client_id'];
    $pdo = Database::getConnection();

    // ============================================================
    // GET /api/v1/profil
    // ============================================================
    if ($request_method === 'GET' && $request_path === '') {
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
                updated_at
            FROM Client 
            WHERE id_client = ?
        ");
        $stmt->execute([$client_id]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$client) {
            ApiResponse::error('Client introuvable', 404);
        }

        $response = [
            'id' => (int)$client['id_client'],
            'nom_complet' => "{$client['prenom']} {$client['nom']}",
            'prenom' => $client['prenom'],
            'nom' => $client['nom'],
            'telephone' => $client['telephone'],
            'email' => $client['email'],
            'photo_profil' => $client['photo_profil'],
            'informations_personnelles' => [
                'date_naissance' => $client['date_naissance'],
                'profession' => $client['profession'],
                'adresse' => $client['adresse'],
                'ville' => $client['ville'],
                'pays' => $client['pays'],
            ],
            'document' => [
                'type' => $client['document_type'],
                'numero' => $client['document_numero'],
                'date_expiration' => $client['document_date_expiration'],
            ],
            'statut' => $client['statut'],
            'dates' => [
                'creation' => $client['created_at'],
                'modification' => $client['updated_at'],
            ],
        ];

        ApiResponse::success($response, 'Profil récupéré');
    }

    // ============================================================
    // PUT /api/v1/profil
    // ============================================================
    else if ($request_method === 'PUT' && $request_path === '') {
        $body = getJsonBody();

        if (!$body) {
            ApiResponse::error('Données requis', 400);
        }

        // Champs autorisés à modifier
        $allowed_fields = [
            'email' => 'email',
            'prenom' => 'prenom',
            'nom' => 'nom',
            'date_naissance' => 'date_naissance',
            'profession' => 'profession',
            'adresse' => 'adresse',
            'ville' => 'ville',
            'pays' => 'pays',
        ];

        $updates = [];
        $params = [];

        foreach ($allowed_fields as $field => $db_field) {
            if (isset($body[$field])) {
                $value = trim($body[$field]);

                // Validations
                if ($field === 'email' && !validateEmail($value)) {
                    ApiResponse::error('Email invalide', 400);
                }
                if ($field === 'prenom' && strlen($value) < 2) {
                    ApiResponse::error('Prénom invalide', 400);
                }
                if ($field === 'nom' && strlen($value) < 2) {
                    ApiResponse::error('Nom invalide', 400);
                }

                $updates[] = "$db_field = ?";
                $params[] = $value;
            }
        }

        if (empty($updates)) {
            ApiResponse::error('Aucun champ à mettre à jour', 400);
        }

        try {
            $pdo->beginTransaction();

            $params[] = $client_id;
            $sql = "UPDATE Client SET " . implode(', ', $updates) . ", updated_at = NOW() WHERE id_client = ?";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $pdo->commit();

            ApiResponse::success(null, 'Profil mis à jour');
        } catch (Exception $e) {
            $pdo->rollBack();
            ApiResponse::error('Erreur: ' . $e->getMessage(), 500);
        }
    }

    // ============================================================
    // POST /api/v1/profil/avatar
    // ============================================================
    else if ($request_method === 'POST' && $request_path === '/avatar') {
        if (!isset($_FILES['avatar'])) {
            ApiResponse::error('Fichier avatar requis', 400);
        }

        $file = $_FILES['avatar'];
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        $max_size = 5 * 1024 * 1024; // 5MB

        if ($file['error'] !== UPLOAD_ERR_OK) {
            ApiResponse::error('Erreur lors du upload', 400);
        }

        if (!in_array($file['type'], $allowed_types)) {
            ApiResponse::error('Format d\'image invalide', 400);
        }

        if ($file['size'] > $max_size) {
            ApiResponse::error('Fichier trop volumineux (max 5MB)', 400);
        }

        try {
            // Créer le dossier s'il n'existe pas
            $upload_dir = __DIR__ . '/../../uploads';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }

            // Générer un nom unique
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'pp_' . bin2hex(random_bytes(12)) . '.' . $ext;
            $filepath = $upload_dir . '/' . $filename;

            // Uploader le fichier
            if (!move_uploaded_file($file['tmp_name'], $filepath)) {
                ApiResponse::error('Erreur lors de la sauvegarde', 500);
            }

            // Mettre à jour la BDD
            $url_photo = '/african-trust-fund/uploads/' . $filename;
            $stmt = $pdo->prepare("UPDATE Client SET photo_profil = ?, updated_at = NOW() WHERE id_client = ?");
            $stmt->execute([$url_photo, $client_id]);

            $response = [
                'photo_profil' => $url_photo,
                'filename' => $filename,
            ];

            ApiResponse::success($response, 'Avatar uploadé');
        } catch (Exception $e) {
            ApiResponse::error('Erreur: ' . $e->getMessage(), 500);
        }
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
