<?php
// config/payment.php

return [
    'maishapay' => [
        // --- Mode TEST (Sandbox) ---
        // Utilisez les clés que vous avez obtenues pour le mode test
        'public_key' => 'MP-SBPK-oi$W0zFg29/9OsZhl9PnNFPEOVTI9V10Nnc40sjNNtH0C.$7Bgduw7AlNMrag9O9HQNOZBEtMlyc$PATa3QAjyd6WDOy$F1$9dabGyKlw1$ybcMJcNF3r2cC',
        'secret_key' => 'MP-SBPK-mA0g6Loa1GNwO8rl8$i0TNBJ0ACgPah6b2k5y2muD1vCgRUY$S96m8m8xK$kkEvDQh1yjYNeS$ieWdr2$AdDvU8zZTP1csr55WcXfR0dEGxOUh8IHBH$1Q9e',
        
        // 0 = Sandbox (Test), 1 = Live (Production)
        'gateway_mode' => 0, 
        
        // URL de l'API pour les paiements marchands
        'api_url' => 'https://marchand.maishapay.online/api/payment/rest/vers1.0/merchant',
        
        // URL de callback (où MaishaPay enverra la confirmation)
        // Vous devrez créer ce fichier plus tard, mais nous pouvons déjà la définir
        'callback_url' => 'http://localhost/african-trust-fund/api/payment_callback.php',
    ],
];
?>