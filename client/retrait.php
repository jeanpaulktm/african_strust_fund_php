<?php
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/entreprise_compte.php';
requireRole('client');

$pdo = Database::getConnection();
$id  = (int)$_SESSION['client_id'];

$errors = [];

/* ============================================================
   RÈGLES DE RETRAIT
   ============================================================ */
define('COMMISSION_TAUX', 0.01);
define('MIN_RETRAIT_CDF', 1000);
define('MULTIPLE_CDF',    100);
define('MIN_RETRAIT_USD', 5);
define('MULTIPLE_USD',    1);

/* ============================================================
   TRAITEMENT DU RETRAIT (POST)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) {
            throw new RuntimeException("Jeton de sécurité invalide.");
        }

        $montant = (float)str_replace([' ', ','], ['', '.'], $_POST['montant'] ?? '0');
        $devise  = in_array($_POST['devise'] ?? '', ['CDF','USD'], true) ? $_POST['devise'] : 'CDF';
        $mot_de_passe = (string)($_POST['mot_de_passe_securite'] ?? '');

        if ($montant <= 0) {
            throw new RuntimeException("Le montant doit être supérieur à zéro.");
        }

        if ($mot_de_passe === '') {
            throw new RuntimeException("Veuillez saisir votre mot de passe de sécurité.");
        }

        if ($devise === 'CDF') {
            if ($montant < MIN_RETRAIT_CDF) {
                throw new RuntimeException("Minimum de retrait : " . number_format(MIN_RETRAIT_CDF, 0, ',', ' ') . " CDF.");
            }
            if (fmod($montant, MULTIPLE_CDF) != 0) {
                throw new RuntimeException("Le montant doit être un multiple de " . MULTIPLE_CDF . " CDF.");
            }
        } else {
            if ($montant < MIN_RETRAIT_USD) {
                throw new RuntimeException("Minimum de retrait : $ " . number_format(MIN_RETRAIT_USD, 2) . ".");
            }
        }

        $pdo->beginTransaction();

        /* Charger le client avec verrou */
        $stmt = $pdo->prepare("
            SELECT solde_cdf, solde_usd, numero_compte, mot_de_passe
            FROM Client WHERE id_client = ? FOR UPDATE
        ");
        $stmt->execute([$id]);
        $c = $stmt->fetch();

        if (!$c) throw new RuntimeException("Client introuvable.");

        /* ============================================================
           VÉRIFICATION DU MOT DE PASSE
           ============================================================ */
        if (!password_verify($mot_de_passe, $c['mot_de_passe'])) {
            $pdo->prepare("
                UPDATE Client 
                SET failed_attempts = failed_attempts + 1
                WHERE id_client = ?
            ")->execute([$id]);

            $stmt2 = $pdo->prepare("SELECT failed_attempts FROM Client WHERE id_client = ?");
            $stmt2->execute([$id]);
            $attempts = (int)$stmt2->fetchColumn();

            logAction($pdo, 'client', $id, 'RETRAIT_PASSWORD_FAILED',
                "Tentative échouée #$attempts");

            if ($attempts >= 5) {
                $pdo->prepare("
                    UPDATE Client 
                    SET locked_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE)
                    WHERE id_client = ?
                ")->execute([$id]);

                $pdo->commit();
                throw new RuntimeException("❌ Mot de passe incorrect. Compte verrouillé pendant 15 minutes (5 tentatives échouées).");
            }

            $pdo->commit();
            $restantes = 5 - $attempts;
            throw new RuntimeException("❌ Mot de passe de sécurité incorrect. Tentatives restantes : $restantes");
        }

        /* Réinitialiser le compteur après succès */
        $pdo->prepare("
            UPDATE Client 
            SET failed_attempts = 0, locked_until = NULL
            WHERE id_client = ?
        ")->execute([$id]);

        /* ============================================================
           RÉCUPÉRATION DES SOLDES
           ============================================================ */
        $solde_cdf_avant = (float)$c['solde_cdf'];
        $solde_usd_avant = (float)$c['solde_usd'];

        /* Commission calculée sur le montant demandé */
        $commission = round($montant * COMMISSION_TAUX, 2);

        /* ============================================================
           LOGIQUE CORRIGÉE :
           - Le montant demandé est retiré du solde
           - La commission est AUSSI retirée du solde
           - Le client reçoit le montant complet (pas de déduction)
           - Le solde doit contenir : montant + commission
           ============================================================ */

        if ($devise === 'USD') {
            $total_a_debiter = round($montant + $commission, 2);

            if ($total_a_debiter > $solde_usd_avant) {
                throw new RuntimeException(
                    "❌ Solde USD insuffisant pour couvrir le retrait et la commission.\n" .
                    "Montant demandé : $ " . number_format($montant, 2, '.', ',') . "\n" .
                    "Commission 1% : $ " . number_format($commission, 2, '.', ',') . "\n" .
                    "Total requis : $ " . number_format($total_a_debiter, 2, '.', ',') . "\n" .
                    "Solde disponible : $ " . number_format($solde_usd_avant, 2, '.', ',')
                );
            }

            $solde_usd_apres = round($solde_usd_avant - $total_a_debiter, 2);

            $pdo->prepare("UPDATE Client SET solde_usd = ? WHERE id_client = ?")
                ->execute([$solde_usd_apres, $id]);

            $solde_avant_display = $solde_usd_avant;
            $solde_apres_display = $solde_usd_apres;

        } else {
            $total_a_debiter = round($montant + $commission, 2);

            if ($total_a_debiter > $solde_cdf_avant) {
                throw new RuntimeException(
                    "❌ Solde CDF insuffisant pour couvrir le retrait et la commission.\n" .
                    "Montant demandé : " . number_format($montant, 2, ',', ' ') . " CDF\n" .
                    "Commission 1% : " . number_format($commission, 2, ',', ' ') . " CDF\n" .
                    "Total requis : " . number_format($total_a_debiter, 2, ',', ' ') . " CDF\n" .
                    "Solde disponible : " . number_format($solde_cdf_avant, 2, ',', ' ') . " CDF"
                );
            }

            $solde_cdf_apres = round($solde_cdf_avant - $total_a_debiter, 2);

            $pdo->prepare("UPDATE Client SET solde_cdf = ? WHERE id_client = ?")
                ->execute([$solde_cdf_apres, $id]);

            $solde_avant_display = $solde_cdf_avant;
            $solde_apres_display = $solde_cdf_apres;
        }

        $reference = genererReference();

        /* Transaction enregistrée avec le montant demandé */
        $pdo->prepare("
            INSERT INTO Transactions 
                (id_client, type_transaction, montant, devise, mode, effectue_par,
                 solde_avant, solde_apres, reference, description, ip_address)
            VALUES (?, 'Retrait', ?, ?, 'En ligne', 'Client', ?, ?, ?, ?, ?)
        ")->execute([
            $id,
            $montant,
            $devise,
            $solde_avant_display,
            $solde_apres_display,
            $reference,
            "Retrait en ligne de " . number_format($montant, 2, ',', ' ') . " $devise " .
            "(commission 1% = " . number_format($commission, 2, ',', ' ') . " $devise prélevée du solde)",
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);

        $id_transaction = (int)$pdo->lastInsertId();

        /* Enregistrer la commission */
        $pdo->prepare("
            INSERT INTO Commissions 
                (id_client, id_transaction, montant_brut, taux_commission,
                 montant_commission, montant_net, devise, date_commission)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ")->execute([
            $id, $id_transaction, $montant, COMMISSION_TAUX,
            $commission, $montant, $devise
        ]);

        if (function_exists('crediterInteretEntreprise')) {
            crediterInteretEntreprise(
                $pdo,
                $commission,
                $devise,
                'commission_retrait',
                $id,
                ['id_transaction' => $id_transaction],
                "Commission 1% sur retrait $reference"
            );
        }

        $pdo->commit();

        logAction($pdo, 'client', $id, 'RETRAIT',
            "Montant: $montant $devise | Commission: $commission | Ref: $reference");

        $_SESSION['flash_success'] = "✅ Retrait effectué avec succès !<br>
            <strong>Référence :</strong> $reference<br>
            <strong>Montant retiré :</strong> " . number_format($montant, 2, ',', ' ') . " $devise<br>
            <strong>Commission (1%) :</strong> − " . number_format($commission, 2, ',', ' ') . " $devise (prélevée du solde)<br>
            <strong>💵 Vous recevez :</strong> " . number_format($montant, 2, ',', ' ') . " $devise<br>
            <strong>Solde $devise restant :</strong> " . number_format($solde_apres_display, 2, ',', ' ') . " $devise";

        header('Location: ' . url('client/retrait.php'));
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[ATF RETRAIT] ' . $e->getMessage());
        $errors[] = $e->getMessage();
    }
}

$success = '';
if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

$stmt = $pdo->prepare("SELECT numero_compte, solde_cdf, solde_usd FROM Client WHERE id_client = ?");
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
<title>Retrait — AFRICAN TRUST FUND</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<style>
/* ============================================================
   VARIABLES
   ============================================================ */
:root {
  --nav-height: 76px;
  --nav-safe-area: env(safe-area-inset-bottom, 0px);
}

body.app-mode {
  padding-bottom: calc(var(--nav-height) + var(--nav-safe-area) + 20px);
}

/* Masquer la nav du haut sur mobile */
@media (max-width: 900px) {
  .atf-header .atf-nav {
    display: none;
  }
}

.duo-solde { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 20px; }
.solde-mini { padding: 16px 18px; border-radius: 14px; border: 1px solid var(--border); position: relative; overflow: hidden; }
.solde-mini::before { content: ""; position: absolute; top: 0; left: 0; width: 4px; height: 100%; }
.solde-mini.usd { background: rgba(59,130,246,.10); border-color: rgba(59,130,246,.3); }
.solde-mini.usd::before { background: #3B82F6; }
.solde-mini.cdf { background: rgba(245,184,0,.10); border-color: rgba(245,184,0,.3); }
.solde-mini.cdf::before { background: #F5B800; }
.solde-mini .lbl { font-size: .72rem; color: var(--muted); text-transform: uppercase; letter-spacing: 1.2px; font-weight: 700; }
.solde-mini .val { font-size: 1.4rem; font-weight: 800; margin-top: 6px; }
.solde-mini.usd .val { color: #93B4FF; }
.solde-mini.cdf .val { color: var(--gold); }

.rules-box { margin-top: 20px; padding: 18px 20px; border-radius: 14px; background: rgba(59,130,246,.06); border: 1px solid rgba(59,130,246,.2); }
.rules-box h4 { font-size: .82rem; color: #93B4FF; margin-bottom: 10px; text-transform: uppercase; letter-spacing: 1.2px; font-weight: 800; }
.rules-box ul { list-style: none; padding: 0; }
.rules-box li { padding: 6px 0; color: var(--muted); font-size: .84rem; display: flex; gap: 8px; }
.rules-box li::before { content: "✓"; color: var(--green); font-weight: 800; }

.sim-box { margin-top: 18px; padding: 18px 20px; border-radius: 14px; background: rgba(245,184,0,.08); border: 1px solid rgba(245,184,0,.3); }
.sim-box .title { font-size: .78rem; color: var(--gold); text-transform: uppercase; letter-spacing: 1.2px; font-weight: 800; margin-bottom: 12px; }
.sim-row { display: flex; justify-content: space-between; padding: 8px 0; font-size: .9rem; border-bottom: 1px solid rgba(255,255,255,.05); }
.sim-row .lbl { color: var(--muted); }
.sim-row .val { font-weight: 700; }
.sim-row.neg .val { color: #FCA5A5; }
.sim-row.total { border-top: 2px solid var(--gold); border-bottom: none; padding-top: 12px; margin-top: 8px; }
.sim-row.total .val { color: var(--gold); font-size: 1.3rem; font-weight: 800; }

.alert-solde { padding: 12px 16px; border-radius: 10px; font-size: .85rem; margin-top: 10px; display: none; }
.alert-solde.warning { display: block; background: rgba(239,68,68,.1); border: 1px solid rgba(239,68,68,.35); color: #FCA5A5; }
.alert-solde.ok { display: block; background: rgba(16,185,129,.1); border: 1px solid rgba(16,185,129,.35); color: #6EE7B7; }

.quick-amounts { display: grid; grid-template-columns: repeat(auto-fit, minmax(85px, 1fr)); gap: 8px; margin-top: 12px; }
.quick-amounts button { padding: 10px; border-radius: 10px; background: var(--bg-1); border: 1px solid var(--border); color: var(--text); font-weight: 700; font-size: .82rem; cursor: pointer; transition: all .2s; font-family: inherit; }
.quick-amounts button:hover { border-color: var(--gold); color: var(--gold); background: var(--gold-soft); }
.quick-amounts button.max { background: rgba(16,185,129,.15); border-color: rgba(16,185,129,.4); color: var(--green); }

.field-error { color: #FCA5A5; font-size: .78rem; margin-top: 6px; display: none; }
.field-error.show { display: block; }

/* ============================================================
   MODALE
   ============================================================ */
.modal-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(11,14,20,.85);
  backdrop-filter: blur(10px);
  z-index: 99999;
  align-items: center;
  justify-content: center;
  padding: 20px;
}
.modal-overlay.active { display: flex; }
.modal-content {
  background: linear-gradient(145deg, #1A2130, #121824);
  border-radius: 24px;
  border: 1px solid rgba(245,184,0,.3);
  box-shadow: 0 30px 80px rgba(0,0,0,.8);
  padding: 32px 28px;
  max-width: 460px;
  width: 100%;
  animation: slideUp .4s cubic-bezier(.4,0,.2,1);
}
@keyframes slideUp {
  from { transform: translateY(30px) scale(.95); opacity: 0; }
  to { transform: translateY(0) scale(1); opacity: 1; }
}
.modal-header { text-align: center; margin-bottom: 24px; }
.modal-icon {
  width: 72px; height: 72px; border-radius: 50%;
  background: linear-gradient(135deg, #F5B800, #D4AF37);
  color: #0B0E14;
  display: flex; align-items: center; justify-content: center;
  font-size: 2rem;
  margin: 0 auto 16px;
  box-shadow: 0 12px 30px rgba(245,184,0,.5);
}
.modal-header h3 { color: #F5B800; font-size: 1.3rem; font-weight: 800; margin-bottom: 8px; }
.modal-header p { color: #8A93A6; font-size: .88rem; }
.modal-summary {
  background: rgba(11,14,20,.5);
  border-radius: 14px;
  padding: 16px 18px;
  margin-bottom: 20px;
  border: 1px solid rgba(245,184,0,.2);
}
.modal-summary .row { display: flex; justify-content: space-between; padding: 6px 0; font-size: .88rem; }
.modal-summary .row .lbl { color: #8A93A6; }
.modal-summary .row .val { color: #fff; font-weight: 700; }
.modal-summary .row.gold .val { color: #F5B800; font-size: 1.1rem; }
.modal-summary .row.info .val { color: #93B4FF; font-weight: 600; font-size: .82rem; }
.modal-field { margin-bottom: 20px; }
.modal-field label { display: block; color: #8A93A6; font-size: .82rem; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px; }
.password-input-wrap { position: relative; display: flex; align-items: center; }
.password-input-wrap input {
  width: 100%;
  padding: 16px 50px 16px 50px;
  background: #0B0E14;
  border: 2px solid rgba(245,184,0,.3);
  border-radius: 14px;
  color: #fff;
  font-size: 1rem;
  letter-spacing: 2px;
}
.password-input-wrap input:focus { outline: none; border-color: #F5B800; box-shadow: 0 0 0 4px rgba(245,184,0,.15); }
.password-input-wrap .icon-lock { position: absolute; left: 16px; font-size: 1.3rem; }
.password-input-wrap .icon-eye { position: absolute; right: 14px; background: transparent; border: none; color: #8A93A6; font-size: 1.2rem; cursor: pointer; padding: 8px; }
.modal-actions { display: flex; gap: 12px; }
.modal-actions button { flex: 1; padding: 15px 20px; border-radius: 999px; font-weight: 800; font-size: .92rem; cursor: pointer; border: none; }
.btn-cancel { background: rgba(138,147,166,.15); color: #E8ECF3; border: 1px solid rgba(138,147,166,.3) !important; }
.btn-confirm { background: linear-gradient(135deg, #F5B800, #D4AF37); color: #0B0E14; box-shadow: 0 8px 24px rgba(245,184,0,.4); }
.btn-confirm:disabled { opacity: .5; cursor: not-allowed; }
.modal-error { padding: 12px 16px; background: rgba(239,68,68,.15); border: 1px solid rgba(239,68,68,.4); color: #FCA5A5; border-radius: 10px; font-size: .85rem; margin-bottom: 16px; display: none; }
.modal-error.show { display: block; animation: shake .4s; }
@keyframes shake { 0%,100% { transform: translateX(0); } 25% { transform: translateX(-8px); } 75% { transform: translateX(8px); } }
.security-note {
  margin-top: 16px;
  padding: 12px 16px;
  background: rgba(59,130,246,.08);
  border-left: 3px solid #3B82F6;
  border-radius: 8px;
  font-size: .78rem;
  color: #93B4FF;
  line-height: 1.5;
}

/* ============================================================
   MENU MOBILE EN BAS
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
  grid-template-columns: repeat(5, 1fr);
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
  padding: 10px 4px;
  text-decoration: none;
  color: var(--muted);
  font-size: .65rem;
  font-weight: 700;
  transition: all .25s;
  border-radius: 14px;
  position: relative;
  text-transform: uppercase;
  background: none;
  border: none;
  cursor: pointer;
  font-family: inherit;
}

.nav-item .nav-icon {
  font-size: 1.5rem;
  line-height: 1;
  transition: transform .3s cubic-bezier(.34,1.56,.64,1);
}

.nav-item .nav-label {
  font-size: .62rem;
  font-weight: 700;
}

.nav-item.active {
  color: var(--gold);
}

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

.nav-fab-wrapper {
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
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
  box-shadow: 0 10px 30px rgba(245,184,0,.5), 0 0 0 6px rgba(245,184,0,.15);
  transition: all .3s cubic-bezier(.34,1.56,.64,1);
  z-index: 2;
}

.nav-fab.active {
  transform: rotate(45deg);
}

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
.fab-menu-item:hover {
  background: rgba(245,184,0,.1);
  border-color: rgba(245,184,0,.3);
  color: var(--gold);
  transform: translateX(4px);
}
.fab-menu-item .ico {
  width: 40px; height: 40px;
  border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  font-size: 1.3rem;
  flex-shrink: 0;
  background: var(--gold-soft);
  color: var(--gold);
}
.fab-menu-item.depot .ico { background: rgba(16,185,129,.15); color: var(--green); }
.fab-menu-item.retrait .ico { background: rgba(239,68,68,.15); color: #FCA5A5; }
.fab-menu-item.virement .ico { background: rgba(59,130,246,.15); color: #93B4FF; }
.fab-menu-item.epargne .ico { background: rgba(139,92,246,.15); color: #A78BFA; }

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
.fab-overlay.open {
  display: block;
  opacity: 1;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 900px) {
  .mobile-nav { display: block; }
  .duo-solde { grid-template-columns: 1fr; }
}

@media (max-width: 640px) {
  .modal-content { padding: 24px 20px; }
  .modal-icon { width: 60px; height: 60px; font-size: 1.6rem; }
  .modal-header h3 { font-size: 1.1rem; }
  .nav-item .nav-icon { font-size: 1.3rem; }
  .nav-item .nav-label { font-size: .55rem; }
  .nav-fab { width: 54px; height: 54px; font-size: 1.6rem; }
}
</style>
</head>
<body class="dark-auth app-mode">

<?php require __DIR__ . '/../includes/header.php'; ?>

<div class="op-wrapper" style="max-width:680px">

  <h1>💸 Faire un retrait</h1>
  <p class="op-sub">Retirez vos fonds en toute sécurité</p>

  <?php if ($success): ?>
    <div class="alert success"><?= $success ?></div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="alert error">
      <strong style="display:block;margin-bottom:8px">❌ Opération refusée</strong>
      <ul style="margin-left:20px">
        <?php foreach ($errors as $e): foreach (explode("\n", $e) as $line): if (trim($line)): ?>
          <li><?= htmlspecialchars(trim($line)) ?></li>
        <?php endif; endforeach; endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <div class="info-banner" style="margin-bottom:14px">
    Compte : <code><?= htmlspecialchars($client['numero_compte']) ?></code>
  </div>

  <div class="duo-solde">
    <div class="solde-mini usd">
      <div class="lbl">🇺🇸 Solde USD</div>
      <div class="val">$ <?= number_format($solde_usd, 2, '.', ',') ?></div>
    </div>
    <div class="solde-mini cdf">
      <div class="lbl">🇨🇩 Solde CDF</div>
      <div class="val"><?= number_format($solde_cdf, 2, ',', ' ') ?> CDF</div>
    </div>
  </div>

  <form method="POST" novalidate autocomplete="off" id="formRetrait">
    <?= csrfField() ?>

    <label class="field">
      <span>💱 Devise du retrait *</span>
      <select name="devise" id="deviseSelect" required>
        <option value="CDF" selected>🇨🇩 Franc Congolais (CDF)</option>
        <option value="USD">🇺🇸 Dollar Américain (USD)</option>
      </select>
    </label>

    <label class="field">
      <span>💵 Montant à retirer *</span>
      <div class="amount-input">
        <span class="cur" id="curSymbol">Montant</span>
        <input type="text" name="montant" id="montantInput" placeholder="0.00" required autofocus inputmode="decimal" autocomplete="off">
      </div>
      <div class="field-error" id="fieldError"></div>
    </label>

    <div class="alert-solde" id="alertSolde"></div>

    <div class="quick-amounts" id="quickAmounts"></div>

    <div class="sim-box" id="simBox" style="display:none">
      <div class="title">💡 Aperçu de l'opération</div>
      <div class="sim-row">
        <span class="lbl">💰 Montant retiré</span>
        <span class="val" id="simMontant">—</span>
      </div>
      <div class="sim-row neg">
        <span class="lbl">💵 Commission (1%) déduite du solde</span>
        <span class="val" id="simCommission">—</span>
      </div>
      <div class="sim-row total">
        <span class="lbl">✅ Vous recevez</span>
        <span class="val" id="simNet">—</span>
      </div>
      <div class="sim-row" style="border-top:1px solid rgba(255,255,255,.1);margin-top:8px;padding-top:12px">
        <span class="lbl">Solde après retrait</span>
        <span class="val" id="simSoldeApres">—</span>
      </div>
    </div>

    <button type="button" class="btn-gold full" id="btnSubmit" style="margin-top:20px" onclick="ouvrirModal()">
      🔐 Continuer vers la confirmation
    </button>

    <p class="center muted" style="margin-top:16px">
      <a href="<?= $base ?>/client/dashboard.php">← Retour au tableau de bord</a>
    </p>
  </form>

  <div class="rules-box">
    <h4>📋 Règles de retrait</h4>
    <ul>
      <li><strong>USD reste USD</strong> · <strong>CDF reste CDF</strong></li>
      <li>Commission de <strong>1%</strong> prélevée du <strong>solde restant</strong> (pas du montant)</li>
      <li>Exemple : Retrait de 10 000 CDF → solde débité de <strong>10 100 CDF</strong> (10 000 + 100 commission)</li>
      <li><strong>Mot de passe de sécurité</strong> requis pour chaque retrait</li>
      <li><strong>CDF :</strong> min <?= number_format(MIN_RETRAIT_CDF, 0, ',', ' ') ?> · multiple de <?= MULTIPLE_CDF ?></li>
      <li><strong>USD :</strong> min $<?= MIN_RETRAIT_USD ?> · multiple de $<?= MULTIPLE_USD ?></li>
    </ul>
  </div>

</div>

<!-- ============================================================
     MODALE DE CONFIRMATION
     ============================================================ -->
<div class="modal-overlay" id="modalOverlay">
  <div class="modal-content">

    <div class="modal-header">
      <div class="modal-icon">🔐</div>
      <h3>Confirmation requise</h3>
      <p>Saisissez votre mot de passe de sécurité pour valider le retrait</p>
    </div>

    <div class="modal-summary">
      <div class="row">
        <span class="lbl">💰 Montant retiré</span>
        <span class="val gold" id="modalMontant">—</span>
      </div>
      <div class="row">
        <span class="lbl">💵 Commission (1%)</span>
        <span class="val" style="color:#FCA5A5" id="modalCommission">—</span>
      </div>
      <div class="row">
        <span class="lbl">✅ Vous recevez</span>
        <span class="val" style="color:#6EE7B7;font-size:1.05rem" id="modalNet">—</span>
      </div>
      <div class="row info">
        <span class="lbl">Total débité du solde</span>
        <span class="val" id="modalTotalDebit">—</span>
      </div>
    </div>

    <div class="modal-error" id="modalError"></div>

    <div class="modal-field">
      <label for="pwdModal">🔑 Mot de passe de sécurité</label>
      <div class="password-input-wrap">
        <span class="icon-lock">🔒</span>
        <input type="password" id="pwdModal" placeholder="Votre mot de passe" autocomplete="current-password" maxlength="100">
        <button type="button" class="icon-eye" id="togglePwd">👁</button>
      </div>
    </div>

    <div class="modal-actions">
      <button type="button" class="btn-cancel" onclick="fermerModal()">✕ Annuler</button>
      <button type="button" class="btn-confirm" id="btnConfirm" onclick="confirmerRetrait()">✅ Confirmer</button>
    </div>

    <div class="security-note">
      🔒 <strong>Sécurité :</strong> Votre mot de passe est chiffré et vérifié de manière sécurisée.
    </div>

  </div>
</div>

<input type="hidden" name="mot_de_passe_securite" id="motDePasseHidden" form="formRetrait">

<!-- ============================================================
     MENU MOBILE EN BAS
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
      <button class="nav-fab" id="navFab" type="button" aria-label="Plus d'actions">＋</button>
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

  <a href="<?= $base ?>/client/virement.php" class="fab-menu-item virement">
    <span class="ico">⇄</span>
    <span>Virement</span>
  </a>

  <a href="<?= $base ?>/client/transactions.php" class="fab-menu-item">
    <span class="ico">📋</span>
    <span>Historique</span>
  </a>

  <a href="<?= $base ?>/client/releve.php" class="fab-menu-item epargne">
    <span class="ico">📄</span>
    <span>Relevé</span>
  </a>

</div>

<script>
/* ============================================================
   CONFIGURATION
   ============================================================ */
const SOLDE_USD  = <?= $solde_usd ?>;
const SOLDE_CDF  = <?= $solde_cdf ?>;
const COMMISSION = 0.01;
const MIN_CDF    = <?= MIN_RETRAIT_CDF ?>;
const MULT_CDF   = <?= MULTIPLE_CDF ?>;
const MIN_USD    = <?= MIN_RETRAIT_USD ?>;
const MULT_USD   = <?= MULTIPLE_USD ?>;

const QUICK_AMOUNTS = {
  CDF: [5000, 10000, 25000, 50000, 100000, 500000],
  USD: [10, 20, 50, 100, 200, 500]
};

const deviseSelect  = document.getElementById('deviseSelect');
const montantInput  = document.getElementById('montantInput');
const curSymbol     = document.getElementById('curSymbol');
const simBox        = document.getElementById('simBox');
const alertSolde    = document.getElementById('alertSolde');
const fieldError    = document.getElementById('fieldError');
const quickBox      = document.getElementById('quickAmounts');
const btnSubmit     = document.getElementById('btnSubmit');

const modalOverlay  = document.getElementById('modalOverlay');
const modalError    = document.getElementById('modalError');
const pwdModal      = document.getElementById('pwdModal');
const btnConfirm    = document.getElementById('btnConfirm');

/* ============================================================
   UTILITAIRES
   ============================================================ */
function fmt(val, devise) {
  if (devise === 'USD') return '$ ' + val.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  return val.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' CDF';
}

function fmod(a, b) { return +(a - Math.floor(a / b) * b).toFixed(10); }

/* ============================================================
   MONTANTS RAPIDES
   ============================================================ */
function buildQuickAmounts(devise) {
  quickBox.innerHTML = '';
  QUICK_AMOUNTS[devise].forEach(v => {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.textContent = devise === 'USD' ? '$' + v : v.toLocaleString('fr-FR');
    btn.onclick = () => { montantInput.value = v; simulate(); };
    quickBox.appendChild(btn);
  });

  const btnMax = document.createElement('button');
  btnMax.type = 'button';
  btnMax.classList.add('max');
  btnMax.textContent = '★ MAX';
  btnMax.onclick = () => {
    const soldeDispo = devise === 'USD' ? SOLDE_USD : SOLDE_CDF;
    /* MAX = solde / (1 + taux) pour que solde + commission <= solde */
    const maxRetirable = Math.floor(soldeDispo / (1 + COMMISSION));
    montantInput.value = maxRetirable;
    simulate();
  };
  quickBox.appendChild(btnMax);
}

/* ============================================================
   SIMULATION
   ============================================================ */
function simulate() {
  const devise = deviseSelect.value;
  const montant = parseFloat(montantInput.value.toString().replace(',', '.')) || 0;

  curSymbol.textContent = devise;
  fieldError.classList.remove('show');
  alertSolde.classList.remove('show');

  if (montant <= 0) {
    simBox.style.display = 'none';
    btnSubmit.disabled = true;
    btnSubmit.style.opacity = '.5';
    return;
  }

  const soldeDispo = devise === 'USD' ? SOLDE_USD : SOLDE_CDF;
  const minVal = devise === 'USD' ? MIN_USD : MIN_CDF;
  const multiple = devise === 'USD' ? MULT_USD : MULT_CDF;

  let hasError = false;

  if (montant < minVal) {
    fieldError.textContent = `⚠️ Minimum : ${fmt(minVal, devise)}`;
    fieldError.classList.add('show');
    hasError = true;
  } else if (fmod(montant, multiple) !== 0) {
    fieldError.textContent = `⚠️ Le montant doit être un multiple de ${multiple}`;
    fieldError.classList.add('show');
    hasError = true;
  }

  /* ============================================================
     NOUVELLE LOGIQUE :
     Commission prélevée du solde restant
     Total débité = montant + commission
     ============================================================ */
  const commission = Math.round(montant * COMMISSION * 100) / 100;
  const totalDebite = Math.round((montant + commission) * 100) / 100;
  const soldeApres = Math.round((soldeDispo - totalDebite) * 100) / 100;

  /* Vérifier que le solde couvre montant + commission */
  if (totalDebite > soldeDispo) {
    const manquant = Math.round((totalDebite - soldeDispo) * 100) / 100;
    alertSolde.className = 'alert-solde warning show';
    alertSolde.innerHTML = `
      ❌ <strong>Solde ${devise} insuffisant.</strong><br>
      <strong>Montant demandé :</strong> ${fmt(montant, devise)}<br>
      <strong>Commission 1% :</strong> + ${fmt(commission, devise)}<br>
      <strong>Total requis :</strong> ${fmt(totalDebite, devise)}<br>
      <strong>Solde disponible :</strong> ${fmt(soldeDispo, devise)}<br>
      <span style="color:#FCA5A5;margin-top:8px;display:block">
        ⚠️ Il vous manque ${fmt(manquant, devise)}
      </span>
    `;
    hasError = true;
  } else if (!hasError) {
    alertSolde.className = 'alert-solde ok show';
    alertSolde.innerHTML = `✅ Solde suffisant · Reste après retrait : <strong>${fmt(soldeApres, devise)}</strong>`;
  }

  /* Afficher la simulation */
  document.getElementById('simMontant').textContent = fmt(montant, devise);
  document.getElementById('simCommission').textContent = '+ ' + fmt(commission, devise) + ' (du solde)';
  document.getElementById('simNet').textContent = fmt(montant, devise);
  document.getElementById('simSoldeApres').textContent = fmt(soldeApres, devise);
  simBox.style.display = 'block';

  btnSubmit.disabled = hasError;
  btnSubmit.style.opacity = hasError ? '.5' : '1';
  btnSubmit.style.cursor = hasError ? 'not-allowed' : 'pointer';
}

/* ============================================================
   MODALE
   ============================================================ */
function ouvrirModal() {
  const devise = deviseSelect.value;
  const montant = parseFloat(montantInput.value.toString().replace(',', '.')) || 0;

  if (montant <= 0) {
    alert('Veuillez saisir un montant valide');
    montantInput.focus();
    return;
  }

  if (btnSubmit.disabled) {
    alert('Veuillez corriger les erreurs avant de continuer');
    return;
  }

  const commission = Math.round(montant * COMMISSION * 100) / 100;
  const totalDebite = Math.round((montant + commission) * 100) / 100;

  document.getElementById('modalMontant').textContent = fmt(montant, devise);
  document.getElementById('modalCommission').textContent = '− ' + fmt(commission, devise);
  document.getElementById('modalNet').textContent = fmt(montant, devise);
  document.getElementById('modalTotalDebit').textContent = fmt(totalDebite, devise);

  modalError.classList.remove('show');
  pwdModal.value = '';
  modalOverlay.classList.add('active');
  document.body.style.overflow = 'hidden';
  setTimeout(() => pwdModal.focus(), 300);
}

function fermerModal() {
  modalOverlay.classList.remove('active');
  document.body.style.overflow = '';
  pwdModal.value = '';
  modalError.classList.remove('show');
}

document.getElementById('togglePwd').addEventListener('click', function() {
  if (pwdModal.type === 'password') {
    pwdModal.type = 'text';
    this.textContent = '🙈';
  } else {
    pwdModal.type = 'password';
    this.textContent = '👁';
  }
});

function confirmerRetrait() {
  const pwd = pwdModal.value.trim();

  if (pwd === '') {
    modalError.textContent = '⚠️ Veuillez saisir votre mot de passe';
    modalError.classList.add('show');
    pwdModal.focus();
    return;
  }

  if (pwd.length < 4) {
    modalError.textContent = '⚠️ Mot de passe trop court';
    modalError.classList.add('show');
    pwdModal.focus();
    return;
  }

  document.getElementById('motDePasseHidden').value = pwd;
  btnConfirm.disabled = true;
  btnConfirm.textContent = '⏳ Vérification...';
  document.getElementById('formRetrait').submit();
}

/* ============================================================
   ÉVÉNEMENTS
   ============================================================ */
deviseSelect.addEventListener('change', () => {
  montantInput.value = '';
  simulate();
  buildQuickAmounts(deviseSelect.value);
});

montantInput.addEventListener('input', (e) => {
  let v = e.target.value.replace(/[^\d.,]/g, '').replace(',', '.');
  const parts = v.split('.');
  if (parts.length > 2) v = parts[0] + '.' + parts.slice(1).join('');
  e.target.value = v;
  simulate();
});

pwdModal.addEventListener('keypress', (e) => {
  if (e.key === 'Enter') {
    e.preventDefault();
    confirmerRetrait();
  }
});

document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape' && modalOverlay.classList.contains('active')) {
    fermerModal();
  }
});

modalOverlay.addEventListener('click', (e) => {
  if (e.target === modalOverlay) fermerModal();
});

pwdModal.addEventListener('input', () => {
  modalError.classList.remove('show');
});

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

/* ============================================================
   INITIALISATION
   ============================================================ */
buildQuickAmounts(deviseSelect.value);
simulate();

console.log('%c🔐 Page retrait ATF', 'color:#F5B800;font-weight:bold;font-size:13px');
console.log('%c💰 Commission prélevée du solde', 'color:#FCA5A5;font-size:12px');
</script>

<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>