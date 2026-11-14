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
   🚨 TRAITEMENT DES ACTIONS DE DÉFENSE
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) {
            throw new RuntimeException("Jeton invalide.");
        }

        $action = $_POST['action'] ?? '';

        /* ═══════════════════════════════════════════════════════
           🚫 ACTION 1 : BLOQUER UNE IP (avec durée personnalisée)
           ═══════════════════════════════════════════════════════ */
        if ($action === 'bloquer_ip') {
            $ip      = cleanString($_POST['ip'] ?? '', 45);
            $raison  = cleanString($_POST['raison'] ?? '', 500);
            $type    = in_array($_POST['type_blocage'] ?? '', ['temporaire','permanent','suspendu'], true)
                       ? $_POST['type_blocage'] : 'temporaire';
            $duree   = max(0, (int)($_POST['duree_minutes'] ?? 1440));

            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                throw new RuntimeException("IP invalide.");
            }

            /* Bloquer l'IP */
            $id_blocage = bloquerIP($pdo, $ip, $raison ?: "Blocage admin", $type, $duree, $admin_id);

            /* Marquer tous les appareils de cette IP comme bloqués */
            $pdo->prepare("
                UPDATE Device_Fingerprints
                SET statut = 'bloque', raison_blocage = ?, bloque_par = ?, date_blocage = NOW()
                WHERE ip_address = ?
            ")->execute([$raison, $admin_id, $ip]);

            /* Déconnecter les sessions actives */
            $killed = 0;
            try {
                $stmt = $pdo->prepare("
                    UPDATE Client SET session_killed_at = NOW(), session_kill_reason = ?
                    WHERE last_ip = ? AND statut = 'actif'
                ");
                $stmt->execute([$raison, $ip]);
                $killed = $stmt->rowCount();
            } catch (Throwable $e) {}

            /* Marquer les attaques de cette IP comme traitées */
            $pdo->prepare("
                UPDATE Attaques_Detectees SET blocage_auto = TRUE
                WHERE ip_address = ? AND blocage_auto = FALSE
            ")->execute([$ip]);

            logAction($pdo, 'admin', $admin_id, 'ATTAQUE_IP_BLOQUEE',
                "IP: $ip | Type: $type | Durée: {$duree}min | Raison: $raison");

            $_SESSION['flash_success'] = "🚫 IP <strong>$ip</strong> bloquée avec succès." .
                ($killed > 0 ? " ($killed session(s) fermée(s))" : '');
        }

        /* ═══════════════════════════════════════════════════════
           🌍 ACTION 2 : BLOQUER UN PAYS ENTIER
           ═══════════════════════════════════════════════════════ */
        if ($action === 'bloquer_pays') {
            $pays   = cleanString($_POST['pays'] ?? '', 100);
            $raison = cleanString($_POST['raison'] ?? "Blocage pays : $pays", 500);

            if ($pays === '') throw new RuntimeException("Pays invalide.");

            /* Récupérer toutes les IPs de ce pays */
            $stmt = $pdo->prepare("
                SELECT DISTINCT ip_address FROM Attaques_Detectees
                WHERE pays = ?
            ");
            $stmt->execute([$pays]);
            $ips = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $count = 0;
            foreach ($ips as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    bloquerIP($pdo, $ip, $raison, 'permanent', 0, $admin_id);
                    $count++;
                }
            }

            logAction($pdo, 'admin', $admin_id, 'PAYS_BLOQUE',
                "Pays: $pays | {$count} IP(s) bloquée(s)");
            $_SESSION['flash_success'] = "🌍 {$count} IP(s) de <strong>$pays</strong> bloquée(s).";
        }

        /* ═══════════════════════════════════════════════════════
           🔪 ACTION 3 : TUER TOUTES LES SESSIONS D'UNE IP
           ═══════════════════════════════════════════════════════ */
        if ($action === 'kill_sessions') {
            $ip = cleanString($_POST['ip'] ?? '', 45);
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                throw new RuntimeException("IP invalide.");
            }

            $killed = 0;
            try {
                $stmt = $pdo->prepare("
                    UPDATE Client SET
                        session_killed_at = NOW(),
                        session_kill_reason = 'Sessions tuées par admin'
                    WHERE last_ip = ?
                ");
                $stmt->execute([$ip]);
                $killed = $stmt->rowCount();
            } catch (Throwable $e) {}

            /* Supprimer les sessions PHP */
            try {
                $pdo->prepare("DELETE FROM Sessions_Actives WHERE ip_address = ?")
                    ->execute([$ip]);
            } catch (Throwable $e) {}

            logAction($pdo, 'admin', $admin_id, 'SESSIONS_KILLED',
                "IP: $ip | {$killed} session(s)");
            $_SESSION['flash_success'] = "🔪 {$killed} session(s) de <strong>$ip</strong> fermée(s).";
        }

        /* ═══════════════════════════════════════════════════════
           ✅ ACTION 4 : MARQUER COMME TRAITÉE
           ═══════════════════════════════════════════════════════ */
        if ($action === 'marquer_traitee') {
            $id_attaque = (int)($_POST['id_attaque'] ?? 0);

            $pdo->prepare("UPDATE Attaques_Detectees SET blocage_auto = TRUE WHERE id_attaque = ?")
                ->execute([$id_attaque]);

            logAction($pdo, 'admin', $admin_id, 'ATTAQUE_TRAITEE', "Attaque #$id_attaque");
            $_SESSION['flash_success'] = "✅ Attaque marquée comme traitée.";
        }

        /* Tout marquer comme traité */
        if ($action === 'tout_traiter') {
            $pdo->exec("UPDATE Attaques_Detectees SET blocage_auto = TRUE WHERE blocage_auto = FALSE");
            $_SESSION['flash_success'] = "✅ Toutes les attaques marquées comme traitées.";
        }

        header('Location: ' . url('admin/attaques.php'));
        exit;

    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

/* ============================================================
   FILTRES
   ============================================================ */
$filtre_type     = $_GET['type'] ?? '';
$filtre_severite = $_GET['severite'] ?? '';
$filtre_pays     = trim($_GET['pays'] ?? '');
$filtre_ip       = trim($_GET['ip'] ?? '');
$recherche       = trim($_GET['q'] ?? '');

/* ============================================================
   CONSTRUCTION REQUÊTE
   ============================================================ */
$sql = "SELECT * FROM Attaques_Detectees WHERE 1=1";
$params = [];

if ($filtre_type !== '') {
    $sql .= " AND type_attaque = ?";
    $params[] = $filtre_type;
}
if ($filtre_severite !== '') {
    $sql .= " AND severite = ?";
    $params[] = $filtre_severite;
}
if ($filtre_pays !== '') {
    $sql .= " AND pays LIKE ?";
    $params[] = "%$filtre_pays%";
}
if ($filtre_ip !== '') {
    $sql .= " AND ip_address LIKE ?";
    $params[] = "%$filtre_ip%";
}
if ($recherche !== '') {
    $sql .= " AND (url LIKE ? OR payload LIKE ? OR outil_detecte LIKE ? OR signature_detectee LIKE ?)";
    $like = "%$recherche%";
    array_push($params, $like, $like, $like, $like);
}

$sql .= " ORDER BY date_attaque DESC LIMIT 500";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$attaques = $stmt->fetchAll();

/* ============================================================
   STATISTIQUES
   ============================================================ */
$stats = [
    'total' => 0, '24h' => 0, '7j' => 0,
    'critique' => 0, 'elevee' => 0, 'moyenne' => 0, 'faible' => 0,
    'ip_uniques' => 0, 'ips_bloquees' => 0,
];

try {
    $stats['total']      = (int)$pdo->query("SELECT COUNT(*) FROM Attaques_Detectees")->fetchColumn();
    $stats['24h']        = (int)$pdo->query("SELECT COUNT(*) FROM Attaques_Detectees WHERE date_attaque > DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();
    $stats['7j']         = (int)$pdo->query("SELECT COUNT(*) FROM Attaques_Detectees WHERE date_attaque > DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn();
    $stats['critique']   = (int)$pdo->query("SELECT COUNT(*) FROM Attaques_Detectees WHERE severite = 'critique'")->fetchColumn();
    $stats['elevee']     = (int)$pdo->query("SELECT COUNT(*) FROM Attaques_Detectees WHERE severite = 'elevee'")->fetchColumn();
    $stats['moyenne']    = (int)$pdo->query("SELECT COUNT(*) FROM Attaques_Detectees WHERE severite = 'moyenne'")->fetchColumn();
    $stats['faible']     = (int)$pdo->query("SELECT COUNT(*) FROM Attaques_Detectees WHERE severite = 'faible'")->fetchColumn();
    $stats['ip_uniques'] = (int)$pdo->query("SELECT COUNT(DISTINCT ip_address) FROM Attaques_Detectees")->fetchColumn();
    $stats['ips_bloquees'] = (int)$pdo->query("
        SELECT COUNT(*) FROM IPs_Bloquees
        WHERE est_active = TRUE AND (date_expiration IS NULL OR date_expiration > NOW())
    ")->fetchColumn();
} catch (Throwable $e) { /* */ }

/* ============================================================
   TOP ATTAQUANTS (7 jours)
   ============================================================ */
$top_attaquants = [];
try {
    $top_attaquants = $pdo->query("
        SELECT 
            ip_address,
            MAX(pays) AS pays, MAX(ville) AS ville,
            MAX(latitude) AS latitude, MAX(longitude) AS longitude,
            COUNT(*) AS nb_attaques,
            MAX(date_attaque) AS derniere,
            SUM(CASE WHEN severite = 'critique' THEN 1 ELSE 0 END) AS nb_critiques,
            GROUP_CONCAT(DISTINCT type_attaque ORDER BY type_attaque SEPARATOR ', ') AS types
        FROM Attaques_Detectees
        WHERE date_attaque > DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY ip_address
        ORDER BY nb_attaques DESC
        LIMIT 10
    ")->fetchAll();
} catch (Throwable $e) { /* */ }

/* ============================================================
   PAYS LES PLUS ATTAQUANTS
   ============================================================ */
$top_pays = [];
try {
    $top_pays = $pdo->query("
        SELECT pays, COUNT(*) AS nb, COUNT(DISTINCT ip_address) AS nb_ips
        FROM Attaques_Detectees
        WHERE pays IS NOT NULL AND date_attaque > DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY pays
        ORDER BY nb DESC
        LIMIT 8
    ")->fetchAll();
} catch (Throwable $e) { /* */ }

/* ============================================================
   PROFILE ATTAQUANT (si IP sélectionnée)
   ============================================================ */
$profil_ip = trim($_GET['profil'] ?? '');
$profil_data = null;
$profil_attaques = [];

if ($profil_ip !== '' && filter_var($profil_ip, FILTER_VALIDATE_IP)) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) AS total,
                MIN(date_attaque) AS premiere,
                MAX(date_attaque) AS derniere,
                MAX(pays) AS pays, MAX(ville) AS ville,
                MAX(latitude) AS latitude, MAX(longitude) AS longitude,
                MAX(user_agent) AS user_agent,
                COUNT(DISTINCT type_attaque) AS nb_types,
                SUM(CASE WHEN severite = 'critique' THEN 1 ELSE 0 END) AS critiques,
                SUM(CASE WHEN severite = 'elevee' THEN 1 ELSE 0 END) AS elevees
            FROM Attaques_Detectees WHERE ip_address = ?
        ");
        $stmt->execute([$profil_ip]);
        $profil_data = $stmt->fetch();

        $stmt = $pdo->prepare("
            SELECT * FROM Attaques_Detectees
            WHERE ip_address = ?
            ORDER BY date_attaque DESC LIMIT 50
        ");
        $stmt->execute([$profil_ip]);
        $profil_attaques = $stmt->fetchAll();
    } catch (Throwable $e) {}
}

/* Flash */
if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

$base = '/african-trust-fund';
logAction($pdo, 'admin', $admin_id, 'ATTAQUES_VIEW', 'Consultation des attaques');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Attaques détectées — ATF Admin</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/admin-premium.css">
<style>
/* ============================================================
   🎯 BOUTONS DE DÉFENSE RAPIDE
   ============================================================ */
.defense-panel {
    padding: 20px;
    border-radius: 16px;
    background: linear-gradient(135deg, rgba(239,68,68,.08), rgba(153,27,27,.04));
    border: 2px solid rgba(239,68,68,.4);
    margin-bottom: 24px;
    animation: pulseAlert 3s ease-in-out infinite;
}
@keyframes pulseAlert {
    0%, 100% { box-shadow: 0 0 0 0 rgba(239,68,68,.4); }
    50%      { box-shadow: 0 0 0 12px rgba(239,68,68,0); }
}
.defense-panel h3 {
    color: #FCA5A5;
    font-size: 1.15rem;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.defense-panel p {
    color: var(--muted);
    font-size: .85rem;
    margin-bottom: 16px;
}
.defense-actions {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 10px;
}

/* Boutons de défense */
.btn-defense {
    padding: 14px 18px;
    border-radius: 12px;
    border: 2px solid;
    font-weight: 800;
    font-size: .85rem;
    cursor: pointer;
    font-family: inherit;
    display: flex;
    align-items: center;
    gap: 10px;
    transition: all .25s;
    text-align: left;
    text-decoration: none;
    color: inherit;
}
.btn-defense:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(0,0,0,.3);
}
.btn-defense .icon { font-size: 1.4rem; flex-shrink: 0; }
.btn-defense .text { display: flex; flex-direction: column; }
.btn-defense .text strong { font-size: .88rem; margin-bottom: 2px; }
.btn-defense .text small { font-size: .7rem; opacity: .8; font-weight: 500; }

.btn-defense.danger {
    background: rgba(239,68,68,.15);
    color: #FCA5A5;
    border-color: rgba(239,68,68,.5);
}
.btn-defense.danger:hover { background: rgba(239,68,68,.3); }

.btn-defense.warning {
    background: rgba(245,184,0,.15);
    color: #FCD34D;
    border-color: rgba(245,184,0,.5);
}
.btn-defense.warning:hover { background: rgba(245,184,0,.3); }

.btn-defense.info {
    background: rgba(59,130,246,.15);
    color: #93B4FF;
    border-color: rgba(59,130,246,.5);
}
.btn-defense.info:hover { background: rgba(59,130,246,.3); }

.btn-defense.success {
    background: rgba(16,185,129,.15);
    color: #6EE7B7;
    border-color: rgba(16,185,129,.5);
}
.btn-defense.success:hover { background: rgba(16,185,129,.3); }

/* ============================================================
   PROFIL ATTAQUANT
   ============================================================ */
.profil-overlay {
    position: fixed;
    inset: 0;
    background: rgba(11,14,20,.92);
    backdrop-filter: blur(12px);
    z-index: 99999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    animation: fadeIn .3s ease;
}
.profil-overlay.hidden { display: none; }
.profil-box {
    background: linear-gradient(145deg, #1A2130, #121824);
    border: 2px solid #991B1B;
    border-radius: 24px;
    max-width: 900px;
    width: 100%;
    max-height: 92vh;
    overflow-y: auto;
    padding: 32px;
    animation: modalIn .35s cubic-bezier(.16,1,.3,1);
}
@keyframes modalIn {
    from { opacity: 0; transform: translateY(-30px) scale(.95); }
    to   { opacity: 1; transform: translateY(0) scale(1); }
}
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

.profil-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 16px;
    padding-bottom: 20px;
    border-bottom: 2px solid rgba(239,68,68,.3);
    margin-bottom: 22px;
    flex-wrap: wrap;
}
.profil-header h2 {
    color: #FCA5A5;
    font-size: 1.4rem;
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 8px;
}
.profil-header .ip-display {
    font-family: monospace;
    color: #F5B800;
    font-weight: 900;
    font-size: 1.05rem;
    padding: 6px 12px;
    background: rgba(245,184,0,.1);
    border-radius: 8px;
    border: 1px solid rgba(245,184,0,.3);
    display: inline-block;
}
.btn-close-modal {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: rgba(239,68,68,.15);
    border: 2px solid rgba(239,68,68,.4);
    color: #FCA5A5;
    cursor: pointer;
    font-size: 1.2rem;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all .2s;
    flex-shrink: 0;
}
.btn-close-modal:hover {
    background: rgba(239,68,68,.3);
    transform: rotate(90deg);
}

.profil-stats {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 12px;
    margin-bottom: 22px;
}
.profil-stat {
    padding: 16px;
    border-radius: 12px;
    background: rgba(11,14,20,.6);
    border: 1px solid var(--border);
    text-align: center;
}
.profil-stat .lbl {
    font-size: .68rem;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 1px;
    font-weight: 800;
    margin-bottom: 6px;
}
.profil-stat .val {
    font-size: 1.3rem;
    font-weight: 900;
    color: var(--gold);
}
.profil-stat.red .val { color: #FCA5A5; }
.profil-stat.orange .val { color: #FCD34D; }
.profil-stat.green .val { color: #6EE7B7; }

.profil-actions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 10px;
    margin-bottom: 22px;
}

.profil-timeline {
    max-height: 300px;
    overflow-y: auto;
    padding-right: 8px;
}
.timeline-item {
    padding: 10px 14px;
    border-radius: 10px;
    background: rgba(11,14,20,.5);
    border-left: 3px solid #EF4444;
    margin-bottom: 8px;
    font-size: .82rem;
    display: flex;
    justify-content: space-between;
    gap: 12px;
    flex-wrap: wrap;
}
.timeline-item .date { color: var(--muted); font-size: .75rem; }
.timeline-item .type { color: #A78BFA; font-weight: 700; }
.timeline-item .sev { font-size: .7rem; padding: 2px 8px; border-radius: 999px; }
.timeline-item .sev.critique { background: rgba(153,27,27,.3); color: #FCA5A5; }
.timeline-item .sev.elevee { background: rgba(239,68,68,.2); color: #FCA5A5; }
.timeline-item .sev.moyenne { background: rgba(245,184,0,.2); color: #FCD34D; }
.timeline-item .sev.faible { background: rgba(107,114,128,.2); color: #9CA3AF; }

/* ============================================================
   MODAL CONFIRMATION BLOQUER IP
   ============================================================ */
.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(11,14,20,.92);
    backdrop-filter: blur(12px);
    z-index: 99999;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    animation: fadeIn .25s ease;
}
.modal-overlay.hidden { display: none; }
.modal-box {
    background: linear-gradient(145deg, #1A2130, #121824);
    border-radius: 22px;
    border: 1px solid rgba(239,68,68,.4);
    max-width: 540px;
    width: 100%;
    padding: 32px 30px;
    max-height: 90vh;
    overflow-y: auto;
    animation: modalIn .3s cubic-bezier(.16,1,.3,1);
}

/* Boutons d'action dans le tableau */
.btn-action {
    padding: 6px 10px;
    border-radius: 8px;
    font-size: .7rem;
    font-weight: 800;
    border: 1px solid;
    cursor: pointer;
    font-family: inherit;
    transition: all .2s;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 4px;
    margin: 2px 0;
    white-space: nowrap;
}
.btn-action.bloquer {
    background: rgba(239,68,68,.15);
    color: #FCA5A5;
    border-color: rgba(239,68,68,.3);
}
.btn-action.bloquer:hover { background: rgba(239,68,68,.3); }
.btn-action.investigate {
    background: rgba(139,92,246,.15);
    color: #A78BFA;
    border-color: rgba(139,92,246,.3);
}
.btn-action.investigate:hover { background: rgba(139,92,246,.3); }
.btn-action.kill {
    background: rgba(245,184,0,.15);
    color: #FCD34D;
    border-color: rgba(245,184,0,.3);
}
.btn-action.kill:hover { background: rgba(245,184,0,.3); }

/* Reste du style existant... */
.attack-hero {
    padding: 28px 32px;
    border-radius: 20px;
    background: linear-gradient(135deg, rgba(239,68,68,.15), rgba(153,27,27,.05));
    border: 1px solid rgba(239,68,68,.4);
    margin-bottom: 24px;
}
.attack-hero h1 {
    color: #FCA5A5;
    font-size: 1.6rem;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.attack-hero p { color: var(--muted); font-size: .92rem; }
.attack-hero .badges { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 14px; }

.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 14px;
    margin-bottom: 24px;
}
.kpi-attack {
    padding: 18px;
    border-radius: 16px;
    background: linear-gradient(145deg, var(--card), var(--card-2));
    border: 1px solid var(--border);
    text-align: center;
    transition: all .25s;
    text-decoration: none;
    color: inherit;
}
.kpi-attack:hover {
    transform: translateY(-3px);
    border-color: rgba(239,68,68,.4);
}
.kpi-attack .ico { font-size: 1.8rem; margin-bottom: 8px; }
.kpi-attack .val { font-size: 1.8rem; font-weight: 900; line-height: 1; }
.kpi-attack .lbl {
    font-size: .7rem;
    color: var(--muted);
    text-transform: uppercase;
    font-weight: 700;
    margin-top: 6px;
    letter-spacing: 1px;
}
.kpi-attack.red .val { color: #FCA5A5; }
.kpi-attack.orange .val { color: #FCD34D; }
.kpi-attack.blue .val { color: #93B4FF; }
.kpi-attack.green .val { color: #6EE7B7; }

.section-card {
    padding: 22px;
    border-radius: 16px;
    background: linear-gradient(145deg, var(--card), var(--card-2));
    border: 1px solid var(--border);
    margin-bottom: 22px;
}
.section-card h3 {
    color: var(--gold);
    font-size: 1.05rem;
    margin-bottom: 16px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.top-attacker {
    padding: 14px;
    border-radius: 12px;
    background: linear-gradient(145deg, rgba(239,68,68,.06), rgba(11,14,20,.4));
    border: 1px solid rgba(239,68,68,.25);
    margin-bottom: 8px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    transition: all .2s;
}
.top-attacker:hover {
    border-color: rgba(239,68,68,.5);
    transform: translateX(4px);
}
.top-attacker .rank {
    width: 34px; height: 34px;
    border-radius: 50%;
    background: linear-gradient(135deg, #991B1B, #EF4444);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 900;
    font-size: .85rem;
    flex-shrink: 0;
}
.top-attacker .info { flex: 1; min-width: 180px; }
.top-attacker .info .ip {
    font-family: monospace;
    color: #FCA5A5;
    font-weight: 800;
    font-size: .9rem;
}
.top-attacker .info .loc {
    color: var(--muted);
    font-size: .75rem;
    margin-top: 2px;
}
.top-attacker .info .types {
    color: #FCD34D;
    font-size: .7rem;
    margin-top: 3px;
    font-style: italic;
}
.top-attacker .count {
    text-align: right;
    color: #FCA5A5;
    font-weight: 900;
    font-size: 1.2rem;
}

.filters-bar {
    display: grid;
    grid-template-columns: 1.5fr 1fr 1fr 1fr 1fr auto;
    gap: 10px;
    padding: 16px;
    background: linear-gradient(145deg, var(--card), var(--card-2));
    border: 1px solid var(--border);
    border-radius: 14px;
    margin-bottom: 20px;
}
.filters-bar input,
.filters-bar select {
    padding: 11px 14px;
    background: var(--bg-1);
    border: 1px solid var(--border);
    border-radius: 10px;
    color: var(--text);
    font-size: .85rem;
    font-family: inherit;
}
.filters-bar input:focus,
.filters-bar select:focus {
    outline: none;
    border-color: var(--gold);
}

.attack-table {
    width: 100%;
    border-collapse: collapse;
    background: linear-gradient(145deg, var(--card), var(--card-2));
    border-radius: 14px;
    overflow: hidden;
    font-size: .85rem;
}
.attack-table thead th {
    padding: 12px 10px;
    text-align: left;
    font-size: .68rem;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: .5px;
    font-weight: 800;
    background: rgba(11,14,20,.5);
    border-bottom: 2px solid var(--border);
    white-space: nowrap;
}
.attack-table tbody td {
    padding: 12px 10px;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
}
.attack-table tbody tr:hover {
    background: rgba(239,68,68,.04);
}

.sev-badge {
    padding: 4px 12px;
    border-radius: 999px;
    font-size: .68rem;
    font-weight: 800;
    text-transform: uppercase;
    white-space: nowrap;
    display: inline-block;
}
.sev-badge.critique { background: rgba(153,27,27,.3); color: #FCA5A5; border: 1px solid #991B1B; }
.sev-badge.elevee { background: rgba(239,68,68,.2); color: #FCA5A5; border: 1px solid rgba(239,68,68,.5); }
.sev-badge.moyenne { background: rgba(245,184,0,.2); color: #FCD34D; border: 1px solid rgba(245,184,0,.5); }
.sev-badge.faible { background: rgba(107,114,128,.2); color: #9CA3AF; border: 1px solid rgba(107,114,128,.4); }

.type-badge {
    padding: 4px 10px;
    border-radius: 6px;
    font-size: .7rem;
    font-weight: 800;
    background: rgba(139,92,246,.15);
    color: #A78BFA;
    border: 1px solid rgba(139,92,246,.3);
    font-family: monospace;
    white-space: nowrap;
    display: inline-block;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--muted);
    background: linear-gradient(145deg, var(--card), var(--card-2));
    border-radius: 14px;
    border: 1px solid var(--border);
}
.empty-state .ico { font-size: 4rem; opacity: .4; margin-bottom: 16px; }

@media (max-width: 768px) {
    .defense-actions { grid-template-columns: 1fr; }
    .kpi-grid { grid-template-columns: 1fr 1fr; }
    .filters-bar { grid-template-columns: 1fr; }
    .attack-table thead { display: none; }
    .attack-table tbody tr {
        display: block;
        margin-bottom: 12px;
        padding: 14px;
        border: 1px solid var(--border);
        border-radius: 12px;
    }
    .attack-table td {
        display: flex;
        justify-content: space-between;
        padding: 6px 0;
        border: none;
    }
    .attack-table td::before {
        content: attr(data-label);
        color: var(--muted);
        font-size: .7rem;
        font-weight: 700;
        text-transform: uppercase;
    }
}
</style>
</head>
<body class="dark-auth">

<?php require __DIR__ . '/../includes/header.php'; ?>

<div class="admin-layout">
  <aside class="admin-sidebar animate-left">
    <h3>📊 Navigation</h3>
    <a href="<?= $base ?>/admin/dashboard.php"><span class="ico">📊</span> Dashboard</a>
    <a href="<?= $base ?>/admin/clients.php"><span class="ico">👥</span> Clients</a>
    <a href="<?= $base ?>/admin/attaques.php" class="active"><span class="ico">🚨</span> Attaques</a>
    <a href="<?= $base ?>/admin/securite_appareils.php"><span class="ico">🛡️</span> Appareils</a>
    <a href="<?= $base ?>/admin/ips_bloquees.php"><span class="ico">🚫</span> IP Bloquées</a>
    <a href="<?= $base ?>/admin/logs.php"><span class="ico">📜</span> Journal</a>
    <a href="<?= $base ?>/auth/logout.php"><span class="ico">🚪</span> Déconnexion</a>
  </aside>

  <main class="admin-main">

    <!-- HERO -->
    <div class="attack-hero">
      <h1>🚨 Centre d'analyse des attaques</h1>
      <p>Surveillance et détection des tentatives de pénétration</p>
      <div class="badges">
        <span class="badge" style="background:rgba(239,68,68,.15);color:#FCA5A5;border:1px solid rgba(239,68,68,.4);padding:6px 14px;border-radius:8px;font-size:.8rem;font-weight:700">
          🔴 <?= $stats['24h'] ?> attaques (24h)
        </span>
        <span class="badge" style="background:rgba(245,184,0,.15);color:var(--gold);border:1px solid rgba(245,184,0,.4);padding:6px 14px;border-radius:8px;font-size:.8rem;font-weight:700">
          📊 <?= $stats['7j'] ?> attaques (7 jours)
        </span>
        <span class="badge" style="background:rgba(59,130,246,.15);color:#93B4FF;border:1px solid rgba(59,130,246,.4);padding:6px 14px;border-radius:8px;font-size:.8rem;font-weight:700">
          🌐 <?= $stats['ip_uniques'] ?> IPs uniques
        </span>
        <span class="badge" style="background:rgba(153,27,27,.25);color:#FCA5A5;border:1px solid #991B1B;padding:6px 14px;border-radius:8px;font-size:.8rem;font-weight:700">
          🚫 <?= $stats['ips_bloquees'] ?> IPs bloquées
        </span>
      </div>
    </div>

    <?php if ($success): ?>
      <div class="alert success"><?= $success ?></div>
    <?php endif; ?>
    <?php if ($errors): ?>
      <div class="alert error"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
    <?php endif; ?>

    <!-- ═══════════════════════════════════════════════════════
         🎯 PANNEAU DE DÉFENSE RAPIDE
         ═══════════════════════════════════════════════════════ -->
    <?php if ($stats['24h'] > 0): ?>
    <div class="defense-panel">
      <h3>⚔️ Actions de défense rapide</h3>
      <p>Réagissez immédiatement face aux menaces détectées</p>

      <div class="defense-actions">
        <button class="btn-defense danger" onclick="ouvrirBlocageRapide()">
          <span class="icon">🚫</span>
          <span class="text">
            <strong>Bloquer une IP</strong>
            <small>Blocage manuel immédiat</small>
          </span>
        </button>

        <?php if (!empty($top_pays)): ?>
        <button class="btn-defense warning" onclick="ouvrirBlocagePays()">
          <span class="icon">🌍</span>
          <span class="text">
            <strong>Bloquer un pays</strong>
            <small>Bannir toutes les IPs d'un pays</small>
          </span>
        </button>
        <?php endif; ?>

        <a href="<?= $base ?>/admin/ips_bloquees.php" class="btn-defense info">
          <span class="icon">📋</span>
          <span class="text">
            <strong>Voir IPs bloquées</strong>
            <small><?= $stats['ips_bloquees'] ?> IP(s) active(s)</small>
          </span>
        </a>

        <form method="POST" style="display:contents" onsubmit="return confirm('Marquer toutes les attaques comme traitées ?')">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="tout_traiter">
          <button class="btn-defense success" type="submit">
            <span class="icon">✅</span>
            <span class="text">
              <strong>Tout marquer traité</strong>
              <small>Nettoyer la liste</small>
            </span>
          </button>
        </form>
      </div>
    </div>
    <?php endif; ?>

    <!-- KPIs -->
    <section class="kpi-grid">
      <a href="?" class="kpi-attack">
        <div class="ico">📊</div>
        <div class="val"><?= $stats['total'] ?></div>
        <div class="lbl">Total</div>
      </a>
      <a href="?severite=critique" class="kpi-attack red">
        <div class="ico">💀</div>
        <div class="val"><?= $stats['critique'] ?></div>
        <div class="lbl">Critique</div>
      </a>
      <a href="?severite=elevee" class="kpi-attack orange">
        <div class="ico">🔴</div>
        <div class="val"><?= $stats['elevee'] ?></div>
        <div class="lbl">Élevée</div>
      </a>
      <a href="?severite=moyenne" class="kpi-attack orange">
        <div class="ico">🟡</div>
        <div class="val"><?= $stats['moyenne'] ?></div>
        <div class="lbl">Moyenne</div>
      </a>
      <a href="?severite=faible" class="kpi-attack">
        <div class="ico">⚪</div>
        <div class="val"><?= $stats['faible'] ?></div>
        <div class="lbl">Faible</div>
      </a>
      <a href="?" class="kpi-attack blue">
        <div class="ico">🕐</div>
        <div class="val"><?= $stats['24h'] ?></div>
        <div class="lbl">24h</div>
      </a>
    </section>

    <!-- TOP ATTAQUANTS -->
    <?php if (!empty($top_attaquants)): ?>
    <div class="section-card">
      <h3>🏴‍☠️ Top 10 attaquants (7 jours)</h3>
      <?php foreach ($top_attaquants as $i => $att): ?>
        <div class="top-attacker">
          <div class="rank"><?= $i + 1 ?></div>
          <div class="info">
            <div class="ip"><?= htmlspecialchars($att['ip_address']) ?></div>
            <div class="loc">
              🌍 <?= htmlspecialchars(($att['ville'] ?? '?') . ', ' . ($att['pays'] ?? '?')) ?>
              · Dernière : <?= date('d/m H:i', strtotime($att['derniere'])) ?>
            </div>
            <?php if (!empty($att['types'])): ?>
              <div class="types">🎯 <?= htmlspecialchars($att['types']) ?></div>
            <?php endif; ?>
          </div>
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <div class="count">
              <?= (int)$att['nb_attaques'] ?><br>
              <small style="font-size:.7rem;color:var(--muted);font-weight:500">attaques</small>
            </div>
            <a href="?profil=<?= urlencode($att['ip_address']) ?>" class="btn-action investigate">
              🔍 Profil
            </a>
            <button class="btn-action bloquer" onclick="ouvrirBlocageIP('<?= htmlspecialchars($att['ip_address'], ENT_QUOTES) ?>', 'Multiples attaques détectées (<?= (int)$att['nb_attaques'] ?>)')">
              🚫 Bloquer
            </button>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- TABLEAU DES ATTAQUES -->
    <div class="section-card">
      <h3>📋 Détail des attaques (<?= count($attaques) ?>)</h3>

      <?php if (empty($attaques)): ?>
        <div class="empty-state">
          <div class="ico">🛡️</div>
          <h3 style="color:var(--text);margin-bottom:8px">Aucune attaque détectée</h3>
          <p>Votre système est protégé. Aucune tentative d'intrusion enregistrée.</p>
        </div>
      <?php else: ?>
        <div style="overflow-x:auto">
          <table class="attack-table">
            <thead>
              <tr>
                <th>Date</th>
                <th>IP</th>
                <th>Localisation</th>
                <th>Type</th>
                <th>Sévérité</th>
                <th>Actions rapides</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($attaques as $a): ?>
                <tr>
                  <td data-label="Date">
                    <strong><?= date('d/m H:i:s', strtotime($a['date_attaque'])) ?></strong>
                  </td>
                  <td data-label="IP">
                    <code style="color:#FCA5A5;font-weight:800"><?= htmlspecialchars($a['ip_address']) ?></code>
                  </td>
                  <td data-label="Localisation">
                    🌍 <?= htmlspecialchars(($a['ville'] ?? '?') . ', ' . ($a['pays'] ?? '?')) ?>
                  </td>
                  <td data-label="Type">
                    <span class="type-badge"><?= htmlspecialchars($a['type_attaque']) ?></span>
                    <?php if (!empty($a['outil_detecte'])): ?>
                      <br><small style="color:var(--gold);font-size:.68rem">🛠️ <?= htmlspecialchars($a['outil_detecte']) ?></small>
                    <?php endif; ?>
                  </td>
                  <td data-label="Sévérité">
                    <span class="sev-badge <?= htmlspecialchars($a['severite']) ?>">
                      <?= htmlspecialchars($a['severite']) ?>
                    </span>
                    <br>
                    <small style="color:#FCA5A5;font-weight:800;font-size:.7rem">
                      Score : <?= (int)$a['score_menace'] ?>/100
                    </small>
                  </td>
                  <td data-label="Actions">
                    <a href="?profil=<?= urlencode($a['ip_address']) ?>" class="btn-action investigate">
                      🔍
                    </a>
                    <button class="btn-action bloquer" onclick="ouvrirBlocageIP('<?= htmlspecialchars($a['ip_address'], ENT_QUOTES) ?>', 'Attaque détectée : <?= htmlspecialchars($a['type_attaque'], ENT_QUOTES) ?>')">
                      🚫
                    </button>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Fermer toutes les sessions de cette IP ?')">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="kill_sessions">
                      <input type="hidden" name="ip" value="<?= htmlspecialchars($a['ip_address']) ?>">
                      <button class="btn-action kill" type="submit" title="Tuer les sessions">🔪</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

  </main>
</div>

<!-- ═══════════════════════════════════════════════════════
     MODAL : BLOQUER UNE IP
     ═══════════════════════════════════════════════════════ -->
<div class="modal-overlay hidden" id="modalBlocageIP">
  <div class="modal-box">
    <h3 style="color:#FCA5A5;font-size:1.2rem;margin-bottom:18px;display:flex;align-items:center;gap:10px">
      🚫 Bloquer une adresse IP
    </h3>

    <div style="padding:14px;border-radius:12px;background:rgba(239,68,68,.08);
                border:1px solid rgba(239,68,68,.3);color:#FCA5A5;font-size:.85rem;
                line-height:1.6;margin-bottom:20px">
      ⚠️ <strong>Action de sécurité</strong><br>
      L'IP sera bloquée et toutes ses sessions seront terminées.
    </div>

    <form method="POST" id="formBlocageIP">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="bloquer_ip">

      <label style="display:block;margin-bottom:14px">
        <span style="color:var(--muted);font-size:.78rem;text-transform:uppercase;
                     font-weight:800;display:block;margin-bottom:6px">
          🌐 Adresse IP
        </span>
        <input type="text" name="ip" id="inputIp" required
               style="width:100%;padding:12px;background:var(--bg-1);
                      border:1px solid var(--border);border-radius:10px;
                      color:#FCA5A5;font-family:monospace;font-weight:700;
                      font-size:.95rem">
      </label>

      <label style="display:block;margin-bottom:14px">
        <span style="color:var(--muted);font-size:.78rem;text-transform:uppercase;
                     font-weight:800;display:block;margin-bottom:6px">
          Type de blocage
        </span>
        <select name="type_blocage" id="selectTypeBlocage" onchange="toggleDuree()"
                style="width:100%;padding:12px;background:var(--bg-1);
                       border:1px solid var(--border);border-radius:10px;
                       color:var(--text);font-family:inherit">
          <option value="temporaire">⏱ Temporaire (durée limitée)</option>
          <option value="permanent" selected>🚫 Permanent</option>
          <option value="suspendu">⏸ Suspendu (indéfini)</option>
        </select>
      </label>

      <label style="display:block;margin-bottom:14px" id="dureeField" hidden>
        <span style="color:var(--muted);font-size:.78rem;text-transform:uppercase;
                     font-weight:800;display:block;margin-bottom:6px">
          Durée (minutes)
        </span>
        <select name="duree_minutes"
                style="width:100%;padding:12px;background:var(--bg-1);
                       border:1px solid var(--border);border-radius:10px;
                       color:var(--text);font-family:inherit">
          <option value="5">5 minutes</option>
          <option value="30">30 minutes</option>
          <option value="60">1 heure</option>
          <option value="240">4 heures</option>
          <option value="1440" selected>24 heures</option>
          <option value="10080">7 jours</option>
          <option value="43200">30 jours</option>
        </select>
      </label>

      <label style="display:block;margin-bottom:16px">
        <span style="color:var(--muted);font-size:.78rem;text-transform:uppercase;
                     font-weight:800;display:block;margin-bottom:6px">
          Raison du blocage *
        </span>
        <textarea name="raison" id="inputRaison" required rows="3" maxlength="500"
                  style="width:100%;padding:12px;background:var(--bg-1);
                         border:1px solid var(--border);border-radius:10px;
                         color:var(--text);font-family:inherit;resize:vertical;
                         font-size:.88rem"
                  placeholder="Ex: Tentative de pénétration SQL Injection..."></textarea>
      </label>

      <div style="display:flex;gap:12px;justify-content:flex-end;margin-top:20px;
                  padding-top:16px;border-top:1px solid var(--border)">
        <button type="button" onclick="fermerModalBlocageIP()"
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
                       font-family:inherit;box-shadow:0 8px 20px rgba(220,38,38,.35)">
          🚫 Confirmer le blocage
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     MODAL : BLOQUER UN PAYS
     ═══════════════════════════════════════════════════════ -->
<div class="modal-overlay hidden" id="modalBlocagePays">
  <div class="modal-box">
    <h3 style="color:#FCA5A5;font-size:1.2rem;margin-bottom:18px;display:flex;align-items:center;gap:10px">
      🌍 Bloquer un pays entier
    </h3>

    <div style="padding:14px;border-radius:12px;background:rgba(245,184,0,.08);
                border:1px solid rgba(245,184,0,.3);color:#FCD34D;font-size:.85rem;
                line-height:1.6;margin-bottom:20px">
      ⚠️ <strong>Attention</strong><br>
      Toutes les IPs attaquantes de ce pays seront bloquées définitivement.
    </div>

    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="bloquer_pays">

      <label style="display:block;margin-bottom:16px">
        <span style="color:var(--muted);font-size:.78rem;text-transform:uppercase;
                     font-weight:800;display:block;margin-bottom:6px">
          Sélectionner un pays
        </span>
        <select name="pays" required
                style="width:100%;padding:12px;background:var(--bg-1);
                       border:1px solid var(--border);border-radius:10px;
                       color:var(--text);font-family:inherit">
          <option value="">— Choisir —</option>
          <?php foreach ($top_pays as $p): ?>
            <option value="<?= htmlspecialchars($p['pays']) ?>">
              <?= htmlspecialchars($p['pays']) ?> (<?= (int)$p['nb'] ?> attaques, <?= (int)$p['nb_ips'] ?> IPs)
            </option>
          <?php endforeach; ?>
        </select>
      </label>

      <label style="display:block;margin-bottom:16px">
        <span style="color:var(--muted);font-size:.78rem;text-transform:uppercase;
                     font-weight:800;display:block;margin-bottom:6px">
          Raison
        </span>
        <textarea name="raison" rows="2" maxlength="500"
                  style="width:100%;padding:12px;background:var(--bg-1);
                         border:1px solid var(--border);border-radius:10px;
                         color:var(--text);font-family:inherit;resize:vertical;
                         font-size:.88rem">Blocage pays entier suite à multiples attaques</textarea>
      </label>

      <div style="display:flex;gap:12px;justify-content:flex-end;padding-top:16px;
                  border-top:1px solid var(--border)">
        <button type="button" onclick="document.getElementById('modalBlocagePays').classList.add('hidden')"
                style="padding:11px 20px;border-radius:10px;
                       background:rgba(255,255,255,.05);color:var(--muted);
                       border:1px solid var(--border);cursor:pointer;
                       font-family:inherit;font-weight:700">
          Annuler
        </button>
        <button type="submit"
                style="padding:11px 22px;border-radius:10px;
                       background:linear-gradient(135deg,#D97706,#F59E0B);
                       color:#fff;border:none;font-weight:800;cursor:pointer;
                       font-family:inherit">
          🌍 Bloquer ce pays
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════
     MODAL : PROFIL ATTAQUANT
     ═══════════════════════════════════════════════════════ -->
<?php if ($profil_data && $profil_ip): ?>
<div class="profil-overlay" id="modalProfil">
  <div class="profil-box">
    <div class="profil-header">
      <div>
        <h2>🏴‍☠️ Profil de l'attaquant</h2>
        <div class="ip-display"><?= htmlspecialchars($profil_ip) ?></div>
        <div style="color:var(--muted);font-size:.8rem;margin-top:8px">
          🌍 <?= htmlspecialchars(($profil_data['ville'] ?? '?') . ', ' . ($profil_data['pays'] ?? '?')) ?>
        </div>
      </div>
      <button class="btn-close-modal" onclick="window.location.href='?'">✕</button>
    </div>

    <div class="profil-stats">
      <div class="profil-stat red">
        <div class="lbl">Total attaques</div>
        <div class="val"><?= (int)$profil_data['total'] ?></div>
      </div>
      <div class="profil-stat orange">
        <div class="lbl">Critiques</div>
        <div class="val"><?= (int)$profil_data['critiques'] ?></div>
      </div>
      <div class="profil-stat orange">
        <div class="lbl">Élevées</div>
        <div class="val"><?= (int)$profil_data['elevees'] ?></div>
      </div>
      <div class="profil-stat">
        <div class="lbl">Types différents</div>
        <div class="val"><?= (int)$profil_data['nb_types'] ?></div>
      </div>
    </div>

    <div style="margin-bottom:16px;color:var(--muted);font-size:.82rem">
      <strong style="color:var(--text)">Première attaque :</strong>
      <?= date('d/m/Y H:i', strtotime($profil_data['premiere'])) ?><br>
      <strong style="color:var(--text)">Dernière attaque :</strong>
      <?= date('d/m/Y H:i', strtotime($profil_data['derniere'])) ?>
    </div>

    <!-- ACTIONS RAPIDES SUR PROFIL -->
    <div class="profil-actions-grid">
      <button class="btn-defense danger"
              onclick="ouvrirBlocageIP('<?= htmlspecialchars($profil_ip, ENT_QUOTES) ?>', 'Multiples attaques — <?= (int)$profil_data['total'] ?> au total')">
        <span class="icon">🚫</span>
        <span class="text">
          <strong>Bloquer cette IP</strong>
          <small>Action immédiate</small>
        </span>
      </button>

      <form method="POST" style="display:contents" onsubmit="return confirm('Tuer toutes les sessions de cette IP ?')">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="kill_sessions">
        <input type="hidden" name="ip" value="<?= htmlspecialchars($profil_ip) ?>">
        <button class="btn-defense warning" type="submit">
          <span class="icon">🔪</span>
          <span class="text">
            <strong>Tuer les sessions</strong>
            <small>Déconnexion forcée</small>
          </span>
        </button>
      </form>

      <a href="https://www.abuseipdb.com/check/<?= urlencode($profil_ip) ?>"
         target="_blank" rel="noopener" class="btn-defense info">
        <span class="icon">🔎</span>
        <span class="text">
          <strong>Vérifier AbusIPDB</strong>
          <small>Base mondiale de blacklist</small>
        </span>
      </a>

      <a href="https://www.google.com/maps?q=<?= (float)($profil_data['latitude'] ?? 0) ?>,<?= (float)($profil_data['longitude'] ?? 0) ?>"
         target="_blank" rel="noopener" class="btn-defense info">
        <span class="icon">🗺️</span>
        <span class="text">
          <strong>Voir sur Google Maps</strong>
          <small>Localisation approximative</small>
        </span>
      </a>
    </div>

    <!-- TIMELINE -->
    <h3 style="color:var(--gold);font-size:1rem;margin-bottom:12px">
      📜 Historique (<?= count($profil_attaques) ?> dernières attaques)
    </h3>
    <div class="profil-timeline">
      <?php foreach ($profil_attaques as $pa): ?>
        <div class="timeline-item">
          <div>
            <span class="date"><?= date('d/m/Y H:i:s', strtotime($pa['date_attaque'])) ?></span>
            · <span class="type"><?= htmlspecialchars($pa['type_attaque']) ?></span>
            <?php if (!empty($pa['outil_detecte'])): ?>
              · 🛠️ <?= htmlspecialchars($pa['outil_detecte']) ?>
            <?php endif; ?>
          </div>
          <span class="sev <?= htmlspecialchars($pa['severite']) ?>">
            <?= htmlspecialchars($pa['severite']) ?> · <?= (int)$pa['score_menace'] ?>/100
          </span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<footer class="atf-footer">© <?= date('Y') ?> AFRICAN TRUST FUND</footer>

<script>
/* ============================================================
   MODAL BLOQUER IP
   ============================================================ */
function ouvrirBlocageIP(ip, raison) {
    document.getElementById('inputIp').value = ip;
    document.getElementById('inputRaison').value = raison || '';
    document.getElementById('modalBlocageIP').classList.remove('hidden');
}

function fermerModalBlocageIP() {
    document.getElementById('modalBlocageIP').classList.add('hidden');
}

function toggleDuree() {
    const type = document.getElementById('selectTypeBlocage').value;
    document.getElementById('dureeField').hidden = (type !== 'temporaire');
}

function ouvrirBlocageRapide() {
    document.getElementById('inputIp').value = '';
    document.getElementById('inputRaison').value = '';
    document.getElementById('modalBlocageIP').classList.remove('hidden');
}

function ouvrirBlocagePays() {
    document.getElementById('modalBlocagePays').classList.remove('hidden');
}

/* Fermer sur clic overlay */
document.querySelectorAll('.modal-overlay, .profil-overlay').forEach(el => {
    el?.addEventListener('click', function(e) {
        if (e.target === this) {
            if (this.id === 'modalProfil') {
                window.location.href = '?';
            } else {
                this.classList.add('hidden');
            }
        }
    });
});

/* Fermer sur Escape */
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.getElementById('modalBlocageIP')?.classList.add('hidden');
        document.getElementById('modalBlocagePays')?.classList.add('hidden');
        if (document.getElementById('modalProfil')) {
            window.location.href = '?';
        }
    }
});
</script>

<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>