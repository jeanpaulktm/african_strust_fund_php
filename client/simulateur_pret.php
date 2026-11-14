<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/prets.php';
requireRole('client');

$pdo = Database::getConnection();
$id = (int)$_SESSION['client_id'];

/* Produits disponibles */
$stmt = $pdo->prepare("
    SELECT * FROM Produits_Pret 
    WHERE actif = 1 AND type_client IN ('physique','tous')
    ORDER BY devise, montant_min ASC
");
$stmt->execute();
$produits = $stmt->fetchAll();

/* Score client */
$score = calculerScoreCredit($pdo, $id);

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Simulateur de prêt — ATF</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<style>
:root {
  --nav-height: 76px;
  --nav-safe-area: env(safe-area-inset-bottom, 0px);
}
body.app-mode { padding-bottom: calc(var(--nav-height) + var(--nav-safe-area) + 20px); }

.hero-sim {
  padding: 28px;
  border-radius: 20px;
  background: linear-gradient(135deg, rgba(139,92,246,.15), rgba(30,64,175,.05)),
              radial-gradient(circle at 90% 20%, rgba(139,92,246,.18), transparent 60%);
  border: 1px solid rgba(139,92,246,.35);
  margin-bottom: 24px;
  position: relative;
  overflow: hidden;
}
.hero-sim::before {
  content: "";
  position: absolute;
  top: -40%; right: -5%;
  width: 260px; height: 260px;
  border-radius: 50%;
  background: radial-gradient(circle, rgba(139,92,246,.25), transparent 70%);
  animation: float 7s ease-in-out infinite;
}
.hero-sim h1 {
  color: #C4B5FD;
  font-size: 1.5rem;
  margin-bottom: 8px;
  position: relative;
  z-index: 1;
}
.hero-sim p { color: var(--muted); font-size: .92rem; position: relative; z-index: 1; }

.sim-grid {
  display: grid;
  grid-template-columns: 1.2fr 1fr;
  gap: 24px;
  margin-bottom: 24px;
}
@media (max-width: 900px) {
  .sim-grid { grid-template-columns: 1fr; }
}

.sim-form {
  padding: 24px 26px;
  border-radius: 20px;
  background: linear-gradient(145deg, var(--card), var(--card-2));
  border: 1px solid var(--border);
}
.sim-form h3 {
  font-size: 1.05rem;
  color: var(--gold);
  margin-bottom: 18px;
  display: flex;
  align-items: center;
  gap: 10px;
}

/* Produit selector */
.produit-picker {
  display: grid;
  grid-template-columns: 1fr;
  gap: 10px;
  margin-bottom: 18px;
}
.produit-pick {
  padding: 14px 16px;
  border-radius: 12px;
  background: rgba(11,14,20,.4);
  border: 2px solid var(--border);
  cursor: pointer;
  transition: all .25s;
}
.produit-pick:hover { border-color: rgba(245,184,0,.5); }
.produit-pick.selected {
  border-color: var(--gold);
  background: rgba(245,184,0,.08);
}
.produit-pick .name {
  font-weight: 800;
  font-size: .92rem;
  color: var(--text);
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}
.produit-pick .name .devise {
  font-size: .68rem;
  padding: 2px 8px;
  border-radius: 999px;
  font-weight: 800;
  background: rgba(245,184,0,.15);
  color: var(--gold);
}
.produit-pick .name .devise.usd {
  background: rgba(59,130,246,.15);
  color: #93B4FF;
}
.produit-pick .info {
  font-size: .78rem;
  color: var(--muted);
  margin-top: 6px;
}
.produit-pick .rate {
  color: #6EE7B7;
  font-weight: 800;
  font-family: monospace;
}

/* Form fields */
.form-group { margin-bottom: 16px; }
.form-group label {
  display: block;
  font-size: .82rem;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: .5px;
  font-weight: 700;
  margin-bottom: 8px;
}
.form-group input,
.form-group select {
  width: 100%;
  padding: 14px 16px;
  background: var(--bg-1);
  border: 1px solid var(--border);
  border-radius: 12px;
  color: var(--text);
  font-size: 1rem;
  font-weight: 700;
  font-family: inherit;
}
.form-group input:focus,
.form-group select:focus {
  outline: none;
  border-color: var(--gold);
  box-shadow: 0 0 0 3px rgba(245,184,0,.15);
}

/* Range slider */
.range-wrap {
  position: relative;
}
.range-wrap input[type="range"] {
  width: 100%;
  height: 8px;
  -webkit-appearance: none;
  background: linear-gradient(90deg, #3B82F6 0%, #8B5CF6 50%, #F5B800 100%);
  border-radius: 4px;
  padding: 0;
}
.range-wrap input[type="range"]::-webkit-slider-thumb {
  -webkit-appearance: none;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  background: #fff;
  border: 3px solid #F5B800;
  cursor: pointer;
  box-shadow: 0 2px 8px rgba(0,0,0,.3);
}
.range-wrap input[type="range"]::-moz-range-thumb {
  width: 24px;
  height: 24px;
  border-radius: 50%;
  background: #fff;
  border: 3px solid #F5B800;
  cursor: pointer;
}

/* Résultat */
.sim-result {
  padding: 24px 26px;
  border-radius: 20px;
  background: linear-gradient(145deg, rgba(16,185,129,.12), rgba(16,185,129,.02)),
              radial-gradient(circle at 90% 20%, rgba(16,185,129,.15), transparent 60%);
  border: 1px solid rgba(16,185,129,.35);
  position: sticky;
  top: 20px;
  height: fit-content;
}
.sim-result h3 {
  color: #6EE7B7;
  font-size: 1.05rem;
  margin-bottom: 18px;
  display: flex;
  align-items: center;
  gap: 10px;
}

.result-main {
  text-align: center;
  padding: 20px 0;
  border-bottom: 1px dashed rgba(16,185,129,.3);
  margin-bottom: 18px;
}
.result-main .lbl {
  font-size: .72rem;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 1.5px;
  font-weight: 700;
  margin-bottom: 8px;
}
.result-main .val {
  font-size: 2.4rem;
  font-weight: 900;
  color: #6EE7B7;
  letter-spacing: -1px;
  line-height: 1;
  word-break: break-word;
}
.result-main .sub {
  font-size: .8rem;
  color: var(--muted);
  margin-top: 8px;
}

.result-line {
  display: flex;
  justify-content: space-between;
  padding: 12px 0;
  border-bottom: 1px solid rgba(255,255,255,.05);
  font-size: .88rem;
}
.result-line:last-child { border-bottom: none; }
.result-line .lbl { color: var(--muted); }
.result-line .val { font-weight: 800; color: var(--text); }
.result-line .val.gold { color: var(--gold); }
.result-line .val.green { color: #6EE7B7; }
.result-line .val.blue { color: #93B4FF; }
.result-line .val.red { color: #FCA5A5; }

.result-line.total {
  padding-top: 16px;
  margin-top: 8px;
  border-top: 2px solid rgba(16,185,129,.3);
  font-size: 1rem;
}
.result-line.total .val { font-size: 1.3rem; }

/* Warning score */
.score-warning {
  margin-top: 18px;
  padding: 14px;
  border-radius: 12px;
  background: rgba(245,184,0,.08);
  border: 1px solid rgba(245,184,0,.3);
  color: #FCD34D;
  font-size: .82rem;
  line-height: 1.5;
}
.score-warning.ok {
  background: rgba(16,185,129,.08);
  border-color: rgba(16,185,129,.3);
  color: #6EE7B7;
}
.score-warning.danger {
  background: rgba(239,68,68,.08);
  border-color: rgba(239,68,68,.4);
  color: #FCA5A5;
}

/* Actions */
.sim-actions {
  display: flex;
  gap: 12px;
  margin-top: 18px;
  flex-wrap: wrap;
}
.btn-pret-primary {
  flex: 1;
  padding: 14px 22px;
  border-radius: 999px;
  background: linear-gradient(135deg, #3B82F6, #2563EB);
  color: #fff;
  font-weight: 800;
  text-decoration: none;
  text-align: center;
  box-shadow: 0 6px 20px rgba(59,130,246,.4);
  transition: all .25s;
}
.btn-pret-primary:hover {
  transform: translateY(-2px);
  color: #fff;
  box-shadow: 0 10px 28px rgba(59,130,246,.55);
}
.btn-pret-ghost {
  flex: 1;
  padding: 14px 22px;
  border-radius: 999px;
  background: rgba(255,255,255,.08);
  color: var(--text);
  font-weight: 800;
  text-decoration: none;
  text-align: center;
  border: 1px solid var(--border);
  transition: all .25s;
}
.btn-pret-ghost:hover {
  background: rgba(255,255,255,.14);
  color: var(--text);
}

@media (max-width: 600px) {
  .result-main .val { font-size: 1.8rem; }
  .sim-actions { flex-direction: column; }
}
</style>
</head>
<body class="dark-auth app-mode">

<?php require __DIR__ . '/../includes/header.php'; ?>

<main class="dash">

  <div class="welcome-head">
    <div>
      <h2>🧮 Simulateur de prêt</h2>
      <p class="sub">Calculez vos mensualités avant de demander</p>
    </div>
    <a class="btn-ghost" href="<?= $base ?>/client/mes_prets.php">← Mes prêts</a>
  </div>

  <div class="hero-sim">
    <h1>💡 Simulez votre prêt en quelques secondes</h1>
    <p>Ajustez le montant et la durée pour visualiser vos mensualités et le coût total du crédit.</p>
  </div>

  <div class="sim-grid">

    <!-- FORMULAIRE -->
    <section class="sim-form">
      <h3>📋 Paramètres de simulation</h3>

      <div class="form-group">
        <label>🎯 Produit de prêt</label>
        <div class="produit-picker" id="produitPicker">
          <?php if (empty($produits)): ?>
            <div class="alert error">Aucun produit de prêt disponible pour le moment.</div>
          <?php else: foreach ($produits as $i => $pr): ?>
            <div class="produit-pick <?= $i === 0 ? 'selected' : '' ?>"
                 data-id="<?= (int)$pr['id_produit'] ?>"
                 data-nom="<?= htmlspecialchars($pr['nom']) ?>"
                 data-devise="<?= htmlspecialchars($pr['devise']) ?>"
                 data-taux="<?= (float)$pr['taux_annuel'] ?>"
                 data-mmin="<?= (float)$pr['montant_min'] ?>"
                 data-mmax="<?= (float)$pr['montant_max'] ?>"
                 data-dmin="<?= (int)$pr['duree_min_mois'] ?>"
                 data-dmax="<?= (int)$pr['duree_max_mois'] ?>"
                 data-score="<?= (int)$pr['score_min_requis'] ?>">
              <div class="name">
                <?= htmlspecialchars($pr['nom']) ?>
                <span class="devise <?= $pr['devise'] === 'USD' ? 'usd' : '' ?>">
                  <?= htmlspecialchars($pr['devise']) ?>
                </span>
              </div>
              <div class="info">
                Taux : <span class="rate"><?= number_format((float)$pr['taux_annuel'] * 100, 2) ?>% / an</span> · 
                Durée : <?= (int)$pr['duree_min_mois'] ?>-<?= (int)$pr['duree_max_mois'] ?> mois
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>

      <div class="form-group">
        <label>💵 Montant à emprunter</label>
        <input type="text" id="montantInput" inputmode="decimal" placeholder="0">
        <div class="range-wrap" style="margin-top:10px">
          <input type="range" id="montantRange" min="0" max="100" step="1" value="50">
        </div>
      </div>

      <div class="form-group">
        <label>📅 Durée (mois)</label>
        <input type="number" id="dureeInput" min="1" max="60" value="12">
        <div class="range-wrap" style="margin-top:10px">
          <input type="range" id="dureeRange" min="1" max="60" step="1" value="12">
        </div>
      </div>

    </section>

    <!-- RÉSULTAT -->
    <aside class="sim-result">
      <h3>📊 Résultat de la simulation</h3>

      <div class="result-main">
        <div class="lbl">Mensualité estimée</div>
        <div class="val" id="resMensualite">0</div>
        <div class="sub" id="resDevise">—</div>
      </div>

      <div class="result-line">
        <span class="lbl">💰 Capital emprunté</span>
        <span class="val gold" id="resCapital">0</span>
      </div>
      <div class="result-line">
        <span class="lbl">📈 Taux annuel appliqué</span>
        <span class="val green" id="resTaux">0%</span>
      </div>
      <div class="result-line">
        <span class="lbl">🎯 Total intérêts</span>
        <span class="val blue" id="resInterets">0</span>
      </div>
      <div class="result-line total">
        <span class="lbl">💵 Total à rembourser</span>
        <span class="val gold" id="resTotal">0</span>
      </div>

      <div class="score-warning" id="scoreWarning" style="display:none">
        <!-- Rempli par JS -->
      </div>

      <div class="sim-actions">
        <a href="<?= $base ?>/client/demande_pret.php" class="btn-pret-primary">
          📤 Faire la demande
        </a>
        <a href="<?= $base ?>/client/mes_prets.php" class="btn-pret-ghost">
          ← Retour
        </a>
      </div>
    </aside>

  </div>

</main>

<footer class="atf-footer" style="padding:20px;text-align:center;color:var(--muted)">
  © <?= date('Y') ?> AFRICAN TRUST FUND
</footer>

<script>
const CLIENT_SCORE = <?= (int)$score['score'] ?>;
let currentProduit = null;

/* ============================================================
   SÉLECTION PRODUIT
   ============================================================ */
document.querySelectorAll('.produit-pick').forEach(pick => {
    pick.addEventListener('click', () => {
        document.querySelectorAll('.produit-pick').forEach(p => p.classList.remove('selected'));
        pick.classList.add('selected');
        chargerProduit(pick);
    });
});

function chargerProduit(pick) {
    currentProduit = {
        id: pick.dataset.id,
        nom: pick.dataset.nom,
        devise: pick.dataset.devise,
        taux: parseFloat(pick.dataset.taux),
        mmin: parseFloat(pick.dataset.mmin),
        mmax: parseFloat(pick.dataset.mmax),
        dmin: parseInt(pick.dataset.dmin),
        dmax: parseInt(pick.dataset.dmax),
        scoreMin: parseInt(pick.dataset.score),
    };

    // Configurer les ranges
    const mRange = document.getElementById('montantRange');
    mRange.min = currentProduit.mmin;
    mRange.max = currentProduit.mmax;
    mRange.step = currentProduit.devise === 'USD' ? 10 : 1000;
    
    const defaultMontant = currentProduit.devise === 'USD'
        ? Math.round((currentProduit.mmin + currentProduit.mmax) / 2 / 100) * 100
        : Math.round((currentProduit.mmin + currentProduit.mmax) / 2 / 10000) * 10000;
    
    mRange.value = defaultMontant;
    document.getElementById('montantInput').value = defaultMontant;

    // Durée
    const dRange = document.getElementById('dureeRange');
    dRange.min = currentProduit.dmin;
    dRange.max = currentProduit.dmax;
    dRange.value = Math.min(12, currentProduit.dmax);
    document.getElementById('dureeInput').value = dRange.value;
    document.getElementById('dureeInput').min = currentProduit.dmin;
    document.getElementById('dureeInput').max = currentProduit.dmax;

    calculer();
}

/* ============================================================
   INPUTS
   ============================================================ */
document.getElementById('montantRange').addEventListener('input', e => {
    document.getElementById('montantInput').value = e.target.value;
    calculer();
});

document.getElementById('montantInput').addEventListener('input', e => {
    let v = parseFloat(e.target.value.replace(/[^\d.,]/g, '').replace(',', '.')) || 0;
    if (currentProduit) {
        v = Math.max(currentProduit.mmin, Math.min(currentProduit.mmax, v));
        document.getElementById('montantRange').value = v;
    }
    calculer();
});

document.getElementById('dureeRange').addEventListener('input', e => {
    document.getElementById('dureeInput').value = e.target.value;
    calculer();
});

document.getElementById('dureeInput').addEventListener('input', e => {
    let v = parseInt(e.target.value) || 12;
    if (currentProduit) {
        v = Math.max(currentProduit.dmin, Math.min(currentProduit.dmax, v));
        document.getElementById('dureeRange').value = v;
    }
    calculer();
});

/* ============================================================
   CALCUL
   ============================================================ */
function format(v, devise) {
    if (devise === 'USD') return '$ ' + v.toLocaleString('fr-FR', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    return Math.round(v).toLocaleString('fr-FR') + ' CDF';
}

function calculer() {
    if (!currentProduit) return;

    const montant = parseFloat(document.getElementById('montantInput').value.replace(',', '.')) || 0;
    const duree = parseInt(document.getElementById('dureeInput').value) || 1;
    const taux = currentProduit.taux;

    if (montant <= 0) return;

    // Formule dégressive
    const tm = taux / 12;
    let mensualite;
    if (tm <= 0) {
        mensualite = montant / duree;
    } else {
        const f = Math.pow(1 + tm, -duree);
        mensualite = montant * tm / (1 - f);
    }

    const total = mensualite * duree;
    const interets = total - montant;

    document.getElementById('resMensualite').textContent = format(mensualite, currentProduit.devise);
    document.getElementById('resDevise').textContent = currentProduit.nom + ' · ' + currentProduit.devise;
    document.getElementById('resCapital').textContent = format(montant, currentProduit.devise);
    document.getElementById('resTaux').textContent = (taux * 100).toFixed(2) + '% / an';
    document.getElementById('resInterets').textContent = '+' + format(interets, currentProduit.devise);
    document.getElementById('resTotal').textContent = format(total, currentProduit.devise);

    // Warning score
    const warn = document.getElementById('scoreWarning');
    warn.style.display = 'block';
    warn.className = 'score-warning';
    
    if (CLIENT_SCORE >= currentProduit.scoreMin) {
        warn.classList.add('ok');
        warn.innerHTML = `✅ <strong>Vous êtes éligible</strong> pour ce produit (score ${CLIENT_SCORE}/100 ≥ minimum ${currentProduit.scoreMin}/100).`;
    } else if (CLIENT_SCORE >= currentProduit.scoreMin - 15) {
        warn.innerHTML = `⚠️ <strong>Score limite</strong> (${CLIENT_SCORE}/100 vs minimum ${currentProduit.scoreMin}/100). Votre demande pourrait nécessiter une garantie supplémentaire.`;
    } else {
        warn.classList.add('danger');
        warn.innerHTML = `❌ <strong>Score insuffisant</strong> (${CLIENT_SCORE}/100 vs minimum ${currentProduit.scoreMin}/100). Améliorez votre épargne et historique pour être éligible.`;
    }
}

/* Init */
const firstPick = document.querySelector('.produit-pick.selected') || document.querySelector('.produit-pick');
if (firstPick) chargerProduit(firstPick);
</script>
<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>