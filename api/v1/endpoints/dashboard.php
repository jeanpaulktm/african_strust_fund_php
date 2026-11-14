<?php
declare(strict_types=1);

/**
 * ============================================================
 * ENDPOINT: DASHBOARD CLIENT
 * ============================================================
 * GET /api/v1/dashboard - Informations principales du dashboard
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../v1/config.php';

$request_method = $_SERVER['REQUEST_METHOD'];

try {
    $token = ApiAuth::getTokenFromHeader();
    if (!$token || !$auth_data = ApiAuth::validateToken($token)) {
        ApiResponse::error('Token invalide', 401);
    }

    $client_id = $auth_data['client_id'];
    $pdo = Database::getConnection();

    // ============================================================
    // GET /api/v1/dashboard
    // ============================================================
    if ($request_method === 'GET') {
        // Informations client
        $stmt = $pdo->prepare("
            SELECT 
                id_client,
                prenom,
                nom,
                photo_profil,
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

        // Statistiques des prêts
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) AS total,
                COUNT(CASE WHEN statut = 'en_cours' THEN 1 END) AS en_cours,
                COUNT(CASE WHEN statut = 'en_retard' THEN 1 END) AS en_retard,
                COUNT(CASE WHEN statut = 'solde' THEN 1 END) AS solde,
                SUM(CASE WHEN statut IN ('en_cours', 'en_retard') THEN capital_restant ELSE 0 END) AS capital_restant,
                SUM(CASE WHEN statut IN ('en_cours', 'en_retard') THEN montant_interet ELSE 0 END) AS interets_restants
            FROM Prets 
            WHERE id_client = ?
        ");
        $stmt->execute([$client_id]);
        $stats_prets = $stmt->fetch(PDO::FETCH_ASSOC);

        // Statistiques des placements
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) AS total,
                COUNT(CASE WHEN statut = 'actif' THEN 1 END) AS actifs,
                COUNT(CASE WHEN statut = 'cloture' THEN 1 END) AS clotures,
                SUM(CASE WHEN statut = 'actif' THEN montant_initial ELSE 0 END) AS capital_investi,
                SUM(CASE WHEN statut = 'actif' THEN (interets_cumules - interets_retires) ELSE 0 END) AS interets_gains
            FROM Placements 
            WHERE id_client = ?
        ");
        $stmt->execute([$client_id]);
        $stats_placements = $stmt->fetch(PDO::FETCH_ASSOC);

        // Dernières transactions
        $stmt = $pdo->prepare("
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
            WHERE id_client = ? 
            ORDER BY date_transaction DESC 
            LIMIT 5
        ");
        $stmt->execute([$client_id]);
        $recent_transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Prêts actifs
        $stmt = $pdo->prepare("
            SELECT 
                id_pret,
                montant_pret,
                taux_interet,
                statut,
                capital_restant,
                date_echeance_prochaine
            FROM Prets 
            WHERE id_client = ? AND statut IN ('en_cours', 'en_retard')
            ORDER BY date_echeance_prochaine ASC
            LIMIT 3
        ");
        $stmt->execute([$client_id]);
        $prets_actifs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $response = [
            'client' => [
                'id' => (int)$client['id_client'],
                'nom_complet' => "{$client['prenom']} {$client['nom']}",
                'photo_profil' => $client['photo_profil'],
            ],
            'soldes' => [
                'cdf' => (float)$client['solde_cdf'],
                'usd' => (float)$client['solde_usd'],
                'epargne_cdf' => (float)$client['solde_epargne_cdf'],
                'epargne_usd' => (float)$client['solde_epargne_usd'],
                'total_cdf' => (float)($client['solde_cdf'] + $client['solde_epargne_cdf']),
                'total_usd' => (float)($client['solde_usd'] + $client['solde_epargne_usd']),
            ],
            'prets' => [
                'total' => (int)($stats_prets['total'] ?? 0),
                'en_cours' => (int)($stats_prets['en_cours'] ?? 0),
                'en_retard' => (int)($stats_prets['en_retard'] ?? 0),
                'soldes' => (int)($stats_prets['solde'] ?? 0),
                'capital_restant' => (float)($stats_prets['capital_restant'] ?? 0),
                'interets_restants' => (float)($stats_prets['interets_restants'] ?? 0),
            ],
            'placements' => [
                'total' => (int)($stats_placements['total'] ?? 0),
                'actifs' => (int)($stats_placements['actifs'] ?? 0),
                'clotures' => (int)($stats_placements['clotures'] ?? 0),
                'capital_investi' => (float)($stats_placements['capital_investi'] ?? 0),
                'interets_gains' => (float)($stats_placements['interets_gains'] ?? 0),
            ],
            'dernières_transactions' => array_map(function($t) {
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
            }, $recent_transactions),
            'prets_actifs' => array_map(function($p) {
                return [
                    'id' => (int)$p['id_pret'],
                    'montant' => (float)$p['montant_pret'],
                    'taux' => (float)$p['taux_interet'],
                    'statut' => $p['statut'],
                    'capital_restant' => (float)$p['capital_restant'],
                    'date_echeance' => $p['date_echeance_prochaine'],
                ];
            }, $prets_actifs),
        ];

        ApiResponse::success($response, 'Dashboard récupéré');
    } else {
        ApiResponse::error('Méthode non autorisée', 405);
    }

} catch (PDOException $e) {
    error_log('DB Error: ' . $e->getMessage());
    ApiResponse::error('Erreur base de données', 500);
} catch (Exception $e) {
    error_log('Error: ' . $e->getMessage());
    ApiResponse::error('Erreur serveur', 500);
}
?>
