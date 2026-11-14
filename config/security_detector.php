<?php
declare(strict_types=1);

/**
 * ============================================================
 * AFRICAN TRUST FUND — MOTEUR DE DÉTECTION DE SÉCURITÉ
 * ============================================================
 * Ce fichier contient TOUTES les fonctions de sécurité :
 *   - Détection IP / VPN / Tor / Proxy
 *   - Géolocalisation
 *   - Fingerprint
 *   - Analyse de connexion
 *   - Blocage / Déblocage IP
 *   - Vérification blocage IP
 * ============================================================
 */

/* ============================================================
   1. RÉCUPÉRATION IP CLIENT (fiable derrière proxy)
   ============================================================ */
function getClientIP(): string
{
    $keys = [
        'HTTP_CF_CONNECTING_IP',   // Cloudflare
        'HTTP_X_REAL_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_CLIENT_IP',
        'REMOTE_ADDR',
    ];

    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            $ips = explode(',', (string)$_SERVER[$key]);
            $ip = trim($ips[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }

    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/* ============================================================
   2. FINGERPRINT SERVEUR
   ============================================================ */
function genererFingerprint(): string
{
    $ua       = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $lang     = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
    $enc      = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? '';
    $ip       = getClientIP();

    $raw = implode('|', [$ua, $lang, $enc, $ip]);

    return hash('sha256', $raw);
}

/* ============================================================
   3. DÉTECTION DU TYPE D'APPAREIL
   ============================================================ */
function detecterTypeAppareil(string $ua): string
{
    $ua_lower = strtolower($ua);

    if (preg_match('/(bot|crawler|spider|scraper|curl|wget|python|java|go-http|headless)/i', $ua)) {
        return 'bot';
    }
    if (preg_match('/(ipad|tablet|playbook|silk|kindle)/i', $ua)) {
        return 'tablet';
    }
    if (preg_match('/(mobile|iphone|ipod|android|blackberry|opera mini|windows phone)/i', $ua)) {
        return 'mobile';
    }
    if (preg_match('/(windows|macintosh|linux|x11)/i', $ua)) {
        return 'desktop';
    }

    return 'unknown';
}

/* ============================================================
   4. DÉTECTION DU SYSTÈME D'EXPLOITATION
   ============================================================ */
function detecterOS(string $ua): string
{
    $os_list = [
        'Windows 11'      => '/Windows NT 10\.0.*Win64.*11/i',
        'Windows 10'      => '/Windows NT 10\.0/i',
        'Windows 8.1'     => '/Windows NT 6\.3/i',
        'Windows 8'       => '/Windows NT 6\.2/i',
        'Windows 7'       => '/Windows NT 6\.1/i',
        'Windows Vista'   => '/Windows NT 6\.0/i',
        'Windows XP'      => '/Windows NT 5\.1/i',
        'macOS'           => '/Mac OS X/i',
        'iOS'             => '/(iPhone|iPad|iPod)/i',
        'Android'         => '/Android/i',
        'Ubuntu'          => '/Ubuntu/i',
        'Linux'           => '/Linux/i',
        'Chrome OS'       => '/CrOS/i',
    ];

    foreach ($os_list as $name => $pattern) {
        if (preg_match($pattern, $ua)) {
            return $name;
        }
    }

    return 'Inconnu';
}

/* ============================================================
   5. DÉTECTION DU NAVIGATEUR
   ============================================================ */
function detecterNavigateur(string $ua): string
{
    $browsers = [
        'Edge'      => '/Edg(e|A|iOS)?\/[\d.]+/i',
        'Opera'     => '/(OPR|Opera)\/[\d.]+/i',
        'Chrome'    => '/Chrome\/[\d.]+/i',
        'Firefox'   => '/Firefox\/[\d.]+/i',
        'Safari'    => '/Version\/[\d.]+.*Safari/i',
        'IE'        => '/(MSIE|Trident)/i',
        'Brave'     => '/Brave/i',
        'Vivaldi'   => '/Vivaldi/i',
    ];

    foreach ($browsers as $name => $pattern) {
        if (preg_match($pattern, $ua)) {
            return $name;
        }
    }

    return 'Inconnu';
}

/* ============================================================
   6. DÉTECTION VPN / PROXY / TOR / DATACENTER
   ============================================================ */
function detecterVPNProxyTor(string $ip): array
{
    $result = [
        'is_vpn'        => false,
        'is_proxy'      => false,
        'is_tor'        => false,
        'is_datacenter' => false,
    ];

    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return $result;
    }

    /* Cache en session pour éviter appels répétés */
    $cache_key = 'vpn_check_' . md5($ip);
    if (isset($_SESSION[$cache_key]) && (time() - $_SESSION[$cache_key]['t']) < 300) {
        return $_SESSION[$cache_key]['d'];
    }

    try {
        /* Utilisation d'un service gratuit : ip-api.com (50 req/min) */
        $ctx = stream_context_create([
            'http' => ['timeout' => 3, 'user_agent' => 'ATF-Security/1.0'],
        ]);
        $url = "http://ip-api.com/json/{$ip}?fields=status,proxy,hosting,country,regionName,city,isp,org,as,timezone,lat,lon";
        $raw = @file_get_contents($url, false, $ctx);

        if ($raw !== false) {
            $data = json_decode($raw, true);

            if (is_array($data) && ($data['status'] ?? '') === 'success') {
                $result['is_proxy']      = !empty($data['proxy']);
                $result['is_datacenter'] = !empty($data['hosting']);

                /* Détection Tor via ASN/org */
                $org = strtolower(($data['org'] ?? '') . ' ' . ($data['isp'] ?? ''));
                if (strpos($org, 'tor') !== false || strpos($org, 'exit') !== false) {
                    $result['is_tor'] = true;
                }
                if (strpos($org, 'vpn') !== false || strpos($org, 'hosting') !== false
                    || strpos($org, 'datacenter') !== false || strpos($org, 'cloud') !== false) {
                    $result['is_vpn'] = true;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[detecterVPNProxyTor] ' . $e->getMessage());
    }

    $_SESSION[$cache_key] = ['t' => time(), 'd' => $result];

    return $result;
}

/* ============================================================
   7. GÉOLOCALISATION IP
   ============================================================ */
function geolocaliserIP(string $ip): array
{
    $default = [
        'pays'         => 'Inconnu',
        'ville'        => 'Inconnue',
        'region'       => 'Inconnue',
        'isp'          => 'Inconnu',
        'asn'          => null,
        'organization' => null,
        'timezone'     => null,
        'latitude'     => null,
        'longitude'    => null,
    ];

    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return $default;
    }

    /* Cache session 1h */
    $cache_key = 'geo_' . md5($ip);
    if (isset($_SESSION[$cache_key]) && (time() - $_SESSION[$cache_key]['t']) < 3600) {
        return $_SESSION[$cache_key]['d'];
    }

    try {
        $ctx = stream_context_create([
            'http' => ['timeout' => 3, 'user_agent' => 'ATF-Security/1.0'],
        ]);
        $url = "http://ip-api.com/json/{$ip}?fields=status,country,regionName,city,isp,org,as,timezone,lat,lon";
        $raw = @file_get_contents($url, false, $ctx);

        if ($raw !== false) {
            $data = json_decode($raw, true);
            if (is_array($data) && ($data['status'] ?? '') === 'success') {
                $geo = [
                    'pays'         => $data['country'] ?? 'Inconnu',
                    'ville'        => $data['city'] ?? 'Inconnue',
                    'region'       => $data['regionName'] ?? 'Inconnue',
                    'isp'          => $data['isp'] ?? 'Inconnu',
                    'asn'          => $data['as'] ?? null,
                    'organization' => $data['org'] ?? null,
                    'timezone'     => $data['timezone'] ?? null,
                    'latitude'     => isset($data['lat']) ? (float)$data['lat'] : null,
                    'longitude'    => isset($data['lon']) ? (float)$data['lon'] : null,
                ];
                $_SESSION[$cache_key] = ['t' => time(), 'd' => $geo];
                return $geo;
            }
        }
    } catch (Throwable $e) {
        error_log('[geolocaliserIP] ' . $e->getMessage());
    }

    return $default;
}

/* ============================================================
   8. ANALYSE DE CONNEXION
   ============================================================ */
function analyserConnexion(PDO $pdo, array $device_info): array
{
    $analyse = [
        'autorise'         => true,
        'raison'           => '',
        'action'           => 'aucune',
        'niveau_alerte'    => null,
        'recommandations'  => [],
    ];

    /* Vérifier si l'IP est bloquée */
    $ip = $device_info['ip_address'] ?? '';
    if ($ip !== '') {
        $blocage = verifierIPBloquee($pdo, $ip);
        if ($blocage) {
            $analyse['autorise'] = false;
            $analyse['raison']   = $blocage['raison'] ?? 'Accès suspendu';
            $analyse['action']   = 'bloque';
            return $analyse;
        }
    }

    /* Vérifier VPN/Tor */
    if (!empty($device_info['is_tor'])) {
        $analyse['niveau_alerte'] = 'critique';
        $analyse['recommandations'][] = 'Réseau Tor détecté — accès restreint';
    } elseif (!empty($device_info['is_vpn'])) {
        $analyse['niveau_alerte'] = 'eleve';
        $analyse['recommandations'][] = 'VPN détecté — connexion surveillée';
    } elseif (!empty($device_info['is_proxy'])) {
        $analyse['niveau_alerte'] = 'moyen';
        $analyse['recommandations'][] = 'Proxy détecté';
    }

    return $analyse;
}

/* ============================================================
   9. 🔒 VÉRIFIER SI UNE IP EST BLOQUÉE (FONCTION CLÉ)
   ============================================================ */
function verifierIPBloquee(PDO $pdo, string $ip): ?array
{
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
        return null;
    }

    try {
        /* Vérifier si la table existe */
        static $table_checked = false;
        if (!$table_checked) {
            try {
                $pdo->query("SELECT 1 FROM IPs_Bloquees LIMIT 1");
                $table_checked = true;
            } catch (Throwable $e) {
                error_log('[verifierIPBloquee] Table IPs_Bloquees inexistante');
                return null;
            }
        }

        $stmt = $pdo->prepare("
            SELECT id_blocage, ip_address, raison, type_blocage, duree_minutes,
                   date_blocage, date_expiration, bloque_par, admin_id
            FROM IPs_Bloquees
            WHERE ip_address = ?
              AND est_active = TRUE
              AND (date_expiration IS NULL OR date_expiration > NOW())
            ORDER BY date_blocage DESC
            LIMIT 1
        ");
        $stmt->execute([$ip]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    } catch (Throwable $e) {
        error_log('[verifierIPBloquee] ' . $e->getMessage());
        return null;
    }
}

/* ============================================================
   10. 🚫 BLOQUER UNE IP (FONCTION CLÉ)
   ============================================================ */
function bloquerIP(
    PDO $pdo,
    string $ip,
    string $raison,
    string $type = 'temporaire',
    int $duree_minutes = 0,
    ?int $admin_id = null
): int {
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
        throw new InvalidArgumentException("IP invalide : $ip");
    }

    /* Calcul expiration */
    $expiration = null;
    if ($type === 'temporaire' && $duree_minutes > 0) {
        $expiration = date('Y-m-d H:i:s', time() + ($duree_minutes * 60));
    }

    /* Désactiver les blocages précédents pour cette IP */
    try {
        $pdo->prepare("
            UPDATE IPs_Bloquees
            SET est_active = FALSE, date_deblocage = NOW()
            WHERE ip_address = ? AND est_active = TRUE
        ")->execute([$ip]);
    } catch (Throwable $e) {
        error_log('[bloquerIP - désactivation] ' . $e->getMessage());
    }

    /* Nouveau blocage */
    $stmt = $pdo->prepare("
        INSERT INTO IPs_Bloquees
        (ip_address, raison, type_blocage, duree_minutes, admin_id,
         date_expiration, bloque_par, est_active, date_blocage)
        VALUES (?, ?, ?, ?, ?, ?, ?, TRUE, NOW())
    ");
    $stmt->execute([
        $ip,
        $raison,
        $type,
        $duree_minutes,
        $admin_id,
        $expiration,
        $admin_id ? 'admin' : 'systeme',
    ]);

    return (int)$pdo->lastInsertId();
}

/* ============================================================
   11. ✅ DÉBLOQUER UNE IP
   ============================================================ */
function debloquerIP(PDO $pdo, string $ip, ?int $admin_id = null): int
{
    $stmt = $pdo->prepare("
        UPDATE IPs_Bloquees
        SET est_active = FALSE,
            debloque_par = ?,
            date_deblocage = NOW()
        WHERE ip_address = ? AND est_active = TRUE
    ");
    $stmt->execute([$admin_id, $ip]);
    return $stmt->rowCount();
}

/* ============================================================
   12. 🧹 NETTOYER LES BLOCAGES EXPIRÉS (à appeler périodiquement)
   ============================================================ */
function nettoyerBlocagesExpires(PDO $pdo): int
{
    try {
        $stmt = $pdo->prepare("
            UPDATE IPs_Bloquees
            SET est_active = FALSE, date_deblocage = NOW()
            WHERE est_active = TRUE
              AND date_expiration IS NOT NULL
              AND date_expiration <= NOW()
        ");
        $stmt->execute();
        return $stmt->rowCount();
    } catch (Throwable $e) {
        error_log('[nettoyerBlocagesExpires] ' . $e->getMessage());
        return 0;
    }
}

/* ============================================================
   13. 📝 LOG D'ACTION (si non défini ailleurs)
   ============================================================ */
if (!function_exists('logAction')) {
    function logAction(PDO $pdo, string $user_type, int $user_id,
                       string $action, string $details = ''): void
    {
        try {
            $pdo->prepare("
                INSERT INTO Logs_Actions
                (user_type, user_id, action, details, ip_address, user_agent, date_action)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ")->execute([
                $user_type,
                $user_id,
                $action,
                $details,
                getClientIP(),
                $_SERVER['HTTP_USER_AGENT'] ?? '',
            ]);
        } catch (Throwable $e) {
            error_log('[logAction] ' . $e->getMessage());
        }
    }
}