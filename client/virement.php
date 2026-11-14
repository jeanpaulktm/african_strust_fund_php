<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
requireRole('client');

$pdo = Database::getConnection();
$id  = (int)$_SESSION['client_id'];

$errors  = [];
$success = '';

/* Taux de change */
define('TAUX_USD_CDF', 2800);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) {
            throw new RuntimeException("Jeton de sécurité invalide.");
        }

        $numero_dest = cleanString($_POST['numero_compte_dest'] ?? '', 30);
        $montant     = (float)str_replace([' ', ','], ['', '.'], $_POST['montant'] ?? '0');
        $devise      = in_array($_POST['devise'] ?? '', ['CDF','USD'], true) ? $_POST['devise'] : 'CDF';
        $description = cleanString($_POST['description'] ?? '', 255);

        if ($numero_dest === '')   throw new RuntimeException("Le numéro de compte du destinataire est obligatoire.");
        if ($montant <= 0)         throw new RuntimeException("Le montant doit être positif.");
        if ($montant > 10_000_000) throw new RuntimeException("Montant maximal dépassé.");

        $pdo->beginTransaction();

        /* Charger l'émetteur */
        $stmt = $pdo->prepare("SELECT id_client, solde, devise, numero_compte FROM Client WHERE id_client = ? FOR UPDATE");
        $stmt->execute([$id]);
        $emetteur = $stmt->fetch();

        if (!$emetteur) throw new RuntimeException("Émetteur introuvable.");

        /* Charger le destinataire */
        $stmt = $pdo->prepare("SELECT id_client, solde, devise, numero_compte, nom, postnom FROM Client WHERE numero_compte = ? FOR UPDATE");
        $stmt->execute([$numero_dest]);
        $dest = $stmt->fetch();

        if (!$dest) throw new RuntimeException("Destinataire introuvable.");
        if ((int)$dest['id_client'] === $id) throw new RuntimeException("Impossible de faire un virement à soi-même.");

        /* Conversion si devises différentes */
        $montant_debit  = $montant;
        $montant_credit = $montant;

        if ($devise !== $emetteur['devise']) {
            if ($devise === 'USD' && $emetteur['devise'] === 'CDF') {
                $montant_debit = $montant * TAUX_USD_CDF;
            } elseif ($devise === 'CDF' && $emetteur['devise'] === 'USD') {
                $montant_debit = $montant / TAUX_USD_CDF;
            }
        }
        if ($dest['devise'] !== $emetteur['devise']) {
            if ($emetteur['devise'] === 'CDF' && $dest['devise'] === 'USD') {
                $montant_credit = $montant_debit / TAUX_USD_CDF;
            } elseif ($emetteur['devise'] === 'USD' && $dest['devise'] === 'CDF') {
                $montant_credit = $montant_debit * TAUX_USD_CDF;
            }
        }

        /* Vérifier le solde */
        if ($montant_debit > (float)$emetteur['solde']) {
            throw new RuntimeException(
                "Solde insuffisant. Disponible : " .
                number_format((float)$emetteur['solde'], 2, ',', ' ') . ' ' . $emetteur['devise']
            );
        }

        /* Calculs finaux */
        $solde_emetteur_avant = (float)$emetteur['solde'];
        $solde_emetteur_apres = $solde_emetteur_avant - $montant_debit;

        $solde_dest_avant = (float)$dest['solde'];
        $solde_dest_apres = $solde_dest_avant + $montant_credit;

        $reference = genererReference();

        /* Débit émetteur */
        $pdo->prepare("UPDATE Client SET solde = ? WHERE id_client = ?")
            ->execute([$solde_emetteur_apres, $id]);

        /* Crédit destinataire */
        $pdo->prepare("UPDATE Client SET solde = ? WHERE id_client = ?")
            ->execute([$solde_dest_apres, (int)$dest['id_client']]);

        /* Transaction débit */
        $pdo->prepare("
            INSERT INTO Transactions 
                (id_client, type_transaction, montant, mode, effectue_par,
                 id_destinataire, solde_avant, solde_apres, reference, description, ip_address)
            VALUES (?, 'Virement', ?, 'En ligne', 'Client', ?, ?, ?, ?, ?, ?)
        ")->execute([
            $id, $montant_debit, (int)$dest['id_client'],
            $solde_emetteur_avant, $solde_emetteur_apres,
            $reference . '-D',
            'Virement envoyé à ' . $dest['numero_compte'] . ' — ' . $description,
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);

        /* Transaction crédit */
        $pdo->prepare("
            INSERT INTO Transactions 
                (id_client, type_transaction, montant, mode, effectue_par,
                 solde_avant, solde_apres, reference, description, ip_address)
            VALUES (?, 'Virement', ?, 'En ligne', 'Client', ?, ?, ?, ?, ?)
        ")->execute([
            (int)$dest['id_client'], $montant_credit,
            $solde_dest_avant, $solde_dest_apres,
            $reference . '-C',
            'Virement reçu de ' . $emetteur['numero_compte'] . ' — ' . $description,
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);

        $pdo->commit();

        logAction($pdo, 'client', $id, 'VIREMENT',
            "Vers {$dest['numero_compte']} | Montant: $montant $devise | Ref: $reference");

        $_SESSION['flash_success'] = "✅ Virement effectué avec succès !<br>
            <strong>Référence :</strong> $reference<br>
            <strong>Destinataire :</strong> " . htmlspecialchars($dest['nom'] . ' ' . $dest['postnom']) . "<br>
            <strong>Envoyé :</strong> " . number_format($montant_debit, 2, ',', ' ') . " " . $emetteur['devise'];

        header('Location: ' . url('client/virement.php'));
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = $e->getMessage();
    }
}

/* Flash */
if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

/* Info client */
$stmt = $pdo->prepare("SELECT solde, devise, numero_compte FROM Client WHERE id_client = ?");
$stmt->execute([$id]);
$client = $stmt->fetch();

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
<title>Virement — AFRICAN TRUST FUND</title>
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

.fab-menu-item.active {
  background: rgba(59,130,246,.12);
  border-color: rgba(59,130,246,.4);
  color: #93B4FF;
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
  <h1>⇄ Faire un virement</h1>
  <p class="op-sub">Transférez de l'argent vers un autre compte ATF</p>

  <?php if ($success): ?>
    <div class="alert success"><?= $success ?></div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="alert error">
      <ul><?php foreach ($errors as $e) echo "<li>" . htmlspecialchars($e) . "</li>"; ?></ul>
    </div>
  <?php endif; ?>

  <div class="info-banner">
    Votre solde : <strong><?= number_format((float)$client['solde'], 2, ',', ' ') ?> <?= htmlspecialchars($client['devise']) ?></strong><br>
    Votre compte : <code><?= htmlspecialchars($client['numero_compte']) ?></code><br>
    Taux appliqué : <strong>1 USD = <?= number_format(TAUX_USD_CDF, 0, ',', ' ') ?> CDF</strong>
  </div>

  <form method="POST" novalidate autocomplete="off">
    <?= csrfField() ?>

    <!-- Destinataire -->
    <label class="field">
      <span>Numéro de compte du destinataire *</span>
      <input type="text" name="numero_compte_dest" required maxlength="30"
             placeholder="ATF-2025-XXXXXX" autofocus>
    </label>

    <!-- Montant -->
    <label class="field">
      <span>Montant à envoyer *</span>
      <div class="amount-input">
        <input type="text" name="montant" id="montant" placeholder="0.00" required inputmode="decimal">
      </div>
    </label>

    <!-- Devise -->
    <label class="field">
      <span>Unité monétaire *</span>
      <select name="devise" required style="
        padding:13px 16px;
        background:var(--bg-1);
        border:1px solid var(--border);
        border-radius:12px;
        color:var(--gold);
        font-weight:700;
        font-size:.95rem;
        cursor:pointer;
      ">
        <option value="CDF" <?= $client['devise']==='CDF' ? 'selected' : '' ?>>
          🇨🇩 Franc Congolais (CDF)
        </option>
        <option value="USD" <?= $client['devise']==='USD' ? 'selected' : '' ?>>
          🇺🇸 Dollar Américain (USD)
        </option>
      </select>
    </label>

    <!-- Montants rapides -->
    <div class="quick-amounts">
      <button type="button" onclick="setAmount(1000)">1 000</button>
      <button type="button" onclick="setAmount(5000)">5 000</button>
      <button type="button" onclick="setAmount(10000)">10 000</button>
      <button type="button" onclick="setAmount(50000)">50 000</button>
      <button type="button" onclick="setAmount(100000)">100 000</button>
    </div>

    <!-- Description -->
    <label class="field">
      <span>Motif du virement (optionnel)</span>
      <input type="text" name="description" maxlength="255"
             placeholder="Ex: Paiement loyer, Aide familiale...">
    </label>

    <button class="btn-gold full" type="submit">Confirmer le virement</button>

    <p class="center muted" style="margin-top:16px">
      <a href="<?= $base ?>/client/dashboard.php">← Retour au tableau de bord</a>
    </p>
  </form>
</div>

<!-- ============================================================
     BARRE DE NAVIGATION MOBILE (comme page Épargne)
     ============================================================ -->
<nav class="mobile-nav">
  <div class="mobile-nav-inner">

    <a href="<?= $base ?>/client/dashboard.php" class="nav-item">
      <span class="nav-icon">🏠</span>
      <span class="nav-label">Accueil</span>
    </a>

    <a href="<?= $base ?>/client/depot.php" class="nav-item">
      <span class="nav-icon">＋</span>
      <span class="nav-label">Dépôt</span>
    </a>

    <div class="nav-fab-wrapper">
      <button class="nav-fab active" id="navFab" type="button" aria-label="Plus d'actions">＋</button>
    </div>

    <a href="<?= $base ?>/client/epargne.php" class="nav-item">
      <span class="nav-icon">💰</span>
      <span class="nav-label">Épargne</span>
    </a>

    <a href="<?= $base ?>/client/profil.php" class="nav-item">
      <span class="nav-icon">👤</span>
      <span class="nav-label">Profil</span>
    </a>

  </div>
</nav>

<!-- Menu FAB -->
<div class="fab-overlay" id="fabOverlay"></div>

<div class="fab-menu" id="fabMenu">

  <a href="<?= $base ?>/client/retrait.php" class="fab-menu-item retrait">
    <span class="ico">💸</span>
    <span>Retrait</span>
  </a>

  <a href="<?= $base ?>/client/virement.php" class="fab-menu-item virement active">
    <span class="ico">⇄</span>
    <span>Virement</span>
  </a>

  <a href="<?= $base ?>/client/transactions.php" class="fab-menu-item">
    <span class="ico">📋</span>
    <span>Historique</span>
  </a>

  <a href="<?= $base ?>/client/releve.php" class="fab-menu-item depot">
    <span class="ico">📄</span>
    <span>Relevé</span>
  </a>

</div>

<script>
/* ============================================================
   MONTANTS RAPIDES
   ============================================================ */
function setAmount(v) {
  document.getElementById('montant').value = v;
}

/* ============================================================
   MENU FAB
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

console.log('%c⇄ Page Virement ATF — Navigation mobile activée', 'color:#F5B800;font-weight:bold;font-size:13px');
</script>
<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>