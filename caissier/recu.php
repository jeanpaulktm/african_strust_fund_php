<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
requireRole('caissier');

$pdo = Database::getConnection();
$caissier_id = (int)$_SESSION['caissier_id'];

/* ============================================================
   RÉCUPÉRATION SÉCURISÉE DU REÇU
   ============================================================ */
$recu = null;

/* Cas A : depuis la session (après opération) */
if (!empty($_SESSION['recu_data'])) {
    $recu = $_SESSION['recu_data'];
    unset($_SESSION['recu_data']);
}
/* Cas B : depuis un token (re-impression) */
elseif (!empty($_GET['token'])) {
    $data = consommerToken('transaction', $_GET['token']);
    if ($data) {
        $stmt = $pdo->prepare("
            SELECT t.*, 
                   c.nom, c.postnom, c.prenom, c.numero_compte, c.numero_phone, c.adresse_complete, c.photo_profil,
                   ca.nom AS caissier_nom, ca.postnom AS caissier_postnom, ca.numero_phone AS caissier_phone
            FROM Transactions t
            JOIN Client c ON c.id_client = t.id_client
            LEFT JOIN Caissier ca ON ca.id_caissier = t.id_caissier
            WHERE t.id_transaction = ?
        ");
        $stmt->execute([(int)$data['id']]);
        $recu = $stmt->fetch();

        if ($recu) {
            $recu['caissier_nom_complet'] = trim(($recu['caissier_nom'] ?? '') . ' ' . ($recu['caissier_postnom'] ?? ''));
        }
    }
}

if (!$recu) {
    $_SESSION['flash_error'] = "Reçu introuvable ou expiré.";
    header('Location: ' . url('caissier/dashboard.php'));
    exit;
}

/* ============================================================
   PRÉPARATION DES VARIABLES
   ============================================================ */
$type_op    = $recu['type_transaction'] ?? 'Depot';
$montant    = (float)($recu['montant'] ?? 0);
$devise     = $recu['devise'] ?? 'CDF';
$reference  = $recu['reference'] ?? '—';
$date_op    = $recu['date_transaction'] ?? date('Y-m-d H:i:s');
$solde_avant = (float)($recu['solde_avant'] ?? 0);
$solde_apres = (float)($recu['solde_apres'] ?? 0);
$mode_op    = $recu['mode'] ?? 'Agence';
$effectue_par = $recu['effectue_par'] ?? 'Caissier';
$note       = $recu['description'] ?? '';

/* Nom complet du client */
$nom_client = trim(($recu['nom'] ?? '') . ' ' . ($recu['postnom'] ?? '') . ' ' . ($recu['prenom'] ?? ''));

/* Nom du caissier */
$nom_caissier = $recu['caissier_nom_complet'] ?? ($_SESSION['caissier_nom'] ?? 'Caissier ATF');

/* ============================================================
   AUDIT
   ============================================================ */
logAction($pdo, 'caissier', $caissier_id, 'RECU_PRINT', "Ref: $reference | Type: $type_op");

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reçu <?= htmlspecialchars($reference) ?> — AFRICAN TRUST FUND</title>
<style>
/* ============================================================
   CONFIGURATION IMPRESSION (2 reçus par page A4)
   ============================================================ */
@page {
  size: A4 portrait;
  margin: 8mm;
}

* { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
  color: #1a1a1a;
  background: #e8e8e8;
  padding: 30px 20px;
  font-size: 11px;
  line-height: 1.4;
}

/* ============================================================
   CHAQUE REÇU (1 exemplaire)
   ============================================================ */
.receipt {
  max-width: 850px;
  margin: 0 auto 30px;
  background: #fff;
  padding: 28px 34px;
  box-shadow: 0 6px 30px rgba(0,0,0,.15);
  border-radius: 4px;
  position: relative;
  overflow: hidden;
  border-top: 6px solid #0D1B5E;
}

/* Filigrane "EXEMPLAIRE" */
.receipt::after {
  content: attr(data-copy);
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%) rotate(-30deg);
  font-size: 90px;
  font-weight: 900;
  color: rgba(13,27,94,.045);
  letter-spacing: 8px;
  pointer-events: none;
  white-space: nowrap;
  z-index: 0;
}

.receipt > * { position: relative; z-index: 1; }

/* ============================================================
   EN-TÊTE
   ============================================================ */
.receipt-header {
  display: flex;
  justify-content: space-between;
  gap: 20px;
  align-items: center;
  padding-bottom: 14px;
  border-bottom: 3px double #F5B800;
  margin-bottom: 10px;
}

.rh-left {
  display: flex;
  align-items: center;
  gap: 14px;
}

.receipt-logo {
  width: 60px;
  height: 60px;
  object-fit: contain;
  border: 2px solid #F5B800;
  border-radius: 50%;
  padding: 4px;
  background: #fff;
}

.receipt-brand-name {
  font-size: 18px;
  font-weight: 900;
  color: #0D1B5E;
  letter-spacing: 1.5px;
  line-height: 1.1;
}
.receipt-brand-slogan {
  font-size: 9px;
  color: #666;
  font-style: italic;
  margin-top: 3px;
}
.receipt-brand-legal {
  font-size: 8px;
  color: #888;
  margin-top: 2px;
}

.rh-right {
  text-align: right;
  font-size: 9px;
  color: #444;
  line-height: 1.6;
}

/* ============================================================
   TITRE + NUMÉRO
   ============================================================ */
.receipt-title {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 16px;
  background: linear-gradient(90deg, #0D1B5E, #1E293B);
  color: #fff;
  border-radius: 8px;
  margin-bottom: 18px;
}
.receipt-title h1 {
  font-size: 14px;
  font-weight: 900;
  letter-spacing: 2px;
  text-transform: uppercase;
  color: #F5B800;
}
.receipt-title .ref-box {
  font-family: 'Courier New', monospace;
  font-size: 11px;
  background: rgba(245,184,0,.15);
  color: #F5B800;
  padding: 4px 12px;
  border-radius: 6px;
  border: 1px solid rgba(245,184,0,.3);
  font-weight: 700;
}

/* ============================================================
   BADGE MONTANT
   ============================================================ */
.amount-badge {
  text-align: center;
  padding: 22px;
  border-radius: 14px;
  margin-bottom: 20px;
  position: relative;
  overflow: hidden;
}
.amount-badge::before {
  content: "";
  position: absolute;
  top: -50%; right: -10%;
  width: 240px; height: 240px;
  background: radial-gradient(circle, rgba(255,255,255,.15), transparent 70%);
  border-radius: 50%;
}
.amount-badge.depot {
  background: linear-gradient(135deg, #10B981, #059669);
  color: #fff;
  box-shadow: 0 8px 24px rgba(16,185,129,.35);
}
.amount-badge.retrait {
  background: linear-gradient(135deg, #EF4444, #B91C1C);
  color: #fff;
  box-shadow: 0 8px 24px rgba(239,68,68,.35);
}
.amount-badge .type-label {
  font-size: 12px;
  letter-spacing: 3px;
  font-weight: 700;
  opacity: .95;
  position: relative;
  z-index: 1;
}
.amount-badge .montant-big {
  font-size: 34px;
  font-weight: 900;
  margin-top: 8px;
  letter-spacing: 1px;
  line-height: 1;
  position: relative;
  z-index: 1;
  text-shadow: 0 2px 8px rgba(0,0,0,.2);
}
.amount-badge .montant-words {
  font-size: 10px;
  font-style: italic;
  opacity: .9;
  margin-top: 8px;
  position: relative;
  z-index: 1;
}

/* ============================================================
   GRILLE D'INFOS
   ============================================================ */
.info-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
  margin-bottom: 18px;
}

.info-block {
  border: 1px solid #e5e5e5;
  border-radius: 10px;
  padding: 12px 14px;
  background: #fafafa;
}
.info-block h3 {
  font-size: 10px;
  color: #0D1B5E;
  text-transform: uppercase;
  letter-spacing: 1px;
  margin-bottom: 8px;
  padding-bottom: 6px;
  border-bottom: 1px solid #ddd;
  font-weight: 800;
}
.info-block table {
  width: 100%;
  border-collapse: collapse;
  font-size: 10px;
}
.info-block td {
  padding: 4px 0;
  vertical-align: top;
}
.info-block td:first-child {
  color: #666;
  width: 42%;
}
.info-block td:last-child {
  font-weight: 700;
  color: #1a1a1a;
}
.info-block td strong { color: #0D1B5E; }

/* ============================================================
   SOLDE AVANT / APRÈS
   ============================================================ */
.solde-flow {
  display: grid;
  grid-template-columns: 1fr auto 1fr;
  gap: 14px;
  align-items: center;
  margin-bottom: 18px;
  padding: 16px 20px;
  background: linear-gradient(135deg, #f9f9f9, #f0f0f0);
  border-radius: 12px;
  border: 1px solid #e5e5e5;
}
.solde-item { text-align: center; }
.solde-item .lbl {
  font-size: 9px;
  color: #666;
  text-transform: uppercase;
  letter-spacing: 1px;
  font-weight: 700;
}
.solde-item .val {
  font-size: 16px;
  font-weight: 800;
  margin-top: 4px;
  color: #0D1B5E;
}
.solde-item.apres .val {
  color: #F5B800;
  font-size: 20px;
  text-shadow: 0 0 15px rgba(245,184,0,.25);
}
.solde-arrow {
  font-size: 22px;
  color: #F5B800;
  font-weight: 900;
}

/* ============================================================
   NOTES
   ============================================================ */
.notes-block {
  padding: 10px 14px;
  background: #FFF8E1;
  border-left: 4px solid #F5B800;
  border-radius: 6px;
  font-size: 10px;
  color: #555;
  margin-bottom: 18px;
}

/* ============================================================
   SIGNATURES
   ============================================================ */
.signatures {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 50px;
  margin-top: 22px;
  padding-top: 14px;
  border-top: 1px dashed #ccc;
}
.sig-box { text-align: center; }
.sig-box .sig-label {
  font-size: 10px;
  color: #666;
  margin-bottom: 40px;
  text-transform: uppercase;
  letter-spacing: 1px;
  font-weight: 700;
}
.sig-box .sig-line {
  border-top: 1px solid #333;
  padding-top: 5px;
  font-size: 10px;
  color: #0D1B5E;
  font-weight: 700;
}

/* ============================================================
   PIED DE PAGE
   ============================================================ */
.receipt-footer {
  margin-top: 20px;
  padding-top: 12px;
  border-top: 2px solid #F5B800;
  font-size: 8px;
  color: #666;
  text-align: center;
  line-height: 1.6;
}
.receipt-footer strong { color: #0D1B5E; }
.receipt-footer .footer-coords {
  margin-bottom: 6px;
  font-size: 9px;
  color: #444;
}

/* ============================================================
   SÉPARATEUR ENTRE LES 2 REÇUS
   ============================================================ */
.receipt-separator {
  max-width: 850px;
  margin: 0 auto 30px;
  text-align: center;
  padding: 10px;
  color: #999;
  font-size: 10px;
  letter-spacing: 4px;
  position: relative;
}
.receipt-separator::before,
.receipt-separator::after {
  content: "";
  position: absolute;
  top: 50%;
  width: 40%;
  height: 1px;
  background: repeating-linear-gradient(90deg, #ccc 0 8px, transparent 8px 16px);
}
.receipt-separator::before { left: 0; }
.receipt-separator::after { right: 0; }

/* ============================================================
   BARRE D'ACTIONS
   ============================================================ */
.action-bar {
  position: fixed;
  top: 20px;
  right: 20px;
  display: flex;
  gap: 12px;
  z-index: 9999;
  background: rgba(11,14,20,.9);
  padding: 10px 12px;
  border-radius: 999px;
  backdrop-filter: blur(12px);
  box-shadow: 0 8px 32px rgba(0,0,0,.5);
  border: 1px solid rgba(245,184,0,.25);
  flex-wrap: wrap;
}
.btn-action {
  padding: 12px 22px;
  border-radius: 999px;
  border: none;
  font-weight: 700;
  cursor: pointer;
  font-size: 13px;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  text-decoration: none;
  transition: all .25s ease;
  font-family: inherit;
  white-space: nowrap;
}
.btn-action:hover { transform: translateY(-2px); }
.btn-action:active { transform: scale(.95); }

.btn-print {
  background: linear-gradient(135deg, #F5B800, #D4AF37);
  color: #0B0E14;
  box-shadow: 0 6px 20px rgba(245,184,0,.45);
}
.btn-back {
  background: rgba(255,255,255,.1);
  color: #E8ECF3;
  border: 1px solid rgba(255,255,255,.2);
}
.btn-back:hover { background: rgba(255,255,255,.2); }

/* ============================================================
   IMPRESSION
   ============================================================ */
@media print {
  html, body {
    background: #fff !important;
    padding: 0 !important;
    margin: 0 !important;
    font-size: 10pt;
  }
  .receipt {
    box-shadow: none !important;
    border-radius: 0 !important;
    padding: 20px 24px !important;
    max-width: 100% !important;
    margin: 0 0 8mm 0 !important;
    page-break-inside: avoid;
    page-break-after: always;
  }
  .receipt:last-of-type { page-break-after: auto; }

  .action-bar, .no-print, .receipt-separator {
    display: none !important;
  }

  * {
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
  }

  .receipt-title,
  .amount-badge,
  .solde-item.apres .val {
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
  }
}

@media screen and (max-width: 768px) {
  body { padding: 20px 10px; }
  .receipt { padding: 20px 16px; }
  .receipt-header { flex-direction: column; text-align: center; }
  .rh-left { flex-direction: column; }
  .rh-right { text-align: center; }
  .info-grid { grid-template-columns: 1fr; }
  .signatures { grid-template-columns: 1fr; gap: 30px; }
  .action-bar {
    top: auto; bottom: 20px;
    left: 10px; right: 10px;
    justify-content: center;
    border-radius: 20px;
  }
  .btn-action { font-size: 11px; padding: 10px 14px; }
}
</style>
</head>
<body>

<!-- Barre d'actions -->
<div class="action-bar no-print">
  <a class="btn-action btn-back" href="<?= $base ?>/caissier/dashboard.php">← Retour</a>
  <button class="btn-action btn-print" onclick="printDocument()" type="button">🖨️ Imprimer (2 exemplaires)</button>
</div>

<!-- ============================================================
     EXEMPLAIRE 1 : CLIENT
     ============================================================ -->
<div class="receipt" data-copy="CLIENT">
  <!-- En-tête -->
  <div class="receipt-header">
    <div class="rh-left">
      <img src="<?= $base ?>/assets/img/logo.png" alt="ATF" class="receipt-logo"
           onerror="this.src='data:image/svg+xml;utf8,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><circle cx=%2250%22 cy=%2250%22 r=%2245%22 fill=%22%23F5B800%22/><text x=%2250%22 y=%2262%22 font-size=%2234%22 text-anchor=%22middle%22 fill=%22%230B0E14%22 font-weight=%22bold%22 font-family=%22Arial%22>ATF</text></svg>'">
      <div>
        <div class="receipt-brand-name">AFRICAN TRUST FUND</div>
        <div class="receipt-brand-slogan">« L'argent de l'Afrique pour construire l'Afrique »</div>
        <div class="receipt-brand-legal">Société Coopérative d'Épargne et de Crédit (COOPEC)</div>
      </div>
    </div>
    <div class="rh-right">
      <strong style="color:#0D1B5E;font-size:10px">République Démocratique du Congo</strong><br>
      RCCM : CD/KIN/RCCM/24-B-01234<br>
      NIF : A0123456Z<br>
      Agrément BCC : 045/2024
    </div>
  </div>

  <!-- Titre -->
  <div class="receipt-title">
    <h1>🧾 Reçu d'Opération</h1>
    <div class="ref-box"><?= htmlspecialchars($reference) ?></div>
  </div>

  <!-- Badge montant -->
  <div class="amount-badge <?= strtolower($type_op) ?>">
    <div class="type-label">
      <?php if ($type_op === 'Depot'): ?>
        ➕ DÉPÔT EFFECTUÉ
      <?php elseif ($type_op === 'Retrait'): ?>
        ➖ RETRAIT EFFECTUÉ
      <?php else: ?>
        ⇄ VIREMENT
      <?php endif; ?>
    </div>
    <div class="montant-big">
      <?= number_format($montant, 2, ',', ' ') ?> <?= htmlspecialchars($devise) ?>
    </div>
    <div class="montant-words">
      <?= htmlspecialchars(mb_strtoupper(nombreEnLettres($montant))) ?> <?= htmlspecialchars($devise) ?>
    </div>
  </div>

  <!-- Infos client + opération -->
  <div class="info-grid">
    <div class="info-block">
      <h3>👤 Client</h3>
      <table>
        <tr><td>Nom complet</td><td><?= htmlspecialchars($nom_client) ?></td></tr>
        <tr><td>N° de compte</td><td><strong><?= htmlspecialchars($recu['numero_compte'] ?? '—') ?></strong></td></tr>
        <tr><td>Téléphone</td><td><?= htmlspecialchars($recu['numero_phone'] ?? '—') ?></td></tr>
        <tr><td>Adresse</td><td><?= htmlspecialchars(mb_substr($recu['adresse_complete'] ?? '—', 0, 50)) ?></td></tr>
      </table>
    </div>
    <div class="info-block">
      <h3>💼 Opération</h3>
      <table>
        <tr><td>Date & heure</td><td><?= date('d/m/Y à H:i', strtotime($date_op)) ?></td></tr>
        <tr><td>Mode</td><td><?= htmlspecialchars($mode_op) ?></td></tr>
        <tr><td>Effectué par</td><td><?= htmlspecialchars($effectue_par) ?></td></tr>
        <tr><td>Caissier</td><td><strong><?= htmlspecialchars($nom_caissier) ?></strong></td></tr>
      </table>
    </div>
  </div>

  <!-- Solde avant/après -->
  <div class="solde-flow">
    <div class="solde-item">
      <div class="lbl">Solde avant</div>
      <div class="val"><?= number_format($solde_avant, 2, ',', ' ') ?> <?= htmlspecialchars($devise) ?></div>
    </div>
    <div class="solde-arrow">→</div>
    <div class="solde-item apres">
      <div class="lbl">Nouveau solde</div>
      <div class="val"><?= number_format($solde_apres, 2, ',', ' ') ?> <?= htmlspecialchars($devise) ?></div>
    </div>
  </div>

  <!-- Notes -->
  <?php if (!empty($note)): ?>
    <div class="notes-block">
      <strong>📝 Observation :</strong> <?= htmlspecialchars($note) ?>
    </div>
  <?php endif; ?>

  <!-- Signatures -->
  <div class="signatures">
    <div class="sig-box">
      <div class="sig-label">Signature du client</div>
      <div class="sig-line"><?= htmlspecialchars($nom_client) ?></div>
    </div>
    <div class="sig-box">
      <div class="sig-label">Cachet & signature</div>
      <div class="sig-line"><?= htmlspecialchars($nom_caissier) ?></div>
    </div>
  </div>

  <!-- Pied de page -->
  <div class="receipt-footer">
    <div class="footer-coords">
      <strong>Rawbank</strong> — Agence Kinshasa-Gombe · Compte CDF : <strong>00012-12345-6789012-34</strong> · SWIFT : <strong>RAWBCDKI</strong><br>
      Avenue de la Paix n°45, Gombe, Kinshasa, RDC · Tél : +243 123 456 789 · www.africantrustfund.cd
    </div>
    Ce reçu fait foi de l'opération ci-dessus · Conservez ce document précieusement<br>
    Réf. : <?= htmlspecialchars($reference) ?> · Édité le <?= date('d/m/Y à H:i:s') ?>
  </div>
</div>

<!-- Séparateur visuel (masqué à l'impression) -->
<div class="receipt-separator">✂️ COUPER ICI ✂️</div>

<!-- ============================================================
     EXEMPLAIRE 2 : CAISSE (souche)
     ============================================================ -->
<div class="receipt" data-copy="CAISSE">
  <!-- En-tête -->
  <div class="receipt-header">
    <div class="rh-left">
      <img src="<?= $base ?>/assets/img/logo.png" alt="ATF" class="receipt-logo"
           onerror="this.src='data:image/svg+xml;utf8,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><circle cx=%2250%22 cy=%2250%22 r=%2245%22 fill=%22%23F5B800%22/><text x=%2250%22 y=%2262%22 font-size=%2234%22 text-anchor=%22middle%22 fill=%22%230B0E14%22 font-weight=%22bold%22 font-family=%22Arial%22>ATF</text></svg>'">
      <div>
        <div class="receipt-brand-name">AFRICAN TRUST FUND</div>
        <div class="receipt-brand-slogan">« L'argent de l'Afrique pour construire l'Afrique »</div>
        <div class="receipt-brand-legal">Société Coopérative d'Épargne et de Crédit (COOPEC)</div>
      </div>
    </div>
    <div class="rh-right">
      <strong style="color:#0D1B5E;font-size:10px">République Démocratique du Congo</strong><br>
      RCCM : CD/KIN/RCCM/24-B-01234<br>
      NIF : A0123456Z<br>
      Agrément BCC : 045/2024
    </div>
  </div>

  <!-- Titre -->
  <div class="receipt-title">
    <h1>🧾 Reçu d'Opération — SOUCHE CAISSE</h1>
    <div class="ref-box"><?= htmlspecialchars($reference) ?></div>
  </div>

  <!-- Badge montant -->
  <div class="amount-badge <?= strtolower($type_op) ?>">
    <div class="type-label">
      <?php if ($type_op === 'Depot'): ?>
        ➕ DÉPÔT EFFECTUÉ
      <?php elseif ($type_op === 'Retrait'): ?>
        ➖ RETRAIT EFFECTUÉ
      <?php else: ?>
        ⇄ VIREMENT
      <?php endif; ?>
    </div>
    <div class="montant-big">
      <?= number_format($montant, 2, ',', ' ') ?> <?= htmlspecialchars($devise) ?>
    </div>
    <div class="montant-words">
      <?= htmlspecialchars(mb_strtoupper(nombreEnLettres($montant))) ?> <?= htmlspecialchars($devise) ?>
    </div>
  </div>

  <!-- Infos client + opération -->
  <div class="info-grid">
    <div class="info-block">
      <h3>👤 Client</h3>
      <table>
        <tr><td>Nom complet</td><td><?= htmlspecialchars($nom_client) ?></td></tr>
        <tr><td>N° de compte</td><td><strong><?= htmlspecialchars($recu['numero_compte'] ?? '—') ?></strong></td></tr>
        <tr><td>Téléphone</td><td><?= htmlspecialchars($recu['numero_phone'] ?? '—') ?></td></tr>
        <tr><td>Adresse</td><td><?= htmlspecialchars(mb_substr($recu['adresse_complete'] ?? '—', 0, 50)) ?></td></tr>
      </table>
    </div>
    <div class="info-block">
      <h3>💼 Opération</h3>
      <table>
        <tr><td>Date & heure</td><td><?= date('d/m/Y à H:i', strtotime($date_op)) ?></td></tr>
        <tr><td>Mode</td><td><?= htmlspecialchars($mode_op) ?></td></tr>
        <tr><td>Effectué par</td><td><?= htmlspecialchars($effectue_par) ?></td></tr>
        <tr><td>Caissier</td><td><strong><?= htmlspecialchars($nom_caissier) ?></strong></td></tr>
      </table>
    </div>
  </div>

  <!-- Solde avant/après -->
  <div class="solde-flow">
    <div class="solde-item">
      <div class="lbl">Solde avant</div>
      <div class="val"><?= number_format($solde_avant, 2, ',', ' ') ?> <?= htmlspecialchars($devise) ?></div>
    </div>
    <div class="solde-arrow">→</div>
    <div class="solde-item apres">
      <div class="lbl">Nouveau solde</div>
      <div class="val"><?= number_format($solde_apres, 2, ',', ' ') ?> <?= htmlspecialchars($devise) ?></div>
    </div>
  </div>

  <!-- Notes -->
  <?php if (!empty($note)): ?>
    <div class="notes-block">
      <strong>📝 Observation :</strong> <?= htmlspecialchars($note) ?>
    </div>
  <?php endif; ?>

  <!-- Signatures -->
  <div class="signatures">
    <div class="sig-box">
      <div class="sig-label">Le Caissier</div>
      <div class="sig-line"><?= htmlspecialchars($nom_caissier) ?></div>
    </div>
    <div class="sig-box">
      <div class="sig-label">Visa Superviseur</div>
      <div class="sig-line">_______________________</div>
    </div>
  </div>

  <!-- Pied de page -->
  <div class="receipt-footer">
    <div class="footer-coords">
      <strong>Souche interne</strong> — À conserver dans les archives de la caisse · Réf. : <?= htmlspecialchars($reference) ?><br>
      AFRICAN TRUST FUND · Kinshasa-Gombe · contact@africantrustfund.cd
    </div>
    Édité le <?= date('d/m/Y à H:i:s') ?> · Document interne — Ne pas remettre au client
  </div>
</div>

<script>
/* ============================================================
   IMPRESSION
   ============================================================ */
function printDocument() {
  const originalTitle = document.title;
  document.title = 'Recu_<?= preg_replace('/[^A-Za-z0-9_-]/', '_', $reference) ?>';

  setTimeout(() => {
    window.print();
    setTimeout(() => { document.title = originalTitle; }, 1000);
  }, 100);
}

/* Raccourcis clavier */
document.addEventListener('keydown', (e) => {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'p') {
    e.preventDefault();
    printDocument();
  }
  if (e.key === 'Escape') {
    window.location.href = '<?= $base ?>/caissier/dashboard.php';
  }
});

/* Toast message */
function showToast(msg, duration = 4000) {
  const t = document.createElement('div');
  t.style.cssText = `
    position: fixed; bottom: 30px; left: 50%; transform: translateX(-50%) translateY(120%);
    background: linear-gradient(135deg, #F5B800, #D4AF37);
    color: #0B0E14; padding: 16px 28px; border-radius: 999px;
    font-weight: 700; font-size: 13px; box-shadow: 0 12px 40px rgba(245,184,0,.5);
    z-index: 99999; transition: transform .4s ease; font-family: Arial;
  `;
  t.textContent = msg;
  document.body.appendChild(t);
  requestAnimationFrame(() => t.style.transform = 'translateX(-50%) translateY(0)');
  setTimeout(() => {
    t.style.transform = 'translateX(-50%) translateY(120%)';
    setTimeout(() => t.remove(), 500);
  }, duration);
}

/* Auto-impression si ?auto=1 (après une opération) */
if (new URLSearchParams(location.search).has('auto')) {
  window.addEventListener('load', () => {
    setTimeout(() => {
      showToast('💡 Impression automatique dans 2 secondes...', 2000);
      setTimeout(() => printDocument(), 2000);
    }, 500);
  });
}
</script>

</body>
</html>