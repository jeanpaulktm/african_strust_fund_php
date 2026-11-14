<?php
declare(strict_types=1);

/**
 * ============================================================
 * ENDPOINT: TRANSACTIONS CLIENT
 * ============================================================
 * GET    /api/v1/transactions                      - Liste paginée
 * GET    /api/v1/transactions/{id}                 - Détails
 * POST   /api/v1/transactions/depot                - Faire un dépôt
 * POST   /api/v1/transactions/retrait              - Faire un retrait
 * POST   /api/v1/transactions/virement             - Faire un virement
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../v1/config.php';

$request_method = $_SERVER['REQUEST_METHOD'];
$request_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$request_path = str_replace('/african-trust-fund/api/v1/transactions', '', $request_path);

try {
    $token = ApiAuth::getTokenFromHeader();
    if (!$token || !$auth_data = ApiAuth::validateToken($token)) {
        ApiResponse::error('Token invalide', 401);
    }

    $client_id = $auth_data['client_id'];
    $pdo = Database::getConnection();

    // ============================================================
    // GET /api/v1/transactions - Liste paginée
    // ============================================================
    if ($request_method === 'GET' && $request_path === '') {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $per_page = min(50, max(5, (int)($_GET['per_page'] ?? 10)));
        $offset = ($page - 1) * $per_page;
        $type = $_GET['type'] ?? '';
        $statut = $_GET['statut'] ?? '';

        $where = "WHERE id_client = ?";
        $params = [$client_id];

        if ($type && in_array($type, ['Depot', 'Retrait', 'Virement'])) {
            $where .= " AND type_transaction = ?";
            $params[] = $type;
        }

        if ($statut && in_array($statut, ['en_attente', 'approuve', 'rejete'])) {
            $where .= " AND statut = ?";
            $params[] = $statut;
        }

        // Compter
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM Transactions $where");
        $stmt->execute($params);
        $total = (int)$stmt->fetch(PDO::FETCH_ASSOC)['total'];

        // Récupérer
        $sql = "
            SELECT 
                id_transaction,
                type_transaction,
                montant,
                devise,
                mode,
                statut,
                description,
                date_transaction
            FROM Transactions 
            $where
            ORDER BY date_transaction DESC
            LIMIT ? OFFSET ?
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($params, [$per_page, $offset]));
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $data = array_map(function($t) {
            return [
                'id' => (int)$t['id_transaction'],
                'type' => $t['type_transaction'],
                'montant' => (float)$t['montant'],
                'devise' => $t['devise'],
                'mode' => $t['mode'],
                'statut' => $t['statut'],
                'description' => $t['description'],
                'date' => $t['date_transaction'],
            ];
        }, $transactions);

        ApiResponse::paginated($data, $page, $per_page, $total);
    }

    // ============================================================
    // GET /api/v1/transactions/{id}
    // ============================================================
    else if ($request_method === 'GET' && preg_match('/^\/(\d+)$/', $request_path, $matches)) {
        $transaction_id = (int)$matches[1];

        $stmt = $pdo->prepare("
            SELECT 
                id_transaction,
                type_transaction,
                montant,
                devise,
                mode,
                statut,
                description,
                date_transaction,
                reference
            FROM Transactions 
            WHERE id_transaction = ? AND id_client = ?
        ");
        $stmt->execute([$transaction_id, $client_id]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$transaction) {
            ApiResponse::error('Transaction introuvable', 404);
        }

        $response = [
            'id' => (int)$transaction['id_transaction'],
            'type' => $transaction['type_transaction'],
            'montant' => (float)$transaction['montant'],
            'devise' => $transaction['devise'],
            'mode' => $transaction['mode'],
            'statut' => $transaction['statut'],
            'description' => $transaction['description'],
            'reference' => $transaction['reference'],
            'date' => $transaction['date_transaction'],
        ];

        ApiResponse::success($response, 'Transaction récupérée');
    }

    // ============================================================
    // POST /api/v1/transactions/depot
    // ============================================================
    else if ($request_method === 'POST' && $request_path === '/depot') {
        $body = getJsonBody();

        if (!$body || !validateRequired($body, ['montant', 'devise'])) {
            ApiResponse::error('Montant et devise requis', 400);
        }

        $montant = (float)$body['montant'];
        $devise = strtoupper(trim($body['devise']));
        $mode = $_GET['mode'] ?? 'En ligne';

        if (!validateAmount($montant)) {
            ApiResponse::error('Montant invalide', 400);
        }
        if (!in_array($devise, ['CDF', 'USD'])) {
            ApiResponse::error('Devise invalide', 400);
        }
        if (!in_array($mode, ['En ligne', 'Agence'])) {
            ApiResponse::error('Mode invalide', 400);
        }

        try {
            $pdo->beginTransaction();

            // Créer la transaction
            $stmt = $pdo->prepare("
                INSERT INTO Transactions 
                (id_client, type_transaction, montant, devise, mode, statut, description, date_transaction)
                VALUES (?, 'Depot', ?, ?, ?, 'en_attente', 'Dépôt de fonds', NOW())
            ");
            $stmt->execute([$client_id, $montant, $devise, $mode]);
            $transaction_id = (int)$pdo->lastInsertId();

            $pdo->commit();

            $response = [
                'id' => $transaction_id,
                'montant' => $montant,
                'devise' => $devise,
                'statut' => 'en_attente',
                'message' => 'Dépôt en cours de traitement',
            ];

            ApiResponse::success($response, 'Dépôt créé', 201);
        } catch (Exception $e) {
            $pdo->rollBack();
            ApiResponse::error('Erreur: ' . $e->getMessage(), 500);
        }
    }

    // ============================================================
    // POST /api/v1/transactions/retrait
    // ============================================================
    else if ($request_method === 'POST' && $request_path === '/retrait') {
        $body = getJsonBody();

        if (!$body || !validateRequired($body, ['montant', 'devise'])) {
            ApiResponse::error('Montant et devise requis', 400);
        }

        $montant = (float)$body['montant'];
        $devise = strtoupper(trim($body['devise']));
        $mode = $body['mode'] ?? 'En ligne';

        if (!validateAmount($montant)) {
            ApiResponse::error('Montant invalide', 400);
        }
        if (!in_array($devise, ['CDF', 'USD'])) {
            ApiResponse::error('Devise invalide', 400);
        }

        // Vérifier le solde
        $champ_solde = "solde_" . strtolower($devise);
        $stmt = $pdo->prepare("SELECT $champ_solde FROM Client WHERE id_client = ?");
        $stmt->execute([$client_id]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);

        $solde = (float)$client[$champ_solde];
        if ($montant > $solde) {
            ApiResponse::error('Solde insuffisant', 400);
        }

        try {
            $pdo->beginTransaction();

            // Créer la transaction
            $stmt = $pdo->prepare("
                INSERT INTO Transactions 
                (id_client, type_transaction, montant, devise, mode, statut, description, date_transaction)
                VALUES (?, 'Retrait', ?, ?, ?, 'en_attente', 'Retrait de fonds', NOW())
            ");
            $stmt->execute([$client_id, $montant, $devise, $mode]);
            $transaction_id = (int)$pdo->lastInsertId();

            // Mettre à jour le solde
            $nouveau_solde = $solde - $montant;
            $stmt = $pdo->prepare("UPDATE Client SET $champ_solde = ? WHERE id_client = ?");
            $stmt->execute([$nouveau_solde, $client_id]);

            $pdo->commit();

            $response = [
                'id' => $transaction_id,
                'montant' => $montant,
                'devise' => $devise,
                'nouveau_solde' => $nouveau_solde,
                'statut' => 'en_attente',
            ];

            ApiResponse::success($response, 'Retrait créé', 201);
        } catch (Exception $e) {
            $pdo->rollBack();
            ApiResponse::error('Erreur: ' . $e->getMessage(), 500);
        }
    }

    // ============================================================
    // POST /api/v1/transactions/virement
    // ============================================================
    else if ($request_method === 'POST' && $request_path === '/virement') {
        $body = getJsonBody();

        if (!$body || !validateRequired($body, ['montant', 'devise', 'telephone_beneficiaire'])) {
            ApiResponse::error('Montant, devise et numéro bénéficiaire requis', 400);
        }

        $montant = (float)$body['montant'];
        $devise = strtoupper(trim($body['devise']));
        $telephone_beneficiaire = trim($body['telephone_beneficiaire']);

        if (!validateAmount($montant)) {
            ApiResponse::error('Montant invalide', 400);
        }
        if (!in_array($devise, ['CDF', 'USD'])) {
            ApiResponse::error('Devise invalide', 400);
        }
        if (!validatePhone($telephone_beneficiaire)) {
            ApiResponse::error('Numéro bénéficiaire invalide', 400);
        }

        // Vérifier le client bénéficiaire
        $stmt = $pdo->prepare("SELECT id_client FROM Client WHERE telephone = ? AND id_client != ?");
        $stmt->execute([$telephone_beneficiaire, $client_id]);
        $beneficiaire = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$beneficiaire) {
            ApiResponse::error('Bénéficiaire introuvable', 404);
        }

        // Vérifier le solde
        $champ_solde = "solde_" . strtolower($devise);
        $stmt = $pdo->prepare("SELECT $champ_solde FROM Client WHERE id_client = ?");
        $stmt->execute([$client_id]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);

        $solde = (float)$client[$champ_solde];
        if ($montant > $solde) {
            ApiResponse::error('Solde insuffisant', 400);
        }

        try {
            $pdo->beginTransaction();

            // Créer la transaction
            $stmt = $pdo->prepare("
                INSERT INTO Transactions 
                (id_client, type_transaction, montant, devise, mode, statut, description, date_transaction)
                VALUES (?, 'Virement', ?, ?, 'En ligne', 'en_attente', ?, NOW())
            ");
            $stmt->execute([$client_id, $montant, $devise, "Virement vers {$telephone_beneficiaire}"]);
            $transaction_id = (int)$pdo->lastInsertId();

            // Débiter l'expéditeur
            $nouveau_solde = $solde - $montant;
            $stmt = $pdo->prepare("UPDATE Client SET $champ_solde = ? WHERE id_client = ?");
            $stmt->execute([$nouveau_solde, $client_id]);

            // Créditer le bénéficiaire
            $stmt = $pdo->prepare("UPDATE Client SET $champ_solde = $champ_solde + ? WHERE id_client = ?");
            $stmt->execute([$montant, $beneficiaire['id_client']]);

            $pdo->commit();

            $response = [
                'id' => $transaction_id,
                'montant' => $montant,
                'devise' => $devise,
                'beneficiaire' => $telephone_beneficiaire,
                'nouveau_solde' => $nouveau_solde,
                'statut' => 'approuve',
            ];

            ApiResponse::success($response, 'Virement effectué', 201);
        } catch (Exception $e) {
            $pdo->rollBack();
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
