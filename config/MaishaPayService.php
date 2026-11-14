<?php
// config/MaishaPayService.php

class MaishaPayService
{
    private string $publicKey;
    private string $secretKey;
    private int $gatewayMode;
    private string $apiUrl;

    public function __construct(array $config)
    {
        $this->publicKey   = $config['public_key'];
        $this->secretKey   = $config['secret_key'];
        $this->gatewayMode = $config['gateway_mode'];
        $this->apiUrl      = $config['api_url'];
    }

    /**
     * Initie une transaction de paiement.
     */
    public function initiatePayment(array $params): array
    {
        // Construction du corps de la requête selon la documentation MaishaPay[citation:1]
        $payload = [
            'gatewayMode'           => $this->gatewayMode,
            'publicApiKey'          => $this->publicKey,
            'secretApiKey'          => $this->secretKey,
            'transactionReference'  => $params['reference'] ?? 'TEST-' . time(),
            'amount'                => $params['amount'],
            'currency'              => $params['currency'] ?? 'CDF', // 'USD', 'CDF', 'FCFA', 'EURO'
            'customerFullName'      => $params['customer_name'] ?? 'Client Test',
            'customerPhoneNumber'   => $params['customer_phone'] ?? '+243990001234',
            'customerEmailAddress'  => $params['customer_email'] ?? 'test@atf.cd',
            'chanel'                => 'MOBILEMONEY', // Obligatoire
            'provider'              => $params['provider'], // Ex: 'MPESA', 'ORANGE', 'AIRTEL', 'MTN'
            'walletID'              => $params['wallet_id'], // Le numéro qui paie
        ];

        $ch = curl_init($this->apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new Exception("Erreur de connexion à MaishaPay : " . $curlError);
        }

        $responseData = json_decode($response, true);

        if ($httpCode !== 200 || !is_array($responseData)) {
            throw new Exception("Erreur API MaishaPay (Code: $httpCode) : " . $response);
        }

        return $responseData;
    }
}
?>