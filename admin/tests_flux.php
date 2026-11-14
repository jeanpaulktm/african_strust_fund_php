<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/prets.php';
require_once __DIR__ . '/../config/tests_flux.php';
requireRole('admin');

$pdo = Database::getConnection();
$admin_id = (int)$_SESSION['admin_id'];

$errors = [];
$success = '';

/* Exécuter tous les tests */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'executer_tous') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) throw new RuntimeException("Jeton invalide.");
        
        $resultat = executerTousTests($pdo, $admin_id);
        
        $_SESSION['flash_success'] = "✅ Tests exécutés !<br>
            <strong>Total :</strong> {$resultat['total']}<br>
            <strong>✅ Succès :</strong> {$resultat['succes']}<br>
            <strong>❌ Échecs :</strong> {$resultat['echecs']}<br>
            <strong>⚠️ Warnings :</strong> {$resultat['warnings']}<br>
            <strong>⏱️ Durée :</strong> {$resultat['duree']} ms";
        header('Location: ' . url('admin/tests_flux.php'));
        exit;
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

/* Exécuter un test spécifique */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'executer_un') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) throw new RuntimeException("Jeton invalide.");
        
        $id_test = (int)($_POST['id_test'] ?? 0);
        $resultat = executerTest($pdo, $id_test, $admin_id);
        
        $_SESSION['flash_success'] = "✅ Test « {$resultat['nom']} » exécuté : " . strtoupper($resultat['statut']);
        header('Location: ' . url('admin/tests_flux.php'));
        exit;
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

/* Charger tous les tests */
$tests = $pdo->query("SELECT * FROM Tests_Flux ORDER BY categorie, code")->fetchAll();

/* Grouper par catégorie */
$tests_par_cat = [];
foreach ($tests as $t) {
    $tests_par_cat[$t['categorie']][] = $t;
}

/* Stats globales */
$stats = [
    'total' => count($tests),
    'succes' => 0, 'echecs' => 0, 'warnings' => 0, 'jamais' => 0,
];
foreach ($tests as $t) {
    if ($t['dernier_statut'] === 'succes') $stats['succes']++;
    elseif ($t['dernier_statut'] === 'echec') $stats['echecs']++;
    elseif ($t['dernier_statut'] === 'partiel') $stats['warnings']++;
    else $stats['jamais']++;
}

/* Dernières exécutions */
$executions = $pdo->query("SELECT * FROM Tests_Executions ORDER BY date_execution DESC LIMIT 5")->fetchAll();

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Tests des flux — ATF Admin</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/admin-premium.css">
<style>
.kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 14px; margin-bottom: 24px; }
.stat-block { padding: 16px; border-radius: 14px; background: linear-gradient(145deg, var(--card), var(--card-2)); border: 1px solid var(--border); text-align: center; }
.stat-block .ico { font-size: 1.8rem; margin-bottom: 6px; }
.stat-block .val { font-size: 1.6rem; font-weight: 900; color: var(--gold); }
.stat-block .lbl { font-size: .72rem; color: var(--muted); text-transform: uppercase; letter-spacing: 1px; font-weight: 700; }
.stat-block.green .val { color: #6EE7B7; }
.stat-block.red .val { color: #FCA5A5; }
.stat-block.blue .val { color: #93B4FF; }
.stat-block.gray .val { color: #9CA3AF; }

.cat-section { margin-bottom: 24px; }
.cat-title {
  color: var(--gold);
  font-size: 1.05rem;
  font-weight: 800;
  margin-bottom: 12px;
  padding-bottom: 8px;
  border-bottom: 2px solid rgba(245,184,0,.3);
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.cat-title .count { font-size: .82rem; color: var(--muted); font-weight: 600; }

.test-card {
  padding: 16px 18px;
  border-radius: 12px;
  background: linear-gradient(145deg, var(--card), var(--card-2));
  border: 1px solid var(--border);
  margin-bottom: 10px;
  display: flex;
  align-items: center;
  gap: 16px;
  flex-wrap: wrap;
  transition: all .25s;
}
.test-card:hover { border-color: rgba(245,184,0,.3); }
.test-card.succes { border-left: 4px solid #10B981; }
.test-card.echec { border-left: 4px solid #EF4444; }
.test-card.partiel { border-left: 4px solid #F5B800; }
.test-card.jamais { border-left: 4px solid #6B7280; }

.test-status {
  width: 44px; height: 44px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.3rem;
  flex-shrink: 0;
  font-weight: 900;
}
.test-status.succes { background: rgba(16,185,129,.15); color: #6EE7B7; border: 2px solid #10B981; }
.test-status.echec { background: rgba(239,68,68,.15); color: #FCA5A5; border: 2px solid #EF4444; }
.test-status.partiel { background: rgba(245,184,0,.15); color: #FCD34D; border: 2px solid #F5B800; }
.test-status.jamais { background: rgba(107,114,128,.15); color: #9CA3AF; border: 2px solid #6B7280; }

.test-info { flex: 1; min-width: 200px; }
.test-info .code {
  font-family: monospace;
  color: var(--gold);
  font-size: .78rem;
  font-weight: 800;
}
.test-info .nom {
  color: var(--text);
  font-weight: 700;
  font-size: .95rem;
  margin: 4px 0;
}
.test-info .desc {
  color: var(--muted);
  font-size: .78rem;
  line-height: 1.5;
}
.test-info .type-badge {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 999px;
  font-size: .68rem;
  font-weight: 800;
  margin-right: 6px;
}
.type-badge.pp { background: rgba(59,130,246,.15); color: #93B4FF; }
.type-badge.pm { background: rgba(139,92,246,.15); color: #A78BFA; }
.type-badge.tous { background: rgba(107,114,128,.15); color: #9CA3AF; }

.btn-exec {
  padding: 10px 16px;
  border-radius: 10px;
  background: linear-gradient(135deg, #3B82F6, #2563EB);
  color: #fff;
  border: none;
  font-weight: 700;
  cursor: pointer;
  font-size: .82rem;
  font-family: inherit;
}
.btn-exec:hover { transform: translateY(-1px); }
.btn-exec:disabled { opacity: .5; cursor: not-allowed; }

.btn-exec-all {
  padding: 16px 32px;
  border-radius: 999px;
  background: linear-gradient(135deg, #10B981, #059669);
  color: #fff;
  border: none;
  font-weight: 800;
  font-size: 1rem;
  cursor: pointer;
  font-family: inherit;
  box-shadow: 0 6px 20px rgba(16,185,129,.4);
}
.btn-exec-all:hover { transform: translateY(-2px); }

/* Modal résultats */
.result-modal {
  position: fixed;
  inset: 0;
  background: rgba(11,14,20,.9);
  backdrop-filter: blur(10px);
  z-index: 9999;
  display: none;
  align-items: center;
  justify-content: center;
  padding: 20px;
  overflow-y: auto;
}
.result-modal.active { display: flex; }
.result-content {
  max-width: 700px;
  width: 100%;
  max-height: 90vh;
  overflow-y: auto;
  background: linear-gradient(145deg, #1A2130, #121824);
  border-radius: 20px;
  border: 1px solid rgba(245,184,0,.3);
  padding: 28px;
}
.result-content h3 {
  color: var(--gold);
  margin-bottom: 16px;
  font-size: 1.2rem;
}

.result-etape {
  padding: 12px 14px;
  border-radius: 10px;
  margin-bottom: 8px;
  display: flex;
  align-items: flex-start;
  gap: 12px;
  font-size: .88rem;
}
.result-etape.ok { background: rgba(16,185,129,.08); border-left: 3px solid #10B981; }
.result-etape.warning { background: rgba(245,184,0,.08); border-left: 3px solid #F5B800; }
.result-etape.erreur { background: rgba(239,68,68,.08); border-left: 3px solid #EF4444; }
.result-etape.skip { background: rgba(107,114,128,.08); border-left: 3px solid #6B7280; }

.result-etape .ico { font-size: 1.1rem; flex-shrink: 0; }
.result-etape .msg { color: var(--text); flex: 1; }
.result-etape .msg .etape-nom { font-weight: 800; color: var(--gold); display: block; margin-bottom: 3px; }
.result-etape .duree { color: var(--muted); font-size: .72rem; font-family: monospace; flex-shrink: 0; }

.exec-history {
  padding: 14px;
  border-radius: 12px;
  background: var(--card);
  border: 1px solid var(--border);
  margin-bottom: 10px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
}
.exec-history .date { color: var(--muted); font-size: .8rem; }
</style>
</head>
<!-- Modal résultat -->
<div class="result-modal" id="resultModal">
  <div class="result-content">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px">
      <h3 id="modalTitle">Résultats du test</h3>
      <button onclick="fermerModal()" style="background:rgba(239,68,68,.15);color:#FCA5A5;border:1px solid rgba(239,68,68,.3);width:36px;height:36px;border-radius:50%;font-size:1.2rem;cursor:pointer">✕</button>
    </div>
    <div id="modalBody"></div>
  </div>
</div>

<style>
.spinner-inline {
  display: inline-block;
  width: 16px; height: 16px;
  border: 2px solid rgba(255,255,255,.3);
  border-top-color: #fff;
  border-radius: 50%;
  animation: spin .8s linear infinite;
  vertical-align: middle;
  margin-right: 6px;
}
@keyframes spin { to { transform: rotate(360deg); } }

.test-card.running { opacity: .7; }
.test-status.running { animation: pulse 1s infinite; }
@keyframes pulse {
  0%, 100% { transform: scale(1); }
  50% { transform: scale(1.15); }
}
</style>

<script>
/* ============================================================
   EXÉCUTION AJAX D'UN TEST
   ============================================================ */
async function executerTestAjax(idTest, btn) {
  const card = btn.closest('.test-card');
  const status = card.querySelector('.test-status');
  const originalContent = btn.innerHTML;
  
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-inline"></span>Exécution...';
  card.classList.add('running');
  status.textContent = '⏳';
  
  try {
    const formData = new FormData();
    formData.append('action', 'executer_un');
    formData.append('id_test', idTest);
    formData.append('csrf', '<?= csrfToken() ?>');
    
    const res = await fetch('<?= $base ?>/admin/test_runner_ajax.php', {
      method: 'POST',
      body: formData,
    });
    
    const data = await res.json();
    
    if (!data.success) throw new Error(data.error || 'Erreur inconnue');
    
    /* Mettre à jour la carte */
    const nouveauStatut = data.test.statut;
    const statutIcones = { succes: '✅', echec: '❌', partiel: '⚠️', jamais: '⏸️' };
    
    card.className = 'test-card ' + nouveauStatut;
    status.className = 'test-status ' + nouveauStatut;
    status.textContent = statutIcones[nouveauStatut] || '❓';
    
    btn.disabled = false;
    btn.innerHTML = originalContent;
    card.classList.remove('running');
    
    /* Afficher les détails */
    afficherResultats(data.test);
    
    /* Refresh stats */
    refreshStats();
    
  } catch (err) {
    alert('❌ Erreur : ' + err.message);
    btn.disabled = false;
    btn.innerHTML = originalContent;
    card.classList.remove('running');
    status.textContent = '❌';
  }
}

/* ============================================================
   AFFICHER RÉSULTATS DANS MODAL
   ============================================================ */
function afficherResultats(test) {
  document.getElementById('modalTitle').textContent = '📊 ' + test.nom;
  
  const etapesIcons = { ok: '✅', warning: '⚠️', erreur: '❌', skip: '⏸️' };
  
  let html = `
    <div style="margin-bottom:16px;padding:12px;border-radius:10px;background:rgba(11,14,20,.5)">
      <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:10px">
        <span>Statut : <strong>${test.statut.toUpperCase()}</strong></span>
        <span>⏱️ ${test.duree} ms</span>
      </div>
    </div>
  `;
  
  if (test.etapes && test.etapes.length > 0) {
    test.etapes.forEach(e => {
      const ico = etapesIcons[e.statut] || '❓';
      html += `
        <div class="result-etape ${e.statut}">
          <div class="ico">${ico}</div>
          <div class="msg">
            <span class="etape-nom">${escapeHtml(e.etape)}</span>
            ${escapeHtml(e.message || '')}
          </div>
          <div class="duree">${e.duree_ms || 0} ms</div>
        </div>
      `;
    });
  }
  
  document.getElementById('modalBody').innerHTML = html;
  document.getElementById('resultModal').classList.add('active');
}

function fermerModal() {
  document.getElementById('resultModal').classList.remove('active');
}

document.getElementById('resultModal').addEventListener('click', function(e) {
  if (e.target === this) fermerModal();
});

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') fermerModal();
});

function escapeHtml(str) {
  return String(str).replace(/[&<>"']/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[s]));
}

/* ============================================================
   REFRESH STATS
   ============================================================ */
async function refreshStats() {
  try {
    const res = await fetch('<?= $base ?>/admin/test_runner_ajax.php?action=get_stats&csrf=<?= csrfToken() ?>');
    const data = await res.json();
    if (!data.success) return;
    
    const s = data.stats;
    const blocks = document.querySelectorAll('.kpi-grid .stat-block');
    if (blocks.length >= 5) {
      blocks[0].querySelector('.val').textContent = s.total;
      blocks[1].querySelector('.val').textContent = s.succes;
      blocks[2].querySelector('.val').textContent = s.echecs;
      blocks[3].querySelector('.val').textContent = s.warnings;
      blocks[4].querySelector('.val').textContent = s.jamais;
    }
  } catch (e) { /* silencieux */ }
}

/* ============================================================
   BINDING BOUTONS
   ============================================================ */
document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.btn-exec').forEach(btn => {
    const form = btn.closest('form');
    if (!form) return;
    
    const idTest = form.querySelector('input[name="id_test"]')?.value;
    if (!idTest) return;
    
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      executerTestAjax(parseInt(idTest), btn);
    });
  });
});

console.log('%c🧪 Test Runner AJAX chargé', 'color:#10B981;font-weight:bold;font-size:13px');
</script>
<body class="dark-auth">

<?php require __DIR__ . '/../includes/header.php'; ?>

<div class="admin-layout">
  <aside class="admin-sidebar animate-left">
    <h3>📊 Navigation</h3>
    <a href="<?= $base ?>/admin/dashboard.php"><span class="ico">📊</span> Tableau de bord</a>
    <a href="<?= $base ?>/admin/prets.php"><span class="ico">💳</span> Prêts</a>
    <a href="<?= $base ?>/admin/entreprises.php"><span class="ico">🏢</span> Entreprises</a>
    <a href="<?= $base ?>/admin/tests_flux.php" class="active"><span class="ico">🧪</span> Tests</a>
    <a href="<?= $base ?>/admin/logs.php"><span class="ico">📜</span> Journal</a>
    <a href="<?= $base ?>/auth/logout.php"><span class="ico">🚪</span> Déconnexion</a>
  </aside>

  <main class="admin-main">

    <div class="welcome-head">
      <div>
        <h2>🧪 Tests des flux PRÊT</h2>
        <p class="sub"><?= $stats['total'] ?> tests — <?= $stats['succes'] ?> succès, <?= $stats['echecs'] ?> échecs</p>
      </div>
    </div>

    <?php if (!empty($_SESSION['flash_success'])): ?>
      <div class="alert success"><?= $_SESSION['flash_success'] ?></div>
      <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>
    <?php if ($errors): ?>
      <div class="alert error"><ul><?php foreach ($errors as $e) echo "<li>" . htmlspecialchars($e) . "</li>"; ?></ul></div>
    <?php endif; ?>

    <!-- KPIs -->
    <section class="kpi-grid">
      <div class="stat-block"><div class="ico">📋</div><div class="val"><?= $stats['total'] ?></div><div class="lbl">Total</div></div>
      <div class="stat-block green"><div class="ico">✅</div><div class="val"><?= $stats['succes'] ?></div><div class="lbl">Succès</div></div>
      <div class="stat-block red"><div class="ico">❌</div><div class="val"><?= $stats['echecs'] ?></div><div class="lbl">Échecs</div></div>
      <div class="stat-block"><div class="ico">⚠️</div><div class="val"><?= $stats['warnings'] ?></div><div class="lbl">Warnings</div></div>
      <div class="stat-block gray"><div class="ico">⏸️</div><div class="val"><?= $stats['jamais'] ?></div><div class="lbl">Non exécutés</div></div>
    </section>

    <!-- Bouton exécuter tous -->
    <div style="text-align:center;margin-bottom:28px">
      <form method="POST" style="display:inline">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="executer_tous">
        <button class="btn-exec-all" type="submit" onclick="return confirm('⚠️ Exécuter TOUS les tests ? Cela peut créer des données de test.')">
          🚀 Exécuter tous les tests
        </button>
      </form>
    </div>

    <!-- Historique -->
    <?php if (!empty($executions)): ?>
      <h3 style="color:var(--gold);margin-bottom:12px">📜 Dernières exécutions</h3>
      <?php foreach ($executions as $ex): ?>
        <div class="exec-history">
          <div>
            <div style="font-weight:800;color:var(--text)"><?= date('d/m/Y à H:i:s', strtotime($ex['date_execution'])) ?></div>
            <div class="date">par <?= htmlspecialchars($ex['execute_par_role']) ?> #<?= (int)$ex['execute_par'] ?></div>
          </div>
          <div style="display:flex;gap:14px;align-items:center">
            <span style="color:#6EE7B7;font-weight:800">✅ <?= (int)$ex['nb_succes'] ?></span>
            <span style="color:#FCA5A5;font-weight:800">❌ <?= (int)$ex['nb_echecs'] ?></span>
            <span style="color:#FCD34D;font-weight:800">⚠️ <?= (int)$ex['nb_warnings'] ?></span>
            <span class="date">⏱️ <?= (int)$ex['duree_totale_ms'] ?> ms</span>
          </div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>

    <!-- Tests par catégorie -->
    <?php 
      $cat_labels = [
        'creation' => '🏗️ Création',
        'workflow' => '🔄 Workflow',
        'securite' => '🔐 Sécurité',
        'calculs' => '🧮 Calculs',
        'edge_case' => '⚡ Cas limites',
      ];
      foreach ($cat_labels as $cat => $label):
        if (empty($tests_par_cat[$cat])) continue;
    ?>
      <div class="cat-section">
        <div class="cat-title">
          <?= $label ?>
          <span class="count"><?= count($tests_par_cat[$cat]) ?> test(s)</span>
        </div>

        <?php foreach ($tests_par_cat[$cat] as $t): 
          $statut_icon = [
            'succes' => '✅', 'echec' => '❌', 'partiel' => '⚠️', 'jamais' => '⏸️'
          ][$t['dernier_statut']] ?? '⏸️';
          
          $type_class = $t['type_client'] === 'tous' ? 'tous' : strtolower($t['type_client']);
        ?>
          <div class="test-card <?= htmlspecialchars($t['dernier_statut']) ?>">
            <div class="test-status <?= htmlspecialchars($t['dernier_statut']) ?>">
              <?= $statut_icon ?>
            </div>
            <div class="test-info">
              <div class="code">
                <span class="type-badge <?= $type_class ?>">
                  <?= strtoupper($t['type_client']) ?>
                </span>
                <?= htmlspecialchars($t['code']) ?>
              </div>
              <div class="nom"><?= htmlspecialchars($t['nom']) ?></div>
              <div class="desc"><?= htmlspecialchars($t['description']) ?></div>
              <?php if ($t['derniere_execution']): ?>
                <div class="desc" style="margin-top:6px;font-size:.72rem">
                  Dernière exécution : <?= date('d/m/Y H:i', strtotime($t['derniere_execution'])) ?>
                  · <?= (int)$t['duree_ms'] ?> ms
                </div>
              <?php endif; ?>
            </div>
            <form method="POST" style="display:inline">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="executer_un">
              <input type="hidden" name="id_test" value="<?= (int)$t['id_test'] ?>">
              <button class="btn-exec" type="submit">▶️ Exécuter</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>

  </main>
</div>

<footer class="atf-footer">© <?= date('Y') ?> AFRICAN TRUST FUND</footer>
<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>