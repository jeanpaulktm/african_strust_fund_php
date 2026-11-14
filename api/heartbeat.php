<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
startSecureSession();

header('Content-Type: application/json');

// Session active = utilisateur en ligne
if (!empty($_SESSION['role']) && !empty($_SESSION['client_id'])) {
    $pdo = Database::getConnection();
    $pdo->prepare("UPDATE Client SET last_login = NOW() WHERE id_client = ?")
        ->execute([(int)$_SESSION['client_id']]);
}

// Compter les clients actifs dans les 2 dernières minutes
$pdo = Database::getConnection();
$count = (int)$pdo->query("
    SELECT COUNT(*) FROM Client 
    WHERE last_login >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
")->fetchColumn();

echo json_encode(['online' => $count, 'time' => date('H:i:s')]);