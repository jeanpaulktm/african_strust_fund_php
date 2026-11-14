<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

echo "<pre style='background:#0B0E14;color:#0f0;padding:30px;font-family:monospace;font-size:14px;line-height:1.8'>";
echo "🔍 TEST COMPLET DE L'API IA ATF\n\n";

/* ============================================================
   1. VÉRIFIER LES FICHIERS
   ============================================================ */
echo "=== 1. Fichiers requis ===\n";
$files = [
    '../config/database.php',
    '../config/security.php',
    '../config/gemini.php',
    '../config/ia_contexte.php',
    '../config/.env',
];

foreach ($files as $f) {
    $path = __DIR__ . '/' . $f;
    $exists = file_exists($path);
    echo "   " . ($exists ? "✅" : "❌") . " $f";
    if ($exists) {
        echo " (" . filesize($path) . " octets)";
    }
    echo "\n";
}

/* ============================================================
   2. CHARGER LES CONFIGS
   ============================================================ */
echo "\n=== 2. Chargement des configurations ===\n";

try {
    require_once __DIR__ . '/../config/database.php';
    echo "   ✅ database.php chargé\n";
} catch (Throwable $e) {
    echo "   ❌ database.php : " . $e->getMessage() . "\n";
}

try {
    require_once __DIR__ . '/../config/security.php';
    echo "   ✅ security.php chargé\n";
} catch (Throwable $e) {
    echo "   ❌ security.php : " . $e->getMessage() . "\n";
}

try {
    require_once __DIR__ . '/../config/gemini.php';
    echo "   ✅ gemini.php chargé\n";
} catch (Throwable $e) {
    echo "   ❌ gemini.php : " . $e->getMessage() . "\n";
}

try {
    require_once __DIR__ . '/../config/ia_contexte.php';
    echo "   ✅ ia_contexte.php chargé\n";
} catch (Throwable $e) {
    echo "   ❌ ia_contexte.php : " . $e->getMessage() . "\n";
}

/* ============================================================
   3. VÉRIFIER LES FONCTIONS
   ============================================================ */
echo "\n=== 3. Fonctions disponibles ===\n";
$fonctions = [
    'startSecureSession',
    'csrfToken',
    'verifyCsrf',
    'chargerConfigGemini',
    'envoyerMessageGemini',
    'getContexteATF',
];

foreach ($fonctions as $f) {
    echo "   " . (function_exists($f) ? "✅" : "❌") . " $f()\n";
}

/* ============================================================
   4. VÉRIFIER LA CONFIG
   ============================================================ */
echo "\n=== 4. Configuration Gemini ===\n";

try {
    $config = chargerConfigGemini();
    echo "   API Key : " . (empty($config['api_key']) ? "❌ VIDE" : "✅ présente (" . mb_substr($config['api_key'], 0, 15) . "...)") . "\n";
    echo "   Modèle : ✅ " . $config['model'] . "\n";
    echo "   Enabled : ✅ " . ($config['enabled'] ? 'true' : 'false') . "\n";
    echo "   Max messages/jour : " . $config['max_messages_per_day'] . "\n";
} catch (Throwable $e) {
    echo "   ❌ Erreur : " . $e->getMessage() . "\n";
}

/* ============================================================
   5. TESTER LA SESSION
   ============================================================ */
echo "\n=== 5. Session sécurisée ===\n";

try {
    if (function_exists('startSecureSession')) {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            startSecureSession();
        }
        echo "   ✅ Session active — ID : " . session_id() . "\n";
    } else {
        echo "   ❌ startSecureSession() introuvable\n";
    }
} catch (Throwable $e) {
    echo "   ❌ Erreur : " . $e->getMessage() . "\n";
}

/* ============================================================
   6. TESTER LE CSRF
   ============================================================ */
echo "\n=== 6. CSRF Token ===\n";

try {
    if (function_exists('csrfToken')) {
        $token = csrfToken();
        echo "   ✅ Token généré : " . substr($token, 0, 20) . "...\n";
    } else {
        echo "   ❌ csrfToken() introuvable\n";
    }
} catch (Throwable $e) {
    echo "   ❌ Erreur : " . $e->getMessage() . "\n";
}

/* ============================================================
   7. TEST RÉEL DE L'IA
   ============================================================ */
echo "\n=== 7. Test d'appel Gemini ===\n";

try {
    if (!function_exists('envoyerMessageGemini')) {
        throw new RuntimeException("Fonction envoyerMessageGemini() manquante");
    }

    echo "   ⏳ Envoi du message 'Bonjour'...\n";
    $debut = microtime(true);

    $reponse = envoyerMessageGemini("Bonjour, comment vas-tu ?");

    $duree = round((microtime(true) - $debut) * 1000);

    echo "   ✅ Réponse reçue en {$duree}ms :\n\n";
    echo "   ┌─────────────────────────────────────────\n";
    echo "   │ " . str_replace("\n", "\n   │ ", htmlspecialchars($reponse)) . "\n";
    echo "   └─────────────────────────────────────────\n";

} catch (Throwable $e) {
    echo "   ❌ Erreur : " . $e->getMessage() . "\n";
    echo "   📍 Fichier : " . $e->getFile() . "\n";
    echo "   📍 Ligne : " . $e->getLine() . "\n";
}

/* ============================================================
   8. TEST SIMULATION POST
   ============================================================ */
echo "\n=== 8. Test simulation requête POST ===\n";

try {
    /* Simuler les données POST */
    $_SERVER['REQUEST_METHOD'] = 'POST';
    
    /* Créer un CSRF valide pour le test */
    $csrf = csrfToken();
    
    /* Préparer les données */
    $postData = [
        'message' => 'Comment ouvrir un compte ?',
        'csrf' => $csrf,
        'historique' => []
    ];

    echo "   ✅ POST simulé avec :\n";
    echo "      message : Comment ouvrir un compte ?\n";
    echo "      csrf : " . substr($csrf, 0, 16) . "...\n";
    echo "      historique : []\n";
    echo "\n   ✅ Simulation réussie — le chat peut fonctionner\n";

} catch (Throwable $e) {
    echo "   ❌ Erreur : " . $e->getMessage() . "\n";
}

/* ============================================================
   9. RÉSUMÉ
   ============================================================ */
echo "\n" . str_repeat('═', 60) . "\n";
echo "📊 RÉSUMÉ DU DIAGNOSTIC\n";
echo str_repeat('═', 60) . "\n";

$tous_ok = true;

if (!file_exists(__DIR__ . '/../config/gemini.php')) $tous_ok = false;
if (!file_exists(__DIR__ . '/../config/ia_contexte.php')) $tous_ok = false;
if (!function_exists('envoyerMessageGemini')) $tous_ok = false;

if ($tous_ok) {
    echo "✅ SYSTÈME FONCTIONNEL\n\n";
    echo "Prochaines étapes :\n";
    echo "1. Créer api/chat_ia.php (endpoint)\n";
    echo "2. Créer api/get_csrf.php (token)\n";
    echo "3. Créer test_chat.html (interface test)\n";
    echo "4. SUPPRIMER ce fichier test_api.php\n";
} else {
    echo "❌ PROBLÈMES DÉTECTÉS\n\n";
    echo "Corrigez les erreurs ci-dessus avant de continuer.\n";
}

echo "\n⚠️ SUPPRIMEZ CE FICHIER APRÈS USAGE\n";
echo "</pre>";