<?php
declare(strict_types=1);

/**
 * ============================================================
 * CONFIGURATION GEMINI IA — AFRICAN TRUST FUND
 * Clé API intégrée directement (⚠️ À sécuriser en production)
 * ============================================================
 */

/* ============================================================
   1. CHARGEMENT DE LA CONFIGURATION
   ============================================================ */
function chargerConfigGemini(): array
{
    static $config = null;
    if ($config !== null) return $config;

    /* ============================================================
       ⚠️ CLÉ API CHARGÉE DEPUIS LES VARIABLES D'ENVIRONNEMENT
       ============================================================ */
    $api_key = getenv('GEMINI_API_KEY') ?: '';
    if (empty($api_key)) {
        error_log('ERREUR: GEMINI_API_KEY non définie dans les variables d\'environnement');
        throw new Exception('Configuration Gemini incomplète: API_KEY manquante');
    }
    /* ============================================================ */

    $config = [
        'api_key'              => $api_key,
        'model'                => 'gemini-3.5-flash',
        'enabled'              => true,
        'max_messages_per_day' => 100,
        'max_message_length'   => 1000,
        'max_history'          => 10,
        'temperature'          => 0.7,
        'max_output_tokens'    => 500,
        'timeout'              => 30,
    ];

    return $config;
}

/* ============================================================
   2. APPEL À L'API GEMINI
   ============================================================ */
function envoyerMessageGemini(string $message, array $historique = []): string
{
    /* Charger la config */
    $config = chargerConfigGemini();

    /* Vérifications */
    if (empty($config['api_key']) || $config['api_key'] === 'COLLEZ_VOTRE_CLE_GEMINI_ICI') {
        throw new RuntimeException("Clé API Gemini non configurée. Modifiez config/gemini.php");
    }

    if (!$config['enabled']) {
        throw new RuntimeException("L'assistant IA est temporairement désactivé.");
    }

    /* Construire la conversation */
    $contents = [];

    foreach ($historique as $h) {
        if (!isset($h['role'], $h['contenu'])) continue;
        $role = $h['role'] === 'user' ? 'user' : 'model';
        $contents[] = [
            'role'  => $role,
            'parts' => [['text' => (string)$h['contenu']]]
        ];
    }

    $contents[] = [
        'role'  => 'user',
        'parts' => [['text' => $message]]
    ];

    /* Charger le contexte ATF */
    require_once __DIR__ . '/ia_contexte.php';
    $contexte = function_exists('getContexteATF')
                ? getContexteATF()
                : "Tu es l'assistant d'AFRICAN TRUST FUND, une banque en RDC.";

    /* Payload */
    $payload = [
        'system_instruction' => [
            'parts' => [['text' => $contexte]]
        ],
        'contents' => $contents,
        'generationConfig' => [
            'temperature'      => $config['temperature'],
            'topK'             => 40,
            'topP'             => 0.95,
            'maxOutputTokens'  => $config['max_output_tokens'],
        ],
        'safetySettings' => [
            ['category' => 'HARM_CATEGORY_HARASSMENT',        'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
            ['category' => 'HARM_CATEGORY_HATE_SPEECH',       'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
            ['category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
            ['category' => 'HARM_CATEGORY_DANGEROUS_CONTENT', 'threshold' => 'BLOCK_MEDIUM_AND_ABOVE'],
        ],
    ];

    /* Endpoint */
    $url = "https://generativelanguage.googleapis.com/v1beta/models/"
         . $config['model']
         . ":generateContent?key=" . $config['api_key'];

    /* Appel cURL */
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => $config['timeout'],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response   = curl_exec($ch);
    $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    /* Gestion erreurs */
    if ($response === false) {
        error_log('[ATF IA] Erreur cURL : ' . $curl_error);
        throw new RuntimeException("Impossible de contacter l'assistant. Vérifiez votre connexion.");
    }

    if ($http_code !== 200) {
        error_log('[ATF IA] HTTP ' . $http_code . ' : ' . $response);

        $errorData = json_decode($response, true);
        $errorMsg = $errorData['error']['message'] ?? "Erreur inconnue";

        if ($http_code === 404) {
            throw new RuntimeException("Modèle IA introuvable. Vérifiez le nom du modèle.");
        }
        if ($http_code === 429) {
            throw new RuntimeException("Trop de requêtes. Réessayez dans quelques minutes.");
        }
        if ($http_code === 400) {
            throw new RuntimeException("Requête invalide : " . $errorMsg);
        }
        if ($http_code === 403) {
            throw new RuntimeException("Clé API invalide ou expirée. Vérifiez config/gemini.php");
        }

        throw new RuntimeException("Erreur IA (code $http_code) : $errorMsg");
    }

    /* Parse la réponse */
    $data = json_decode($response, true);

    if (!isset($data['candidates'][0]['content']['parts'][0]['text'])) {
        error_log('[ATF IA] Réponse invalide : ' . $response);

        $finish = $data['candidates'][0]['finishReason'] ?? '';
        if ($finish === 'SAFETY') {
            throw new RuntimeException("Votre question a été filtrée pour des raisons de sécurité.");
        }
        if ($finish === 'RECITATION') {
            throw new RuntimeException("Je ne peux pas répondre à cette question.");
        }

        throw new RuntimeException("Réponse IA invalide. Réessayez.");
    }

    return trim($data['candidates'][0]['content']['parts'][0]['text']);
}