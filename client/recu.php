<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/nombres.php';
requireRole('client');

$pdo = Database::getConnection();
$client_id = (int)$_SESSION['client_id'];

/* ============================================================
   RÉCUPÉRATION DU REÇU PAR TOKEN
   ============================================================ */
$recu = null;

if (!empty($_GET['token'])) {
    $data = consommerToken('transaction', $_GET['token']);
    if ($data) {
        $stmt = $pdo->prepare("
            SELECT t.*, 
                   c.nom, c.postnom, c.prenom, c.numero_compte, c.numero_phone, c.adresse_complete,
                   ca.nom AS caissier_nom, ca.postnom AS caissier_postnom
            FROM Transactions t
            JOIN Client c ON c.id_client = t.id_client
            LEFT JOIN Caissier ca ON ca.id_caissier = t.id_caissier
            WHERE t.id_transaction = ? AND t.id_client = ?
        ");
        $stmt->execute([(int)$data['id'], $client_id]);
        $recu = $stmt->fetch();

        if ($recu) {
            $recu['caissier_nom_complet'] = trim(($recu['caissier_nom'] ?? '') . ' ' . ($recu['caissier_postnom'] ?? ''));
        }
    }
}

if (!$recu) {
    $_SESSION['flash_error'] = "Reçu introuvable ou expiré.";
    header('Location: ' . url('client/transactions.php'));
    exit;
}

/* Audit */
logAction($pdo, 'client', $client_id, 'RECU_VIEW', "Ref: {$recu['reference']}");

/* Variables pour la vue */
$type_op    = $recu['type_transaction'] ?? 'Depot';
$montant    = (float)($recu['montant'] ?? 0);
$devise     = $recu['devise'] ?? 'CDF';
$reference  = $recu['reference'] ?? '—';
$date_op    = $recu['date_transaction'] ?? date('Y-m-d H:i:s');
$solde_avant = (float)($recu['solde_avant'] ?? 0);
$solde_apres = (float)($recu['solde_apres'] ?? 0);
$mode_op    = $recu['mode'] ?? 'Agence';
$effectue_par = $recu['effectue_par'] ?? 'Client';
$note       = $recu['description'] ?? '';

$nom_client = trim(($recu['nom'] ?? '') . ' ' . ($recu['postnom'] ?? '') . ' ' . ($recu['prenom'] ?? ''));
$nom_caissier = $recu['caissier_nom_complet'] ?: 'AFRICAN TRUST FUND';

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reçu <?= htmlspecialchars($reference) ?> — AFRICAN TRUST FUND</title>
<style>
@page { size: A5 portrait; margin: 10mm; }
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
  color: #1a1a1a;
  background: #e8e8e8;
  padding: 30px 20px;
  font-size: 11px;
  line-height: 1.5;
}
.receipt {
  max-width: 700px;
  margin: 0 auto;
  background: #fff;
  padding: 30px 36px;
  box-shadow: 0 8px 40px rgba(0,0,0,.15);
  border-radius: 4px;
  border-top: 6px solid #0D1B5E;
  position: relative;
  overflow: hidden;
}
.receipt::after {
  content: "CLIENT";
  position: absolute;
  top: 50%; left: 50%;
  transform: translate(-50%, -50%) rotate(-30deg);
  font-size: 100px;
  font-weight: 900;
  color: rgba(13,27,94,.04);
  letter-spacing: 10px;
  pointer-events: none;
  z-index: 0;
}
.receipt > * { position: relative; z-index: 1; }

.receipt-header {
  display: flex;
  justify-content: space-between;
  gap: 20px;
  align-items: center;
  padding-bottom: 14px;
  border-bottom: 3px double #F5B800;
  margin-bottom: 12px;
}
.rh-left { display: flex; align-items: center; gap: 14px; }
.receipt-logo {
  width: 60px; height: 60px; object-fit: contain;
  border: 2px solid #F5B800; border-radius: 50%;
  padding: 4px; background: #fff;
}
.receipt-brand-name {
  font-size: 18px; font-weight: 900; color: #0D1B5E;
  letter-spacing: 1.5px; line-height: 1.1;
}
.receipt-brand-slogan { font-size: 9px; color: #666; font-style: italic; margin-top: 3px; }
.receipt-brand-legal { font-size: 8px; color: #888; margin-top: 2px; }
.rh-right { text-align: right; font-size: 9px; color: #444; line-height: 1.6; }

.receipt-title {
  display: flex; justify-content: space-between; align-items: center;
  padding: 12px 16px;
  background: linear-gradient(90deg, #0D1B5E, #1E293B);
  color: #fff; border-radius: 8px; margin-bottom: 18px;
}
.receipt-title h1 {
  font-size: 14px; font-weight: 900; letter-spacing: 2px;
  text-transform: uppercase; color: #F5B800;
}
.receipt-title .ref-box {
  font-family: 'Courier New', monospace; font-size: 11px;
  background: rgba(245,184,0,.15); color: #F5B800;
  padding: 4px 12px; border-radius: 6px;
  border: 1px solid rgba(245,184,0,.3); font-weight: 700;
}

.amount-badge {
  text-align: center; padding: 22px; border-radius: 14px;
  margin-bottom: 20px; position: relative; overflow: hidden;
}
.amount-badge.depot { background: linear-gradient(135deg, #10B981, #059669); color: #fff; }
.amount-badge.retrait { background: linear-gradient(135deg, #EF4444, #B91C1C); color: #fff; }
.amount-badge .type-label { font-size: 12px; letter-spacing: 3px; font-weight: 700; opacity: .95; }
.amount-badge .montant-big {
  font-size: 34px; font-weight: 900; margin-top: 8px;
  letter-spacing: 1px; line-height: 1;
}
.amount-badge .montant-words { font-size: 10px; font-style: italic; opacity: .9; margin-top: 8px; }

.info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 18px; }
.info-block {
  border: 1px solid #e5e5e5; border-radius: 10px;
  padding: 12px 14px; background: #fafafa;
}
.info-block h3 {
  font-size: 10px; color: #0D1B5E; text-transform: uppercase;
  letter-spacing: 1px; margin-bottom: 8px; padding-bottom: 6px;
  border-bottom: 1px solid #ddd; font-weight: 800;
}
.info-block table { width: 100%; border-collapse: collapse; font-size: 10px; }
.info-block td { padding: 4px 0; vertical-align: top; }
.info-block td:first-child { color: #666; width: 42%; }
.info-block td:last-child { font-weight: 700; color: #1a1a1a; }

.solde-flow {
  display: grid; grid-template-columns: 1fr auto 1fr; gap: 14px;
  align-items: center; padding: 16px 20px;
  background: linear-gradient(135deg, #f9f9f9, #f0f0f0);
  border-radius: 12px; border: 1px solid #e5e5e5; margin-bottom: 18px;
}
.solde-item { text-align: center; }
.solde-item .lbl { font-size: 9px; color: #666; text-transform: uppercase; letter-spacing: 1px; font-weight: 700; }
.solde-item .val { font-size: 16px; font-weight: 800; margin-top: 4px; color: #0D1B5E; }
.solde-item.apres .val { color: #F5B800; font-size: 20px; }
.solde-arrow { font-size: 22px; color: #F5B800; font-weight: 900; }

.receipt-footer {
  margin-top: 20px; padding-top: 12px;
  border-top: 2px solid #F5B800;
  font-size: 8px; color: #666; text-align: center; line-height: 1.6;
}
.receipt-footer strong { color: #0D1B5E; }

.action-bar {
  position: fixed; top: 20px; right: 20px;
  display: flex; gap: 12px; z-index: 9999;
  background: rgba(11,14,20,.9); padding: 10px 12px;
  border-radius: 999px; backdrop-filter: blur(12px);
  box-shadow: 0 8px 32px rgba(0,0,0,.5);
  border: 1px solid rgba(245,184,0,.25);
}
.btn-action {
  padding: 12px 22px; border-radius: 999px; border: none;
  font-weight: 700; cursor: pointer; font-size: 13px;
  display: inline-flex; align-items: center; gap: 8px;
  text-decoration: none; transition: all .25s ease;
  font-family: inherit;
}
.btn-action:hover { transform: translateY(-2px); }
.btn-print {
  background: linear-gradient(135deg, #F5B800, #D4AF37);
  color: #0B0E14;
  box-shadow: 0 6px 20px rgba(245,184,0,.45);
}
.btn-back {
  background: rgba(255,255,255,.1); color: #E8ECF3;
  border: 1px solid rgba(255,255,255,.2);
}

@media print {
  html, body { background: #fff !important; padding: 0 !important; margin: 0 !important; }
  .receipt { box-shadow: none; border-radius: 0; padding: 0; max-width: 100%; }
  .action-bar { display: none !important; }
  * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
  @page { margin: 1cm; }
}
@media screen and (max-width: 768px) {
  body { padding: 20px 10px; }
  .receipt { padding: 20px 16px; }
  .receipt-header { flex-direction: column; text-align: center; }
  .rh-left { flex-direction: column; }
  .info-grid { grid-template-columns: 1fr; }
  .action-bar { top: auto; bottom: 20px; left: 10px; right: 10px; justify-content: center; border-radius: 20px; }
  .btn-action { font-size: 11px; padding: 10px 14px; }
}
</style>
</head>
<body>

<div class="action-bar">
  <a class="btn-action btn-back" href="<?= $base ?>/client/transactions.php">← Mes transactions</a>
  <button class="btn-action btn-print" onclick="window.print()" type="button">🖨️ Télécharger mon reçu</button>
</div>

<div class="receipt">
  <div class="receipt-header">
    <div class="rh-left">
      <img src="<?= $base ?>/assets/img/logo.png" alt="ATF" class="receipt-logo">
      <div>
        <div class="receipt-brand-name">AFRICAN TRUST FUND</div>
        <div class="receipt-brand-slogan">« L'argent de l'Afrique pour construire l'Afrique »</div>
        <div class="receipt-brand-legal">Société de Gestion d'Actifs(S.G.A)</div>
      </div>
    </div>
    <div class="rh-right">
      <strong style="color:#0D1B5E;font-size:10px">REPUBLIQUE DEMOCRATIQUE DU CONGO</strong><br>
      RCCM : CD/KIN/RCCM/24-B-01234<br>
      NIF : A0123456Z
    </div>
  </div>

  <div class="receipt-title">
    <h1>🧾 Reçu d'Opération</h1>
    <div class="ref-box"><?= htmlspecialchars($reference) ?></div>
  </div>

  <div class="amount-badge <?= strtolower($type_op) ?>">
    <div class="type-label">
      <?php if ($type_op === 'Depot'): ?>➕ DÉPÔT EFFECTUÉ
      <?php elseif ($type_op === 'Retrait'): ?>➖ RETRAIT EFFECTUÉ
      <?php else: ?>⇄ VIREMENT<?php endif; ?>
    </div>
    <div class="montant-big"><?= number_format($montant, 2, ',', ' ') ?> <?= htmlspecialchars($devise) ?></div>
    <div class="montant-words"><?= htmlspecialchars(mb_strtoupper(nombreEnLettres($montant))) ?> <?= htmlspecialchars($devise) ?></div>
  </div>

  <div class="info-grid">
    <div class="info-block">
      <h3>👤 Client</h3>
      <table>
        <tr><td>Nom</td><td><?= htmlspecialchars($nom_client) ?></td></tr>
        <tr><td>Compte</td><td><strong><?= htmlspecialchars($recu['numero_compte'] ?? '—') ?></strong></td></tr>
        <tr><td>Téléphone</td><td><?= htmlspecialchars($recu['numero_phone'] ?? '—') ?></td></tr>
      </table>
    </div>
    <div class="info-block">
      <h3>💼 Opération</h3>
      <table>
        <tr><td>Date</td><td><?= date('d/m/Y à H:i', strtotime($date_op)) ?></td></tr>
        <tr><td>Mode</td><td><?= htmlspecialchars($mode_op) ?></td></tr>
        <tr><td>Par</td><td><strong><?= htmlspecialchars($nom_caissier) ?></strong></td></tr>
      </table>
    </div>
  </div>

  <div class="solde-flow">
    <div class="solde-item">
      <div class="lbl">Solde avant</div>
      <div class="val"><?= number_format($solde_avant, 2, ',', ' ') ?></div>
    </div>
    <div class="solde-arrow">→</div>
    <div class="solde-item apres">
      <div class="lbl">Nouveau solde</div>
      <div class="val"><?= number_format($solde_apres, 2, ',', ' ') ?></div>
    </div>
  </div>

  <div class="receipt-footer">
    <strong>AFRICAN TRUST FUND</strong> — Document à conserver · Réf. : <?= htmlspecialchars($reference) ?><br>
    Édité le <?= date('d/m/Y à H:i:s') ?> · contact@africantrustfund.cd
  </div>
</div>

<script>
document.addEventListener('keydown', (e) => {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'p') {
    e.preventDefault();
    window.print();
  }
});
</script>
</body>
</html>