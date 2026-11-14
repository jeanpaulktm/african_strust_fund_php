<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/prets.php';
require_once __DIR__ . '/../config/tests_flux.php';
requireRole('admin');

$pdo = Database::getConnection();
$admin_id = (int)$_SESSION['admin_id'];

header('Content-Type: application/json; charset=utf-8');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    if (!verifyCsrf($_POST['csrf'] ?? $_GET['csrf'] ?? null)) {
        throw new RuntimeException("Jeton CSRF invalide.");
    }

    /* ============================================================
       EXÉCUTER UN TEST ET RETOURNER LE RÉSULTAT EN JSON
       ============================================================ */
    if ($action === 'executer_un') {
        $id_test = (int)($_POST['id_test'] ?? 0);
        if ($id_test <= 0) throw new RuntimeException("Test invalide.");
        
        $resultat = executerTest($pdo, $id_test, $admin_id, false);
        
        echo json_encode([
            'success' => true,
            'test' => [
                'id' => $resultat['id_test'],
                'nom' => $resultat['nom'],
                'statut' => $resultat['statut'],
                'duree' => $resultat['duree'],
                'etapes' => $resultat['etapes'],
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ============================================================
       EXÉCUTER TOUS LES TESTS PAR CATÉGORIE
       ============================================================ */
    if ($action === 'executer_categorie') {
        $categorie = $_POST['categorie'] ?? '';
        if (!in_array($categorie, ['creation','workflow','securite','calculs','edge_case'], true)) {
            throw new RuntimeException("Catégorie invalide.");
        }
        
        $stmt = $pdo->prepare("SELECT id_test, nom FROM Tests_Flux WHERE categorie = ? ORDER BY code");
        $stmt->execute([$categorie]);
        $tests = $stmt->fetchAll();
        
        $resultats = [];
        $nb_succes = 0; $nb_echecs = 0; $nb_warnings = 0;
        $start = microtime(true);
        
        foreach ($tests as $t) {
            $r = executerTest($pdo, (int)$t['id_test'], $admin_id, false);
            $resultats[] = [
                'id' => $r['id_test'],
                'nom' => $r['nom'],
                'statut' => $r['statut'],
                'duree' => $r['duree'],
            ];
            
            if ($r['statut'] === 'succes') $nb_succes++;
            elseif ($r['statut'] === 'echec') $nb_echecs++;
            elseif ($r['statut'] === 'partiel') $nb_warnings++;
        }
        
        $duree = (int)((microtime(true) - $start) * 1000);
        
        $pdo->prepare("
            INSERT INTO Tests_Executions 
                (execute_par, execute_par_role, nb_tests, nb_succes, nb_echecs, nb_warnings, duree_totale_ms)
            VALUES (?, 'admin', ?, ?, ?, ?, ?)
        ")->execute([$admin_id, count($tests), $nb_succes, $nb_echecs, $nb_warnings, $duree]);
        
        echo json_encode([
            'success' => true,
            'categorie' => $categorie,
            'total' => count($tests),
            'succes' => $nb_succes,
            'echecs' => $nb_echecs,
            'warnings' => $nb_warnings,
            'duree' => $duree,
            'resultats' => $resultats,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ============================================================
       RÉCUPÉRER LES RÉSULTATS D'UN TEST (JSON)
       ============================================================ */
    if ($action === 'get_resultats') {
        $id_test = (int)($_GET['id_test'] ?? 0);
        if ($id_test <= 0) throw new RuntimeException("Test invalide.");
        
        $stmt = $pdo->prepare("SELECT resultats_json, nom, dernier_statut, duree_ms FROM Tests_Flux WHERE id_test = ?");
        $stmt->execute([$id_test]);
        $t = $stmt->fetch();
        
        if (!$t) throw new RuntimeException("Test introuvable.");
        
        echo json_encode([
            'success' => true,
            'nom' => $t['nom'],
            'statut' => $t['dernier_statut'],
            'duree' => (int)$t['duree_ms'],
            'resultats' => json_decode($t['resultats_json'] ?? '[]', true),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ============================================================
       VÉRIFIER STATUT GLOBAL (polling)
       ============================================================ */
    if ($action === 'get_stats') {
        $stats = $pdo->query("
            SELECT 
                COUNT(*) AS total,
                COUNT(CASE WHEN dernier_statut = 'succes' THEN 1 END) AS succes,
                COUNT(CASE WHEN dernier_statut = 'echec' THEN 1 END) AS echecs,
                COUNT(CASE WHEN dernier_statut = 'partiel' THEN 1 END) AS warnings,
                COUNT(CASE WHEN dernier_statut = 'jamais' THEN 1 END) AS jamais
            FROM Tests_Flux
        ")->fetch();
        
        echo json_encode(['success' => true, 'stats' => $stats], JSON_UNESCAPED_UNICODE);
        exit;
    }

    throw new RuntimeException("Action inconnue.");

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}