<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
requireRole('client');

$pdo = Database::getConnection();
$id  = (int)$_SESSION['client_id'];

$errors = [];

/* ============================================================
   TRAITEMENT DU DÉPÔT (POST)
   → Chaque devise a son propre solde, pas de conversion
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) {
            throw new RuntimeException("Jeton invalide.");
        }

        $montant = (float)str_replace([' ', ','], ['', '.'], $_POST['montant'] ?? '0');
        $devise  = in_array($_POST['devise'] ?? '', ['CDF','USD'], true) ? $_POST['devise'] : 'CDF';

        if ($montant <= 0)         throw new RuntimeException("Le montant doit être positif.");
        if ($montant > 10_000_000) throw new RuntimeException("Montant maximal : 10 000 000.");

        $pdo->beginTransaction();

        /* ---------- Charger les 2 soldes avec verrou ---------- */
        $stmt = $pdo->prepare("
            SELECT solde_cdf, solde_usd, numero_compte 
            FROM Client WHERE id_client = ? FOR UPDATE
        ");
        $stmt->execute([$id]);
        $c = $stmt->fetch();

        if (!$c) throw new RuntimeException("Client introuvable.");

        $solde_cdf_avant = (float)$c['solde_cdf'];
        $solde_usd_avant = (float)$c['solde_usd'];

        /* ---------- Créditer LA devise choisie SANS conversion ---------- */
        if ($devise === 'USD') {
            $solde_usd_apres = $solde_usd_avant + $montant;
            $solde_cdf_apres = $solde_cdf_avant;

            $pdo->prepare("UPDATE Client SET solde_usd = ? WHERE id_client = ?")
                ->execute([$solde_usd_apres, $id]);

            $solde_avant_display = $solde_usd_avant;
            $solde_apres_display = $solde_usd_apres;
        } else {
            $solde_cdf_apres = $solde_cdf_avant + $montant;
            $solde_usd_apres = $solde_usd_avant;

            $pdo->prepare("UPDATE Client SET solde_cdf = ? WHERE id_client = ?")
                ->execute([$solde_cdf_apres, $id]);

            $solde_avant_display = $solde_cdf_avant;
            $solde_apres_display = $solde_cdf_apres;
        }

        /* ---------- Référence unique ---------- */
        $reference = genererReference();

        /* ---------- Enregistrement transaction ---------- */
        $pdo->prepare("
            INSERT INTO Transactions 
                (id_client, type_transaction, montant, devise, mode, effectue_par,
                 solde_avant, solde_apres, reference, description, ip_address)
            VALUES (?, 'Depot', ?, ?, 'En ligne', 'Client', ?, ?, ?, ?, ?)
        ")->execute([
            $id, $montant, $devise,
            $solde_avant_display, $solde_apres_display,
            $reference,
            "Dépôt en ligne de " . number_format($montant, 2, ',', ' ') . " $devise",
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);

        $pdo->commit();

        logAction($pdo, 'client', $id, 'DEPOT',
            "Montant: $montant $devise | Ref: $reference");

        /* ============================================================
           FLASH + PRG (empêche double soumission)
           ============================================================ */
        $_SESSION['flash_success'] = "✅ Dépôt effectué avec succès !<br>
            <strong>Référence :</strong> $reference<br>
            <strong>Montant déposé :</strong> " . number_format($montant, 2, ',', ' ') . " $devise<br>
            <strong>Nouveau solde $devise :</strong> " . number_format($solde_apres_display, 2, ',', ' ') . " $devise";

        header('Location: ' . url('client/depot.php'));
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = $e->getMessage();
    }
}

/* ============================================================
   FLASH (une seule fois)
   ============================================================ */
$success = '';
if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

/* ============================================================
   INFO CLIENT (soldes séparés)
   ============================================================ */
$stmt = $pdo->prepare("
    SELECT numero_compte, solde_cdf, solde_usd 
    FROM Client WHERE id_client = ?
");
$stmt->execute([$id]);
$client = $stmt->fetch();

$solde_cdf = (float)($client['solde_cdf'] ?? 0);
$solde_usd = (float)($client['solde_usd'] ?? 0);

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0,user-scalable=no">
<meta name="theme-color" content="#0B0E14">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title>Dépôt — AFRICAN TRUST FUND</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<style>
/* ============================================================
   VARIABLES NAV MOBILE
   ============================================================ */
:root {
  --nav-height: 76px;
  --nav-safe-area: env(safe-area-inset-bottom, 0px);
}

body.app-mode {
  padding-bottom: calc(var(--nav-height) + var(--nav-safe-area) + 20px);
}

@media (max-width: 900px) {
  .atf-header .atf-nav { display: none; }
}

/* ============================================================
   BANDEAU DOUBLE SOLDE
   ============================================================ */
.duo-solde {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
  margin-bottom: 18px;
}
.solde-mini {
  padding: 14px 16px;
  border-radius: 12px;
  border: 1px solid var(--border);
  position: relative;
  overflow: hidden;
}
.solde-mini::before {
  content: "";
  position: absolute;
  top: 0; left: 0;
  width: 4px; height: 100%;
}
.solde-mini.usd { background: rgba(59,130,246,.08); border-color: rgba(59,130,246,.3); }
.solde-mini.usd::before { background: #3B82F6; }
.solde-mini.cdf { background: rgba(245,184,0,.08); border-color: rgba(245,184,0,.3); }
.solde-mini.cdf::before { background: #F5B800; }

.solde-mini .lbl {
  font-size: .7rem;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 1px;
  font-weight: 700;
}
.solde-mini .val {
  font-size: 1.3rem;
  font-weight: 800;
  margin-top: 4px;
}
.solde-mini.usd .val { color: #93B4FF; }
.solde-mini.cdf .val { color: var(--gold); }

@media (max-width: 540px) {
  .duo-solde { grid-template-columns: 1fr; }
}

/* ============================================================
   BARRE DE NAVIGATION MOBILE
   ============================================================ */
.mobile-nav {
  display: none;
  position: fixed;
  bottom: 0;
  left: 0;
  right: 0;
  height: calc(var(--nav-height) + var(--nav-safe-area));
  padding-bottom: var(--nav-safe-area);
  background: rgba(11,14,20,.95);
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
  border-top: 1px solid rgba(245,184,0,.15);
  z-index: 9000;
  box-shadow: 0 -8px 30px rgba(0,0,0,.5);
}
.mobile-nav-inner {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr 1fr 1fr;
  height: var(--nav-height);
  align-items: center;
  padding: 0 8px;
  max-width: 600px;
  margin: 0 auto;
}
.nav-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
  padding: 10px 6px;
  text-decoration: none;
  color: var(--muted);
  font-size: .65rem;
  font-weight: 700;
  letter-spacing: .3px;
  transition: all .25s cubic-bezier(.4,0,.2,1);
  border-radius: 14px;
  position: relative;
  cursor: pointer;
  background: none;
  border: none;
  font-family: inherit;
  text-transform: uppercase;
}
.nav-item .nav-icon {
  font-size: 1.5rem;
  line-height: 1;
  transition: transform .3s cubic-bezier(.34,1.56,.64,1);
  position: relative;
}
.nav-item .nav-label { font-size: .62rem; line-height: 1; font-weight: 700; }
.nav-item.active { color: var(--gold); }
.nav-item.active .nav-icon {
  transform: scale(1.15) translateY(-2px);
  filter: drop-shadow(0 0 12px rgba(245,184,0,.6));
}
.nav-item.active::before {
  content: "";
  position: absolute;
  top: 0;
  left: 50%;
  transform: translateX(-50%);
  width: 32px;
  height: 3px;
  background: linear-gradient(90deg, #F5B800, #D4AF37);
  border-radius: 0 0 3px 3px;
  box-shadow: 0 0 12px rgba(245,184,0,.8);
}
.nav-item:active .nav-icon { transform: scale(.9); }

/* FAB central */
.nav-fab-wrapper {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  height: 100%;
}
.nav-fab {
  position: absolute;
  top: -22px;
  width: 60px;
  height: 60px;
  border-radius: 50%;
  background: linear-gradient(135deg, #F5B800, #D4AF37);
  color: #0B0E14;
  border: 4px solid #0B0E14;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.8rem;
  font-weight: 900;
  box-shadow:
    0 10px 30px rgba(245,184,0,.5),
    0 0 0 6px rgba(245,184,0,.15);
  transition: all .3s cubic-bezier(.34,1.56,.64,1);
  z-index: 2;
}
.nav-fab:active { transform: scale(.9); }
.nav-fab.active { transform: rotate(45deg); }

/* Menu popup du FAB */
.fab-menu {
  display: none;
  position: fixed;
  bottom: calc(var(--nav-height) + var(--nav-safe-area) + 20px);
  left: 50%;
  transform: translateX(-50%) translateY(20px);
  background: linear-gradient(180deg, #1A2130, #121824);
  border-radius: 20px;
  padding: 16px;
  box-shadow: 0 20px 60px rgba(0,0,0,.7);
  border: 1px solid rgba(245,184,0,.25);
  z-index: 8999;
  opacity: 0;
  transition: all .3s cubic-bezier(.4,0,.2,1);
  min-width: 240px;
}
.fab-menu.open {
  display: block;
  opacity: 1;
  transform: translateX(-50%) translateY(0);
}
.fab-menu-item {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 14px 18px;
  border-radius: 12px;
  color: var(--text);
  text-decoration: none;
  font-size: .9rem;
  font-weight: 700;
  transition: all .2s;
  border: 1px solid transparent;
  background: transparent;
  width: 100%;
  cursor: pointer;
  font-family: inherit;
  text-align: left;
}
.fab-menu-item:hover,
.fab-menu-item:active {
  background: rgba(245,184,0,.1);
  border-color: rgba(245,184,0,.3);
  color: var(--gold);
  transform: translateX(4px);
}
.fab-menu-item .ico {
  width: 40px;
  height: 40px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.3rem;
  flex-shrink: 0;
  background: var(--gold-soft);
  color: var(--gold);
}
.fab-menu-item.depot .ico { background: rgba(16,185,129,.15); color: var(--green); }
.fab-menu-item.retrait .ico { background: rgba(239,68,68,.15); color: #FCA5A5; }
.fab-menu-item.virement .ico { background: rgba(59,130,246,.15); color: #93B4FF; }
.fab-menu-item.epargne .ico { background: rgba(139,92,246,.15); color: #A78BFA; }

/* Élément actif dans le menu FAB */
.fab-menu-item.active {
  background: rgba(245,184,0,.12);
  border-color: rgba(245,184,0,.4);
  color: var(--gold);
}

.fab-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.5);
  backdrop-filter: blur(4px);
  z-index: 8998;
  opacity: 0;
  transition: opacity .3s;
}
.fab-overlay.open { display: block; opacity: 1; }

/* ============================================================
   MOBILE
   ============================================================ */
@media (max-width: 900px) {
  .mobile-nav { display: block; }
  .op-wrapper { padding: 16px 14px !important; }
}

@media (max-width: 480px) {
  .nav-item .nav-icon { font-size: 1.3rem; }
  .nav-item .nav-label { font-size: .58rem; }
  .nav-fab { width: 54px; height: 54px; font-size: 1.6rem; }
}
</style>
</head>
<body class="dark-auth app-mode">

<?php require __DIR__ . '/../includes/header.php'; ?>

<div class="op-wrapper">
  <h1>💰 Faire un dépôt</h1>
  <p class="op-sub">Alimentez votre compte en toute sécurité</p>

  <?php if ($success): ?>
    <div class="alert success"><?= $success ?></div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="alert error">
      <ul><?php foreach ($errors as $e) echo "<li>" . htmlspecialchars($e) . "</li>"; ?></ul>
    </div>
  <?php endif; ?>

  <!-- ============ SOLDES SÉPARÉS ============ -->
  <div class="info-banner" style="margin-bottom:12px">
    Compte : <code><?= htmlspecialchars($client['numero_compte']) ?></code>
  </div>

  <div class="duo-solde">
    <div class="solde-mini usd">
      <div class="lbl">🇺🇸 Solde en USD</div>
      <div class="val">$ <?= number_format($solde_usd, 2, '.', ',') ?></div>
    </div>
    <div class="solde-mini cdf">
      <div class="lbl">🇨🇩 Solde en CDF</div>
      <div class="val"><?= number_format($solde_cdf, 2, ',', ' ') ?> CDF</div>
    </div>
  </div>

  <!-- ============ FORMULAIRE ============ -->
  <form method="POST" novalidate autocomplete="off">
    <?= csrfField() ?>

    <label class="field">
      <span>Devise du dépôt *</span>
      <select name="devise" id="deviseSelect" required>
        <option value="CDF" selected>🇨🇩 Franc Congolais (CDF)</option>
        <option value="USD">🇺🇸 Dollar Américain (USD)</option>
      </select>
    </label>

    <label class="field">
      <span>Montant à déposer *</span>
      <div class="amount-input">
        <span class="cur" id="curSymbol">Montant</span>
        <input type="text" name="montant" id="montant" placeholder="0.00" required autofocus inputmode="decimal">
      </div>
    </label>

    <!-- Info pédagogique -->
    <div class="info-banner" style="background:rgba(16,185,129,.08);border-color:rgba(16,185,129,.25);color:#6EE7B7">
      💡 <strong>Le montant reste dans sa devise d'origine.</strong><br>
      <small>Un dépôt de 10 000 CDF reste en CDF. Un dépôt de $50 reste en USD.</small>
    </div>

    <div class="quick-amounts">
      <button type="button" onclick="setAmount(1000)">1 000</button>
      <button type="button" onclick="setAmount(5000)">5 000</button>
      <button type="button" onclick="setAmount(10000)">10 000</button>
      <button type="button" onclick="setAmount(50000)">50 000</button>
      <button type="button" onclick="setAmount(100000)">100 000</button>
    </div>

    <button class="btn-gold full" type="submit">Confirmer le dépôt</button>
    <p class="center muted" style="margin-top:16px">
      <a href="<?= $base ?>/client/dashboard.php">← Retour au tableau de bord</a>
    </p>
  </form>
</div>

<!-- ============================================================
     BARRE DE NAVIGATION MOBILE
     ============================================================ -->
<nav class="mobile-nav" id="mobileNav">
  <div class="mobile-nav-inner">
    <a href="<?= $base ?>/client/dashboard.php" class="nav-item">
      <span class="nav-icon">🏠</span>
      <span class="nav-label">Accueil</span>
    </a>
    <a href="<?= $base ?>/client/epargne.php" class="nav-item">
      <span class="nav-icon">💰</span>
      <span class="nav-label">Épargne</span>
    </a>
    <div class="nav-fab-wrapper">
      <button class="nav-fab active" id="navFab" type="button" aria-label="Nouvelle opération">＋</button>
    </div>
    <a href="<?= $base ?>/client/transactions.php" class="nav-item">
      <span class="nav-icon">📋</span>
      <span class="nav-label">Historique</span>
    </a>
    <a href="<?= $base ?>/client/profil.php" class="nav-item">
      <span class="nav-icon">👤</span>
      <span class="nav-label">Profil</span>
    </a>
  </div>
</nav>

<!-- MENU POPUP DU FAB -->
<div class="fab-overlay" id="fabOverlay"></div>

<div class="fab-menu" id="fabMenu">
  <a href="<?= $base ?>/client/depot.php" class="fab-menu-item depot active">
    <span class="ico">＋</span><span>Dépôt</span>
  </a>
  <a href="<?= $base ?>/client/retrait.php" class="fab-menu-item retrait">
    <span class="ico">－</span><span>Retrait</span>
  </a>
  <a href="<?= $base ?>/client/virement.php" class="fab-menu-item virement">
    <span class="ico">⇄</span><span>Virement</span>
  </a>
  <a href="<?= $base ?>/client/epargne.php" class="fab-menu-item epargne">
    <span class="ico">💰</span><span>Épargne</span>
  </a>
</div>

<script>
/* ============================================================
   Affichage dynamique de la devise
   ============================================================ */
const deviseSelect = document.getElementById('deviseSelect');
const curSymbol    = document.getElementById('curSymbol');
const montantInput = document.getElementById('montant');

const QUICK_CDF = [1000, 5000, 10000, 50000, 100000];
const QUICK_USD = [1, 5, 10, 20, 50, 100];

function setAmount(v) {
  montantInput.value = v;
  montantInput.focus();
}

function updateCurrency() {
  const devise = deviseSelect.value;
  curSymbol.textContent = devise;
  montantInput.placeholder = devise === 'USD' ? '0.00' : '0';

  const buttons = document.querySelectorAll('.quick-amounts button');
  const values = devise === 'USD' ? QUICK_USD : QUICK_CDF;

  buttons.forEach((btn, i) => {
    if (values[i] !== undefined) {
      btn.textContent = values[i].toLocaleString('fr-FR');
      btn.onclick = () => setAmount(values[i]);
    }
  });

  montantInput.value = '';
}

deviseSelect.addEventListener('change', updateCurrency);
updateCurrency();

/* ============================================================
   MENU FAB (Bouton central)
   ============================================================ */
(function() {
  const fab = document.getElementById('navFab');
  const menu = document.getElementById('fabMenu');
  const overlay = document.getElementById('fabOverlay');

  if (!fab || !menu || !overlay) return;

  function toggleMenu() {
    const isOpen = menu.classList.contains('open');
    if (isOpen) {
      menu.classList.remove('open');
      overlay.classList.remove('open');
      fab.classList.remove('active');
    } else {
      menu.classList.add('open');
      overlay.classList.add('open');
      fab.classList.add('active');
    }
  }

  fab.addEventListener('click', toggleMenu);
  overlay.addEventListener('click', toggleMenu);

  menu.querySelectorAll('a').forEach(link => {
    link.addEventListener('click', () => {
      menu.classList.remove('open');
      overlay.classList.remove('open');
      fab.classList.remove('active');
    });
  });
})();

/* Effet "tap" sur les items de nav */
document.querySelectorAll('.nav-item').forEach(item => {
  item.addEventListener('touchstart', function() {
    this.style.transform = 'scale(0.95)';
  }, { passive: true });
  item.addEventListener('touchend', function() {
    this.style.transform = '';
  });
});

console.log('%c💰 Page Dépôt ATF — Navigation mobile activée', 'color:#F5B800;font-weight:bold;font-size:13px');
</script>
<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>