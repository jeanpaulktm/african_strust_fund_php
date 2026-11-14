<?php
declare(strict_types=1);

/**
 * ============================================================
 * ENDPOINT: PLACEMENTS CLIENT
 * ============================================================
 * GET    /api/v1/placements              - Liste paginée
 * GET    /api/v1/placements/{id}         - Détails
 * POST   /api/v1/placements              - Créer un placement
 * POST   /api/v1/placements/{id}/retirer - Retirer un placement
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../v1/config.php';

$request_method = $_SERVER['REQUEST_METHOD'];
$request_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$request_path = str_replace('/african-trust-fund/api/v1/placements', '', $request_path);

try {
    $token = ApiAuth::getTokenFromHeader();
    if (!$token || !$auth_data = ApiAuth::validateToken($token)) {
        ApiResponse::error('Token invalide', 401);
    }

    $client_id = $auth_data['client_id'];
    $pdo = Database::getConnection();

    // ============================================================
    // GET /api/v1/placements
    // ============================================================
    if ($request_method === 'GET' && $request_path === '') {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $per_page = min(50, max(5, (int)($_GET['per_page'] ?? 10)));
        $offset = ($page - 1) * $per_page;
        $statut = $_GET['statut'] ?? '';

        $where = "WHERE id_client = ?";
        $params = [$client_id];

        if ($statut && in_array($statut, ['actif', 'cloture', 'anticipe'])) {
            $where .= " AND statut = ?";
            $params[] = $statut;
        }

        // Compter
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM Placements $where");
        $stmt->execute($params);
        $total = (int)$stmt->fetch(PDO::FETCH_ASSOC)['total'];

        // Récupérer
        $sql = "
            SELECT 
                id_placement,
                montant_initial,
                devise,
                taux_interet,
                statut,
                interets_cumules,
                interets_retires,
                date_debut,
                date_echeance,
                date_cloture,
                duree_jours
            FROM Placements 
            $where
            ORDER BY date_debut DESC
            LIMIT ? OFFSET ?
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($params, [$per_page, $offset]));
        $placements = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $data = array_map(function($p) {
            $interets_restants = (float)$p['interets_cumules'] - (float)$p['interets_retires'];
            return [
                'id' => (int)$p['id_placement'],
                'montant_initial' => (float)$p['montant_initial'],
                'devise' => $p['devise'],
                'taux_interet' => (float)$p['taux_interet'],
                'statut' => $p['statut'],
                'interets_cumules' => (float)$p['interets_cumules'],
                'interets_retires' => (float)$p['interets_retires'],
                'interets_restants' => $interets_restants,
                'date_debut' => $p['date_debut'],
                'date_echeance' => $p['date_echeance'],
                'duree_jours' => (int)$p['duree_jours'],
            ];
        }, $placements);

        ApiResponse::paginated($data, $page, $per_page, $total);
    }

    // ============================================================
    // GET /api/v1/placements/{id}
    // ============================================================
    else if ($request_method === 'GET' && preg_match('/^\/(\d+)$/', $request_path, $matches)) {
        $placement_id = (int)$matches[1];

        $stmt = $pdo->prepare("
            SELECT 
                id_placement,
                montant_initial,
                capital_retire,
                devise,
                taux_interet,
                statut,
                interets_cumules,
                interets_retires,
                date_debut,
                date_echeance,
                date_cloture,
                duree_jours,
                nombre_jours_ecoules
            FROM Placements 
            WHERE id_placement = ? AND id_client = ?
        ");
        $stmt->execute([$placement_id, $client_id]);
        $placement = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$placement) {
            ApiResponse::error('Placement introuvable', 404);
        }

        $interets_restants = (float)$placement['interets_cumules'] - (float)$placement['interets_retires'];
        $capital_restant = (float)$placement['montant_initial'] - (float)$placement['capital_retire'];

        $response = [
            'id' => (int)$placement['id_placement'],
            'montant_initial' => (float)$placement['montant_initial'],
            'capital_retire' => (float)$placement['capital_retire'],
            'capital_restant' => $capital_restant,
            'devise' => $placement['devise'],
            'taux_interet' => (float)$placement['taux_interet'],
            'statut' => $placement['statut'],
            'interets' => [
                'cumules' => (float)$placement['interets_cumules'],
                'retires' => (float)$placement['interets_retires'],
                'restants' => $interets_restants,
            ],
            'dates' => [
                'debut' => $placement['date_debut'],
                'echeance' => $placement['date_echeance'],
                'cloture' => $placement['date_cloture'],
            ],
            'duree' => [
                'total_jours' => (int)$placement['duree_jours'],
                'jours_ecoules' => (int)$placement['nombre_jours_ecoules'],
            ],
            'progression' => (int)round((float)$placement['nombre_jours_ecoules'] / (float)$placement['duree_jours'] * 100),
        ];

        ApiResponse::success($response, 'Placement récupéré');
    }

    // ============================================================
    // POST /api/v1/placements
    // ============================================================
    else if ($request_method === 'POST' && $request_path === '') {
        $body = getJsonBody();

        if (!$body || !validateRequired($body, ['montant', 'devise', 'duree_jours'])) {
            ApiResponse::error('Montant, devise et durée requis', 400);
        }

        $montant = (float)$body['montant'];
        $devise = strtoupper(trim($body['devise']));
        $duree_jours = (int)$body['duree_jours'];

        if (!validateAmount($montant)) {
            ApiResponse::error('Montant invalide', 400);
        }
        if (!in_array($devise, ['CDF', 'USD'])) {
            ApiResponse::error('Devise invalide', 400);
        }
        if ($duree_jours < 30 || $duree_jours > 3650) {
            ApiResponse::error('Durée invalide (30-3650 jours)', 400);
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

            // Récupérer le taux selon la durée
            $taux_interet = 0.05; // 5% par défaut
            if ($duree_jours >= 365) {
                $taux_interet = 0.08;
            } elseif ($duree_jours >= 180) {
                $taux_interet = 0.07;
            }

            // Créer le placement
            $date_echeance = date('Y-m-d', strtotime("+{$duree_jours} days"));
            
            $stmt = $pdo->prepare("
                INSERT INTO Placements 
                (id_client, montant_initial, devise, taux_interet, statut, duree_jours, 
                 date_debut, date_echeance, created_at)
                VALUES (?, ?, ?, ?, 'actif', ?, NOW(), ?, NOW())
            ");
            $stmt->execute([
                $client_id,
                $montant,
                $devise,
                $taux_interet,
                $duree_jours,
                $date_echeance,
            ]);
            $placement_id = (int)$pdo->lastInsertId();

            // Débiter le solde
            $nouveau_solde = $solde - $montant;
            $stmt = $pdo->prepare("UPDATE Client SET $champ_solde = ? WHERE id_client = ?");
            $stmt->execute([$nouveau_solde, $client_id]);

            // Enregistrer la transaction
            $stmt = $pdo->prepare("
                INSERT INTO Transactions 
                (id_client, type_transaction, montant, devise, mode, statut, description, date_transaction)
                VALUES (?, 'Placement', ?, ?, 'En ligne', 'approuve', ?, NOW())
            ");
            $stmt->execute([$client_id, $montant, $devise, "Placement de {$duree_jours} jours"]);

            $pdo->commit();

            $response = [
                'id' => $placement_id,
                'montant' => $montant,
                'devise' => $devise,
                'taux_interet' => $taux_interet,
                'duree_jours' => $duree_jours,
                'date_echeance' => $date_echeance,
                'statut' => 'actif',
                'nouveau_solde' => $nouveau_solde,
            ];

            ApiResponse::success($response, 'Placement créé', 201);
        } catch (Exception $e) {
            $pdo->rollBack();
            ApiResponse::error('Erreur: ' . $e->getMessage(), 500);
        }
    }

    // ============================================================
    // POST /api/v1/placements/{id}/retirer
    // ============================================================
    else if ($request_method === 'POST' && preg_match('/^\/(\d+)\/retirer$/', $request_path, $matches)) {
        $placement_id = (int)$matches[1];
        $body = getJsonBody();

        $type_retrait = $body['type'] ?? 'partiel'; // 'partiel' ou 'total'

        // Récupérer le placement
        $stmt = $pdo->prepare("
            SELECT id_placement, montant_initial, devise, statut, interets_cumules, interets_retires
            FROM Placements 
            WHERE id_placement = ? AND id_client = ?
        ");
        $stmt->execute([$placement_id, $client_id]);
        $placement = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$placement) {
            ApiResponse::error('Placement introuvable', 404);
        }

        if ($placement['statut'] !== 'actif') {
            ApiResponse::error('Seuls les placements actifs peuvent être retirés', 400);
        }

        try {
            $pdo->beginTransaction();

            $interets_restants = (float)$placement['interets_cumules'] - (float)$placement['interets_retires'];
            $montant_retrait = $type_retrait === 'total' 
                ? (float)$placement['montant_initial'] + $interets_restants 
                : $interets_restants;

            $devise = $placement['devise'];
            $champ_solde = "solde_" . strtolower($devise);

            // Créditer le solde
            $stmt = $pdo->prepare("UPDATE Client SET $champ_solde = $champ_solde + ? WHERE id_client = ?");
            $stmt->execute([$montant_retrait, $client_id]);

            // Mettre à jour le placement
            if ($type_retrait === 'total') {
                $stmt = $pdo->prepare("
                    UPDATE Placements 
                    SET statut = 'cloture', capital_retire = montant_initial, 
                        interets_retires = interets_cumules, date_cloture = NOW()
                    WHERE id_placement = ?
                ");
            } else {
                $stmt = $pdo->prepare("
                    UPDATE Placements 
                    SET interets_retires = interets_cumules
                    WHERE id_placement = ?
                ");
            }
            $stmt->execute([$placement_id]);

            $pdo->commit();

            $response = [
                'id' => $placement_id,
                'montant_retrait' => $montant_retrait,
                'devise' => $devise,
                'type_retrait' => $type_retrait,
                'nouveau_statut' => $type_retrait === 'total' ? 'cloture' : 'actif',
            ];

            ApiResponse::success($response, 'Retrait effectué');
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
