<?php
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
requireRole('admin');

$pdo = Database::getConnection();
$admin_id = (int)$_SESSION['admin_id'];

/* ============================================================
   FONCTION : Récupérer le nom d'un utilisateur selon son type
   ============================================================ */
function getNomUtilisateur(PDO $pdo, string $user_type, ?int $user_id): ?array
{
    if (!$user_id || !in_array($user_type, ['admin','caissier','client','actionnaire'], true)) {
        return null;
    }

    try {
        switch ($user_type) {
            case 'admin':
                $stmt = $pdo->prepare("
                    SELECT id_admin AS id, nom, postnom, prenom, numero_phone, NULL AS numero_compte
                    FROM Admin WHERE id_admin = ? LIMIT 1
                ");
                break;
            case 'caissier':
                $stmt = $pdo->prepare("
                    SELECT id_caissier AS id, nom, postnom, post_prenom AS prenom, 
                           telephone AS numero_phone, matricule AS numero_compte
                    FROM Caissier WHERE id_caissier = ? LIMIT 1
                ");
                break;
            case 'client':
                $stmt = $pdo->prepare("
                    SELECT id_client AS id, nom, postnom, prenom, numero_phone, numero_compte
                    FROM Client WHERE id_client = ? LIMIT 1
                ");
                break;
            case 'actionnaire':
                $stmt = $pdo->prepare("
                    SELECT id_actionnaire AS id, nom, postnom, prenom, 
                           numero_phone, numero_actionnaire AS numero_compte
                    FROM Actionnaires WHERE id_actionnaire = ? LIMIT 1
                ");
                break;
            default:
                return null;
        }

        $stmt->execute([$user_id]);
        $user = $stmt->fetch();
        
        if (!$user) return null;

        /* Construire le nom complet */
        $user['nom_complet'] = trim(
            ($user['prenom'] ?? '') . ' ' . 
            ($user['nom'] ?? '') . ' ' . 
            ($user['postnom'] ?? '')
        );
        $user['initiales'] = strtoupper(
            mb_substr($user['prenom'] ?? '', 0, 1) . 
            mb_substr($user['nom'] ?? '', 0, 1)
        );

        return $user;

    } catch (Throwable $e) {
        return null;
    }
}

/* ============================================================
   FILTRES
   ============================================================ */
$user_type = in_array($_GET['user_type'] ?? '', ['admin','caissier','client','guest'], true) ? $_GET['user_type'] : '';
$action    = trim($_GET['action'] ?? '');
$criticite = in_array($_GET['criticite'] ?? '', ['success','failed','security'], true) ? $_GET['criticite'] : '';
$limit     = min((int)($_GET['limit'] ?? 200), 1000);

/* ============================================================
   CONSTRUCTION REQUÊTE
   ============================================================ */
$sql = "SELECT * FROM Audit_Logs WHERE 1=1";
$params = [];

if ($user_type !== '') {
    $sql .= " AND user_type = ?";
    $params[] = $user_type;
}
if ($action !== '') {
    $sql .= " AND action LIKE ?";
    $params[] = "%$action%";
}
if ($criticite === 'success') {
    $sql .= " AND action LIKE '%SUCCESS%'";
} elseif ($criticite === 'failed') {
    $sql .= " AND action LIKE '%FAILED%'";
} elseif ($criticite === 'security') {
    $sql .= " AND (action LIKE '%SECURITY%' OR action LIKE '%DENIED%')";
}

$sql .= " ORDER BY created_at DESC LIMIT $limit";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

/* ============================================================
   ENRICHIR LES LOGS AVEC LES INFOS UTILISATEUR
   ============================================================ */
$cache_users = []; // Éviter les requêtes dupliquées

foreach ($logs as &$log) {
    $type = $log['user_type'] ?? '';
    $uid  = $log['user_id'] ? (int)$log['user_id'] : null;

    if ($uid && in_array($type, ['admin','caissier','client','actionnaire'], true)) {
        $key = $type . ':' . $uid;
        
        if (!isset($cache_users[$key])) {
            $cache_users[$key] = getNomUtilisateur($pdo, $type, $uid);
        }

        $log['utilisateur'] = $cache_users[$key];
    } else {
        $log['utilisateur'] = null;
    }
}
unset($log);

/* ============================================================
   STATISTIQUES
   ============================================================ */
$stats = [
    'total_24h'    => (int)$pdo->query("SELECT COUNT(*) FROM Audit_Logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn(),
    'logins_ok'    => (int)$pdo->query("SELECT COUNT(*) FROM Audit_Logs WHERE action='LOGIN_SUCCESS' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn(),
    'logins_fail'  => (int)$pdo->query("SELECT COUNT(*) FROM Audit_Logs WHERE action='LOGIN_FAILED' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn(),
    'alertes'      => (int)$pdo->query("SELECT COUNT(*) FROM Audit_Logs WHERE (action LIKE '%SECURITY%' OR action LIKE '%DENIED%') AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetchColumn(),
];

/* ============================================================
   IPs SUSPECTES
   ============================================================ */
$ips_suspectes = $pdo->query("
    SELECT ip_address, COUNT(*) AS nb_tentatives, MAX(created_at) AS derniere
    FROM Audit_Logs
    WHERE action LIKE '%LOGIN_FAILED%' 
      AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
      AND ip_address IS NOT NULL
    GROUP BY ip_address
    HAVING nb_tentatives >= 5
    ORDER BY nb_tentatives DESC
    LIMIT 10
")->fetchAll();

logAction($pdo, 'admin', $admin_id, 'LOGS_VIEW',
    "Filtres: user_type=$user_type action=$action criticite=$criticite");

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Journal d'activité — ATF Admin</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/admin-premium.css">
<style>
/* ============================================================
   CELLULE UTILISATEUR AMÉLIORÉE
   ============================================================ */
.user-cell {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 200px;
}
.user-avatar {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 800;
  font-size: .75rem;
  flex-shrink: 0;
  border: 2px solid;
}
.user-avatar.admin {
  background: rgba(245,184,0,.15);
  color: var(--gold);
  border-color: rgba(245,184,0,.4);
}
.user-avatar.caissier {
  background: rgba(59,130,246,.15);
  color: #93B4FF;
  border-color: rgba(59,130,246,.4);
}
.user-avatar.client {
  background: rgba(16,185,129,.15);
  color: #6EE7B7;
  border-color: rgba(16,185,129,.4);
}
.user-avatar.actionnaire {
  background: rgba(139,92,246,.15);
  color: #A78BFA;
  border-color: rgba(139,92,246,.4);
}
.user-avatar.guest {
  background: rgba(107,114,128,.15);
  color: #9CA3AF;
  border-color: rgba(107,114,128,.3);
}

.user-info {
  flex: 1;
  min-width: 0;
}
.user-info .name {
  font-weight: 700;
  color: var(--text);
  font-size: .85rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.user-info .sub {
  font-size: .7rem;
  color: var(--muted);
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 2px;
  flex-wrap: wrap;
}
.user-info .sub .id-badge {
  font-family: monospace;
  color: #93B4FF;
  background: rgba(59,130,246,.1);
  padding: 1px 6px;
  border-radius: 4px;
  font-size: .65rem;
}
.user-info .sub .compte-badge {
  font-family: monospace;
  color: var(--gold);
  font-size: .65rem;
}

/* Style pour utilisateur inconnu */
.user-unknown {
  color: var(--muted);
  font-style: italic;
  font-size: .78rem;
}

/* Responsive */
@media (max-width: 768px) {
  .user-cell { min-width: 0; }
  .user-avatar { width: 32px; height: 32px; font-size: .7rem; }
  .user-info .name { font-size: .8rem; }
}
</style>
</head>
<body class="dark-auth">

<div class="top-progress" id="topProgress"></div>
<?php require __DIR__ . '/../includes/header.php'; ?>

<div class="admin-layout">
  <aside class="admin-sidebar animate-left">
    <h3>📊 Navigation</h3>
    <a href="<?= $base ?>/admin/dashboard.php"><span class="ico">📊</span> Tableau de bord</a>
    <a href="<?= $base ?>/admin/clients.php"><span class="ico">👥</span> Clients</a>
    <a href="<?= $base ?>/admin/caissiers.php"><span class="ico">💼</span> Caissiers</a>
    <a href="<?= $base ?>/admin/produits.php"><span class="ico">📈</span> Produits</a>
    <a href="<?= $base ?>/admin/placements.php"><span class="ico">💰</span> Placements</a>
    <a href="<?= $base ?>/admin/commissions.php"><span class="ico">💵</span> Commissions</a>
    <a href="<?= $base ?>/admin/statistiques.php"><span class="ico">📈</span> Statistiques</a>
    <a href="<?= $base ?>/admin/transactions.php"><span class="ico">💳</span> Transactions</a>
    <a href="<?= $base ?>/admin/logs.php" class="active"><span class="ico">📜</span> Journal</a>
    <a href="<?= $base ?>/auth/logout.php"><span class="ico">🚪</span> Déconnexion</a>
  </aside>

  <main class="admin-main">

    <div class="welcome-head">
      <div>
        <h2>📜 Journal d'activité & sécurité</h2>
        <p class="sub"><?= count($logs) ?> entrée(s) affichée(s)</p>
      </div>
      <span class="badge <?= $stats['alertes'] > 0 ? 'red' : 'green' ?>">
        <?= $stats['alertes'] > 0 ? '⚠️' : '✅' ?> <?= $stats['alertes'] ?> alerte(s)
      </span>
    </div>

    <!-- KPIs -->
    <section class="kpi-grid">
      <div class="stat-block gold animate-in delay-1">
        <div class="ico">📜</div>
        <div class="meta">
          <strong><?= $stats['total_24h'] ?></strong>
          <span>Actions 24h</span>
        </div>
      </div>
      <div class="stat-block green animate-in delay-2">
        <div class="ico">✅</div>
        <div class="meta">
          <strong><?= $stats['logins_ok'] ?></strong>
          <span>Connexions réussies</span>
        </div>
      </div>
      <div class="stat-block red animate-in delay-3">
        <div class="ico">❌</div>
        <div class="meta">
          <strong><?= $stats['logins_fail'] ?></strong>
          <span>Connexions échouées</span>
        </div>
      </div>
      <div class="stat-block blue animate-in delay-4">
        <div class="ico">🛡️</div>
        <div class="meta">
          <strong><?= $stats['alertes'] ?></strong>
          <span>Alertes (7 jours)</span>
        </div>
      </div>
    </section>

    <!-- IPs suspectes -->
    <?php if ($ips_suspectes): ?>
      <section class="card animate-in delay-2" style="border-color:rgba(239,68,68,.4)">
        <h3 style="margin-bottom:16px;color:#FCA5A5">⚠️ IP suspectes (5+ tentatives échouées en 24h)</h3>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Adresse IP</th>
                <th style="text-align:right">Tentatives</th>
                <th>Dernière tentative</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($ips_suspectes as $ip): ?>
                <tr>
                  <td><code><?= htmlspecialchars($ip['ip_address']) ?></code></td>
                  <td style="text-align:right">
                    <span class="badge red"><?= (int)$ip['nb_tentatives'] ?></span>
                  </td>
                  <td><?= date('d/m/Y H:i', strtotime($ip['derniere'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endif; ?>

    <!-- Filtres -->
    <div class="admin-tabs">
      <a href="?" class="admin-tab <?= $user_type==='' && $criticite==='' ? 'active' : '' ?>">Tous</a>
      <a href="?user_type=admin" class="admin-tab <?= $user_type==='admin' ? 'active' : '' ?>">Admins</a>
      <a href="?user_type=caissier" class="admin-tab <?= $user_type==='caissier' ? 'active' : '' ?>">Caissiers</a>
      <a href="?user_type=client" class="admin-tab <?= $user_type==='client' ? 'active' : '' ?>">Clients</a>
      <a href="?user_type=guest" class="admin-tab <?= $user_type==='guest' ? 'active' : '' ?>">Invités</a>
      <a href="?criticite=success" class="admin-tab <?= $criticite==='success' ? 'active' : '' ?>">✅ Succès</a>
      <a href="?criticite=failed" class="admin-tab <?= $criticite==='failed' ? 'active' : '' ?>">❌ Échecs</a>
      <a href="?criticite=security" class="admin-tab <?= $criticite==='security' ? 'active' : '' ?>">🛡️ Sécurité</a>
    </div>

    <!-- Tableau -->
    <section class="card animate-in delay-3">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px">
        <h3 style="margin:0">📋 Détail des logs</h3>
        <input type="text" placeholder="🔍 Rechercher une action..." 
               data-table-filter="logsTable" style="max-width:280px">
      </div>

      <div class="table-wrap">
        <table id="logsTable">
          <thead>
            <tr>
              <th>Utilisateur</th>
              <th>Type</th>
              <th>Action</th>
              <th>Détails</th>
              <th>IP</th>
              <th>Date</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$logs): ?>
              <tr><td colspan="6" class="muted center" style="padding:30px">Aucun log.</td></tr>
            <?php else: foreach ($logs as $l):
              $is_security = str_contains($l['action'], 'SECURITY') || str_contains($l['action'], 'DENIED');
              $is_fail     = str_contains($l['action'], 'FAILED');
              $is_success  = str_contains($l['action'], 'SUCCESS');
              
              $u = $l['utilisateur'];
              $type_css = in_array($l['user_type'], ['admin','caissier','client','actionnaire','guest'], true) 
                  ? $l['user_type'] : 'guest';
            ?>
              <tr <?= $is_security ? 'style="background:rgba(239,68,68,.06)"' : '' ?>>
                
                <!-- 🆕 CELLULE UTILISATEUR AMÉLIORÉE -->
                <td data-label="Utilisateur">
                  <?php if ($u): ?>
                    <div class="user-cell">
                      <div class="user-avatar <?= $type_css ?>">
                        <?= htmlspecialchars($u['initiales']) ?>
                      </div>
                      <div class="user-info">
                        <div class="name" title="<?= htmlspecialchars($u['nom_complet']) ?>">
                          <?= htmlspecialchars($u['nom_complet']) ?>
                        </div>
                        <div class="sub">
                          <?php if (!empty($u['numero_compte'])): ?>
                            <span class="compte-badge"><?= htmlspecialchars($u['numero_compte']) ?></span>
                          <?php endif; ?>
                          <?php if (!empty($u['numero_phone'])): ?>
                            <span>📞 <?= htmlspecialchars($u['numero_phone']) ?></span>
                          <?php endif; ?>
                        </div>
                      </div>
                    </div>
                  <?php elseif ($l['user_id']): ?>
                    <div class="user-cell">
                      <div class="user-avatar <?= $type_css ?>">?</div>
                      <div class="user-info">
                        <div class="user-unknown">Utilisateur inconnu</div>
                        <div class="sub">
                          <span class="id-badge">ID #<?= (int)$l['user_id'] ?></span>
                        </div>
                      </div>
                    </div>
                  <?php else: ?>
                    <span class="user-unknown">—</span>
                  <?php endif; ?>
                </td>

                <!-- Type -->
                <td data-label="Type">
                  <span class="badge <?= $l['user_type']==='admin' ? 'gold' : ($l['user_type']==='caissier' ? 'blue' : ($l['user_type']==='client' ? 'green' : '')) ?>"
                        style="font-size:.7rem">
                    <?= htmlspecialchars($l['user_type']) ?>
                  </span>
                </td>

                <!-- Action -->
                <td data-label="Action">
                  <span style="color:<?= $is_security ? '#FCA5A5' : ($is_fail ? '#FCD34D' : ($is_success ? 'var(--green)' : 'var(--text)')) ?>;font-weight:700;font-size:.82rem">
                    <?= $is_security ? '🛡️ ' : '' ?><?= htmlspecialchars($l['action']) ?>
                  </span>
                </td>

                <!-- Détails -->
                <td data-label="Détails">
                  <small class="muted"><?= htmlspecialchars(mb_substr($l['details'] ?? '', 0, 80)) ?></small>
                </td>

                <!-- IP -->
                <td data-label="IP">
                  <code><?= htmlspecialchars($l['ip_address'] ?? '—') ?></code>
                </td>

                <!-- Date -->
                <td data-label="Date">
                  <?= date('d/m/Y H:i:s', strtotime($l['created_at'])) ?>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </section>

  </main>
</div>

<footer class="atf-footer">© <?= date('Y') ?> AFRICAN TRUST FUND</footer>

<script>
window.addEventListener('scroll', () => {
  const h = document.documentElement.scrollHeight - window.innerHeight;
  document.getElementById('topProgress').style.width = (window.scrollY / h) * 100 + '%';
});
</script>
<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>