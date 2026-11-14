<?php
declare(strict_types=1);

/**
 * ============================================================
 * CONFIGURATION GEMINI IA — AFRICAN TRUST FUND
 * Fichier dédié UNIQUEMENT à l'assistant IA
 * ⚠️ Ne pas confondre avec config/payment.php (MaishaPay)
 * ============================================================
 */

return [
    /* ============================================================
       IDENTIFIANTS API GEMINI - CHARGÉS DEPUIS LES VARIABLES D'ENV
       ⚠️ Ne JAMAIS commiter de clés API directement !
       Définir les variables d'environnement: GEMINI_API_KEY, GEMINI_MODEL
       ============================================================ */
    'api_key' => getenv('GEMINI_API_KEY') ?: '',
    'model'   => getenv('GEMINI_MODEL') ?: 'gemini-3.5-flash',

    /* ============================================================
       PARAMÈTRES DE FONCTIONNEMENT
       ============================================================ */
    'enabled'              => true,     // Activer/désactiver l'IA
    'max_messages_per_day' => 100,      // Limite par session
    'max_message_length'   => 1000,     // Longueur max d'un message
    'max_history'          => 10,       // Nombre de messages d'historique
    'temperature'          => 0.7,      // Créativité (0 = strict, 1 = créatif)
    'max_output_tokens'    => 500,      // Longueur max de la réponse
    'timeout'              => 30,       // Timeout cURL en secondes
];
?>