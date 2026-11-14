<?php
declare(strict_types=1);

/**
 * ============================================================
 * ENDPOINT: PRÊTS CLIENT
 * ============================================================
 * GET    /api/v1/prets                    - Liste des prêts paginée
 * GET    /api/v1/prets/{id}               - Détails d'un prêt
 * POST   /api/v1/prets                    - Demander un prêt
 * POST   /api/v1/prets/{id}/rembourser    - Rembourser un prêt
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../v1/config.php';

$request_method = $_SERVER['REQUEST_METHOD'];
$request_path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$request_path = str_replace('/african-trust-fund/api/v1/prets', '', $request_path);

try {
    $token = ApiAuth::getTokenFromHeader();
    if (!$token || !$auth_data = ApiAuth::validateToken($token)) {
        ApiResponse::error('Token invalide', 401);
    }

    $client_id = $auth_data['client_id'];
    $pdo = Database::getConnection();

    // ============================================================
    // GET /api/v1/prets - Liste paginée
    // ============================================================
    if ($request_method === 'GET' && $request_path === '') {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $per_page = min(50, max(5, (int)($_GET['per_page'] ?? 10)));
        $offset = ($page - 1) * $per_page;
        $statut = $_GET['statut'] ?? '';

        // Construction de la requête
        $where = "WHERE p.id_client = ?";
        $params = [$client_id];

        if ($statut && in_array($statut, ['soumis', 'en_analyse', 'approuve', 'decaisse', 'en_cours', 'en_retard', 'solde', 'rejete', 'annule'])) {
            $where .= " AND p.statut = ?";
            $params[] = $statut;
        }

        // Compter le total
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM Prets p $where");
        $stmt->execute($params);
        $total = (int)$stmt->fetch(PDO::FETCH_ASSOC)['total'];

        // Récupérer les prêts
        $sql = "
            SELECT 
                p.id_pret,
                p.montant_pret,
                p.taux_interet,
                p.statut,
                p.capital_restant,
                p.montant_interet,
                p.date_debut,
                p.date_fin,
                p.date_echeance_prochaine,
                pr.nom as produit_nom
            FROM Prets p
            LEFT JOIN Produits_Pret pr ON pr.id_produit = p.id_produit
            $where
            ORDER BY p.date_debut DESC
            LIMIT ? OFFSET ?
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_merge($params, [$per_page, $offset]));
        $prets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $data = array_map(function($p) {
            return [
                'id' => (int)$p['id_pret'],
                'montant' => (float)$p['montant_pret'],
                'taux_interet' => (float)$p['taux_interet'],
                'statut' => $p['statut'],
                'capital_restant' => (float)$p['capital_restant'],
                'interets_restants' => (float)$p['montant_interet'],
                'produit' => $p['produit_nom'],
                'date_debut' => $p['date_debut'],
                'date_fin' => $p['date_fin'],
                'date_echeance' => $p['date_echeance_prochaine'],
            ];
        }, $prets);

        ApiResponse::paginated($data, $page, $per_page, $total);
    }

    // ============================================================
    // GET /api/v1/prets/{id} - Détails
    // ============================================================
    else if ($request_method === 'GET' && preg_match('/^\/(\d+)$/', $request_path, $matches)) {
        $pret_id = (int)$matches[1];

        $stmt = $pdo->prepare("
            SELECT 
                p.id_pret,
                p.montant_pret,
                p.taux_interet,
                p.statut,
                p.capital_restant,
                p.montant_interet,
                p.date_debut,
                p.date_fin,
                p.date_echeance_prochaine,
                p.duree_mois,
                p.nombre_echeances,
                p.echeances_payees,
                pr.nom as produit_nom,
                pr.description as produit_desc
            FROM Prets p
            LEFT JOIN Produits_Pret pr ON pr.id_produit = p.id_produit
            WHERE p.id_pret = ? AND p.id_client = ?
        ");
        $stmt->execute([$pret_id, $client_id]);
        $pret = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pret) {
            ApiResponse::error('Prêt introuvable', 404);
        }

        // Récupérer les échéances
        $stmt = $pdo->prepare("
            SELECT 
                id_echeance,
                numero_echeance,
                montant_echeance,
                montant_interet,
                date_due,
                date_paiement,
                statut,
                montant_penalite
            FROM Echeances_Pret 
            WHERE id_pret = ?
            ORDER BY numero_echeance ASC
        ");
        $stmt->execute([$pret_id]);
        $echeances = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response = [
            'id' => (int)$pret['id_pret'],
            'montant' => (float)$pret['montant_pret'],
            'taux_interet' => (float)$pret['taux_interet'],
            'statut' => $pret['statut'],
            'capital_restant' => (float)$pret['capital_restant'],
            'interets_restants' => (float)$pret['montant_interet'],
            'produit' => [
                'nom' => $pret['produit_nom'],
                'description' => $pret['produit_desc'],
            ],
            'dates' => [
                'debut' => $pret['date_debut'],
                'fin' => $pret['date_fin'],
                'prochaine_echeance' => $pret['date_echeance_prochaine'],
            ],
            'duree' => [
                'mois' => (int)$pret['duree_mois'],
                'total_echeances' => (int)$pret['nombre_echeances'],
                'payees' => (int)$pret['echeances_payees'],
            ],
            'echeances' => array_map(function($e) {
                return [
                    'id' => (int)$e['id_echeance'],
                    'numero' => (int)$e['numero_echeance'],
                    'montant_capital' => (float)$e['montant_echeance'],
                    'montant_interet' => (float)$e['montant_interet'],
                    'montant_penalite' => (float)$e['montant_penalite'],
                    'date_due' => $e['date_due'],
                    'date_paiement' => $e['date_paiement'],
                    'statut' => $e['statut'],
                ];
            }, $echeances),
        ];

        ApiResponse::success($response, 'Détails du prêt');
    }

    // ============================================================
    // POST /api/v1/prets - Créer une demande
    // ============================================================
    else if ($request_method === 'POST' && $request_path === '') {
        $body = getJsonBody();

        if (!$body || !validateRequired($body, ['montant', 'duree_mois', 'id_produit'])) {
            ApiResponse::error('Montant, durée et produit requis', 400);
        }

        $montant = (float)$body['montant'];
        $duree_mois = (int)$body['duree_mois'];
        $id_produit = (int)$body['id_produit'];

        if (!validateAmount($montant)) {
            ApiResponse::error('Montant invalide', 400);
        }
        if ($duree_mois < 1 || $duree_mois > 360) {
            ApiResponse::error('Durée invalide (1-360 mois)', 400);
        }

        // Vérifier le produit
        $stmt = $pdo->prepare("SELECT id_produit, taux_interet FROM Produits_Pret WHERE id_produit = ? AND statut = 'actif'");
        $stmt->execute([$id_produit]);
        $produit = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$produit) {
            ApiResponse::error('Produit invalide', 404);
        }

        try {
            $pdo->beginTransaction();

            // Créer la demande
            $stmt = $pdo->prepare("
                INSERT INTO Prets 
                (id_client, id_produit, montant_pret, taux_interet, duree_mois, 
                 nombre_echeances, statut, date_debut, created_at)
                VALUES (?, ?, ?, ?, ?, ?, 'soumis', NOW(), NOW())
            ");

            $nombre_echeances = ceil($duree_mois / 1);
            $stmt->execute([
                $client_id,
                $id_produit,
                $montant,
                $produit['taux_interet'],
                $duree_mois,
                $nombre_echeances,
            ]);

            $pret_id = (int)$pdo->lastInsertId();
            $pdo->commit();

            $response = [
                'id' => $pret_id,
                'montant' => $montant,
                'statut' => 'soumis',
                'message' => 'Demande de prêt soumise avec succès',
            ];

            ApiResponse::success($response, 'Demande créée', 201);
        } catch (Exception $e) {
            $pdo->rollBack();
            ApiResponse::error('Erreur lors de la création: ' . $e->getMessage(), 500);
        }
    }

    // ============================================================
    // POST /api/v1/prets/{id}/rembourser
    // ============================================================
    else if ($request_method === 'POST' && preg_match('/^\/(\d+)\/rembourser$/', $request_path, $matches)) {
        $pret_id = (int)$matches[1];
        $body = getJsonBody();

        if (!$body || !validateRequired($body, ['montant'])) {
            ApiResponse::error('Montant requis', 400);
        }

        $montant = (float)$body['montant'];

        if (!validateAmount($montant)) {
            ApiResponse::error('Montant invalide', 400);
        }

        // Vérifier le prêt
        $stmt = $pdo->prepare("
            SELECT id_pret, capital_restant, statut 
            FROM Prets 
            WHERE id_pret = ? AND id_client = ?
        ");
        $stmt->execute([$pret_id, $client_id]);
        $pret = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pret) {
            ApiResponse::error('Prêt introuvable', 404);
        }

        if ($pret['statut'] !== 'en_cours' && $pret['statut'] !== 'en_retard') {
            ApiResponse::error('Remboursement impossible pour ce statut', 400);
        }

        if ($montant > ($pret['capital_restant'] + 50)) {
            ApiResponse::error('Montant supérieur au capital restant', 400);
        }

        try {
            $pdo->beginTransaction();

            // Enregistrer la transaction
            $stmt = $pdo->prepare("
                INSERT INTO Transactions 
                (id_client, type_transaction, montant, devise, mode, statut, description, date_transaction)
                VALUES (?, 'Remboursement', ?, 'CDF', 'En ligne', 'approuve', ?, NOW())
            ");
            $stmt->execute($client_id, $montant, "Remboursement du prêt #{$pret_id}");

            // Mettre à jour le prêt
            $nouveau_capital = (float)$pret['capital_restant'] - $montant;
            $stmt = $pdo->prepare("
                UPDATE Prets 
                SET capital_restant = ? 
                WHERE id_pret = ?
            ");
            $stmt->execute([$nouveau_capital, $pret_id]);

            $pdo->commit();

            $response = [
                'id' => $pret_id,
                'montant_rembourse' => $montant,
                'capital_restant' => $nouveau_capital,
                'message' => 'Remboursement enregistré',
            ];

            ApiResponse::success($response, 'Remboursement effectué');
        } catch (Exception $e) {
            $pdo->rollBack();
            ApiResponse::error('Erreur lors du remboursement: ' . $e->getMessage(), 500);
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
