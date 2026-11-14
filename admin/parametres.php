<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/parametres.php';
requireRole('admin');

$pdo = Database::getConnection();
$admin_id = (int)$_SESSION['admin_id'];

$errors = [];
$success = '';

/* ============================================================
   TRAITEMENT DE LA MISE À JOUR
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) throw new RuntimeException("Jeton invalide.");

        /* Récupérer tous les paramètres postés */
        $parametres = $_POST['parametres'] ?? [];

        if (empty($parametres)) {
            throw new RuntimeException("Aucun paramètre à mettre à jour.");
        }

        /* Validation selon le type */
        foreach ($parametres as $cle => $valeur) {
            /* Validation du taux d'intérêt */
            if ($cle === 'taux_interet_defaut') {
                $taux = (float)$valeur;
                if ($taux < 0 || $taux > 50) {
                    throw new RuntimeException("Le taux d'intérêt doit être entre 0% et 50%.");
                }
            }

            /* Validation durée de blocage */
            if ($cle === 'duree_blocage_interets') {
                $duree = (int)$valeur;
                if ($duree < 1 || $duree > 60) {
                    throw new RuntimeException("La durée de blocage doit être entre 1 et 60 mois.");
                }
            }

            /* Validation commission */
            if ($cle === 'commission_retrait') {
                $comm = (float)$valeur;
                if ($comm < 0 || $comm > 10) {
                    throw new RuntimeException("La commission doit être entre 0% et 10%.");
                }
            }

            /* Mettre à jour */
            setParametre($pdo, $cle, (string)$valeur, $admin_id);
        }

        logAction($pdo, 'admin', $admin_id, 'PARAMETRES_UPDATE',
            "Modification : " . implode(', ', array_keys($parametres)));

        $_SESSION['flash_success'] = "✅ Paramètres mis à jour avec succès !<br>
            <small>Les nouveaux taux s'appliquent aux <strong>nouveaux dépôts</strong>. Les placements existants conservent leur taux initial.</small>";

        header('Location: ' . url('admin/parametres.php'));
        exit;

    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

/* Flash */
$success = '';
if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

/* Charger les paramètres par catégorie */
$parametres_interets    = getParametresParCategorie($pdo, 'interets');
$parametres_commissions = getParametresParCategorie($pdo, 'commissions');
$parametres_epargne     = getParametresParCategorie($pdo, 'epargne');
$parametres_devises     = getParametresParCategorie($pdo, 'devises');
$parametres_general     = getParametresParCategorie($pdo, 'general');

/* Stats impact */
$taux_actuel = getTauxInteretDefaut($pdo);
$duree_blocage = getDureeBlocageInterets($pdo);
$commission = getCommissionRetrait($pdo);

/* Placements existants avec taux fixe */
$stmt = $pdo->query("
    SELECT COUNT(*) AS total,
           AVG(taux_applique * 100) AS taux_moyen,
           MIN(taux_applique * 100) AS taux_min,
           MAX(taux_applique * 100) AS taux_max
    FROM Placements WHERE statut = 'actif'
");
$stats_placements = $stmt->fetch();

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Paramètres Système — ATF Admin</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/admin-premium.css">
<style>
.param-section {
  background: linear-gradient(145deg, var(--card), var(--card-2));
  border: 1px solid var(--border);
  border-radius: 20px;
  padding: 26px;
  margin-bottom: 22px;
  position: relative;
  overflow: hidden;
}
.param-section::before {
  content: "";
  position: absolute;
  top: 0; left: 0;
  width: 4px; height: 100%;
  background: linear-gradient(180deg, var(--gold), transparent);
}
.param-section.interets::before { background: linear-gradient(180deg, #10B981, transparent); }
.param-section.commissions::before { background: linear-gradient(180deg, #F5B800, transparent); }
.param-section.epargne::before { background: linear-gradient(180deg, #3B82F6, transparent); }
.param-section.devises::before { background: linear-gradient(180deg, #8B5CF6, transparent); }
.param-section.general::before { background: linear-gradient(180deg, #6B7280, transparent); }

.param-header {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 20px;
  padding-bottom: 14px;
  border-bottom: 1px solid var(--border);
}
.param-header .ico {
  width: 42px;
  height: 42px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.3rem;
  background: var(--gold-soft);
  color: var(--gold);
}
.param-section.interets .param-header .ico { background: rgba(16,185,129,.15); color: #6EE7B7; }
.param-section.epargne .param-header .ico { background: rgba(59,130,246,.15); color: #93B4FF; }
.param-section.devises .param-header .ico { background: rgba(139,92,246,.15); color: #A78BFA; }
.param-section.general .param-header .ico { background: rgba(107,114,128,.15); color: #9CA3AF; }

.param-header h3 {
  font-size: 1.05rem;
  margin: 0;
}
.param-header .sub {
  font-size: .82rem;
  color: var(--muted);
  margin-top: 2px;
}

.param-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 18px;
}

.param-field {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.param-field label {
  font-size: .82rem;
  color: var(--muted);
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 6px;
}
.param-field input,
.param-field select {
  padding: 12px 14px;
  background: var(--bg-1);
  border: 1px solid var(--border);
  border-radius: 10px;
  color: var(--text);
  font-size: .95rem;
  font-family: inherit;
  font-weight: 600;
  transition: all .2s;
}
.param-field input:focus,
.param-field select:focus {
  outline: none;
  border-color: var(--gold);
  box-shadow: 0 0 0 3px rgba(245,184,0,.15);
}

.param-field .hint {
  font-size: .72rem;
  color: var(--muted);
  font-style: italic;
  margin-top: 2px;
}

.param-field .unite {
  position: relative;
}
.param-field .unite input {
  padding-right: 60px;
}
.param-field .unite::after {
  content: attr(data-unite);
  position: absolute;
  right: 14px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--gold);
  font-weight: 700;
  font-size: .9rem;
  pointer-events: none;
}

/* Info impact */
.impact-box {
  margin-top: 22px;
  padding: 18px 22px;
  border-radius: 14px;
  background: linear-gradient(135deg, rgba(245,184,0,.08), rgba(245,184,0,.02));
  border: 1px solid rgba(245,184,0,.3);
}
.impact-box h4 {
  font-size: .92rem;
  color: var(--gold);
  margin-bottom: 12px;
  display: flex;
  align-items: center;
  gap: 8px;
}
.impact-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 14px;
}
.impact-item {
  padding: 12px 14px;
  border-radius: 10px;
  background: rgba(11,14,20,.5);
  border: 1px solid var(--border);
}
.impact-item .lbl {
  font-size: .72rem;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 1px;
  font-weight: 700;
}
.impact-item .val {
  font-size: 1.1rem;
  font-weight: 800;
  color: var(--gold);
  margin-top: 4px;
}

/* Simulation */
.simulation-box {
  margin-top: 18px;
  padding: 20px;
  border-radius: 14px;
  background: linear-gradient(135deg, rgba(139,92,246,.1), rgba(139,92,246,.02));
  border: 1px solid rgba(139,92,246,.3);
}
.simulation-box h4 {
  font-size: .92rem;
  color: #A78BFA;
  margin-bottom: 14px;
}
.simulation-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px;
}
.simulation-line {
  display: flex;
  justify-content: space-between;
  padding: 10px 0;
  border-bottom: 1px solid rgba(255,255,255,.05);
  font-size: .88rem;
}
.simulation-line .lbl { color: var(--muted); }
.simulation-line .val { font-weight: 700; color: #fff; }
.simulation-line.total {
  border-top: 2px solid #8B5CF6;
  margin-top: 8px;
  padding-top: 12px;
  font-size: 1rem;
}
.simulation-line.total .val { color: #A78BFA; font-size: 1.2rem; }

.save-bar {
  position: sticky;
  bottom: 0;
  padding: 20px;
  background: linear-gradient(180deg, transparent, rgba(11,14,20,.95) 30%);
  margin-top: 20px;
  z-index: 10;
}
</style>
</head>
<body class="dark-auth">

<?php require __DIR__ . '/../includes/header.php'; ?>

<div class="admin-layout">
  <aside class="admin-sidebar animate-left">
    <h3>📊 Navigation</h3>
    <a href="<?= $base ?>/admin/dashboard.php"><span class="ico">📊</span> Tableau de bord</a>
    <a href="<?= $base ?>/admin/clients.php"><span class="ico">👥</span> Clients</a>
    <a href="<?= $base ?>/admin/actionnaires.php"><span class="ico">🏛️</span> Actionnaires</a>
    <a href="<?= $base ?>/admin/placements.php"><span class="ico">💰</span> Placements</a>
    <a href="<?= $base ?>/admin/parametres.php" class="active"><span class="ico">⚙️</span> Paramètres</a>
    <a href="<?= $base ?>/admin/logs.php"><span class="ico">📜</span> Journal</a>
    <a href="<?= $base ?>/auth/logout.php"><span class="ico">🚪</span> Déconnexion</a>
  </aside>

  <main class="admin-main">

    <div class="welcome-head">
      <div>
        <h2>⚙️ Paramètres Système</h2>
        <p class="sub">Configuration des taux, commissions et règles</p>
      </div>
    </div>

    <?php if ($success): ?>
      <div class="alert success"><?= $success ?></div>
    <?php endif; ?>

    <?php if ($errors): ?>
      <div class="alert error">
        <ul><?php foreach ($errors as $e) echo "<li>" . htmlspecialchars($e) . "</li>"; ?></ul>
      </div>
    <?php endif; ?>

    <!-- IMPACT ACTUEL -->
    <section class="impact-box">
      <h4>📊 Impact actuel sur les nouveaux dépôts</h4>
      <div class="impact-grid">
        <div class="impact-item">
          <div class="lbl">Taux d'intérêt annuel</div>
          <div class="val"><?= number_format($taux_actuel, 2) ?>%</div>
        </div>
        <div class="impact-item">
          <div class="lbl">Blocage des intérêts</div>
          <div class="val"><?= $duree_blocage ?> mois</div>
        </div>
        <div class="impact-item">
          <div class="lbl">Commission de retrait</div>
          <div class="val"><?= number_format($commission, 2) ?>%</div>
        </div>
        <div class="impact-item">
          <div class="lbl">Placements actifs</div>
          <div class="val"><?= (int)$stats_placements['total'] ?></div>
        </div>
      </div>
    </section>

    <form method="POST" novalidate>
      <?= csrfField() ?>
      <input type="hidden" name="action" value="update">

      <!-- INTÉRÊTS -->
      <div class="param-section interets animate-in delay-1">
        <div class="param-header">
          <div class="ico">📈</div>
          <div>
            <h3>Taux d'intérêt</h3>
            <div class="sub">Configuration du taux appliqué aux nouveaux dépôts</div>
          </div>
        </div>

        <div class="param-grid">
          <?php foreach ($parametres_interets as $p): ?>
            <div class="param-field">
              <label>💰 <?= htmlspecialchars($p['description'] ?: $p['cle']) ?></label>

              <?php if ($p['type'] === 'pourcentage'): ?>
                <div class="unite" data-unite="%">
                  <input type="number"
                         name="parametres[<?= htmlspecialchars($p['cle']) ?>]"
                         value="<?= htmlspecialchars($p['valeur']) ?>"
                         step="0.01"
                         min="0"
                         max="50"
                         required>
                </div>
              <?php elseif ($p['type'] === 'nombre'): ?>
                <input type="number"
                       name="parametres[<?= htmlspecialchars($p['cle']) ?>]"
                       value="<?= htmlspecialchars($p['valeur']) ?>"
                       step="1"
                       min="1"
                       required>
              <?php else: ?>
                <input type="text"
                       name="parametres[<?= htmlspecialchars($p['cle']) ?>]"
                       value="<?= htmlspecialchars($p['valeur']) ?>"
                       required>
              <?php endif; ?>

              <div class="hint">Clé : <?= htmlspecialchars($p['cle']) ?></div>
            </div>
          <?php endforeach; ?>
        </div>

        <!-- Simulation dynamique -->
        <div class="simulation-box" id="simulationBox">
          <h4>🧮 Simulation : Dépôt de 100 000 CDF sur 1 an</h4>
          <div class="simulation-grid">
            <div>
              <div class="simulation-line">
                <span class="lbl">Capital déposé</span>
                <span class="val">100 000 CDF</span>
              </div>
              <div class="simulation-line">
                <span class="lbl">Taux annuel</span>
                <span class="val" id="simTaux"><?= number_format($taux_actuel, 2) ?>%</span>
              </div>
              <div class="simulation-line">
                <span class="lbl">Intérêts gagnés (1 an)</span>
                <span class="val" id="simInterets" style="color:#6EE7B7">+ 5 000 CDF</span>
              </div>
            </div>
            <div>
              <div class="simulation-line">
                <span class="lbl">Intérêts disponibles après</span>
                <span class="val" id="simBlocage"><?= $duree_blocage ?> mois</span>
              </div>
              <div class="simulation-line">
                <span class="lbl">Commission retrait (1%)</span>
                <span class="val" style="color:#FCA5A5" id="simCommission">− 1 050 CDF</span>
              </div>
              <div class="simulation-line total">
                <span class="lbl">💵 Net si tout retiré</span>
                <span class="val" id="simNet">103 950 CDF</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- COMMISSIONS -->
      <div class="param-section commissions animate-in delay-2">
        <div class="param-header">
          <div class="ico">💵</div>
          <div>
            <h3>Commissions</h3>
            <div class="sub">Frais prélevés sur les opérations</div>
          </div>
        </div>

        <div class="param-grid">
          <?php foreach ($parametres_commissions as $p): ?>
            <div class="param-field">
              <label>💰 <?= htmlspecialchars($p['description'] ?: $p['cle']) ?></label>
              <?php if ($p['type'] === 'pourcentage'): ?>
                <div class="unite" data-unite="%">
                  <input type="number"
                         name="parametres[<?= htmlspecialchars($p['cle']) ?>]"
                         value="<?= htmlspecialchars($p['valeur']) ?>"
                         step="0.01" min="0" max="10" required>
                </div>
              <?php else: ?>
                <input type="text"
                       name="parametres[<?= htmlspecialchars($p['cle']) ?>]"
                       value="<?= htmlspecialchars($p['valeur']) ?>" required>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- ÉPARGNE -->
      <div class="param-section epargne animate-in delay-3">
        <div class="param-header">
          <div class="ico">💼</div>
          <div>
            <h3>Épargne</h3>
            <div class="sub">Limites et règles des dépôts</div>
          </div>
        </div>

        <div class="param-grid">
          <?php foreach ($parametres_epargne as $p): ?>
            <div class="param-field">
              <label>💰 <?= htmlspecialchars($p['description'] ?: $p['cle']) ?></label>
              <input type="number"
                     name="parametres[<?= htmlspecialchars($p['cle']) ?>]"
                     value="<?= htmlspecialchars($p['valeur']) ?>"
                     step="100" min="0" required>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- DEVISES -->
      <div class="param-section devises animate-in delay-4">
        <div class="param-header">
          <div class="ico">💱</div>
          <div>
            <h3>Devises</h3>
            <div class="sub">Taux de change et devise principale</div>
          </div>
        </div>

        <div class="param-grid">
          <?php foreach ($parametres_devises as $p): ?>
            <div class="param-field">
              <label>💱 <?= htmlspecialchars($p['description'] ?: $p['cle']) ?></label>
              <?php if ($p['cle'] === 'pays_devise_principale'): ?>
                <select name="parametres[<?= htmlspecialchars($p['cle']) ?>]" required>
                  <option value="CDF" <?= $p['valeur'] === 'CDF' ? 'selected' : '' ?>>🇨🇩 CDF</option>
                  <option value="USD" <?= $p['valeur'] === 'USD' ? 'selected' : '' ?>>🇺🇸 USD</option>
                </select>
              <?php else: ?>
                <input type="number"
                       name="parametres[<?= htmlspecialchars($p['cle']) ?>]"
                       value="<?= htmlspecialchars($p['valeur']) ?>"
                       step="1" min="0" required>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- GÉNÉRAL -->
      <div class="param-section general animate-in delay-5">
        <div class="param-header">
          <div class="ico">⚙️</div>
          <div>
            <h3>Informations générales</h3>
            <div class="sub">Identité de la banque</div>
          </div>
        </div>

        <div class="param-grid">
          <?php foreach ($parametres_general as $p): ?>
            <div class="param-field">
              <label>📝 <?= htmlspecialchars($p['description'] ?: $p['cle']) ?></label>
              <input type="text"
                     name="parametres[<?= htmlspecialchars($p['cle']) ?>]"
                     value="<?= htmlspecialchars($p['valeur']) ?>"
                     maxlength="255" required>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- INFO PLACEMENTS EXISTANTS -->
      <?php if ((int)$stats_placements['total'] > 0): ?>
        <div class="alert info" style="margin-top:22px">
          ⚠️ <strong>Information :</strong> Il y a actuellement <strong><?= (int)$stats_placements['total'] ?></strong> placement(s) actif(s) 
          avec un taux moyen de <strong><?= number_format((float)$stats_placements['taux_moyen'], 2) ?>%</strong>.
          <br><small>Les modifications de taux ne s'appliquent <strong>qu'aux nouveaux dépôts</strong>. Les placements existants conservent leur taux initial.</small>
        </div>
      <?php endif; ?>

      <!-- BOUTON SAUVEGARDER -->
      <div class="save-bar">
        <button class="btn-gold full" type="submit" style="padding:18px;font-size:1rem">
          💾 Enregistrer tous les paramètres
        </button>
      </div>
    </form>

  </main>
</div>

<footer class="atf-footer">© <?= date('Y') ?> AFRICAN TRUST FUND</footer>

<script>
/* ============================================================
   SIMULATION DYNAMIQUE
   ============================================================ */
const tauxInput = document.querySelector('input[name="parametres[taux_interet_defaut]"]');
const dureeInput = document.querySelector('input[name="parametres[duree_blocage_interets]"]');
const commissionInput = document.querySelector('input[name="parametres[commission_retrait]"]');

const simTaux = document.getElementById('simTaux');
const simInterets = document.getElementById('simInterets');
const simBlocage = document.getElementById('simBlocage');
const simCommission = document.getElementById('simCommission');
const simNet = document.getElementById('simNet');

function updateSimulation() {
  const taux = parseFloat(tauxInput?.value) || 0;
  const duree = parseInt(dureeInput?.value) || 6;
  const commission = parseFloat(commissionInput?.value) || 1;

  const capital = 100000;
  const interets = capital * (taux / 100);
  const total = capital + interets;
  const comm = total * (commission / 100);
  const net = total - comm;

  const fmt = (v) => v.toLocaleString('fr-FR', { minimumFractionDigits: 0, maximumFractionDigits: 0 });

  simTaux.textContent = taux.toFixed(2) + '%';
  simInterets.textContent = '+ ' + fmt(interets) + ' CDF';
  simBlocage.textContent = duree + ' mois';
  simCommission.textContent = '− ' + fmt(comm) + ' CDF';
  simNet.textContent = fmt(net) + ' CDF';
}

[tauxInput, dureeInput, commissionInput].forEach(el => {
  if (el) {
    el.addEventListener('input', updateSimulation);
    el.addEventListener('change', updateSimulation);
  }
});

updateSimulation();

/* ============================================================
   FEEDBACK BOUTON
   ============================================================ */
document.querySelector('form').addEventListener('submit', function() {
  const btn = this.querySelector('button[type="submit"]');
  if (btn) {
    btn.disabled = true;
    btn.textContent = '⏳ Enregistrement en cours...';
  }
});
</script>

<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>