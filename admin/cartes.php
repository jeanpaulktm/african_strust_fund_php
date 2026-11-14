<?php
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
requireRole('admin');

$pdo = Database::getConnection();
$admin_id = (int)$_SESSION['admin_id'];

/* ============================================================
   FILTRE PAR TYPE
   ============================================================ */
$type_filtre = $_GET['type'] ?? '';

$clients = [];
$caissiers = [];
$actionnaires = [];

if ($type_filtre === '' || $type_filtre === 'client') {
    $clients = $pdo->query("
        SELECT id_client, nom, postnom, prenom, numero_compte,
               carte_numero, carte_emise, carte_expire, carte_statut
        FROM Client 
        WHERE type_compte != 'entreprise' OR type_compte IS NULL
        ORDER BY created_at DESC
        LIMIT 30
    ")->fetchAll();
}

if ($type_filtre === '' || $type_filtre === 'caissier') {
    $caissiers = $pdo->query("
        SELECT id_caissier, nom, postnom, post_prenom,
               carte_numero, carte_emise, carte_expire, carte_statut
        FROM Caissier 
        ORDER BY created_at DESC
        LIMIT 30
    ")->fetchAll();
}

if ($type_filtre === '' || $type_filtre === 'actionnaire') {
    $actionnaires = $pdo->query("
        SELECT id_actionnaire, numero_actionnaire, nom, postnom, prenom,
               carte_numero, carte_emise, carte_expire, carte_statut
        FROM Actionnaires 
        ORDER BY created_at DESC
        LIMIT 30
    ")->fetchAll();
}

$base = '/african-trust-fund';
logAction($pdo, 'admin', $admin_id, 'CARTES_VIEW', 'Consultation des cartes membres');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cartes Membres — ATF Admin</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/admin-premium.css">
<style>
.carte-item {
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 16px;
  border-radius: 14px;
  background: linear-gradient(145deg, var(--card), var(--card-2));
  border: 1px solid var(--border);
  transition: all .3s;
}
.carte-item:hover {
  transform: translateY(-3px);
  border-color: var(--gold);
  box-shadow: 0 12px 30px rgba(245,184,0,.15);
}
.carte-mini {
  width: 80px;
  height: 50px;
  border-radius: 8px;
  background: linear-gradient(135deg, #0D1B5E, #0B0E14);
  display: flex;
  align-items: center;
  justify-content: center;
  color: #F5B800;
  font-weight: 800;
  font-size: 11px;
  letter-spacing: 1px;
  flex-shrink: 0;
  position: relative;
  overflow: hidden;
  box-shadow: 0 4px 12px rgba(0,0,0,.4);
}
.carte-mini::before {
  content: "";
  position: absolute;
  top: 0; left: -100%;
  width: 100%; height: 100%;
  background: linear-gradient(90deg, transparent, rgba(245,184,0,.3), transparent);
  animation: shine 3s infinite;
}
@keyframes shine { to { left: 100%; } }

.carte-mini.caissier { background: linear-gradient(135deg, #064e3b, #0B0E14); color: #6EE7B7; }
.carte-mini.actionnaire { background: linear-gradient(135deg, #4c1d95, #0B0E14); color: #A78BFA; }
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
    <a href="<?= $base ?>/admin/actionnaires.php"><span class="ico">🏛️</span> Actionnaires</a>
    <a href="<?= $base ?>/admin/cartes.php" class="active"><span class="ico">💳</span> Cartes membres</a>
    <a href="<?= $base ?>/admin/logs.php"><span class="ico">📜</span> Journal</a>
    <a href="<?= $base ?>/auth/logout.php"><span class="ico">🚪</span> Déconnexion</a>
  </aside>

  <main class="admin-main">

    <div class="welcome-head">
      <div>
        <h2>💳 Gestion des cartes membres</h2>
        <p class="sub">Cartes Clients · Caissiers · Actionnaires</p>
      </div>
    </div>

    <div class="admin-tabs">
      <a href="?" class="admin-tab <?= $type_filtre === '' ? 'active' : '' ?>">Toutes</a>
      <a href="?type=client" class="admin-tab <?= $type_filtre === 'client' ? 'active' : '' ?>">👤 Clients</a>
      <a href="?type=caissier" class="admin-tab <?= $type_filtre === 'caissier' ? 'active' : '' ?>">💼 Caissiers</a>
      <a href="?type=actionnaire" class="admin-tab <?= $type_filtre === 'actionnaire' ? 'active' : '' ?>">🏛️ Actionnaires</a>
    </div>

    <!-- Clients -->
    <?php if (!empty($clients)): ?>
      <section class="card animate-in delay-1">
        <h3 style="margin-bottom:16px">👤 Cartes Clients (<?= count($clients) ?>)</h3>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Aperçu</th>
                <th>Titulaire</th>
                <th>Numéro de carte</th>
                <th>Émise</th>
                <th>Expire</th>
                <th>Statut</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($clients as $c):
                $token = genererToken('client', (int)$c['id_client'], ['action' => 'admin_view_carte']);
              ?>
                <tr>
                  <td><div class="carte-mini">ATF</div></td>
                  <td><strong><?= htmlspecialchars($c['nom'] . ' ' . $c['postnom']) ?></strong><br>
                      <small class="muted"><?= htmlspecialchars($c['numero_compte']) ?></small></td>
                  <td><code><?= htmlspecialchars($c['carte_numero'] ?? '—') ?></code></td>
                  <td><?= $c['carte_emise'] ? date('d/m/Y', strtotime($c['carte_emise'])) : '—' ?></td>
                  <td><?= $c['carte_expire'] ? date('d/m/Y', strtotime($c['carte_expire'])) : '—' ?></td>
                  <td><span class="badge <?= ($c['carte_statut'] ?? 'active') === 'active' ? 'green' : 'red' ?>"><?= htmlspecialchars($c['carte_statut'] ?? 'active') ?></span></td>
                  <td>
                    <a class="btn-ghost btn-sm" 
                       href="<?= $base ?>/carte_membre.php?type=client&token=<?= htmlspecialchars($token) ?>"
                       target="_blank">
                      💳 Voir la carte
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endif; ?>

    <!-- Caissiers -->
    <?php if (!empty($caissiers)): ?>
      <section class="card animate-in delay-2">
        <h3 style="margin-bottom:16px">💼 Cartes Caissiers (<?= count($caissiers) ?>)</h3>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Aperçu</th>
                <th>Nom</th>
                <th>Numéro de carte</th>
                <th>Statut</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($caissiers as $c):
                $token = genererToken('caissier', (int)$c['id_caissier'], ['action' => 'admin_view_carte']);
              ?>
                <tr>
                  <td><div class="carte-mini caissier">ATF</div></td>
                  <td><strong><?= htmlspecialchars($c['nom'] . ' ' . $c['postnom'] . ' ' . $c['post_prenom']) ?></strong></td>
                  <td><code><?= htmlspecialchars($c['carte_numero'] ?? '—') ?></code></td>
                  <td><span class="badge <?= ($c['carte_statut'] ?? 'active') === 'active' ? 'green' : 'red' ?>"><?= htmlspecialchars($c['carte_statut'] ?? 'active') ?></span></td>
                  <td>
                    <a class="btn-ghost btn-sm" 
                       href="<?= $base ?>/carte_membre.php?type=caissier&token=<?= htmlspecialchars($token) ?>"
                       target="_blank">
                      💳 Voir la carte
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endif; ?>

    <!-- Actionnaires -->
    <?php if (!empty($actionnaires)): ?>
      <section class="card animate-in delay-3">
        <h3 style="margin-bottom:16px">🏛️ Cartes Actionnaires (<?= count($actionnaires) ?>)</h3>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Aperçu</th>
                <th>Titulaire</th>
                <th>N° Actionnaire</th>
                <th>N° Carte</th>
                <th>Statut</th>
                <th>Action</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($actionnaires as $a):
                $token = genererToken('actionnaire', (int)$a['id_actionnaire'], ['action' => 'admin_view_carte']);
              ?>
                <tr>
                  <td><div class="carte-mini actionnaire">ATF</div></td>
                  <td><strong><?= htmlspecialchars($a['nom'] . ' ' . $a['postnom']) ?></strong></td>
                  <td><code><?= htmlspecialchars($a['numero_actionnaire']) ?></code></td>
                  <td><code><?= htmlspecialchars($a['carte_numero'] ?? '—') ?></code></td>
                  <td><span class="badge <?= ($a['carte_statut'] ?? 'active') === 'active' ? 'green' : 'red' ?>"><?= htmlspecialchars($a['carte_statut'] ?? 'active') ?></span></td>
                  <td>
                    <a class="btn-ghost btn-sm" 
                       href="<?= $base ?>/carte_membre.php?type=actionnaire&token=<?= htmlspecialchars($token) ?>"
                       target="_blank">
                      💳 Voir la carte
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </section>
    <?php endif; ?>

  </main>
</div>

<footer class="atf-footer">© <?= date('Y') ?> AFRICAN TRUST FUND</footer>
<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>