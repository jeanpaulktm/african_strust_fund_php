<?php
declare(strict_types=1);
/**
 * ============================================================
 * API : ENREGISTREMENT / MISE À JOUR APPAREIL
 * ============================================================
 * Appelée par JS depuis la page login
 * Enregistre le fingerprint enrichi dans Device_Fingerprints
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/paths.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/security_detector.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

/* Lecture body JSON */
$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}

try {
    $pdo = Database::getConnection();
    $ip  = getClientIP();
    $user_agent = $data['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');

    /* 1. Vérifier si IP bloquée → retourner blocked=true */
    $blocage = verifierIPBloquee($pdo, $ip);
    if ($blocage) {
        echo json_encode([
            'ok'              => false,
            'blocked'         => true,
            'raison'          => $blocage['raison'] ?? 'Accès suspendu',
            'type'            => $blocage['type_blocage'] ?? 'temporaire',
            'date_blocage'    => $blocage['date_blocage'] ?? null,
            'date_expiration' => $blocage['date_expiration'] ?? null,
        ]);
        exit;
    }

    /* 2. Construire les infos appareil */
    $fingerprint = genererFingerprint();

    $device_info = [
        'fingerprint_hash'     => $fingerprint,
        'ip_address'           => $ip,
        'user_agent'           => $user_agent,
        'device_type'          => detecterTypeAppareil($user_agent),
        'os'                   => detecterOS($user_agent),
        'browser'              => detecterNavigateur($user_agent),
        'platform'             => cleanString($data['platform'] ?? '', 100),
        'screen_resolution'    => cleanString($data['screen'] ?? '', 30),
        'languages'            => cleanString($data['languages'] ?? '', 255),
        'timezone'             => cleanString($data['timezone'] ?? '', 100),
        'hardware_concurrency' => (int)($data['cpu'] ?? 0),
        'device_memory'        => (int)($data['memory'] ?? 0),
    ];

    /* 3. VPN/Proxy/Tor */
    try {
        $vpn_info = detecterVPNProxyTor($ip);
        $device_info = array_merge($device_info, $vpn_info);
    } catch (Throwable $e) {
        $device_info = array_merge($device_info, [
            'is_vpn' => false, 'is_proxy' => false,
            'is_tor' => false, 'is_datacenter' => false,
        ]);
    }

    /* 4. Géolocalisation */
    try {
        $geo = geolocaliserIP($ip);
        $device_info = array_merge($device_info, [
            'ville'        => $geo['ville'] ?? null,
            'pays'         => $geo['pays'] ?? null,
            'region'       => $geo['region'] ?? null,
            'isp'          => $geo['isp'] ?? null,
            'asn'          => $geo['asn'] ?? null,
            'organization' => $geo['organization'] ?? null,
            'latitude'     => $geo['latitude'] ?? null,
            'longitude'    => $geo['longitude'] ?? null,
        ]);
    } catch (Throwable $e) {}

    /* 5. Utilisateur connecté ? */
    $user_type = null;
    $user_id   = null;
    if (!empty($_SESSION['client_id'])) {
        $user_type = 'client';
        $user_id   = (int)$_SESSION['client_id'];
    } elseif (!empty($_SESSION['admin_id'])) {
        $user_type = 'admin';
        $user_id   = (int)$_SESSION['admin_id'];
    }

    /* 6. UPSERT dans Device_Fingerprints */
    $fp = $device_info['fingerprint_hash'];

    $stmt = $pdo->prepare("
        SELECT id_device FROM Device_Fingerprints
        WHERE fingerprint_hash = ? AND ip_address = ?
        LIMIT 1
    ");
    $stmt->execute([$fp, $ip]);
    $existing_id = $stmt->fetchColumn();

    if ($existing_id) {
        $pdo->prepare("
            UPDATE Device_Fingerprints SET
                user_agent = ?, device_type = ?, os = ?, browser = ?,
                platform = ?, screen_resolution = ?, languages = ?,
                timezone = ?, hardware_concurrency = ?, device_memory = ?,
                ville = ?, pays = ?, region = ?, isp = ?, asn = ?,
                organization = ?, latitude = ?, longitude = ?,
                is_vpn = ?, is_proxy = ?, is_tor = ?, is_datacenter = ?,
                user_type = ?, user_id = ?,
                last_seen = NOW()
            WHERE id_device = ?
        ")->execute([
            $device_info['user_agent'],
            $device_info['device_type'],
            $device_info['os'],
            $device_info['browser'],
            $device_info['platform'],
            $device_info['screen_resolution'],
            $device_info['languages'],
            $device_info['timezone'],
            $device_info['hardware_concurrency'],
            $device_info['device_memory'],
            $device_info['ville'] ?? null,
            $device_info['pays'] ?? null,
            $device_info['region'] ?? null,
            $device_info['isp'] ?? null,
            $device_info['asn'] ?? null,
            $device_info['organization'] ?? null,
            $device_info['latitude'] ?? null,
            $device_info['longitude'] ?? null,
            !empty($device_info['is_vpn']) ? 1 : 0,
            !empty($device_info['is_proxy']) ? 1 : 0,
            !empty($device_info['is_tor']) ? 1 : 0,
            !empty($device_info['is_datacenter']) ? 1 : 0,
            $user_type,
            $user_id,
            (int)$existing_id,
        ]);
        $id_device = (int)$existing_id;
    } else {
        $statut = (!empty($device_info['is_vpn']) || !empty($device_info['is_tor']) || !empty($device_info['is_proxy']))
            ? 'suspect' : 'fiable';

        $stmt = $pdo->prepare("
            INSERT INTO Device_Fingerprints
            (fingerprint_hash, ip_address, user_agent, device_type, os, browser,
             platform, screen_resolution, languages, timezone,
             hardware_concurrency, device_memory,
             ville, pays, region, isp, asn, organization, latitude, longitude,
             is_vpn, is_proxy, is_tor, is_datacenter,
             user_type, user_id, statut, first_seen, last_seen, connexions_reussies)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), 1)
        ");
        $stmt->execute([
            $fp, $ip,
            $device_info['user_agent'],
            $device_info['device_type'],
            $device_info['os'],
            $device_info['browser'],
            $device_info['platform'],
            $device_info['screen_resolution'],
            $device_info['languages'],
            $device_info['timezone'],
            $device_info['hardware_concurrency'],
            $device_info['device_memory'],
            $device_info['ville'] ?? null,
            $device_info['pays'] ?? null,
            $device_info['region'] ?? null,
            $device_info['isp'] ?? null,
            $device_info['asn'] ?? null,
            $device_info['organization'] ?? null,
            $device_info['latitude'] ?? null,
            $device_info['longitude'] ?? null,
            !empty($device_info['is_vpn']) ? 1 : 0,
            !empty($device_info['is_proxy']) ? 1 : 0,
            !empty($device_info['is_tor']) ? 1 : 0,
            !empty($device_info['is_datacenter']) ? 1 : 0,
            $user_type,
            $user_id,
            $statut,
        ]);
        $id_device = (int)$pdo->lastInsertId();
    }

    echo json_encode([
        'ok'          => true,
        'blocked'     => false,
        'id_device'   => $id_device,
        'fingerprint' => substr($fingerprint, 0, 16),
    ]);

} catch (Throwable $e) {
    error_log('[register_device] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error', 'message' => $e->getMessage()]);
}