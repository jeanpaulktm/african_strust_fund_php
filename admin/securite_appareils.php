<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/security_detector.php';
requireRole('admin');

$pdo = Database::getConnection();
$admin_id = (int)$_SESSION['admin_id'];

$errors = [];
$success = '';

/* ============================================================
   HELPER : BLOQUER UNE IP (fallback si non définie ailleurs)
   ============================================================ */
if (!function_exists('bloquerIP')) {
    function bloquerIP(PDO $pdo, string $ip, string $raison, string $type = 'temporaire',
                       int $duree_minutes = 0, ?int $admin_id = null): int
    {
        $expiration = null;
        if ($type === 'temporaire' && $duree_minutes > 0) {
            $expiration = date('Y-m-d H:i:s', time() + ($duree_minutes * 60));
        }

        /* Désactiver les blocages précédents */
        $pdo->prepare("
            UPDATE IPs_Bloquees
            SET est_active = FALSE, date_deblocage = NOW()
            WHERE ip_address = ? AND est_active = TRUE
        ")->execute([$ip]);

        $stmt = $pdo->prepare("
            INSERT INTO IPs_Bloquees
            (ip_address, raison, type_blocage, duree_minutes, admin_id,
             date_expiration, bloque_par)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $ip, $raison, $type, $duree_minutes,
            $admin_id, $expiration,
            $admin_id ? 'admin' : 'systeme'
        ]);

        return (int)$pdo->lastInsertId();
    }
}

/* ============================================================
   TRAITEMENT POST
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) {
            throw new RuntimeException("Jeton invalide.");
        }

        $action = $_POST['action'] ?? '';

        /* ===================== BLOQUER CLIENT (IP + DEVICE) ===================== */
        if ($action === 'bloquer_client' || $action === 'bloquer_device') {
            $id_device = (int)($_POST['id_device'] ?? 0);
            $ip_input  = trim((string)($_POST['ip_address'] ?? ''));
            $raison    = cleanString(
                $_POST['raison'] ?? 'Irrégularité de norme de système détectée',
                500
            );
            $type      = in_array($_POST['type_blocage'] ?? 'temporaire',
                          ['temporaire', 'permanent', 'suspendu'], true)
                          ? $_POST['type_blocage'] : 'temporaire';
            $duree     = max(0, (int)($_POST['duree_minutes'] ?? 60));

            /* Récupérer l'IP de l'appareil si non fournie */
            $ip_address = $ip_input;
            if ($ip_address === '' && $id_device > 0) {
                $stmt = $pdo->prepare("SELECT ip_address FROM Device_Fingerprints WHERE id_device = ?");
                $stmt->execute([$id_device]);
                $ip_address = (string)$stmt->fetchColumn();
            }

            if ($ip_address === '' || !filter_var($ip_address, FILTER_VALIDATE_IP)) {
                throw new RuntimeException("Adresse IP invalide ou introuvable.");
            }

            /* 1. Bloquer l'IP */
            $id_blocage = bloquerIP($pdo, $ip_address, $raison, $type, $duree, $admin_id);

            /* 2. Marquer tous les appareils de cette IP comme bloqués */
            $pdo->prepare("
                UPDATE Device_Fingerprints
                SET statut = 'bloque',
                    raison_blocage = ?,
                    bloque_par = ?,
                    date_blocage = NOW()
                WHERE ip_address = ?
            ")->execute([$raison, $admin_id, $ip_address]);

            /* 3. Déconnecter les sessions actives de cette IP */
            $killed = 0;
            try {
                /* Marquer les clients pour forcer la déconnexion */
                $stmt = $pdo->prepare("
                    UPDATE Client SET
                        session_killed_at = NOW(),
                        session_kill_reason = ?
                    WHERE last_ip = ? AND statut = 'actif'
                ");
                $stmt->execute([$raison, $ip_address]);
                $killed = $stmt->rowCount();
            } catch (Throwable $e) {
                /* Colonnes optionnelles — ignorer */
            }

            /* 4. Nettoyer les sessions PHP actives en base si table existe */
            try {
                $pdo->prepare("DELETE FROM Sessions_Actives WHERE ip_address = ?")
                    ->execute([$ip_address]);
            } catch (Throwable $e) {}

            logAction($pdo, 'admin', $admin_id, 'CLIENT_BLOQUE',
                "IP: {$ip_address} | Device: #{$id_device} | Type: {$type} | " .
                "Durée: {$duree}min | Raison: {$raison}");

            $_SESSION['flash_success'] =
                "🚫 Client bloqué — IP {$ip_address} suspendue" .
                ($type === 'temporaire' && $duree > 0 ? " pour {$duree} minutes." : " indéfiniment.") .
                ($killed > 0 ? " {$killed} session(s) fermée(s)." : '');
        }

        /* ===================== DÉBLOQUER UN APPAREIL / IP ===================== */
        if ($action === 'debloquer_device') {
            $id_device = (int)($_POST['id_device'] ?? 0);
            if ($id_device <= 0) throw new RuntimeException("Appareil invalide.");

            $stmt = $pdo->prepare("SELECT ip_address FROM Device_Fingerprints WHERE id_device = ?");
            $stmt->execute([$id_device]);
            $ip_address = (string)$stmt->fetchColumn();

            /* Débloquer l'appareil */
            $pdo->prepare("
                UPDATE Device_Fingerprints
                SET statut = 'fiable',
                    raison_blocage = NULL,
                    date_blocage = NULL
                WHERE id_device = ?
            ")->execute([$id_device]);

            /* Débloquer l'IP */
            if ($ip_address !== '') {
                $pdo->prepare("
                    UPDATE IPs_Bloquees
                    SET est_active = FALSE,
                        debloque_par = ?,
                        date_deblocage = NOW()
                    WHERE ip_address = ? AND est_active = TRUE
                ")->execute([$admin_id, $ip_address]);

                /* Réactiver les sessions */
                try {
                    $pdo->prepare("
                        UPDATE Client SET
                            session_killed_at = NULL,
                            session_kill_reason = NULL
                        WHERE last_ip = ?
                    ")->execute([$ip_address]);
                } catch (Throwable $e) {}
            }

            logAction($pdo, 'admin', $admin_id, 'DEVICE_DEBLOQUE',
                "Appareil #{$id_device} | IP: {$ip_address}");
            $_SESSION['flash_success'] = "✅ Appareil et IP débloqués avec succès.";
        }

        /* ===================== SUPPRIMER UN APPAREIL ===================== */
        if ($action === 'supprimer_device') {
            $id_device = (int)($_POST['id_device'] ?? 0);
            if ($id_device <= 0) throw new RuntimeException("Appareil invalide.");

            $pdo->prepare("DELETE FROM Device_Fingerprints WHERE id_device = ?")
                ->execute([$id_device]);

            logAction($pdo, 'admin', $admin_id, 'DEVICE_SUPPRIME', "Appareil #{$id_device}");
            $_SESSION['flash_success'] = "🗑 Appareil supprimé.";
        }

        /* ===================== BLOQUER UNE IP DIRECTEMENT ===================== */
        if ($action === 'bloquer_ip_direct') {
            $ip_address = trim((string)($_POST['ip_address'] ?? ''));
            $raison     = cleanString($_POST['raison'] ?? 'Blocage manuel', 500);
            $duree      = max(0, (int)($_POST['duree_minutes'] ?? 60));
            $type       = in_array($_POST['type_blocage'] ?? 'temporaire',
                            ['temporaire','permanent','suspendu'], true)
                            ? $_POST['type_blocage'] : 'temporaire';

            if (!filter_var($ip_address, FILTER_VALIDATE_IP)) {
                throw new RuntimeException("IP invalide.");
            }

            bloquerIP($pdo, $ip_address, $raison, $type, $duree, $admin_id);

            $pdo->prepare("
                UPDATE Device_Fingerprints
                SET statut = 'bloque', raison_blocage = ?,
                    bloque_par = ?, date_blocage = NOW()
                WHERE ip_address = ?
            ")->execute([$raison, $admin_id, $ip_address]);

            logAction($pdo, 'admin', $admin_id, 'IP_BLOQUEE',
                "IP: {$ip_address} | {$type} {$duree}min | {$raison}");
            $_SESSION['flash_success'] = "🚫 IP {$ip_address} bloquée.";
        }

        header('Location: ' . url('admin/securite_appareils.php'));
        exit;

    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

/* ============================================================
   FILTRES
   ============================================================ */
$f_statut = $_GET['statut'] ?? '';
$f_type   = $_GET['type'] ?? '';
$f_online = $_GET['online'] ?? '';
$f_pays   = trim($_GET['pays'] ?? '');
$f_q      = trim($_GET['q'] ?? '');

/* ============================================================
   CONSTRUCTION REQUÊTE
   ============================================================ */
$sql = "SELECT d.*, 
        (SELECT COUNT(*) FROM IPs_Bloquees ib 
         WHERE ib.ip_address = d.ip_address 
           AND ib.est_active = TRUE 
           AND (ib.date_expiration IS NULL OR ib.date_expiration > NOW())
        ) AS is_ip_bloquee,
        (SELECT ib.date_expiration FROM IPs_Bloquees ib 
         WHERE ib.ip_address = d.ip_address 
           AND ib.est_active = TRUE 
           AND (ib.date_expiration IS NULL OR ib.date_expiration > NOW())
         ORDER BY ib.date_blocage DESC LIMIT 1
        ) AS ip_expiration
        FROM Device_Fingerprints d WHERE 1=1";
$params = [];

if ($f_statut !== '' && in_array($f_statut, ['fiable','suspect','bloque'], true)) {
    $sql .= " AND d.statut = ?";
    $params[] = $f_statut;
}
if ($f_type !== '' && in_array($f_type, ['desktop','mobile','tablet','bot','unknown'], true)) {
    $sql .= " AND d.device_type = ?";
    $params[] = $f_type;
}
if ($f_pays !== '') {
    $sql .= " AND d.pays LIKE ?";
    $params[] = "%$f_pays%";
}
if ($f_q !== '') {
    $sql .= " AND (d.ip_address LIKE ? OR d.user_agent LIKE ? OR d.fingerprint_hash LIKE ? OR d.ville LIKE ?)";
    $like = "%$f_q%";
    array_push($params, $like, $like, $like, $like);
}
if ($f_online === '1') {
    $sql .= " AND d.last_seen > DATE_SUB(NOW(), INTERVAL 5 MINUTE)";
} elseif ($f_online === '0') {
    $sql .= " AND d.last_seen <= DATE_SUB(NOW(), INTERVAL 5 MINUTE)";
}

$sql .= " ORDER BY 
            CASE WHEN d.last_seen > DATE_SUB(NOW(), INTERVAL 5 MINUTE) THEN 0 ELSE 1 END,
            d.last_seen DESC 
          LIMIT 500";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$appareils = $stmt->fetchAll();

/* ============================================================
   STATISTIQUES
   ============================================================ */
$stats = [
    'total' => 0, 'en_ligne' => 0, 'bloques' => 0,
    'suspects' => 0, 'fiables' => 0, 'pays_uniques' => 0,
    'ips_bloquees' => 0,
];

try {
    $stats['total']    = (int)$pdo->query("SELECT COUNT(*) FROM Device_Fingerprints")->fetchColumn();
    $stats['en_ligne'] = (int)$pdo->query("SELECT COUNT(*) FROM Device_Fingerprints WHERE last_seen > DATE_SUB(NOW(), INTERVAL 5 MINUTE)")->fetchColumn();
    $stats['bloques']  = (int)$pdo->query("SELECT COUNT(*) FROM Device_Fingerprints WHERE statut = 'bloque'")->fetchColumn();
    $stats['suspects'] = (int)$pdo->query("SELECT COUNT(*) FROM Device_Fingerprints WHERE statut = 'suspect'")->fetchColumn();
    $stats['fiables']  = (int)$pdo->query("SELECT COUNT(*) FROM Device_Fingerprints WHERE statut = 'fiable'")->fetchColumn();
    $stats['pays_uniques'] = (int)$pdo->query("SELECT COUNT(DISTINCT pays) FROM Device_Fingerprints WHERE pays IS NOT NULL")->fetchColumn();
    $stats['ips_bloquees'] = (int)$pdo->query("
        SELECT COUNT(*) FROM IPs_Bloquees
        WHERE est_active = TRUE
          AND (date_expiration IS NULL OR date_expiration > NOW())
    ")->fetchColumn();
} catch (Throwable $e) {}

/* ============================================================
   DONNÉES CARTE
   ============================================================ */
$markers = [];
foreach ($appareils as $a) {
    if (empty($a['latitude']) || empty($a['longitude'])) continue;

    $is_online = strtotime($a['last_seen']) > (time() - 300);
    $is_bloque = $a['statut'] === 'bloque' || !empty($a['is_ip_bloquee']);

    if ($is_bloque) $color = '#991B1B';
    elseif (!empty($a['is_tor'])) $color = '#7C2D12';
    elseif (!empty($a['is_vpn'])) $color = '#EF4444';
    elseif ($is_online) $color = '#10B981';
    else $color = '#6B7280';

    $markers[] = [
        'id'     => (int)$a['id_device'],
        'lat'    => (float)$a['latitude'],
        'lon'    => (float)$a['longitude'],
        'ip'     => $a['ip_address'],
        'ville'  => $a['ville'],
        'pays'   => $a['pays'],
        'isp'    => $a['isp'],
        'os'     => $a['os'],
        'browser'=> $a['browser'],
        'device' => $a['device_type'],
        'user'   => $a['user_type'],
        'statut' => $a['statut'],
        'online' => $is_online,
        'bloque' => $is_bloque,
        'color'  => $color,
    ];
}

/* Flash */
if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

$base = '/african-trust-fund';

logAction($pdo, 'admin', $admin_id, 'SECURITE_VIEW', 'Consultation appareils');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sécurité Appareils — ATF Admin</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/admin-premium.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 14px;
    margin-bottom: 24px;
}
.stat-block {
    padding: 18px; border-radius: 16px;
    background: linear-gradient(145deg, var(--card), var(--card-2));
    border: 1px solid var(--border);
    display: flex; gap: 12px; align-items: center;
    transition: all .25s; cursor: pointer; text-decoration: none; color: inherit;
}
.stat-block:hover {
    transform: translateY(-3px);
    border-color: rgba(245,184,0,.4);
    box-shadow: 0 12px 30px rgba(0,0,0,.3);
}
.stat-block .ico { font-size: 1.8rem; }
.stat-block .meta strong {
    display: block; font-size: 1.5rem; color: var(--gold); line-height: 1;
}
.stat-block .meta span {
    font-size: .7rem; color: var(--muted);
    text-transform: uppercase; letter-spacing: 1px;
    font-weight: 700; margin-top: 4px; display: block;
}
.stat-block.green .meta strong { color: #6EE7B7; }
.stat-block.red .meta strong { color: #FCA5A5; }
.stat-block.blue .meta strong { color: #93B4FF; }
.stat-block.purple .meta strong { color: #A78BFA; }
.stat-block.orange .meta strong { color: #FCD34D; }

.filters-bar {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1fr auto auto;
    gap: 10px; padding: 16px;
    background: linear-gradient(145deg, var(--card), var(--card-2));
    border: 1px solid var(--border);
    border-radius: 14px; margin-bottom: 20px;
}
.filters-bar input,
.filters-bar select {
    padding: 11px 14px;
    background: var(--bg-1);
    border: 1px solid var(--border);
    border-radius: 10px; color: var(--text);
    font-size: .85rem; font-family: inherit;
}
.filters-bar input:focus,
.filters-bar select:focus {
    outline: none; border-color: var(--gold);
}
@media (max-width: 1000px) { .filters-bar { grid-template-columns: 1fr 1fr; } }

#map {
    height: 450px; border-radius: 14px;
    border: 2px solid rgba(245,184,0,.3);
    margin-bottom: 20px;
}
.leaflet-container { background: #0B0E14 !important; }
.leaflet-tile { filter: invert(1) hue-rotate(180deg) brightness(0.9); }

.map-legend {
    display: flex; gap: 14px; flex-wrap: wrap;
    margin-top: 12px; font-size: .78rem; color: var(--muted);
}
.map-legend .item { display: flex; align-items: center; gap: 6px; }
.map-legend .dot {
    width: 12px; height: 12px; border-radius: 50%;
    border: 2px solid #fff;
}

.device-card {
    padding: 22px; border-radius: 16px;
    background: linear-gradient(145deg, var(--card), var(--card-2));
    border: 1px solid var(--border);
    margin-bottom: 14px; transition: all .3s;
    position: relative;
}
.device-card:hover {
    border-color: rgba(245,184,0,.4);
    box-shadow: 0 12px 30px rgba(0,0,0,.3);
}
.device-card.online { border-left: 4px solid #10B981; }
.device-card.bloque {
    border-left: 4px solid #991B1B;
    background: linear-gradient(145deg, rgba(153,27,27,.08), rgba(11,14,20,.4));
}
.device-card.suspect { border-left: 4px solid #F5B800; }
.device-card.offline { border-left: 4px solid #6B7280; opacity: 0.85; }

.device-header {
    display: flex; justify-content: space-between;
    align-items: flex-start; gap: 14px;
    margin-bottom: 16px; flex-wrap: wrap;
}
.device-title-block {
    display: flex; gap: 14px; align-items: center;
    flex: 1; min-width: 220px;
}
.device-icon {
    width: 60px; height: 60px; border-radius: 15px;
    display: flex; align-items: center; justify-content: center;
    font-size: 2rem; flex-shrink: 0;
}
.device-icon.desktop { background: rgba(59,130,246,.15); color: #93B4FF; }
.device-icon.mobile { background: rgba(16,185,129,.15); color: #6EE7B7; }
.device-icon.tablet { background: rgba(245,184,0,.15); color: var(--gold); }
.device-icon.bot { background: rgba(239,68,68,.15); color: #FCA5A5; }
.device-icon.unknown { background: rgba(107,114,128,.15); color: #9CA3AF; }

.device-title { flex: 1; min-width: 0; }
.device-title .name {
    font-weight: 800; color: var(--text); font-size: 1rem;
    margin-bottom: 4px; display: flex; align-items: center;
    gap: 8px; flex-wrap: wrap;
}
.device-title .id {
    font-family: 'Courier New', monospace;
    color: var(--gold); font-size: .72rem;
    word-break: break-all;
}

.status-pill {
    padding: 5px 12px; border-radius: 999px;
    font-size: .7rem; font-weight: 800;
    text-transform: uppercase; letter-spacing: .5px;
    display: inline-flex; align-items: center;
    gap: 5px; white-space: nowrap;
}
.status-pill.online {
    background: rgba(16,185,129,.15);
    color: #6EE7B7; border: 1px solid rgba(16,185,129,.4);
}
.status-pill.online .dot {
    width: 8px; height: 8px; border-radius: 50%;
    background: #10B981; box-shadow: 0 0 8px #10B981;
    animation: pulse 2s infinite;
}
.status-pill.offline {
    background: rgba(107,114,128,.15);
    color: #9CA3AF; border: 1px solid rgba(107,114,128,.3);
}
.status-pill.offline .dot {
    width: 8px; height: 8px; border-radius: 50%;
    background: #6B7280;
}
.status-pill.bloque {
    background: rgba(153,27,27,.25);
    color: #FCA5A5; border: 1px solid #991B1B;
}
@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: .4; }
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 10px; margin-top: 12px;
}
.info-item {
    padding: 11px 13px; border-radius: 10px;
    background: rgba(11,14,20,.5);
    border: 1px solid var(--border);
}
.info-item .lbl {
    font-size: .65rem; color: var(--muted);
    text-transform: uppercase; letter-spacing: .5px;
    font-weight: 800; margin-bottom: 3px;
    display: flex; align-items: center; gap: 4px;
}
.info-item .val {
    font-size: .85rem; font-weight: 700;
    color: var(--text); word-break: break-word;
}
.info-item .val.mono {
    font-family: 'Courier New', monospace;
    font-size: .78rem; color: #93B4FF;
}
.info-item .val.gold { color: var(--gold); }
.info-item .val.green { color: #6EE7B7; }
.info-item .val.red { color: #FCA5A5; }

.actions-row {
    display: flex; gap: 8px; flex-wrap: wrap;
    margin-top: 14px; padding-top: 14px;
    border-top: 1px solid var(--border);
}
.btn-action {
    padding: 9px 16px; border-radius: 9px;
    font-size: .78rem; font-weight: 800;
    border: 1px solid; cursor: pointer;
    font-family: inherit; display: inline-flex;
    align-items: center; gap: 5px;
    transition: all .2s; text-decoration: none;
    white-space: nowrap;
}
.btn-action:hover { transform: translateY(-2px); }
.btn-action.bloquer {
    background: rgba(239,68,68,.15); color: #FCA5A5;
    border-color: rgba(239,68,68,.4);
}
.btn-action.bloquer:hover { background: rgba(239,68,68,.25); }
.btn-action.debloquer {
    background: rgba(16,185,129,.15); color: #6EE7B7;
    border-color: rgba(16,185,129,.4);
}
.btn-action.debloquer:hover { background: rgba(16,185,129,.25); }
.btn-action.map {
    background: rgba(59,130,246,.15); color: #93B4FF;
    border-color: rgba(59,130,246,.4);
}
.btn-action.map:hover { background: rgba(59,130,246,.25); }
.btn-action.supprimer {
    background: rgba(107,114,128,.15); color: #9CA3AF;
    border-color: rgba(107,114,128,.3);
}
.btn-action.supprimer:hover {
    background: rgba(239,68,68,.15);
    color: #FCA5A5; border-color: rgba(239,68,68,.4);
}

.threat-badges {
    display: flex; gap: 6px; flex-wrap: wrap;
    margin-top: 10px;
}
.threat-badge {
    padding: 4px 10px; border-radius: 6px;
    font-size: .68rem; font-weight: 800;
    text-transform: uppercase;
}
.threat-badge.tor { background: rgba(153,27,27,.3); color: #FCA5A5; border: 1px solid #991B1B; }
.threat-badge.vpn { background: rgba(239,68,68,.2); color: #FCA5A5; border: 1px solid rgba(239,68,68,.5); }
.threat-badge.proxy { background: rgba(245,184,0,.2); color: #FCD34D; border: 1px solid rgba(245,184,0,.5); }
.threat-badge.datacenter { background: rgba(139,92,246,.2); color: #A78BFA; border: 1px solid rgba(139,92,246,.5); }
.threat-badge.blocked-ip {
    background: rgba(153,27,27,.4); color: #FCA5A5;
    border: 2px solid #991B1B; font-weight: 900;
    animation: pulseRed 2s infinite;
}
@keyframes pulseRed {
    0%, 100% { box-shadow: 0 0 0 0 rgba(153,27,27,.7); }
    50% { box-shadow: 0 0 0 6px rgba(153,27,27,0); }
}

.empty-state {
    text-align: center; padding: 60px 20px;
    color: var(--muted);
    background: linear-gradient(145deg, var(--card), var(--card-2));
    border-radius: 14px; border: 1px solid var(--border);
}
.empty-state .ico { font-size: 4rem; opacity: .4; margin-bottom: 16px; }

/* Modale */
.modal-overlay {
    position: fixed; inset: 0;
    background: rgba(11,14,20,.92);
    backdrop-filter: blur(12px);
    z-index: 99999;
    display: flex; align-items: center;
    justify-content: center; padding: 20px;
    animation: fadeIn .25s ease;
}
.modal-overlay.hidden { display: none; }
@keyframes fadeIn {
    from { opacity: 0; }
    to { opacity: 1; }
}
.modal-box {
    background: linear-gradient(145deg, #1A2130, #121824);
    border-radius: 22px;
    border: 1px solid rgba(239,68,68,.4);
    max-width: 540px; width: 100%;
    padding: 32px 30px;
    max-height: 90vh; overflow-y: auto;
    animation: modalIn .3s cubic-bezier(.16,1,.3,1);
}
@keyframes modalIn {
    from { opacity: 0; transform: translateY(-20px) scale(.97); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

@media (max-width: 768px) {
    .kpi-grid { grid-template-columns: 1fr 1fr; }
    .filters-bar { grid-template-columns: 1fr; }
    .info-grid { grid-template-columns: 1fr 1fr; }
    #map { height: 300px; }
    .device-header { flex-direction: column; }
}
@media (max-width: 480px) {
    .info-grid { grid-template-columns: 1fr; }
    .kpi-grid { grid-template-columns: 1fr; }
    .device-icon { width: 50px; height: 50px; font-size: 1.6rem; }
}
</style>
</head>
<body class="dark-auth">

<?php require __DIR__ . '/../includes/header.php'; ?>

<div class="admin-layout">
  <aside class="admin-sidebar animate-left">
    <h3>📊 Navigation</h3>
    <a href="<?= $base ?>/admin/dashboard.php"><span class="ico">📊</span> Dashboard</a>
    <a href="<?= $base ?>/admin/attaques.php"><span class="ico">🚨</span> Attaques</a>
    <a href="<?= $base ?>/admin/securite_appareils.php" class="active"><span class="ico">🛡️</span> Appareils</a>
    <a href="<?= $base ?>/admin/logs.php"><span class="ico">📜</span> Journal</a>
    <a href="<?= $base ?>/auth/logout.php"><span class="ico">🚪</span> Déconnexion</a>
  </aside>

  <main class="admin-main">

    <div class="welcome-head">
      <div>
        <h2>🛡️ Surveillance des appareils</h2>
        <p class="sub">
          <?= $stats['total'] ?> appareil(s) ·
          <strong style="color:#6EE7B7"><?= $stats['en_ligne'] ?> en ligne</strong>
          <?php if ($stats['bloques'] > 0): ?>
            · <strong style="color:#FCA5A5"><?= $stats['bloques'] ?> bloqué(s)</strong>
          <?php endif; ?>
          <?php if ($stats['ips_bloquees'] > 0): ?>
            · <strong style="color:#FCD34D"><?= $stats['ips_bloquees'] ?> IP active(s)</strong>
          <?php endif; ?>
        </p>
      </div>
      <button class="btn-gold" onclick="location.reload()"
              style="padding:12px 20px;border-radius:12px;font-weight:800">
        🔄 Rafraîchir
      </button>
    </div>

    <?php if ($success): ?>
      <div class="alert success"><?= $success ?></div>
    <?php endif; ?>
    <?php if ($errors): ?>
      <div class="alert error"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
    <?php endif; ?>

    <!-- KPIs -->
    <section class="kpi-grid">
      <a href="?" class="stat-block">
        <div class="ico">📱</div>
        <div class="meta"><strong><?= $stats['total'] ?></strong><span>Total</span></div>
      </a>
      <a href="?online=1" class="stat-block green">
        <div class="ico">🟢</div>
        <div class="meta"><strong><?= $stats['en_ligne'] ?></strong><span>En ligne</span></div>
      </a>
      <a href="?statut=bloque" class="stat-block red">
        <div class="ico">🚫</div>
        <div class="meta"><strong><?= $stats['bloques'] ?></strong><span>Bloqués</span></div>
      </a>
      <a href="?statut=suspect" class="stat-block purple">
        <div class="ico">⚠️</div>
        <div class="meta"><strong><?= $stats['suspects'] ?></strong><span>Suspects</span></div>
      </a>
      <a href="?statut=fiable" class="stat-block green">
        <div class="ico">✅</div>
        <div class="meta"><strong><?= $stats['fiables'] ?></strong><span>Fiables</span></div>
      </a>
      <div class="stat-block orange">
        <div class="ico">🛑</div>
        <div class="meta"><strong><?= $stats['ips_bloquees'] ?></strong><span>IP Bloquées</span></div>
      </div>
    </section>

    <!-- CARTE -->
    <div class="card" style="margin-bottom:20px">
      <h3 style="margin-bottom:16px;color:var(--gold)">🗺️ Carte mondiale des appareils</h3>
      <div id="map"></div>
      <div class="map-legend">
        <div class="item"><span class="dot" style="background:#10B981"></span> En ligne (fiable)</div>
        <div class="item"><span class="dot" style="background:#6B7280"></span> Hors ligne</div>
        <div class="item"><span class="dot" style="background:#F5B800"></span> Suspect</div>
        <div class="item"><span class="dot" style="background:#EF4444"></span> VPN</div>
        <div class="item"><span class="dot" style="background:#991B1B"></span> Tor / Bloqué</div>
      </div>
    </div>

    <!-- FILTRES -->
    <form method="GET" class="filters-bar">
      <input type="text" name="q" placeholder="🔍 Rechercher (IP, ville, fingerprint...)" value="<?= htmlspecialchars($f_q) ?>">
      <select name="statut">
        <option value="">Tous statuts</option>
        <option value="fiable" <?= $f_statut === 'fiable' ? 'selected' : '' ?>>✅ Fiable</option>
        <option value="suspect" <?= $f_statut === 'suspect' ? 'selected' : '' ?>>⚠️ Suspect</option>
        <option value="bloque" <?= $f_statut === 'bloque' ? 'selected' : '' ?>>🚫 Bloqué</option>
      </select>
      <select name="type">
        <option value="">Tous types</option>
        <option value="desktop" <?= $f_type === 'desktop' ? 'selected' : '' ?>>💻 Desktop</option>
        <option value="mobile" <?= $f_type === 'mobile' ? 'selected' : '' ?>>📱 Mobile</option>
        <option value="tablet" <?= $f_type === 'tablet' ? 'selected' : '' ?>>📲 Tablette</option>
        <option value="bot" <?= $f_type === 'bot' ? 'selected' : '' ?>>🤖 Bot</option>
      </select>
      <select name="online">
        <option value="">Tous</option>
        <option value="1" <?= $f_online === '1' ? 'selected' : '' ?>>🟢 En ligne</option>
        <option value="0" <?= $f_online === '0' ? 'selected' : '' ?>>⚫ Hors ligne</option>
      </select>
      <button class="btn-gold" type="submit"
              style="padding:11px 20px;border-radius:10px;font-weight:800">Filtrer</button>
      <a href="?" class="btn-ghost"
         style="padding:11px 20px;border-radius:10px;text-decoration:none;text-align:center;font-weight:800">
        Réinitialiser
      </a>
    </form>

    <!-- LISTE -->
    <h3 style="margin-bottom:16px;color:var(--gold);font-size:1.05rem">
      📱 Appareils détectés (<?= count($appareils) ?>)
    </h3>

    <?php if (empty($appareils)): ?>
      <div class="empty-state">
        <div class="ico">📭</div>
        <h3 style="color:var(--text);margin-bottom:8px">Aucun appareil détecté</h3>
        <p>Les appareils apparaîtront ici dès qu'ils se connecteront.</p>
      </div>
    <?php else: foreach ($appareils as $a):
        $is_online = strtotime($a['last_seen']) > (time() - 300);
        $is_bloque = $a['statut'] === 'bloque' || !empty($a['is_ip_bloquee']);
        $status_class = $is_bloque ? 'bloque' : ($is_online ? 'online' : 'offline');
    ?>
      <div class="device-card <?= $status_class ?>">

        <div class="device-header">
          <div class="device-title-block">
            <div class="device-icon <?= htmlspecialchars($a['device_type'] ?? 'unknown') ?>">
              <?php
                echo match($a['device_type'] ?? '') {
                    'mobile' => '📱',
                    'tablet' => '📲',
                    'bot' => '🤖',
                    'desktop' => '💻',
                    default => '🖥️',
                };
              ?>
            </div>
            <div class="device-title">
              <div class="name">
                <?= htmlspecialchars(ucfirst($a['device_type'] ?? 'Inconnu')) ?>
                <?php if (!empty($a['os'])): ?> · <?= htmlspecialchars($a['os']) ?><?php endif; ?>
                <?php if (!empty($a['browser'])): ?> · <?= htmlspecialchars($a['browser']) ?><?php endif; ?>
              </div>
              <div class="id">
                🔐 <?= htmlspecialchars(substr($a['fingerprint_hash'] ?? '', 0, 32)) ?>...
              </div>
            </div>
          </div>

          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <?php if ($is_bloque): ?>
              <span class="status-pill bloque">🚫 BLOQUÉ</span>
            <?php elseif ($is_online): ?>
              <span class="status-pill online">
                <span class="dot"></span> EN LIGNE
              </span>
            <?php else: ?>
              <span class="status-pill offline">
                <span class="dot"></span> HORS LIGNE
              </span>
            <?php endif; ?>
          </div>
        </div>

        <div class="info-grid">
          <div class="info-item">
            <div class="lbl">🌐 Adresse IP</div>
            <div class="val mono">
              <?= htmlspecialchars($a['ip_address'] ?? '—') ?>
              <?php if (!empty($a['is_ip_bloquee'])): ?>
                <span style="color:#FCA5A5;font-weight:900;font-size:.7rem;margin-left:4px">● BLOQUÉE</span>
              <?php endif; ?>
            </div>
          </div>

          <div class="info-item">
            <div class="lbl">📍 Ville / Pays</div>
            <div class="val gold">
              <?= htmlspecialchars($a['ville'] ?? 'Inconnue') ?>,
              <?= htmlspecialchars($a['pays'] ?? 'Inconnu') ?>
            </div>
          </div>

          <?php if (!empty($a['latitude']) && !empty($a['longitude'])): ?>
            <div class="info-item">
              <div class="lbl">🗺️ Coordonnées</div>
              <div class="val mono" style="font-size:.72rem">
                <?= number_format((float)$a['latitude'], 4) ?>°,
                <?= number_format((float)$a['longitude'], 4) ?>°
              </div>
            </div>
          <?php endif; ?>

          <?php if (!empty($a['hardware_concurrency'])): ?>
            <div class="info-item">
              <div class="lbl">⚙️ Processeur</div>
              <div class="val"><?= (int)$a['hardware_concurrency'] ?> cœurs</div>
            </div>
          <?php endif; ?>

          <?php if (!empty($a['device_memory'])): ?>
            <div class="info-item">
              <div class="lbl">💾 RAM</div>
              <div class="val"><?= (int)$a['device_memory'] ?> GB</div>
            </div>
          <?php endif; ?>

          <div class="info-item">
            <div class="lbl">💻 Système (OS)</div>
            <div class="val"><?= htmlspecialchars($a['os'] ?? '—') ?></div>
          </div>

          <div class="info-item">
            <div class="lbl">🌍 Navigateur</div>
            <div class="val"><?= htmlspecialchars($a['browser'] ?? '—') ?></div>
          </div>

          <?php if (!empty($a['screen_resolution'])): ?>
            <div class="info-item">
              <div class="lbl">📺 Résolution</div>
              <div class="val"><?= htmlspecialchars($a['screen_resolution']) ?></div>
            </div>
          <?php endif; ?>

          <div class="info-item">
            <div class="lbl">🏢 FAI / ISP</div>
            <div class="val"><?= htmlspecialchars($a['isp'] ?? '—') ?></div>
          </div>

          <?php if (!empty($a['asn'])): ?>
            <div class="info-item">
              <div class="lbl">🔢 ASN</div>
              <div class="val mono"><?= htmlspecialchars($a['asn']) ?></div>
            </div>
          <?php endif; ?>

          <?php if (!empty($a['organization'])): ?>
            <div class="info-item">
              <div class="lbl">🏛️ Organisation</div>
              <div class="val"><?= htmlspecialchars($a['organization']) ?></div>
            </div>
          <?php endif; ?>

          <div class="info-item">
            <div class="lbl">🕐 Dernière activité</div>
            <div class="val" style="font-size:.78rem">
              <?= !empty($a['last_seen']) ? date('d/m/Y H:i', strtotime($a['last_seen'])) : '—' ?>
            </div>
          </div>

          <div class="info-item">
            <div class="lbl">✅ Connexions OK</div>
            <div class="val green"><?= (int)($a['connexions_reussies'] ?? 0) ?></div>
          </div>

          <div class="info-item">
            <div class="lbl">❌ Échecs</div>
            <div class="val red"><?= (int)($a['connexions_echouees'] ?? 0) ?></div>
          </div>

          <?php if (!empty($a['user_type'])): ?>
            <div class="info-item">
              <div class="lbl">👤 Type utilisateur</div>
              <div class="val">
                <?= htmlspecialchars(ucfirst($a['user_type'])) ?>
                <?php if (!empty($a['user_id'])): ?>
                  <span style="color:var(--muted);font-size:.75rem">#<?= (int)$a['user_id'] ?></span>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>
        </div>

        <!-- Badges menace -->
        <?php if (!empty($a['is_tor']) || !empty($a['is_vpn']) || !empty($a['is_proxy']) || !empty($a['is_datacenter']) || !empty($a['is_ip_bloquee'])): ?>
          <div class="threat-badges">
            <?php if (!empty($a['is_ip_bloquee'])): ?>
              <span class="threat-badge blocked-ip">
                🛑 IP BLOQUÉE
                <?php if (!empty($a['ip_expiration'])): ?>
                  · Expire <?= date('d/m H:i', strtotime($a['ip_expiration'])) ?>
                <?php endif; ?>
              </span>
            <?php endif; ?>
            <?php if (!empty($a['is_tor'])): ?>
              <span class="threat-badge tor">🧅 TOR NETWORK</span>
            <?php endif; ?>
            <?php if (!empty($a['is_vpn'])): ?>
              <span class="threat-badge vpn">🌐 VPN DÉTECTÉ</span>
            <?php endif; ?>
            <?php if (!empty($a['is_proxy'])): ?>
              <span class="threat-badge proxy">🔀 PROXY</span>
            <?php endif; ?>
            <?php if (!empty($a['is_datacenter'])): ?>
              <span class="threat-badge datacenter">🏢 DATACENTER</span>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <!-- Actions -->
        <div class="actions-row">
          <?php if (!empty($a['latitude']) && !empty($a['longitude'])): ?>
            <button class="btn-action map"
                    onclick="localiser(<?= (float)$a['latitude'] ?>, <?= (float)$a['longitude'] ?>, '<?= htmlspecialchars($a['ip_address'] ?? '', ENT_QUOTES) ?>')">
              🗺️ Voir sur la carte
            </button>
            <a class="btn-action map" target="_blank"
               href="https://www.google.com/maps?q=<?= (float)$a['latitude'] ?>,<?= (float)$a['longitude'] ?>">
              🌍 Google Maps
            </a>
          <?php endif; ?>

          <?php if ($is_bloque): ?>
            <form method="POST" style="display:inline"
                  onsubmit="return confirm('Débloquer cet appareil et son IP ?')">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="debloquer_device">
              <input type="hidden" name="id_device" value="<?= (int)$a['id_device'] ?>">
              <button class="btn-action debloquer" type="submit">✅ Débloquer</button>
            </form>
          <?php else: ?>
            <button class="btn-action bloquer"
                    onclick="ouvrirBlocage(<?= (int)$a['id_device'] ?>, '<?= htmlspecialchars($a['ip_address'] ?? '', ENT_QUOTES) ?>')">
              🚫 Bloquer ce client
            </button>
          <?php endif; ?>

          <form method="POST" style="display:inline"
                onsubmit="return confirm('⚠️ Supprimer définitivement cet appareil ?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="supprimer_device">
            <input type="hidden" name="id_device" value="<?= (int)$a['id_device'] ?>">
            <button class="btn-action supprimer" type="submit">🗑 Supprimer</button>
          </form>
        </div>

      </div>
    <?php endforeach; endif; ?>

  </main>
</div>

<!-- ============================================================
     MODALE DE BLOCAGE
     ============================================================ -->
<div class="modal-overlay hidden" id="modalBlocage">
  <div class="modal-box">
    <h3 style="color:#FCA5A5;font-size:1.2rem;margin-bottom:18px;display:flex;align-items:center;gap:10px">
      🚫 Bloquer / Suspendre un client
    </h3>

    <div style="padding:14px;border-radius:12px;background:rgba(239,68,68,.08);
                border:1px solid rgba(239,68,68,.3);color:#FCA5A5;font-size:.85rem;
                line-height:1.6;margin-bottom:20px">
      ⚠️ <strong>Action administrative tracée</strong><br>
      L'IP sera bloquée immédiatement et la session du client sera terminée.
      Le client verra : <em style="color:#FCD34D">« Connexion suspendue »</em>.
    </div>

    <form method="POST" id="formBlocage">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="bloquer_client">
      <input type="hidden" name="id_device" id="blocageIdDevice">
      <input type="hidden" name="ip_address" id="blocageIpInput">

      <div style="margin-bottom:16px;padding:12px 14px;border-radius:10px;
                  background:rgba(11,14,20,.6);border:1px solid var(--border)">
        <div style="font-size:.7rem;color:var(--muted);text-transform:uppercase;
                    font-weight:800;letter-spacing:.8px;margin-bottom:4px">
          🌐 IP ciblée
        </div>
        <div id="blocageIpDisplay" style="color:#FCA5A5;font-family:monospace;
             font-weight:800;font-size:.95rem"></div>
      </div>

      <label style="display:block;margin-bottom:14px">
        <span style="color:var(--muted);font-size:.78rem;text-transform:uppercase;
                     font-weight:800;display:block;margin-bottom:6px">
          Type de blocage
        </span>
        <select name="type_blocage" id="blocageType" onchange="updateDureeField()"
                style="width:100%;padding:12px;background:var(--bg-1);
                       border:1px solid var(--border);border-radius:10px;
                       color:var(--text);font-family:inherit;font-size:.9rem">
          <option value="temporaire">⏱ Temporaire (durée limitée)</option>
          <option value="suspendu">⏸ Suspendu (indéfini, réversible)</option>
          <option value="permanent">🚫 Permanent</option>
        </select>
      </label>

      <label style="display:block;margin-bottom:14px" id="dureeField">
        <span style="color:var(--muted);font-size:.78rem;text-transform:uppercase;
                     font-weight:800;display:block;margin-bottom:6px">
          Durée (minutes)
        </span>
        <select name="duree_minutes"
                style="width:100%;padding:12px;background:var(--bg-1);
                       border:1px solid var(--border);border-radius:10px;
                       color:var(--text);font-family:inherit;font-size:.9rem">
          <option value="5">5 minutes</option>
          <option value="15">15 minutes</option>
          <option value="30">30 minutes</option>
          <option value="60" selected>1 heure</option>
          <option value="240">4 heures</option>
          <option value="720">12 heures</option>
          <option value="1440">24 heures</option>
          <option value="10080">7 jours</option>
          <option value="43200">30 jours</option>
        </select>
      </label>

      <label style="display:block;margin-bottom:16px">
        <span style="color:var(--muted);font-size:.78rem;text-transform:uppercase;
                     font-weight:800;display:block;margin-bottom:6px">
          Raison du blocage *
        </span>
        <textarea name="raison" required rows="3" maxlength="500"
                  style="width:100%;padding:12px;background:var(--bg-1);
                         border:1px solid var(--border);border-radius:10px;
                         color:var(--text);font-family:inherit;resize:vertical;
                         font-size:.88rem"
                  placeholder="Ex: Irrégularité de norme de système, tentative d'intrusion...">Irrégularité de norme de système détectée</textarea>
      </label>

      <div style="display:flex;gap:12px;justify-content:flex-end;margin-top:22px;
                  padding-top:18px;border-top:1px solid var(--border)">
        <button type="button" onclick="fermerBlocage()"
                style="padding:11px 20px;border-radius:10px;
                       background:rgba(255,255,255,.05);color:var(--muted);
                       border:1px solid var(--border);cursor:pointer;
                       font-family:inherit;font-weight:700">
          Annuler
        </button>
        <button type="submit"
                style="padding:11px 22px;border-radius:10px;
                       background:linear-gradient(135deg,#DC2626,#EF4444);
                       color:#fff;border:none;font-weight:800;cursor:pointer;
                       box-shadow:0 8px 20px rgba(220,38,38,.35);
                       font-family:inherit">
          🚫 Confirmer le blocage
        </button>
      </div>
    </form>
  </div>
</div>

<footer class="atf-footer">© <?= date('Y') ?> AFRICAN TRUST FUND — Projet gouvernemental RDC</footer>

<script>
/* ============================================================
   CARTE LEAFLET
   ============================================================ */
let map = null;
let markers = [];

function initMap() {
    map = L.map('map').setView([-4.0383, 21.7587], 4);

    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap | ATF Surveillance',
        maxZoom: 19,
    }).addTo(map);

    const devices = <?= json_encode($markers) ?>;

    devices.forEach(d => {
        const icon = L.divIcon({
            className: 'custom-marker',
            html: `<div style="background:${d.color};width:16px;height:16px;border-radius:50%;border:3px solid #fff;box-shadow:0 0 12px ${d.color},0 0 0 4px ${d.color}40"></div>`,
            iconSize: [22, 22],
            iconAnchor: [11, 11],
        });

        const m = L.marker([d.lat, d.lon], { icon }).addTo(map);

        const popupContent = `
            <div style="font-family:Arial;min-width:220px">
                <div style="font-weight:800;color:${d.bloque ? '#FCA5A5' : '#F5B800'};font-size:1rem;margin-bottom:6px">
                    ${d.bloque ? '🚫 BLOQUÉ' : (d.online ? '🟢 EN LIGNE' : '⚫ HORS LIGNE')}
                </div>
                <div style="font-family:monospace;color:#FCA5A5;font-weight:800">${d.ip}</div>
                <div style="color:#666;font-size:.85rem;margin-top:6px">
                    📍 ${d.ville || '?'}, ${d.pays || '?'}<br>
                    💻 ${d.os || '?'} · ${d.browser || '?'}<br>
                    📱 ${d.device || '?'}<br>
                    🏢 ${d.isp || '?'}<br>
                    <strong style="color:#333">Statut : ${d.statut}</strong>
                </div>
            </div>
        `;

        m.bindPopup(popupContent);
        markers.push(m);
    });
}

function localiser(lat, lon, ip) {
    if (!map) return;
    map.setView([lat, lon], 15);
    L.popup()
        .setLatLng([lat, lon])
        .setContent(`
            <div style="font-family:Arial;min-width:180px">
                <strong style="color:#F5B800">📍 Position exacte</strong><br>
                <code style="color:#DC2626;font-weight:800">${escapeHtml(ip)}</code><br>
                <small>Lat: ${lat.toFixed(6)}°<br>Lon: ${lon.toFixed(6)}°</small>
            </div>
        `)
        .openOn(map);
    document.getElementById('map').scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({
        '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[c]));
}

/* ============================================================
   MODALE BLOCAGE
   ============================================================ */
function ouvrirBlocage(idDevice, ip) {
    document.getElementById('blocageIdDevice').value = idDevice;
    document.getElementById('blocageIpInput').value = ip;
    document.getElementById('blocageIpDisplay').textContent = ip;
    document.getElementById('modalBlocage').classList.remove('hidden');
    updateDureeField();
}

function fermerBlocage() {
    document.getElementById('modalBlocage').classList.add('hidden');
}

function updateDureeField() {
    const type = document.getElementById('blocageType').value;
    document.getElementById('dureeField').style.display =
        (type === 'temporaire') ? 'block' : 'none';
}

document.getElementById('modalBlocage')?.addEventListener('click', function(e) {
    if (e.target === this) fermerBlocage();
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') fermerBlocage();
});

document.addEventListener('DOMContentLoaded', function() {
    initMap();
});
</script>

<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>