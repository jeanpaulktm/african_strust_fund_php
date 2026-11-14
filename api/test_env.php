<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

echo "<pre style='background:#0B0E14;color:#0f0;padding:30px;font-family:monospace;font-size:14px;line-height:1.8'>";
echo "🔍 TEST DU FICHIER .env\n\n";

/* ============================================================
   1. VÉRIFIER L'EXISTENCE DU FICHIER .env
   ============================================================ */
$env_path = __DIR__ . '/../config/.env';

echo "1. Chemin recherché :\n";
echo "   " . $env_path . "\n\n";

if (!file_exists($env_path)) {
    echo "❌ FICHIER .env INTROUVABLE !\n\n";
    echo "📌 Vous devez créer ce fichier manuellement.\n";
    echo "📌 Emplacement EXACT : C:\\xampp\\htdocs\\african-trust-fund\\config\\.env\n\n";
    echo "⚠️  Attention : le fichier s'appelle .env (avec un POINT devant)\n";
    echo "⚠️  Windows cache souvent les extensions → vérifiez qu'il n'est pas nommé 'env.txt'\n";
    exit;
}

echo "✅ Fichier .env trouvé !\n";
echo "   Taille : " . filesize($env_path) . " octets\n";
echo "   Modifié le : " . date('d/m/Y H:i:s', filemtime($env_path)) . "\n\n";

/* ============================================================
   2. LIRE LE CONTENU DU FICHIER
   ============================================================ */
echo "2. Contenu du fichier (clé masquée) :\n";

$content = file_get_contents($env_path);
$lines = explode("\n", $content);

foreach ($lines as $i => $line) {
    $line = trim($line);
    if ($line === '') continue;
    
    /* Masquer la valeur de la clé */
    if (strpos($line, 'GEMINI_API_KEY') === 0) {
        $parts = explode('=', $line, 2);
        $val = $parts[1] ?? '';
        $masked = substr($val, 0, 10) . str_repeat('*', max(0, strlen($val) - 10));
        echo "   Ligne " . ($i + 1) . " : GEMINI_API_KEY = " . $masked . "\n";
    } else {
        echo "   Ligne " . ($i + 1) . " : " . $line . "\n";
    }
}
echo "\n";

/* ============================================================
   3. PARSER LE FICHIER COMME LE FAIT gemini.php
   ============================================================ */
echo "3. Parsing du fichier :\n";

$parsed = [];
$lines = file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

foreach ($lines as $line) {
    if (strpos($line, '=') === false) {
        echo "   ⚠️  Ligne ignorée (pas de =) : " . $line . "\n";
        continue;
    }
    if (strpos(trim($line), '#') === 0) {
        echo "   ℹ️  Commentaire ignoré : " . $line . "\n";
        continue;
    }
    
    [$key, $value] = explode('=', $line, 2);
    $key = trim($key);
    $value = trim($value, " \t\n\r\0\x0B\"'");
    
    $parsed[$key] = $value;
    echo "   ✅ $key trouvé (longueur valeur : " . strlen($value) . ")\n";
}
echo "\n";

/* ============================================================
   4. VÉRIFIER LA CLÉ GEMINI
   ============================================================ */
echo "4. Vérification de la clé Gemini :\n";

if (empty($parsed['GEMINI_API_KEY'])) {
    echo "   ❌ GEMINI_API_KEY est VIDE ou MANQUANTE\n\n";
    echo "📌 Le format doit être EXACTEMENT :\n";
    echo "   GEMINI_API_KEY=AQ.Ab8RN6...votre_cle_complete\n\n";
    echo "⚠️  Pas d'espace avant ou après le =\n";
    echo "⚠️  Pas de guillemets autour de la clé\n";
} else {
    echo "   ✅ GEMINI_API_KEY présente\n";
    echo "   Longueur : " . strlen($parsed['GEMINI_API_KEY']) . " caractères\n";
    echo "   Commence par : " . substr($parsed['GEMINI_API_KEY'], 0, 15) . "...\n";
    echo "   Se termine par : ..." . substr($parsed['GEMINI_API_KEY'], -10) . "\n\n";
    
    /* Vérifier le format Gemini */
    if (strpos($parsed['GEMINI_API_KEY'], 'AIza') === 0) {
        echo "   ✅ Format ancien (AIza...) — devrait fonctionner\n";
    } elseif (strpos($parsed['GEMINI_API_KEY'], 'AQ.') === 0) {
        echo "   ✅ Format nouveau (AQ....) — devrait fonctionner\n";
    } else {
        echo "   ⚠️  Format inconnu — vérifiez votre clé\n";
    }
}
echo "\n";

/* ============================================================
   5. TESTER LE CHARGEMENT PAR chargerConfigGemini()
   ============================================================ */
echo "5. Test avec chargerConfigGemini() :\n";

try {
    require_once __DIR__ . '/../config/gemini.php';
    
    $config = chargerConfigGemini();
    
    if (empty($config['api_key'])) {
        echo "   ❌ chargerConfigGemini() retourne une clé VIDE\n";
        echo "   📌 Cause : le fichier .env n'est pas lu correctement\n";
    } else {
        echo "   ✅ Clé chargée : " . substr($config['api_key'], 0, 15) . "...\n";
        echo "   ✅ Modèle : " . $config['model'] . "\n";
    }
} catch (Throwable $e) {
    echo "   ❌ Erreur : " . $e->getMessage() . "\n";
}

/* ============================================================
   6. SOLUTION
   ============================================================ */
echo "\n" . str_repeat('═', 60) . "\n";
echo "🎯 SOLUTION\n";
echo str_repeat('═', 60) . "\n\n";

if (empty($parsed['GEMINI_API_KEY']) || empty($config['api_key'])) {
    echo "Le fichier .env existe mais la clé n'est pas lue.\n\n";
    echo "Procédure de correction :\n\n";
    echo "1. Ouvrez le fichier : " . $env_path . "\n";
    echo "2. Contenu EXACT à mettre (1 seule ligne) :\n\n";
    echo "   GEMINI_API_KEY=VOTRE_CLE_ICI\n";
    echo "   GEMINI_MODEL=gemini-2.5-flash\n";
    echo "   IA_ENABLED=true\n\n";
    echo "3. Sauvegardez en UTF-8 sans BOM\n";
    echo "4. Rechargez cette page\n";
} else {
    echo "✅ Tout est OK — la clé est bien configurée\n";
    echo "Supprimez ce fichier test_env.php et testez le chat.\n";
}

echo "\n⚠️ SUPPRIMEZ CE FICHIER APRÈS USAGE\n";
echo "</pre>";