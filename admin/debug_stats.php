<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
requireRole('admin');

$pdo = Database::getConnection();

header('Content-Type: text/plain; charset=utf-8');

echo "=== DEBUG STATS ===\n\n";

/* 1. Test colonne devise dans Placements */
echo "1. Colonnes Placements contenant 'devise' :\n";
$r = $pdo->query("SHOW COLUMNS FROM Placements LIKE '%devise%'")->fetchAll();
print_r($r);

/* 2. Test la requête exacte */
echo "\n2. Test requête répartition devise :\n";
try {
    $sql = "SELECT devise, COUNT(*) AS nb, SUM(montant_initial) AS total
            FROM Placements
            WHERE statut = 'actif'
            GROUP BY devise";
    echo "SQL: $sql\n\n";
    $r = $pdo->query($sql)->fetchAll();
    echo "✅ Résultat : " . count($r) . " ligne(s)\n";
    print_r($r);
} catch (Throwable $e) {
    echo "❌ Erreur : " . $e->getMessage() . "\n";
}

/* 3. Vérifier si Placements a des données */
echo "\n3. Nombre de placements :\n";
echo "Total : " . $pdo->query("SELECT COUNT(*) FROM Placements")->fetchColumn() . "\n";
echo "Actifs : " . $pdo->query("SELECT COUNT(*) FROM Placements WHERE statut='actif'")->fetchColumn() . "\n";

/* 4. Vérifier OPcache */
echo "\n4. OPcache actif : ";
echo function_exists('opcache_get_status') && opcache_get_status() ? 'OUI (redémarrer Apache)' : 'NON';
echo "\n";
?>