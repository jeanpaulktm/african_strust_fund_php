<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/paths.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/security_detector.php';

sendSecurityHeaders();
startSecureSession();

$base = BASE_URL;
$pdo  = Database::getConnection();
$ip   = getClientIP();

/* ============================================================
   🔒 VÉRIFICATION IMMÉDIATE : IP BLOQUÉE ?
   ============================================================ */
$blocage_actif = verifierIPBloquee($pdo, $ip);

/* 🛑 Si IP bloquée → écran de suspension total */
if ($blocage_actif) {
    if (!empty($_SESSION['client_id'])) {
        try {
            logAction($pdo, 'client', (int)$_SESSION['client_id'],
                'FORCED_LOGOUT_IP_BLOCK', 'IP bloquée');
        } catch (Throwable $e) {}
        session_unset();
        session_destroy();
    }

    $raison       = $blocage_actif['raison'] ?? 'Irrégularité détectée';
    $date_blocage = $blocage_actif['date_blocage'] ?? date('Y-m-d H:i:s');
    $expiration   = $blocage_actif['date_expiration'] ?? null;
    $type_blocage = $blocage_actif['type_blocage'] ?? 'temporaire';
    $duree        = (int)($blocage_actif['duree_minutes'] ?? 0);
    $ref          = 'BLOCK-' . strtoupper(substr(md5($ip . $date_blocage), 0, 12));

    http_response_code(403);
    ?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>🚫 Connexion suspendue — AFRICAN TRUST FUND</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
* { margin:0; padding:0; box-sizing:border-box; }
body {
    font-family:'Inter',sans-serif;
    background:radial-gradient(ellipse at top,#1a0505 0%,#080A10 60%);
    min-height:100vh; display:flex; align-items:center; justify-content:center;
    padding:20px; color:#E5E7EB;
}
.card {
    background:linear-gradient(145deg,rgba(153,27,27,.15),rgba(18,23,33,.98));
    border:2px solid #991B1B; border-radius:26px; padding:48px 36px;
    max-width:600px; width:100%; text-align:center;
    box-shadow:0 30px 80px rgba(153,27,27,.4),0 0 0 1px rgba(255,255,255,.03) inset;
    animation:cardIn .6s cubic-bezier(.16,1,.3,1);
    position:relative; overflow:hidden;
}
.card::before {
    content:""; position:absolute; top:0; left:15%; right:15%; height:1px;
    background:linear-gradient(90deg,transparent,#EF4444,transparent); opacity:.7;
}
@keyframes cardIn {
    from { opacity:0; transform:translateY(30px) scale(.97); }
    to   { opacity:1; transform:translateY(0) scale(1); }
}
.ico-block {
    width:110px; height:110px; border-radius:50%;
    background:rgba(153,27,27,.25); border:3px solid #991B1B;
    display:flex; align-items:center; justify-content:center;
    margin:0 auto 26px; animation:shake 2.5s ease-in-out infinite;
    box-shadow:0 0 50px rgba(153,27,27,.5);
}
.ico-block i { font-size:3.2rem; color:#FCA5A5; }
@keyframes shake {
    0%,100% { transform:translateX(0) scale(1); }
    25%     { transform:translateX(-5px) scale(1.03); }
    75%     { transform:translateX(5px) scale(1.03); }
}
h1 {
    color:#FCA5A5; font-size:1.75rem; font-weight:900;
    margin-bottom:14px; letter-spacing:-.5px;
}
.subtitle {
    color:#FCA5A5; font-size:1rem; line-height:1.7;
    font-weight:600; margin-bottom:22px;
}
.message-principal {
    padding:22px 24px; border-radius:14px;
    background:rgba(153,27,27,.18); border:1px solid rgba(239,68,68,.4);
    color:#FCA5A5; font-size:.98rem; line-height:1.75;
    font-weight:700; margin-bottom:26px;
}
.message-principal .highlight {
    display:block; color:#FCD34D; font-size:1.05rem;
    margin-top:10px; font-weight:800;
}
.timer-box {
    padding:18px; border-radius:14px;
    background:linear-gradient(135deg,rgba(245,184,0,.14),rgba(245,184,0,.05));
    border:1px solid rgba(245,184,0,.45);
    margin-bottom:24px;
    display:flex; align-items:center; justify-content:center; gap:14px;
}
.timer-box i { color:#F5B800; font-size:1.8rem; }
.timer-box .info { text-align:left; }
.timer-box .label {
    color:#94A3B8; font-size:.7rem;
    text-transform:uppercase; letter-spacing:1.5px; font-weight:800;
    margin-bottom:2px;
}
.timer-box .value {
    color:#F5B800; font-family:'JetBrains Mono',monospace;
    font-size:1.6rem; font-weight:900; letter-spacing:2px;
    text-shadow:0 0 20px rgba(245,184,0,.4);
}
.info-box {
    padding:22px; border-radius:14px; background:rgba(0,0,0,.5);
    border:1px solid rgba(153,27,27,.3); text-align:left;
    font-family:'JetBrains Mono','Courier New',monospace;
    font-size:.82rem; color:#94A3B8; line-height:2.1;
    margin-bottom:24px;
}
.info-box .title {
    color:#F5B800; font-family:'Inter',sans-serif;
    font-weight:800; font-size:.78rem; text-transform:uppercase;
    letter-spacing:1.3px; margin-bottom:14px;
    display:flex; align-items:center; gap:8px;
}
.info-box .row {
    display:flex; align-items:flex-start; gap:10px; padding:4px 0;
}
.info-box .row i {
    color:rgba(239,68,68,.8); width:18px; flex-shrink:0;
    margin-top:6px; font-size:.85rem;
}
.info-box .row span {
    color:#FCA5A5; font-weight:700; word-break:break-word;
}
.contact-section {
    padding-top:26px; border-top:1px solid rgba(153,27,27,.3);
}
.contact-title {
    color:#94A3B8; font-size:.78rem; font-weight:800;
    text-transform:uppercase; letter-spacing:1.5px; margin-bottom:16px;
}
.contact-buttons {
    display:flex; gap:12px; justify-content:center; flex-wrap:wrap;
}
.btn-contact {
    padding:13px 26px; border-radius:12px;
    text-decoration:none; font-weight:800; font-size:.88rem;
    display:inline-flex; align-items:center; gap:8px;
    transition:all .25s; border:none; cursor:pointer;
    font-family:inherit;
}
.btn-contact.primary {
    background:linear-gradient(135deg,#F5B800,#D4AF37);
    color:#0B0E14; box-shadow:0 8px 24px rgba(245,184,0,.4);
}
.btn-contact.primary:hover {
    transform:translateY(-2px);
    box-shadow:0 12px 32px rgba(245,184,0,.55);
}
.btn-contact.secondary {
    background:rgba(245,184,0,.12); color:#F5B800;
    border:1px solid rgba(245,184,0,.4);
}
.btn-contact.secondary:hover {
    background:rgba(245,184,0,.22); transform:translateY(-2px);
}
.btn-contact.ghost {
    background:rgba(255,255,255,.05); color:#94A3B8;
    border:1px solid rgba(255,255,255,.1);
}
.btn-contact.ghost:hover {
    background:rgba(255,255,255,.1); color:#E5E7EB;
}
.footer-note {
    margin-top:26px; padding-top:18px;
    border-top:1px solid rgba(153,27,27,.2);
    color:#64748B; font-size:.72rem; line-height:1.7;
}
.footer-note strong { color:#F5B800; }
@media (max-width:480px) {
    .card { padding:32px 22px; border-radius:20px; }
    h1 { font-size:1.4rem; }
    .ico-block { width:85px; height:85px; }
    .ico-block i { font-size:2.5rem; }
    .contact-buttons { flex-direction:column; }
    .btn-contact { justify-content:center; }
    .timer-box .value { font-size:1.3rem; }
}
</style>
</head>
<body>
    <div class="card">
        <div class="ico-block">
            <i class="fa-solid fa-ban"></i>
        </div>

        <h1>🚫 Connexion suspendue</h1>
        <p class="subtitle">Votre accès a été temporairement suspendu</p>

        <div class="message-principal">
            ⚠️ La connexion est suspendue pour un certain moment<br>
            suite à l'irrégularité de norme de système.
            <span class="highlight">📞 Veuillez contacter le service client.</span>
        </div>

        <?php if ($expiration): ?>
        <div class="timer-box">
            <i class="fa-solid fa-hourglass-half"></i>
            <div class="info">
                <div class="label">Temps restant</div>
                <div class="value" id="timerValue">--:--:--</div>
            </div>
        </div>
        <?php endif; ?>

        <div class="info-box">
            <div class="title">
                <i class="fa-solid fa-circle-info"></i> Détails du blocage
            </div>
            <div class="row">
                <i class="fa-solid fa-globe"></i>
                <div>Adresse IP : <span><?= htmlspecialchars($ip) ?></span></div>
            </div>
            <div class="row">
                <i class="fa-solid fa-comment-dots"></i>
                <div>Raison : <span><?= htmlspecialchars($raison) ?></span></div>
            </div>
            <div class="row">
                <i class="fa-regular fa-calendar"></i>
                <div>Date : <span><?= date('d/m/Y H:i', strtotime($date_blocage)) ?></span></div>
            </div>
            <div class="row">
                <i class="fa-solid fa-tag"></i>
                <div>Type : <span><?= htmlspecialchars(ucfirst($type_blocage)) ?><?= $duree > 0 ? " ({$duree} min)" : '' ?></span></div>
            </div>
            <?php if ($expiration): ?>
            <div class="row">
                <i class="fa-regular fa-clock"></i>
                <div>Fin prévue : <span><?= date('d/m/Y H:i', strtotime($expiration)) ?></span></div>
            </div>
            <?php endif; ?>
            <div class="row">
                <i class="fa-solid fa-hashtag"></i>
                <div>Référence : <span><?= htmlspecialchars($ref) ?></span></div>
            </div>
        </div>

        <div class="contact-section">
            <div class="contact-title">📞 Service client disponible 24/7</div>
            <div class="contact-buttons">
                <a href="tel:+243800000000" class="btn-contact primary">
                    <i class="fa-solid fa-phone"></i> Appeler
                </a>
                <a href="mailto:support@atf-rdc.cd" class="btn-contact secondary">
                    <i class="fa-solid fa-envelope"></i> Écrire
                </a>
                <a href="<?= $base ?>/index.php" class="btn-contact ghost">
                    <i class="fa-solid fa-house"></i> Accueil
                </a>
            </div>
        </div>

        <div class="footer-note">
            <strong>AFRICAN TRUST FUND</strong> — Sécurité & Protection<br>
            <?= date('d/m/Y H:i:s') ?>
        </div>
    </div>

    <script>
    /* ⏱ Décompte temps réel */
    <?php if ($expiration): ?>
    (function() {
        const exp = <?= (int)strtotime($expiration) ?> * 1000;
        const el = document.getElementById('timerValue');
        if (!el) return;

        function tick() {
            const diff = exp - Date.now();
            if (diff <= 0) {
                el.textContent = '00:00:00';
                setTimeout(() => location.reload(), 1500);
                return;
            }
            const h = Math.floor(diff / 3600000);
            const m = Math.floor((diff % 3600000) / 60000);
            const s = Math.floor((diff % 60000) / 1000);
            el.textContent = String(h).padStart(2,'0') + ':' +
                             String(m).padStart(2,'0') + ':' +
                             String(s).padStart(2,'0');
        }
        tick();
        setInterval(tick, 1000);
    })();
    <?php endif; ?>

    /* 🔄 Vérification toutes les 5s si le blocage est levé */
    setInterval(function() {
        fetch('<?= $base ?>/api/check_ip_status.php', {
            cache: 'no-store',
            credentials: 'same-origin'
        })
        .then(r => r.json())
        .then(d => {
            if (d.ok && !d.blocked) location.reload();
        })
        .catch(() => {});
    }, 5000);
    </script>
</body>
</html>
    <?php
    exit;
}

/* ============================================================
   Si déjà connecté → rediriger
   ============================================================ */
if (!empty($_SESSION['role'])) {
    header('Location: ' . url($_SESSION['role'] . '/dashboard.php'));
    exit;
}

$error = '';
$warning = '';

/* ============================================================
   1. IDENTIFICATION APPAREIL
   ============================================================ */
$fingerprint = genererFingerprint();
$user_agent  = $_SERVER['HTTP_USER_AGENT'] ?? '';

$device_info = [
    'fingerprint_hash' => $fingerprint,
    'ip_address'       => $ip,
    'user_agent'       => $user_agent,
    'device_type'      => detecterTypeAppareil($user_agent),
    'os'               => detecterOS($user_agent),
    'browser'          => detecterNavigateur($user_agent),
];

try {
    $vpn_info = detecterVPNProxyTor($ip);
    $device_info = array_merge($device_info, $vpn_info);
} catch (Throwable $e) {
    $device_info = array_merge($device_info, [
        'is_vpn' => false, 'is_proxy' => false,
        'is_tor' => false, 'is_datacenter' => false,
    ]);
}

try {
    $geo = geolocaliserIP($ip);
} catch (Throwable $e) {
    $geo = [
        'pays' => 'Inconnu', 'ville' => 'Inconnue', 'region' => 'Inconnue',
        'isp' => 'Inconnu', 'asn' => null, 'organization' => null,
        'timezone' => null, 'latitude' => null, 'longitude' => null,
    ];
}

/* Niveau de risque */
$risk_color = '#10B981';
$risk_label = 'Connexion sécurisée';
$risk_icon  = 'fa-shield-halved';

if (!empty($device_info['is_tor'])) {
    $risk_color = '#991B1B';
    $risk_label = 'Réseau Tor';
    $risk_icon  = 'fa-user-secret';
} elseif (!empty($device_info['is_vpn']) || !empty($device_info['is_proxy'])) {
    $risk_color = '#EF4444';
    $risk_label = 'VPN / Proxy';
    $risk_icon  = 'fa-mask';
} elseif (!empty($device_info['is_datacenter'])) {
    $risk_color = '#F5B800';
    $risk_label = 'Datacenter';
    $risk_icon  = 'fa-server';
}

/* ============================================================
   2. TRAITEMENT FORMULAIRE
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        /* Re-vérifier blocage au POST */
        if (verifierIPBloquee($pdo, $ip)) {
            header('Location: ' . $base . '/client/login.php');
            exit;
        }

        if (!verifyCsrf($_POST['csrf'] ?? null)) {
            throw new RuntimeException("Session expirée. Rechargez la page.");
        }
        if (empty($_POST['accept_cgu'])) {
            throw new RuntimeException("Vous devez accepter les CGU.");
        }

        $identifiant = cleanString($_POST['identifiant'] ?? '', 60);
        $password    = (string)($_POST['mot_de_passe'] ?? '');

        if ($identifiant === '' || $password === '') {
            throw new RuntimeException("Veuillez remplir tous les champs.");
        }

        $stmt = $pdo->prepare("
            SELECT id_client, nom, postnom, prenom, mot_de_passe, numero_compte,
                   statut, locked_until, type_client
            FROM Client
            WHERE (nom = ? OR postnom = ? OR numero_compte = ?)
              AND type_client = 'physique'
            LIMIT 1
        ");
        $stmt->execute([$identifiant, $identifiant, $identifiant]);
        $client = $stmt->fetch();

        if (!$client) {
            usleep(500000);
            throw new RuntimeException("Identifiants incorrects.");
        }

        if ($client['statut'] !== 'actif') {
            throw new RuntimeException("Votre compte est « {$client['statut']} ».");
        }

        if (empty($client['mot_de_passe'])) {
            throw new RuntimeException("Aucun mot de passe défini. Contactez l'administrateur.");
        }

        if (!password_verify($password, $client['mot_de_passe'])) {
            usleep(500000);
            throw new RuntimeException("Identifiants incorrects.");
        }

        /* ✅ CONNEXION RÉUSSIE */
        $pdo->prepare("
            UPDATE Client SET failed_attempts = 0, locked_until = NULL, last_login = NOW()
            WHERE id_client = ?
        ")->execute([(int)$client['id_client']]);

        session_regenerate_id(true);

        $_SESSION['client_id']     = (int)$client['id_client'];
        $_SESSION['client_nom']    = $client['nom'];
        $_SESSION['client_compte'] = $client['numero_compte'];
        $_SESSION['role']          = 'client';
        $_SESSION['fingerprint']   = $fingerprint;
        $_SESSION['last_activity'] = time();
        $_SESSION['device_info']   = $device_info;

        try {
            logAction($pdo, 'client', (int)$client['id_client'], 'LOGIN_SUCCESS',
                "Connexion depuis {$ip}");
        } catch (Throwable $e) {}

        header('Location: ' . url('client/dashboard.php'));
        exit;

    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$device_icon = match($device_info['device_type'] ?? '') {
    'mobile'  => 'fa-mobile-screen-button',
    'tablet'  => 'fa-tablet-screen-button',
    'bot'     => 'fa-robot',
    'desktop' => 'fa-desktop',
    default   => 'fa-laptop',
};
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0">
<meta name="theme-color" content="#0B0E14">
<title>🔐 Connexion Client — AFRICAN TRUST FUND</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
      integrity="sha512-SnH5WK+bZxgPHs44uWIX+LLJAJ9/2PkPKZ5QiAj6Ta86w+fsb2TkcmfRyVX3pBnMFcV7oQPJkl9QevSCWr3W6A=="
      crossorigin="anonymous" referrerpolicy="no-referrer">
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">

<style>
:root {
    --gold:#F5B800; --gold-light:#FCD34D; --gold-dark:#D4AF37;
    --bg-deep:#080A10; --card:#121721; --card-2:#1A2130;
    --border:rgba(255,255,255,.08); --text:#E5E7EB; --muted:#94A3B8;
    --green:#10B981; --red:#EF4444; --blue:#3B82F6; --purple:#8B5CF6;
}
body.dark-auth {
    background:radial-gradient(ellipse at top,#0F1420 0%,#080A10 60%);
    min-height:100vh;
    font-family:'Inter',-apple-system,BlinkMacSystemFont,sans-serif;
    color:var(--text);
}
.auth-wrapper {
    min-height:calc(100vh - 80px);
    display:flex; align-items:center; justify-content:center;
    padding:40px 20px; position:relative; overflow:hidden;
}
.auth-wrapper::before {
    content:""; position:absolute; top:-20%; left:-10%;
    width:500px; height:500px; border-radius:50%;
    background:radial-gradient(circle,rgba(245,184,0,.08),transparent 70%);
    pointer-events:none; animation:floatOrb 18s ease-in-out infinite;
}
.auth-wrapper::after {
    content:""; position:absolute; bottom:-20%; right:-10%;
    width:600px; height:600px; border-radius:50%;
    background:radial-gradient(circle,rgba(139,92,246,.08),transparent 70%);
    pointer-events:none; animation:floatOrb 22s ease-in-out infinite reverse;
}
@keyframes floatOrb {
    0%,100% { transform:translate(0,0) scale(1); }
    50%     { transform:translate(40px,-30px) scale(1.1); }
}
.auth-card {
    width:100%; max-width:540px; padding:42px 38px;
    background:linear-gradient(160deg,rgba(26,33,48,.95),rgba(18,23,33,.98));
    border-radius:28px; border:1px solid rgba(245,184,0,.15);
    box-shadow:0 30px 80px rgba(0,0,0,.6),0 0 0 1px rgba(255,255,255,.03) inset;
    position:relative; z-index:2; backdrop-filter:blur(20px);
    animation:cardEnter .6s cubic-bezier(.16,1,.3,1);
}
@keyframes cardEnter {
    from { opacity:0; transform:translateY(30px) scale(.98); }
    to   { opacity:1; transform:translateY(0) scale(1); }
}
.auth-card::before {
    content:""; position:absolute; top:0; left:15%; right:15%; height:1px;
    background:linear-gradient(90deg,transparent,var(--gold),transparent);
    opacity:.5; border-radius:28px 28px 0 0;
}
.auth-head { text-align:center; margin-bottom:28px; position:relative; z-index:1; }
.auth-head .logo-mark {
    width:64px; height:64px; margin:0 auto 18px; border-radius:18px;
    background:linear-gradient(135deg,var(--gold),var(--gold-dark));
    display:flex; align-items:center; justify-content:center;
    box-shadow:0 12px 30px rgba(245,184,0,.35);
    position:relative; animation:logoPulse 4s ease-in-out infinite;
}
.auth-head .logo-mark i { font-size:1.7rem; color:#0B0E14; }
.auth-head .logo-mark::after {
    content:""; position:absolute; inset:-6px; border-radius:24px;
    border:1px solid rgba(245,184,0,.3);
    animation:ringPulse 3s ease-out infinite;
}
@keyframes logoPulse { 0%,100% { transform:scale(1); } 50% { transform:scale(1.03); } }
@keyframes ringPulse {
    0%   { transform:scale(.9); opacity:.6; }
    100% { transform:scale(1.15); opacity:0; }
}
.auth-head .badge-pill {
    display:inline-flex; align-items:center; gap:6px;
    padding:5px 14px; border-radius:999px;
    background:rgba(245,184,0,.1); color:var(--gold);
    border:1px solid rgba(245,184,0,.3);
    font-size:.7rem; font-weight:800;
    text-transform:uppercase; letter-spacing:1.5px;
    margin-bottom:14px;
}
.auth-head h1 {
    color:#fff; font-size:1.85rem; font-weight:900;
    letter-spacing:-.5px; margin-bottom:8px;
    background:linear-gradient(135deg,#fff,#CBD5E1);
    -webkit-background-clip:text; background-clip:text;
    -webkit-text-fill-color:transparent;
}
.auth-head p { color:var(--muted); font-size:.9rem; line-height:1.5; }
.device-panel {
    padding:18px 20px; border-radius:18px;
    background:linear-gradient(135deg,rgba(59,130,246,.06),rgba(139,92,246,.03));
    border:1px solid rgba(59,130,246,.2);
    margin-bottom:22px; position:relative; z-index:1;
}
.device-panel .header {
    display:flex; align-items:center; justify-content:space-between;
    gap:10px; margin-bottom:14px; flex-wrap:wrap;
}
.device-panel .header .title {
    color:#93B4FF; font-weight:800; font-size:.82rem;
    display:flex; align-items:center; gap:8px;
}
.risk-badge {
    padding:4px 12px; border-radius:999px;
    font-size:.68rem; font-weight:800;
    text-transform:uppercase; letter-spacing:.6px;
    display:inline-flex; align-items:center; gap:5px;
}
.device-grid {
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(140px,1fr));
    gap:8px;
}
.device-item {
    padding:10px 12px; border-radius:10px;
    background:rgba(11,14,20,.5);
    border:1px solid rgba(255,255,255,.05);
}
.device-item .lbl {
    font-size:.62rem; color:var(--muted);
    text-transform:uppercase; letter-spacing:.6px;
    font-weight:800; margin-bottom:4px;
    display:flex; align-items:center; gap:5px;
}
.device-item .val {
    font-size:.82rem; font-weight:700;
    color:var(--text); word-break:break-word;
}
.device-item .val.blue  { color:#93B4FF; }
.device-item .val.gold  { color:var(--gold); }
.device-item .val.mono  { font-family:'JetBrains Mono',monospace; font-size:.74rem; }
.surveillance-badge {
    display:flex; align-items:center; gap:8px;
    padding:8px 14px; border-radius:10px;
    background:rgba(16,185,129,.08);
    border:1px solid rgba(16,185,129,.25);
    color:#6EE7B7; font-size:.72rem; font-weight:700;
    margin-top:14px;
    text-transform:uppercase; letter-spacing:.8px;
    justify-content:center;
    animation:pulseGreen 3s ease-in-out infinite;
}
@keyframes pulseGreen {
    0%,100% { opacity:1; }
    50%     { opacity:.7; }
}
.field { display:block; margin-bottom:20px; position:relative; z-index:1; }
.field > span {
    display:block; color:var(--muted);
    font-size:.76rem; text-transform:uppercase;
    letter-spacing:1px; font-weight:800; margin-bottom:10px;
}
.input-wrap { position:relative; display:flex; align-items:center; }
.input-wrap .ico {
    position:absolute; left:16px;
    color:var(--muted); font-size:.95rem;
    pointer-events:none; transition:color .2s; z-index:1;
}
.input-wrap input {
    width:100%; padding:15px 46px 15px 46px;
    background:rgba(8,10,16,.6);
    border:1.5px solid rgba(255,255,255,.08);
    border-radius:14px; color:var(--text);
    font-size:.95rem; font-family:inherit; font-weight:500;
    transition:all .25s;
}
.input-wrap input:focus {
    outline:none; border-color:var(--gold);
    background:rgba(8,10,16,.85);
    box-shadow:0 0 0 4px rgba(245,184,0,.1);
}
.input-wrap .eye {
    position:absolute; right:12px;
    background:none; border:none; cursor:pointer;
    font-size:.95rem; padding:8px; color:var(--muted);
    transition:all .2s; border-radius:8px;
    display:flex; align-items:center; justify-content:center;
    width:34px; height:34px;
}
.input-wrap .eye:hover { color:var(--gold); background:rgba(245,184,0,.08); }
.cgu-box {
    margin:24px 0; padding:18px 20px; border-radius:16px;
    background:linear-gradient(135deg,rgba(245,184,0,.06),rgba(139,92,246,.03));
    border:2px solid rgba(245,184,0,.25);
    transition:all .3s; position:relative; z-index:1;
}
.cgu-box.checked {
    border-color:rgba(16,185,129,.5);
    background:linear-gradient(135deg,rgba(16,185,129,.08),rgba(16,185,129,.02));
}
.cgu-checkbox-row {
    display:flex; align-items:flex-start; gap:14px;
    cursor:pointer; user-select:none; position:relative;
}
.cgu-checkbox-row input[type="checkbox"] {
    position:absolute; opacity:0; pointer-events:none;
}
.cgu-custom-check {
    width:26px; height:26px; border-radius:8px;
    border:2px solid rgba(245,184,0,.5);
    background:rgba(8,10,16,.6);
    display:flex; align-items:center; justify-content:center;
    flex-shrink:0; transition:all .3s; margin-top:1px;
}
.cgu-custom-check i {
    color:#fff; font-size:.8rem; opacity:0; transform:scale(0);
    transition:all .3s;
}
.cgu-checkbox-row input[type="checkbox"]:checked + .cgu-custom-check {
    background:linear-gradient(135deg,var(--green),#059669);
    border-color:var(--green);
    box-shadow:0 4px 12px rgba(16,185,129,.4);
}
.cgu-checkbox-row input[type="checkbox"]:checked + .cgu-custom-check i {
    opacity:1; transform:scale(1);
}
.cgu-text { font-size:.83rem; color:var(--text); line-height:1.65; font-weight:500; }
.cgu-text a {
    color:var(--gold); font-weight:800;
    text-decoration:none; border-bottom:1px dashed rgba(245,184,0,.5);
}
.btn-gold.full {
    width:100%; padding:17px; border-radius:14px;
    background:linear-gradient(135deg,var(--gold),var(--gold-dark));
    color:#0B0E14; border:none;
    font-weight:800; font-size:.98rem; letter-spacing:.3px;
    cursor:pointer; font-family:inherit;
    box-shadow:0 10px 28px rgba(245,184,0,.35);
    transition:all .25s;
    position:relative; z-index:1;
    display:flex; align-items:center; justify-content:center; gap:10px;
}
.btn-gold.full:hover:not(:disabled) {
    transform:translateY(-2px);
    box-shadow:0 16px 38px rgba(245,184,0,.5);
}
.btn-gold.full:disabled { opacity:.35; cursor:not-allowed; }
.auth-footer {
    margin-top:26px; padding-top:22px;
    border-top:1px solid var(--border);
    text-align:center; font-size:.85rem; color:var(--muted);
    position:relative; z-index:1;
}
.auth-footer strong { color:var(--text); display:block; margin-bottom:12px; font-size:.82rem; }
.auth-footer .links {
    display:flex; justify-content:center; align-items:center;
    gap:8px; flex-wrap:wrap;
}
.auth-footer a {
    color:var(--gold); text-decoration:none; font-weight:700;
    padding:8px 14px; border-radius:10px; transition:all .2s;
    display:inline-flex; align-items:center; gap:6px; font-size:.82rem;
}
.auth-footer a:hover { background:rgba(245,184,0,.1); }
.auth-footer .sep { color:rgba(148,163,184,.3); font-size:.7rem; }
.alert {
    padding:15px 18px; border-radius:14px; font-size:.88rem;
    margin-bottom:20px; position:relative; z-index:1;
    line-height:1.55; display:flex; align-items:flex-start; gap:12px;
    font-weight:600;
}
.alert i { font-size:1.05rem; flex-shrink:0; margin-top:2px; }
.alert.error { background:rgba(239,68,68,.1); border:1px solid rgba(239,68,68,.3); color:#FCA5A5; }
.alert.warning { background:rgba(245,184,0,.1); border:1px solid rgba(245,184,0,.3); color:#FCD34D; }
@media (max-width:640px) {
    .auth-card { padding:32px 24px; }
    .auth-head h1 { font-size:1.5rem; }
}
@media (max-width:420px) {
    .auth-wrapper { padding:20px 12px; }
    .auth-card { padding:26px 20px; }
    .device-grid { grid-template-columns:1fr; }
    .auth-footer .links { flex-direction:column; }
    .auth-footer .sep { display:none; }
}
</style>
</head>
<body class="dark-auth">

<?php require __DIR__ . '/../includes/header.php'; ?>

<main class="auth-wrapper">
    <div class="auth-card">

        <div class="auth-head">
            <div class="logo-mark">
                <i class="fa-solid fa-shield-halved"></i>
            </div>
            <span class="badge-pill">
                <i class="fa-solid fa-user"></i> Espace Client
            </span>
            <h1>Bienvenue</h1>
            <p>Connectez-vous à votre espace sécurisé AFRICAN TRUST FUND</p>
        </div>

        <?php if ($error): ?>
            <div class="alert error">
                <i class="fa-solid fa-circle-exclamation"></i>
                <div><?= htmlspecialchars($error) ?></div>
            </div>
        <?php endif; ?>

        <?php if (!empty($warning)): ?>
            <div class="alert warning">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div><?= htmlspecialchars($warning) ?></div>
            </div>
        <?php endif; ?>

        <!-- PANNEAU APPAREIL -->
        <div class="device-panel">
            <div class="header">
                <div class="title">
                    <i class="fa-solid fa-shield-halved"></i>
                    Analyse de sécurité
                </div>
                <span class="risk-badge"
                      style="background:<?= $risk_color ?>20;color:<?= $risk_color ?>;border:1px solid <?= $risk_color ?>50">
                    <i class="fa-solid <?= $risk_icon ?>"></i>
                    <?= htmlspecialchars($risk_label) ?>
                </span>
            </div>

            <div class="device-grid">
                <div class="device-item">
                    <div class="lbl"><i class="fa-solid fa-globe"></i> IP publique</div>
                    <div class="val mono" id="clientIp"><?= htmlspecialchars($ip) ?></div>
                </div>
                <div class="device-item">
                    <div class="lbl"><i class="fa-solid fa-location-dot"></i> Localisation</div>
                    <div class="val blue">
                        <?= htmlspecialchars($geo['ville'] ?? '—') ?>,
                        <?= htmlspecialchars($geo['pays'] ?? '—') ?>
                    </div>
                </div>
                <div class="device-item">
                    <div class="lbl"><i class="fa-solid fa-microchip"></i> Système</div>
                    <div class="val"><?= htmlspecialchars($device_info['os'] ?? '—') ?></div>
                </div>
                <div class="device-item">
                    <div class="lbl"><i class="fa-solid fa-compass"></i> Navigateur</div>
                    <div class="val"><?= htmlspecialchars($device_info['browser'] ?? '—') ?></div>
                </div>
                <div class="device-item">
                    <div class="lbl"><i class="fa-solid fa-<?= $device_icon ?>"></i> Appareil</div>
                    <div class="val"><?= htmlspecialchars(ucfirst($device_info['device_type'] ?? '—')) ?></div>
                </div>
                <div class="device-item">
                    <div class="lbl"><i class="fa-regular fa-clock"></i> Heure locale</div>
                    <div class="val gold"><?= date('d/m/Y H:i') ?></div>
                </div>
            </div>

            <div class="surveillance-badge">
                <i class="fa-solid fa-circle" style="font-size:.6rem;color:#10B981"></i>
                Surveillance active en temps réel
            </div>
        </div>

        <!-- FORMULAIRE -->
        <form method="POST" novalidate autocomplete="off" id="loginForm">
            <?= csrfField() ?>

            <label class="field">
                <span>Nom, Post-nom ou N° de compte</span>
                <div class="input-wrap">
                    <i class="fa-solid fa-user ico"></i>
                    <input type="text" name="identifiant" required maxlength="60" autofocus
                           placeholder="Votre nom ou ATF-2025-XXXXXX"
                           value="<?= htmlspecialchars($_POST['identifiant'] ?? '') ?>">
                </div>
            </label>

            <label class="field">
                <span>Mot de passe</span>
                <div class="input-wrap">
                    <i class="fa-solid fa-lock ico"></i>
                    <input type="password" id="pwd" name="mot_de_passe" required
                           autocomplete="current-password" placeholder="••••••••">
                    <button type="button" class="eye" data-target="pwd" aria-label="Afficher le mot de passe">
                        <i class="fa-solid fa-eye"></i>
                    </button>
                </div>
            </label>

            <div class="cgu-box" id="cguBox">
                <label class="cgu-checkbox-row">
                    <input type="checkbox" name="accept_cgu" id="acceptCgu" value="1"
                           <?= !empty($_POST['accept_cgu']) ? 'checked' : '' ?>>
                    <div class="cgu-custom-check">
                        <i class="fa-solid fa-check"></i>
                    </div>
                    <div class="cgu-text">
                        J'ai lu et j'accepte les
                        <a href="<?= $base ?>/auth/conditions_utilisation.php" target="_blank" rel="noopener">
                            Conditions Générales d'Utilisation
                        </a>
                        et la
                        <a href="<?= $base ?>/auth/politique_confidentialite.php" target="_blank" rel="noopener">
                            Politique de Confidentialité
                        </a>
                        d'AFRICAN TRUST FUND.
                    </div>
                </label>
            </div>

            <button class="btn-gold full" type="submit" id="btnSubmit" disabled>
                <i class="fa-solid fa-right-to-bracket"></i>
                <span>Se connecter</span>
            </button>

            <div class="auth-footer">
                <strong>Vous n'avez pas de compte ?</strong>
                <div class="links">
                    <a href="<?= $base ?>/auth/inscription_client.php">
                        <i class="fa-solid fa-user-plus"></i> Inscription Particulier
                    </a>
                    <span class="sep">•</span>
                    <a href="<?= $base ?>/entreprise/login.php">
                        <i class="fa-solid fa-building"></i> Espace Entreprise
                    </a>
                </div>
            </div>
        </form>

    </div>
</main>

<script src="<?= $base ?>/assets/js/main.js"></script>
<script>
/* ============================================================
   1. CGU
   ============================================================ */
(function () {
    const c = document.getElementById('acceptCgu');
    const b = document.getElementById('cguBox');
    const btn = document.getElementById('btnSubmit');
    if (!c || !b || !btn) return;
    function update() {
        b.classList.toggle('checked', c.checked);
        btn.disabled = !c.checked;
    }
    c.addEventListener('change', update);
    update();
})();

/* ============================================================
   2. TOGGLE MOT DE PASSE
   ============================================================ */
document.querySelectorAll('.eye').forEach(btn => {
    btn.addEventListener('click', function () {
        const t = document.getElementById(this.dataset.target);
        if (!t) return;
        const i = this.querySelector('i');
        if (t.type === 'password') {
            t.type = 'text';
            i?.classList.replace('fa-eye', 'fa-eye-slash');
        } else {
            t.type = 'password';
            i?.classList.replace('fa-eye-slash', 'fa-eye');
        }
    });
});

/* ============================================================
   3. SUBMIT
   ============================================================ */
document.getElementById('loginForm')?.addEventListener('submit', function (e) {
    const c = document.getElementById('acceptCgu');
    const btn = document.getElementById('btnSubmit');
    if (!c?.checked) { e.preventDefault(); return; }
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i><span>Connexion en cours…</span>';
});

/* ============================================================
   4. FINGERPRINT AVANCÉ
   ============================================================ */
(function() {
    'use strict';

    function getCanvasFp() {
        try {
            const c = document.createElement('canvas');
            c.width = 200; c.height = 50;
            const ctx = c.getContext('2d');
            ctx.textBaseline = 'top';
            ctx.font = '14px Arial';
            ctx.fillStyle = '#F5B800';
            ctx.fillRect(0, 0, 200, 50);
            ctx.fillStyle = '#0B0E14';
            ctx.fillText('ATF-Fingerprint', 10, 10);
            ctx.fillStyle = 'rgba(245,184,0,0.5)';
            ctx.fillText('African Trust Fund', 10, 30);
            return btoa(c.toDataURL()).slice(0, 64);
        } catch(e) { return 'canvas_error'; }
    }

    function getWebGLFp() {
        try {
            const c = document.createElement('canvas');
            const gl = c.getContext('webgl') || c.getContext('experimental-webgl');
            if (!gl) return 'no_webgl';
            const dbg = gl.getExtension('WEBGL_debug_renderer_info');
            if (dbg) {
                return gl.getParameter(dbg.UNMASKED_VENDOR_WEBGL) + '~' + gl.getParameter(dbg.UNMASKED_RENDERER_WEBGL);
            }
            return gl.getParameter(gl.VERSION);
        } catch(e) { return 'webgl_error'; }
    }

    async function sendFingerprint() {
        const fp = {
            screen: `${screen.width}x${screen.height}x${screen.colorDepth}`,
            timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
            language: navigator.language,
            languages: (navigator.languages || []).join(','),
            platform: navigator.platform || 'unknown',
            cpu: navigator.hardwareConcurrency || 0,
            memory: navigator.deviceMemory || 0,
            touch: navigator.maxTouchPoints || 0,
            canvas: getCanvasFp(),
            webgl: getWebGLFp(),
            user_agent: navigator.userAgent,
            timestamp: Date.now(),
        };

        const csrfEl = document.querySelector('input[name="csrf"]');
        const csrf = csrfEl ? csrfEl.value : '';

        try {
            const res = await fetch('<?= $base ?>/api/register_device.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrf,
                },
                body: JSON.stringify(fp),
                credentials: 'same-origin',
            });
            const data = await res.json();
            if (data.blocked) {
                showBlockedScreen(data.raison, data.date_blocage, data.date_expiration);
            }
        } catch(e) {
            console.warn('[ATF] Fingerprint send failed:', e);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', sendFingerprint);
    } else {
        sendFingerprint();
    }
})();

/* ============================================================
   5. POLLING ANTI-BLOCAGE (toutes les 2s)
   ============================================================ */
let __atf_blocked = false;

function showBlockedScreen(raison, dateBlocage, dateExpiration) {
    if (__atf_blocked) return;
    __atf_blocked = true;

    function escapeHtml(s) {
        return String(s || '').replace(/[&<>"']/g, c => ({
            '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
        }[c]));
    }

    const expTs = dateExpiration ? new Date(dateExpiration.replace(' ', 'T')).getTime() : 0;
    const hasTimer = expTs > Date.now();

    const html = `
        <div style="position:fixed;inset:0;background:radial-gradient(ellipse at top,#1a0505,#080A10 70%);
                    display:flex;align-items:center;justify-content:center;z-index:999999;
                    font-family:Inter,sans-serif;padding:20px;animation:atfFadeIn .5s ease;
                    overflow-y:auto">
            <div style="background:linear-gradient(145deg,rgba(153,27,27,.15),rgba(18,23,33,.98));
                        border:2px solid #991B1B;border-radius:26px;padding:48px 36px;
                        max-width:600px;width:100%;text-align:center;
                        box-shadow:0 30px 80px rgba(153,27,27,.4)">
                <div style="width:110px;height:110px;border-radius:50%;background:rgba(153,27,27,.25);
                            border:3px solid #991B1B;display:flex;align-items:center;justify-content:center;
                            margin:0 auto 26px;animation:atfShake 2.5s ease-in-out infinite;
                            box-shadow:0 0 50px rgba(153,27,27,.5)">
                    <span style="font-size:3.5rem">🚫</span>
                </div>
                <h1 style="color:#FCA5A5;font-size:1.75rem;font-weight:900;margin-bottom:14px;
                           letter-spacing:-.5px">
                    Connexion suspendue
                </h1>
                <p style="color:#FCA5A5;font-size:1rem;line-height:1.7;font-weight:600;margin-bottom:22px">
                    Votre accès a été temporairement suspendu
                </p>
                <div style="padding:22px 24px;border-radius:14px;background:rgba(153,27,27,.18);
                            border:1px solid rgba(239,68,68,.4);color:#FCA5A5;font-size:.98rem;
                            line-height:1.75;font-weight:700;margin-bottom:26px">
                    ⚠️ La connexion est suspendue pour un certain moment<br>
                    suite à l'irrégularité de norme de système.<br>
                    <span style="display:block;color:#FCD34D;font-size:1.05rem;margin-top:10px;font-weight:800">
                        📞 Veuillez contacter le service client.
                    </span>
                </div>
                ${hasTimer ? `
                <div style="padding:18px;border-radius:14px;background:linear-gradient(135deg,rgba(245,184,0,.14),rgba(245,184,0,.05));
                            border:1px solid rgba(245,184,0,.45);margin-bottom:24px;
                            display:flex;align-items:center;justify-content:center;gap:14px">
                    <span style="font-size:1.8rem">⏳</span>
                    <div style="text-align:left">
                        <div style="color:#94A3B8;font-size:.7rem;text-transform:uppercase;
                                    letter-spacing:1.5px;font-weight:800;margin-bottom:2px">Temps restant</div>
                        <div id="atfTimer" style="color:#F5B800;font-family:'JetBrains Mono',monospace;
                             font-size:1.6rem;font-weight:900;letter-spacing:2px;
                             text-shadow:0 0 20px rgba(245,184,0,.4)">--:--:--</div>
                    </div>
                </div>` : ''}
                <div style="padding:22px;border-radius:14px;background:rgba(0,0,0,.5);
                            border:1px solid rgba(153,27,27,.3);text-align:left;
                            font-family:'JetBrains Mono',monospace;font-size:.82rem;
                            color:#94A3B8;line-height:2.1;margin-bottom:24px">
                    <div style="color:#F5B800;font-family:Inter,sans-serif;font-weight:800;
                                font-size:.78rem;text-transform:uppercase;letter-spacing:1.3px;
                                margin-bottom:14px">
                        📋 Détails du blocage
                    </div>
                    <div style="padding:4px 0">💬 Raison : <span style="color:#FCA5A5;font-weight:700">${escapeHtml(raison || 'Non spécifiée')}</span></div>
                    <div style="padding:4px 0">📅 Date : <span style="color:#FCA5A5;font-weight:700">${escapeHtml(dateBlocage || new Date().toLocaleString('fr-FR'))}</span></div>
                </div>
                <div style="padding-top:26px;border-top:1px solid rgba(153,27,27,.3)">
                    <div style="color:#94A3B8;font-size:.78rem;font-weight:800;
                                text-transform:uppercase;letter-spacing:1.5px;margin-bottom:16px">
                        📞 Service client disponible 24/7
                    </div>
                    <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
                        <a href="tel:+243800000000" style="padding:13px 26px;border-radius:12px;
                           background:linear-gradient(135deg,#F5B800,#D4AF37);color:#0B0E14;
                           text-decoration:none;font-weight:800;font-size:.88rem;
                           display:inline-flex;align-items:center;gap:8px">
                           📞 Appeler
                        </a>
                        <a href="mailto:support@atf-rdc.cd" style="padding:13px 26px;border-radius:12px;
                           background:rgba(245,184,0,.12);color:#F5B800;
                           border:1px solid rgba(245,184,0,.4);text-decoration:none;
                           font-weight:800;font-size:.88rem;display:inline-flex;align-items:center;gap:8px">
                           ✉️ Écrire
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <style>
            @keyframes atfShake {
                0%,100% { transform:translateX(0) scale(1); }
                25%     { transform:translateX(-5px) scale(1.03); }
                75%     { transform:translateX(5px) scale(1.03); }
            }
            @keyframes atfFadeIn {
                from { opacity:0; }
                to   { opacity:1; }
            }
        </style>
    `;

    document.body.innerHTML = html;
    document.body.style.overflow = 'hidden';

    /* Décompte */
    if (hasTimer) {
        const el = document.getElementById('atfTimer');
        function tick() {
            const diff = expTs - Date.now();
            if (diff <= 0) {
                el.textContent = '00:00:00';
                setTimeout(() => location.reload(), 1500);
                return;
            }
            const h = Math.floor(diff / 3600000);
            const m = Math.floor((diff % 3600000) / 60000);
            const s = Math.floor((diff % 60000) / 1000);
            el.textContent = String(h).padStart(2,'0') + ':' +
                             String(m).padStart(2,'0') + ':' +
                             String(s).padStart(2,'0');
        }
        tick();
        setInterval(tick, 1000);
    }
}

async function checkIpStatus() {
    if (__atf_blocked) return;
    try {
        const res = await fetch('<?= $base ?>/api/check_ip_status.php?t=' + Date.now(), {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        if (!res.ok) return;
        const data = await res.json();
        if (data.blocked) {
            showBlockedScreen(data.raison, data.date_blocage, data.date_expiration);
        }
    } catch(e) {
        /* Silencieux */
    }
}

/* Premier check immédiat + intervalle 2s */
setTimeout(checkIpStatus, 500);
setInterval(checkIpStatus, 2000);

/* Vérif au retour sur l'onglet */
document.addEventListener('visibilitychange', () => {
    if (!document.hidden) checkIpStatus();
});

console.log('%c🔒 ATF Anti-blocage actif (polling 2s)', 'color:#10B981;font-weight:bold');
console.log('%c🔐 Login — IP:', 'color:#F5B800;font-weight:bold', <?= json_encode($ip) ?>);
</script>

</body>
</html>