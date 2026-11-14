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
   TRAITEMENT POST
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) {
            throw new RuntimeException("Jeton invalide.");
        }

        $action = $_POST['action'] ?? '';

        /* Débloquer une IP */
        if ($action === 'debloquer') {
            $ip = cleanString($_POST['ip'] ?? '', 45);
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                throw new RuntimeException("IP invalide.");
            }

            $count = debloquerIP($pdo, $ip, $admin_id);

            /* Réactiver les appareils */
            $pdo->prepare("
                UPDATE Device_Fingerprints SET statut = 'fiable', raison_blocage = NULL
                WHERE ip_address = ?
            ")->execute([$ip]);

            logAction($pdo, 'admin', $admin_id, 'IP_DEBLOQUEE', "IP: $ip");
            $_SESSION['flash_success'] = "✅ IP <strong>$ip</strong> débloquée.";
        }

        /* Supprimer définitivement */
        if ($action === 'supprimer') {
            $id_blocage = (int)($_POST['id_blocage'] ?? 0);
            $pdo->prepare("DELETE FROM IPs_Bloquees WHERE id_blocage = ?")->execute([$id_blocage]);
            $_SESSION['flash_success'] = "🗑 Entrée supprimée.";
        }

        /* Prolonger un blocage */
        if ($action === 'prolonger') {
            $id_blocage = (int)($_POST['id_blocage'] ?? 0);
            $minutes = max(1, (int)($_POST['minutes'] ?? 60));

            $pdo->prepare("
                UPDATE IPs_Bloquees
                SET date_expiration = DATE_ADD(COALESCE(date_expiration, NOW()), INTERVAL ? MINUTE)
                WHERE id_blocage = ?
            ")->execute([$minutes, $id_blocage]);

            $_SESSION['flash_success'] = "⏱ Blocage prolongé de {$minutes} minutes.";
        }

        header('Location: ' . url('admin/ips_bloquees.php'));
        exit;

    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

/* ============================================================
   RÉCUPÉRATION
   ============================================================ */
$filtre = $_GET['filtre'] ?? 'actives';

$sql = "SELECT * FROM IPs_Bloquees WHERE 1=1";
switch ($filtre) {
    case 'actives':
        $sql .= " AND est_active = TRUE AND (date_expiration IS NULL OR date_expiration > NOW())";
        break;
    case 'expirees':
        $sql .= " AND (est_active = FALSE OR (date_expiration IS NOT NULL AND date_expiration <= NOW()))";
        break;
    case 'permanentes':
        $sql .= " AND est_active = TRUE AND type_blocage = 'permanent'";
        break;
}
$sql .= " ORDER BY date_blocage DESC LIMIT 500";

try {
    $ips_bloquees = $pdo->query($sql)->fetchAll();
} catch (Throwable $e) {
    $ips_bloquees = [];
}

$stats = [
    'actives' => 0, 'expirees' => 0, 'permanentes' => 0, 'total' => 0,
];
try {
    $stats['total'] = (int)$pdo->query("SELECT COUNT(*) FROM IPs_Bloquees")->fetchColumn();
    $stats['actives'] = (int)$pdo->query("SELECT COUNT(*) FROM IPs_Bloquees WHERE est_active = TRUE AND (date_expiration IS NULL OR date_expiration > NOW())")->fetchColumn();
    $stats['expirees'] = (int)$pdo->query("SELECT COUNT(*) FROM IPs_Bloquees WHERE est_active = FALSE OR (date_expiration IS NOT NULL AND date_expiration <= NOW())")->fetchColumn();
    $stats['permanentes'] = (int)$pdo->query("SELECT COUNT(*) FROM IPs_Bloquees WHERE est_active = TRUE AND type_blocage = 'permanent'")->fetchColumn();
} catch (Throwable $e) {}

if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

$base = '/african-trust-fund';
logAction($pdo, 'admin', $admin_id, 'IPS_BLOQUEES_VIEW', 'Consultation IPs bloquées');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>IPs Bloquées — ATF Admin</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/admin-premium.css">
<style>
.page-hero {
    padding: 24px 28px;
    border-radius: 20px;
    background: linear-gradient(135deg, rgba(153,27,27,.15), rgba(11,14,20,.4));
    border: 1px solid rgba(153,27,27,.4);
    margin-bottom: 24px;
}
.page-hero h1 { color: #FCA5A5; font-size: 1.5rem; margin-bottom: 6px; }
.page-hero p { color: var(--muted); font-size: .9rem; }

.filter-tabs {
    display: flex;
    gap: 8px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}
.filter-tab {
    padding: 10px 20px;
    border-radius: 10px;
    background: var(--card);
    border: 1px solid var(--border);
    color: var(--muted);
    font-weight: 700;
    font-size: .85rem;
    text-decoration: none;
    transition: all .2s;
    display: flex;
    align-items: center;
    gap: 6px;
}
.filter-tab:hover { border-color: rgba(245,184,0,.4); color: var(--text); }
.filter-tab.active {
    background: linear-gradient(135deg, rgba(245,184,0,.15), rgba(245,184,0,.05));
    border-color: var(--gold);
    color: var(--gold);
}
.filter-tab .count {
    background: rgba(0,0,0,.4);
    padding: 2px 8px;
    border-radius: 999px;
    font-size: .72rem;
}

.ip-card {
    padding: 18px 22px;
    border-radius: 14px;
    background: linear-gradient(145deg, var(--card), var(--card-2));
    border: 1px solid var(--border);
    margin-bottom: 12px;
    transition: all .25s;
    position: relative;
}
.ip-card.active {
    border-left: 4px solid #991B1B;
    background: linear-gradient(145deg, rgba(153,27,27,.08), rgba(11,14,20,.4));
}
.ip-card.expired {
    border-left: 4px solid #6B7280;
    opacity: .7;
}
.ip-card.permanent {
    border-left: 4px solid #DC2626;
    animation: pulseRedBorder 3s ease-in-out infinite;
}
@keyframes pulseRedBorder {
    0%, 100% { box-shadow: 0 0 0 0 rgba(220,38,38,.4); }
    50%      { box-shadow: 0 0 0 6px rgba(220,38,38,0); }
}
.ip-card:hover { transform: translateX(4px); }

.ip-card .header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    margin-bottom: 10px;
}
.ip-card .ip-address {
    font-family: monospace;
    font-size: 1.15rem;
    font-weight: 900;
    color: #FCA5A5;
}
.ip-card .meta-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 8px;
    margin-top: 10px;
}
.ip-card .meta-item {
    font-size: .82rem;
}
.ip-card .meta-item .lbl {
    color: var(--muted);
    font-size: .7rem;
    text-transform: uppercase;
    font-weight: 800;
    margin-bottom: 2px;
}
.ip-card .meta-item .val {
    color: var(--text);
    font-weight: 600;
}
.ip-card .meta-item .val.red { color: #FCA5A5; }
.ip-card .meta-item .val.gold { color: var(--gold); }
.ip-card .meta-item .val.green { color: #6EE7B7; }

.badge-type {
    padding: 4px 12px;
    border-radius: 999px;
    font-size: .7rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: .5px;
}
.badge-type.temporaire { background: rgba(245,184,0,.2); color: #FCD34D; border: 1px solid rgba(245,184,0,.5); }
.badge-type.permanent { background: rgba(153,27,27,.3); color: #FCA5A5; border: 1px solid #991B1B; }
.badge-type.suspendu { background: rgba(139,92,246,.2); color: #A78BFA; border: 1px solid rgba(139,92,246,.5); }

.btn-action {
    padding: 8px 14px;
    border-radius: 8px;
    font-size: .75rem;
    font-weight: 800;
    border: 1px solid;
    cursor: pointer;
    font-family: inherit;
    transition: all .2s;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    white-space: nowrap;
}
.btn-action.debloquer {
    background: rgba(16,185,129,.15);
    color: #6EE7B7;
    border-color: rgba(16,185,129,.3);
}
.btn-action.debloquer:hover { background: rgba(16,185,129,.3); }

.btn-action.prolonger {
    background: rgba(245,184,0,.15);
    color: #FCD34D;
    border-color: rgba(245,184,0,.3);
}
.btn-action.prolonger:hover { background: rgba(245,184,0,.3); }

.btn-action.supprimer {
    background: rgba(107,114,128,.15);
    color: #9CA3AF;
    border-color: rgba(107,114,128,.3);
}
.btn-action.supprimer:hover { background: rgba(239,68,68,.2); color: #FCA5A5; }

.actions-row {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 14px;
    padding-top: 14px;
    border-top: 1px solid var(--border);
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
</style>
</head>
<body class="dark-auth">

<?php require __DIR__ . '/../includes/header.php'; ?>

<div class="admin-layout">
  <aside class="admin-sidebar animate-left">
    <h3>📊 Navigation</h3>
    <a href="<?= $base ?>/admin/dashboard.php"><span class="ico">📊</span> Dashboard</a>
    <a href="<?= $base ?>/admin/attaques.php"><span class="ico">🚨</span> Attaques</a>
    <a href="<?= $base ?>/admin/securite_appareils.php"><span class="ico">🛡️</span> Appareils</a>
    <a href="<?= $base ?>/admin/ips_bloquees.php" class="active"><span class="ico">🚫</span> IP Bloquées</a>
    <a href="<?= $base ?>/admin/logs.php"><span class="ico">📜</span> Journal</a>
    <a href="<?= $base ?>/auth/logout.php"><span class="ico">🚪</span> Déconnexion</a>
  </aside>

  <main class="admin-main">

    <div class="page-hero">
      <h1>🚫 Adresses IP Bloquées</h1>
      <p>Gestion des blocages actifs, expirés et permanents</p>
    </div>

    <?php if ($success): ?>
      <div class="alert success"><?= $success ?></div>
    <?php endif; ?>
    <?php if ($errors): ?>
      <div class="alert error"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
    <?php endif; ?>

    <!-- FILTRES -->
    <div class="filter-tabs">
      <a href="?filtre=actives" class="filter-tab <?= $filtre === 'actives' ? 'active' : '' ?>">
        🚫 Actives <span class="count"><?= $stats['actives'] ?></span>
      </a>
      <a href="?filtre=permanentes" class="filter-tab <?= $filtre === 'permanentes' ? 'active' : '' ?>">
        ⛔ Permanentes <span class="count"><?= $stats['permanentes'] ?></span>
      </a>
      <a href="?filtre=expirees" class="filter-tab <?= $filtre === 'expirees' ? 'active' : '' ?>">
        ⏱ Expirées <span class="count"><?= $stats['expirees'] ?></span>
      </a>
      <a href="?filtre=toutes" class="filter-tab <?= $filtre === 'toutes' ? 'active' : '' ?>">
        📋 Toutes <span class="count"><?= $stats['total'] ?></span>
      </a>
    </div>

    <!-- LISTE -->
    <?php if (empty($ips_bloquees)): ?>
      <div class="empty-state">
        <div class="ico">🛡️</div>
        <h3 style="color:var(--text);margin-bottom:8px">Aucune IP bloquée</h3>
        <p>Aucun blocage actif dans cette catégorie.</p>
      </div>
    <?php else: ?>
      <?php foreach ($ips_bloquees as $ip):
          $is_active = !empty($ip['est_active']) && (empty($ip['date_expiration']) || strtotime($ip['date_expiration']) > time());
          $is_permanent = $ip['type_blocage'] === 'permanent' && $is_active;
          $card_class = $is_permanent ? 'permanent' : ($is_active ? 'active' : 'expired');
      ?>
        <div class="ip-card <?= $card_class ?>">
          <div class="header">
            <div class="ip-address">🌐 <?= htmlspecialchars($ip['ip_address']) ?></div>
            <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
              <span class="badge-type <?= htmlspecialchars($ip['type_blocage']) ?>">
                <?= match($ip['type_blocage']) {
                    'permanent' => '⛔ PERMANENT',
                    'suspendu' => '⏸ SUSPENDU',
                    default => '⏱ TEMPORAIRE',
                } ?>
              </span>
              <?php if ($is_active): ?>
                <span style="background:rgba(153,27,27,.25);color:#FCA5A5;border:1px solid #991B1B;padding:4px 12px;border-radius:999px;font-size:.7rem;font-weight:800">
                  🚫 ACTIF
                </span>
              <?php else: ?>
                <span style="background:rgba(107,114,128,.15);color:#9CA3AF;border:1px solid rgba(107,114,128,.3);padding:4px 12px;border-radius:999px;font-size:.7rem;font-weight:800">
                  ⏱ EXPIRÉ
                </span>
              <?php endif; ?>
            </div>
          </div>

          <div class="meta-grid">
            <div class="meta-item">
              <div class="lbl">📝 Raison</div>
              <div class="val red"><?= htmlspecialchars($ip['raison']) ?></div>
            </div>
            <div class="meta-item">
              <div class="lbl">📅 Date blocage</div>
              <div class="val"><?= date('d/m/Y H:i', strtotime($ip['date_blocage'])) ?></div>
            </div>
            <?php if (!empty($ip['date_expiration'])): ?>
              <div class="meta-item">
                <div class="lbl">⏰ Expiration</div>
                <div class="val gold"><?= date('d/m/Y H:i', strtotime($ip['date_expiration'])) ?></div>
              </div>
            <?php else: ?>
              <div class="meta-item">
                <div class="lbl">⏰ Expiration</div>
                <div class="val red">Jamais (permanent)</div>
              </div>
            <?php endif; ?>
            <?php if (!empty($ip['duree_minutes'])): ?>
              <div class="meta-item">
                <div class="lbl">⏱ Durée</div>
                <div class="val"><?= (int)$ip['duree_minutes'] ?> minutes</div>
              </div>
            <?php endif; ?>
            <div class="meta-item">
              <div class="lbl">👤 Bloqué par</div>
              <div class="val"><?= htmlspecialchars($ip['bloque_par'] ?? 'Système') ?>
                <?php if (!empty($ip['admin_id'])): ?>
                  <span style="color:var(--muted)">#<?= (int)$ip['admin_id'] ?></span>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <div class="actions-row">
            <?php if ($is_active): ?>
              <form method="POST" style="display:inline" onsubmit="return confirm('Débloquer cette IP ?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="debloquer">
                <input type="hidden" name="ip" value="<?= htmlspecialchars($ip['ip_address']) ?>">
                <button class="btn-action debloquer" type="submit">✅ Débloquer</button>
              </form>

              <?php if ($ip['type_blocage'] === 'temporaire'): ?>
                <form method="POST" style="display:inline" onsubmit="return confirm('Prolonger le blocage de 60 minutes ?')">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="prolonger">
                  <input type="hidden" name="id_blocage" value="<?= (int)$ip['id_blocage'] ?>">
                  <input type="hidden" name="minutes" value="60">
                  <button class="btn-action prolonger" type="submit">⏱ +60 min</button>
                </form>
              <?php endif; ?>
            <?php endif; ?>

            <form method="POST" style="display:inline" onsubmit="return confirm('Supprimer définitivement cette entrée ?')">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="supprimer">
              <input type="hidden" name="id_blocage" value="<?= (int)$ip['id_blocage'] ?>">
              <button class="btn-action supprimer" type="submit">🗑 Supprimer</button>
            </form>

            <a href="https://www.abuseipdb.com/check/<?= urlencode($ip['ip_address']) ?>"
               target="_blank" rel="noopener" class="btn-action" style="background:rgba(59,130,246,.15);color:#93B4FF;border-color:rgba(59,130,246,.3)">
              🔎 Vérifier AbusIPDB
            </a>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

  </main>
</div>

<footer class="atf-footer">© <?= date('Y') ?> AFRICAN TRUST FUND</footer>
<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>