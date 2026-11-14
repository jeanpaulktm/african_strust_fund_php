<?php
declare(strict_types=1);

// Affichage d'erreurs pour le debug
ini_set('display_errors', '1');
error_reporting(E_ALL);

echo "<h1>🔍 Diagnostic auth/</h1>";

echo "<h2>1. Vérification des fichiers config/</h2>";
$files = [
    '../config/database.php',
    '../config/security.php',
    '../config/paths.php',
    '../config/auth_guard.php',
    '../includes/header.php',
    '../assets/css/style.css',
    '../assets/js/main.js',
];
foreach ($files as $f) {
    $path = __DIR__ . '/' . $f;
    echo (file_exists($path) ? "✅" : "❌") . " $f<br>";
}

echo "<h2>2. Chargement des fichiers PHP</h2>";
try {
    require_once __DIR__ . '/../config/database.php';
    echo "✅ database.php chargé<br>";
    echo "✅ security.php chargé (via database.php)<br>";
} catch (Throwable $e) {
    echo "❌ Erreur database.php : " . $e->getMessage() . "<br>";
}

try {
    require_once __DIR__ . '/../config/paths.php';
    echo "✅ paths.php chargé<br>";
    echo "📌 BASE_URL = <code>" . BASE_URL . "</code><br>";
    echo "📌 asset('css/style.css') = <code>" . asset('css/style.css') . "</code><br>";
    echo "📌 url('index.php') = <code>" . url('index.php') . "</code><br>";
} catch (Throwable $e) {
    echo "❌ Erreur paths.php : " . $e->getMessage() . "<br>";
}

echo "<h2>3. Test de connexion PDO</h2>";
try {
    $pdo = Database::getConnection();
    echo "✅ PDO OK — version MySQL : " . $pdo->query('SELECT VERSION()')->fetchColumn() . "<br>";
} catch (Throwable $e) {
    echo "❌ PDO erreur : " . $e->getMessage() . "<br>";
}

echo "<h2>4. Test session sécurisée</h2>";
try {
    startSecureSession();
    echo "✅ Session OK — ID : " . session_id() . "<br>";
    echo "✅ CSRF token : " . substr(csrfToken(), 0, 16) . "...<br>";
} catch (Throwable $e) {
    echo "❌ Session erreur : " . $e->getMessage() . "<br>";
}

echo "<h2>5. Test CSS</h2>";
?>
<link rel="stylesheet" href="<?= asset('css/style.css') ?>">
<div style="padding:20px;background:#0B0E14">
    <button class="btn-gold">Si ce bouton est DORÉ → CSS OK ✅</button>
</div>
<p><a href="<?= asset('css/style.css') ?>" target="_blank">Tester le CSS directement</a></p>