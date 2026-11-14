<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
requireRole('caissier');

$pdo = Database::getConnection();
$caissier_id = (int)$_SESSION['caissier_id'];

/* ============================================================
   DATE DU RAPPORT
   ============================================================ */
$date_rapport = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_rapport)) {
    $date_rapport = date('Y-m-d');
}

/* ============================================================
   RÉCUPÉRATION DES OPÉRATIONS
   ============================================================ */
$stmt = $pdo->prepare("
    SELECT t.*, c.nom, c.postnom, c.prenom, c.numero_compte, c.numero_phone
    FROM Transactions t
    JOIN Client c ON c.id_client = t.id_client
    WHERE t.id_caissier = ? AND DATE(t.date_transaction) = ?
    ORDER BY t.date_transaction ASC
");
$stmt->execute([$caissier_id, $date_rapport]);
$operations = $stmt->fetchAll();

/* ============================================================
   TOTAUX
   ============================================================ */
$total_depots = 0;
$total_retraits = 0;
$nb_depots = 0;
$nb_retraits = 0;
$montant_max = 0;

foreach ($operations as $op) {
    if ($op['type_transaction'] === 'Depot') {
        $total_depots += (float)$op['montant'];
        $nb_depots++;
    } elseif ($op['type_transaction'] === 'Retrait') {
        $total_retraits += (float)$op['montant'];
        $nb_retraits++;
    }
    if ((float)$op['montant'] > $montant_max) $montant_max = (float)$op['montant'];
}

/* ============================================================
   INFOS CAISSIER
   ============================================================ */
$stmt = $pdo->prepare("SELECT * FROM Caissier WHERE id_caissier = ?");
$stmt->execute([$caissier_id]);
$caissier = $stmt->fetch();

/* Audit */
logAction($pdo, 'caissier', $caissier_id, 'RAPPORT_PRINT',
    "Rapport du $date_rapport | " . count($operations) . " opérations");

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Rapport Journalier — <?= date('d/m/Y', strtotime($date_rapport)) ?> — AFRICAN TRUST FUND</title>
<style>
/* ============================================================
   CONFIGURATION IMPRESSION
   ============================================================ */
@page {
  size: A4;
  margin: 12mm 10mm;
}

* { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
  color: #1a1a1a;
  background: #e8e8e8;
  padding: 40px 20px;
  font-size: 11px;
  line-height: 1.5;
}

.document {
  max-width: 950px;
  margin: 0 auto;
  background: #fff;
  padding: 35px 45px;
  box-shadow: 0 8px 40px rgba(0,0,0,.18);
  border-radius: 4px;
  position: relative;
}

/* ============================================================
   EN-TÊTE OFFICIEL RDC + ATF
   ============================================================ */
.official-header {
  display: grid;
  grid-template-columns: 1fr 1.4fr;
  gap: 24px;
  align-items: center;
  padding-bottom: 16px;
  border-bottom: 3px double #0D1B5E;
  margin-bottom: 8px;
}

.rdc-block {
  text-align: center;
  font-size: 10px;
  line-height: 1.6;
  color: #0D1B5E;
}
.rdc-block .rep {
  font-weight: 900;
  font-size: 11px;
  letter-spacing: 1px;
  text-transform: uppercase;
}
.rdc-block .devise {
  font-style: italic;
  font-size: 10px;
  color: #444;
  margin-top: 2px;
}
.rdc-block .ministere {
  font-weight: 700;
  font-size: 10px;
  margin-top: 6px;
}

.atf-header-block {
  display: flex;
  align-items: center;
  gap: 16px;
  justify-content: flex-end;
}

.atf-logo-official {
  width: 75px;
  height: 75px;
  object-fit: contain;
  border: 3px solid #F5B800;
  border-radius: 50%;
  padding: 5px;
  background: #fff;
  box-shadow: 0 0 0 2px #0D1B5E inset;
}

.atf-brand-info { text-align: right; }
.atf-brand-name {
  font-size: 22px;
  font-weight: 900;
  color: #0D1B5E;
  letter-spacing: 2px;
  line-height: 1.1;
}
.atf-brand-slogan {
  font-size: 10px;
  color: #666;
  font-style: italic;
  margin-top: 4px;
}
.atf-brand-legal {
  font-size: 9px;
  color: #888;
  margin-top: 3px;
}

.separator-line {
  height: 2px;
  background: linear-gradient(90deg, #0D1B5E, #F5B800, #0D1B5E);
  margin: 12px 0;
}

/* ============================================================
   BANDEAU LÉGAL
   ============================================================ */
.legal-band {
  display: flex;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 8px;
  padding: 8px 14px;
  background: linear-gradient(90deg, #0D1B5E, #1E293B);
  color: #fff;
  font-size: 9px;
  border-radius: 4px;
  margin-bottom: 16px;
}
.legal-band span { white-space: nowrap; }
.legal-band strong { color: #F5B800; }

/* ============================================================
   TITRE
   ============================================================ */
.doc-title {
  text-align: center;
  margin: 22px 0 8px;
}
.doc-title h1 {
  font-size: 18px;
  color: #0D1B5E;
  font-weight: 900;
  letter-spacing: 3px;
  text-transform: uppercase;
  padding-bottom: 8px;
  border-bottom: 3px solid #F5B800;
  display: inline-block;
  min-width: 65%;
}
.doc-title .subtitle {
  font-size: 11px;
  color: #666;
  margin-top: 8px;
  font-style: italic;
}

/* ============================================================
   INFO RAPPORT
   ============================================================ */
.rapport-info {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 14px;
  margin: 22px 0 24px;
  padding: 16px 20px;
  background: #f9f9f9;
  border-radius: 10px;
  border-left: 5px solid #F5B800;
}
.rapport-info .item .lbl {
  color: #666;
  font-size: 9px;
  text-transform: uppercase;
  letter-spacing: 1px;
  font-weight: 600;
}
.rapport-info .item .val {
  color: #0D1B5E;
  font-weight: 800;
  font-size: 13px;
  margin-top: 4px;
}

/* ============================================================
   KPIs
   ============================================================ */
.kpi-row {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 14px;
  margin: 20px 0;
}
.kpi-box {
  padding: 18px 16px;
  border-radius: 12px;
  background: linear-gradient(145deg, #f9f9f9, #f0f0f0);
  border: 1px solid #e5e5e5;
  text-align: center;
  position: relative;
  overflow: hidden;
}
.kpi-box::before {
  content: "";
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 3px;
}
.kpi-box.gold::before { background: #F5B800; }
.kpi-box.green::before { background: #10B981; }
.kpi-box.red::before { background: #EF4444; }
.kpi-box.blue::before { background: #3B82F6; }
.kpi-box.gold { background: linear-gradient(145deg, rgba(245,184,0,.10), rgba(245,184,0,.02)); border-color: rgba(245,184,0,.4); }
.kpi-box.green { background: linear-gradient(145deg, rgba(16,185,129,.10), rgba(16,185,129,.02)); border-color: rgba(16,185,129,.4); }
.kpi-box.red { background: linear-gradient(145deg, rgba(239,68,68,.10), rgba(239,68,68,.02)); border-color: rgba(239,68,68,.4); }
.kpi-box.blue { background: linear-gradient(145deg, rgba(59,130,246,.10), rgba(59,130,246,.02)); border-color: rgba(59,130,246,.4); }
.kpi-box .kpi-lbl {
  font-size: 9px;
  color: #666;
  text-transform: uppercase;
  letter-spacing: 1px;
  font-weight: 700;
}
.kpi-box .kpi-val {
  font-size: 22px;
  font-weight: 900;
  color: #0D1B5E;
  margin-top: 6px;
  line-height: 1;
}
.kpi-box.gold .kpi-val { color: #B8860B; }
.kpi-box.green .kpi-val { color: #059669; }
.kpi-box.red .kpi-val { color: #DC2626; }
.kpi-box.blue .kpi-val { color: #2563EB; }

/* ============================================================
   SECTION TITRE
   ============================================================ */
.section-title {
  font-size: 12px;
  color: #0D1B5E;
  text-transform: uppercase;
  letter-spacing: 1.5px;
  font-weight: 800;
  margin: 28px 0 12px;
  padding-bottom: 8px;
  border-bottom: 2px solid #F5B800;
  display: flex;
  align-items: center;
  gap: 8px;
}

/* ============================================================
   TABLEAU
   ============================================================ */
table.ops {
  width: 100%;
  border-collapse: collapse;
  font-size: 10px;
}
table.ops thead {
  background: #0D1B5E;
  color: #fff;
}
table.ops th {
  padding: 10px 8px;
  text-align: left;
  font-weight: 700;
  font-size: 9px;
  text-transform: uppercase;
  letter-spacing: .5px;
  white-space: nowrap;
}
table.ops td {
  padding: 9px 8px;
  border-bottom: 1px solid #e5e5e5;
  vertical-align: middle;
}
table.ops tbody tr:nth-child(even) { background: #fafafa; }
table.ops tbody tr:hover { background: #FFF8E1; }
table.ops .amount-pos { color: #059669; font-weight: 800; }
table.ops .amount-neg { color: #DC2626; font-weight: 800; }
table.ops .empty {
  text-align: center;
  color: #999;
  font-style: italic;
  padding: 40px;
  font-size: 11px;
}
table.ops tfoot {
  background: #FFF8E1;
  font-weight: 800;
  color: #0D1B5E;
}
table.ops tfoot td {
  border-top: 3px double #F5B800;
  padding: 12px 8px;
  font-size: 11px;
}

/* ============================================================
   SYNTHÈSE
   ============================================================ */
.synthese {
  margin-top: 24px;
  padding: 20px 24px;
  background: linear-gradient(135deg, #0D1B5E, #1E293B);
  color: #fff;
  border-radius: 12px;
  position: relative;
  overflow: hidden;
}
.synthese::before {
  content: "";
  position: absolute;
  top: -50%; right: -10%;
  width: 250px; height: 250px;
  background: radial-gradient(circle, rgba(245,184,0,.15), transparent 70%);
  border-radius: 50%;
}
.synthese h3 {
  font-size: 12px;
  color: #F5B800;
  margin-bottom: 14px;
  letter-spacing: 2px;
  text-transform: uppercase;
  position: relative;
  z-index: 1;
}
.synthese-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
  font-size: 11px;
  position: relative;
  z-index: 1;
}
.synthese-grid .line {
  display: flex;
  justify-content: space-between;
  padding: 8px 0;
  border-bottom: 1px solid rgba(255,255,255,.1);
}
.synthese-grid .line .k { color: #8A93A6; }
.synthese-grid .line .v { color: #F5B800; font-weight: 800; font-size: 12px; }

/* ============================================================
   SIGNATURES
   ============================================================ */
.signatures {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 30px;
  margin-top: 40px;
  padding-top: 20px;
  border-top: 2px dashed #ccc;
  page-break-inside: avoid;
}
.signature-item { text-align: center; }
.signature-item .sig-title {
  font-size: 10px;
  color: #666;
  text-transform: uppercase;
  letter-spacing: 1.2px;
  margin-bottom: 55px;
  font-weight: 600;
}
.signature-item .sig-line {
  border-top: 1px solid #333;
  padding-top: 6px;
  font-size: 10px;
  font-weight: 700;
  color: #0D1B5E;
}

/* ============================================================
   PIED DE PAGE OFFICIEL
   ============================================================ */
.print-footer {
  margin-top: 30px;
  padding-top: 16px;
  border-top: 3px double #F5B800;
  font-size: 9px;
  color: #555;
  page-break-inside: avoid;
}
.footer-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
  margin-bottom: 14px;
}
.footer-block {
  padding: 10px 14px;
  background: #f9f9f9;
  border-radius: 8px;
  border-left: 3px solid #F5B800;
  line-height: 1.7;
}
.footer-block strong {
  color: #0D1B5E;
  display: block;
  margin-bottom: 4px;
  font-size: 10px;
  text-transform: uppercase;
  letter-spacing: 1px;
}
.footer-note {
  text-align: center;
  color: #888;
  font-size: 8px;
  padding-top: 10px;
  border-top: 1px solid #e5e5e5;
  line-height: 1.6;
}
.footer-note .official-stamp {
  font-weight: 700;
  color: #0D1B5E;
  margin-bottom: 4px;
}

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
  flex-wrap: wrap;
  background: rgba(11,14,20,.9);
  padding: 10px 12px;
  border-radius: 999px;
  backdrop-filter: blur(12px);
  box-shadow: 0 8px 32px rgba(0,0,0,.5);
  border: 1px solid rgba(245,184,0,.25);
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
.btn-action:active { transform: translateY(0); }

.btn-print {
  background: linear-gradient(135deg, #F5B800, #D4AF37);
  color: #0B0E14;
  box-shadow: 0 6px 20px rgba(245,184,0,.45);
}
.btn-print:hover { box-shadow: 0 10px 28px rgba(245,184,0,.65); }

.btn-back {
  background: rgba(255,255,255,.1);
  color: #E8ECF3;
  border: 1px solid rgba(255,255,255,.2);
}
.btn-back:hover { background: rgba(255,255,255,.2); }

.btn-today {
  background: rgba(59,130,246,.9);
  color: #fff;
}
.btn-today:hover { background: rgba(59,130,246,1); }

.btn-pdf {
  background: linear-gradient(135deg, #EF4444, #B91C1C);
  color: #fff;
  box-shadow: 0 6px 20px rgba(239,68,68,.4);
}
.btn-pdf:hover { box-shadow: 0 10px 28px rgba(239,68,68,.6); }

.date-input {
  padding: 10px 16px;
  border-radius: 999px;
  background: rgba(255,255,255,.1);
  border: 1px solid rgba(255,255,255,.2);
  color: #E8ECF3;
  font-weight: 700;
  font-size: 12px;
  font-family: inherit;
  cursor: pointer;
}
.date-input::-webkit-calendar-picker-indicator {
  filter: invert(1);
  cursor: pointer;
}

/* ============================================================
   IMPRESSION — Rendu professionnel style Ctrl+P
   ============================================================ */
@media print {
  /* Reset body */
  html, body {
    background: #fff !important;
    padding: 0 !important;
    margin: 0 !important;
    width: 100% !important;
    font-size: 10pt;
  }

  /* Document pleine page */
  .document {
    box-shadow: none !important;
    border-radius: 0 !important;
    padding: 0 !important;
    max-width: 100% !important;
    width: 100% !important;
    margin: 0 !important;
    page-break-after: always;
  }
  .document:last-child { page-break-after: auto; }

  /* Masquer les boutons */
  .action-bar,
  .btn-action,
  .date-input,
  .no-print {
    display: none !important;
  }

  /* Préserver les couleurs */
  * {
    -webkit-print-color-adjust: exact !important;
    print-color-adjust: exact !important;
    color-adjust: exact !important;
  }

  /* Éviter les coupures disgracieuses */
  .official-header,
  .legal-band,
  .doc-title,
  .rapport-info,
  .kpi-row,
  .synthese,
  .signatures,
  .print-footer {
    page-break-inside: avoid;
  }

  /* Tableau : gestion des sauts de page */
  table.ops { page-break-inside: auto; }
  table.ops thead { display: table-header-group; }
  table.ops tfoot { display: table-footer-group; }
  table.ops tr { page-break-inside: avoid; page-break-after: auto; }

  /* Sauts de page avant les sections importantes */
  .synthese { page-break-before: auto; }
  .signatures { page-break-before: auto; }

  /* Marges d'impression */
  @page {
    margin: 1.2cm 1cm;
  }
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media screen and (max-width: 768px) {
  body { padding: 20px 10px; }
  .document { padding: 20px 16px; }
  .official-header { grid-template-columns: 1fr; text-align: center; }
  .atf-header-block { justify-content: center; }
  .atf-brand-info { text-align: center; }
  .kpi-row, .rapport-info { grid-template-columns: 1fr 1fr; }
  .signatures { grid-template-columns: 1fr; }
  .action-bar {
    top: auto;
    bottom: 20px;
    right: 10px;
    left: 10px;
    justify-content: center;
    flex-wrap: wrap;
    border-radius: 20px;
  }
  .btn-action { font-size: 11px; padding: 10px 14px; }
  .date-input { font-size: 11px; }
}
</style>
</head>
<body>

<!-- ============================================================
     BARRE D'ACTIONS
     ============================================================ -->
<div class="action-bar no-print">
  <a class="btn-action btn-back" href="<?= $base ?>/caissier/dashboard.php">
    ← Retour
  </a>

  <input type="date"
         class="date-input"
         id="datePicker"
         value="<?= htmlspecialchars($date_rapport) ?>"
         max="<?= date('Y-m-d') ?>">

  <button class="btn-action btn-today" onclick="goToday()" type="button">
    📅 Aujourd'hui
  </button>

  <button class="btn-action btn-pdf" onclick="downloadPDF()" type="button">
    📥 Télécharger PDF
  </button>

  <button class="btn-action btn-print" onclick="printDocument()" type="button">
    🖨️ Imprimer
  </button>
</div>

<!-- ============================================================
     DOCUMENT
     ============================================================ -->
<div class="document" id="document">

  <!-- ============ EN-TÊTE OFFICIEL RDC + ATF ============ -->
  <div class="official-header">
    <div class="rdc-block">
      <div class="rep">République Démocratique du Congo</div>
      <div class="devise">« Justice – Paix – Travail »</div>
      <div class="ministere">Ministère des Finances</div>
      <div style="font-size:9px;color:#666;margin-top:2px">Direction Générale des Institutions Financières</div>
    </div>

    <div class="atf-header-block">
      <div class="atf-brand-info">
        <div class="atf-brand-name">AFRICAN TRUST FUND</div>
        <div class="atf-brand-slogan">« L'argent de l'Afrique pour construire l'Afrique »</div>
        <div class="atf-brand-legal">Société Coopérative d'Épargne et de Crédit (COOPEC)</div>
      </div>
      <img src="<?= $base ?>/assets/img/logo.png"
           alt="ATF"
           class="atf-logo-official"
           onerror="this.src='data:image/svg+xml;utf8,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><circle cx=%2250%22 cy=%2250%22 r=%2245%22 fill=%22%23F5B800%22/><text x=%2250%22 y=%2262%22 font-size=%2234%22 text-anchor=%22middle%22 fill=%22%230B0E14%22 font-weight=%22bold%22 font-family=%22Arial%22>ATF</text></svg>'">
    </div>
  </div>

  <div class="separator-line"></div>

  <!-- ============ BANDEAU LÉGAL ============ -->
  <div class="legal-band">
    <span><strong>RCCM :</strong> CD/KIN/RCCM/24-B-01234</span>
    <span><strong>ID Nat :</strong> 01-234-N56789A</span>
    <span><strong>NIF :</strong> A0123456Z</span>
    <span><strong>Agrément BCC :</strong> 045/2024</span>
  </div>

  <!-- ============ TITRE ============ -->
  <div class="doc-title">
    <h1>Rapport Journalier de Caisse</h1>
    <div class="subtitle">
      Édité le <?= date('d/m/Y à H:i') ?> · Document officiel de gestion journalière
    </div>
  </div>

  <!-- ============ INFO RAPPORT ============ -->
  <div class="rapport-info">
    <div class="item">
      <div class="lbl">Caissier</div>
      <div class="val"><?= htmlspecialchars($caissier['nom'] . ' ' . $caissier['postnom']) ?></div>
    </div>
    <div class="item">
      <div class="lbl">Date du rapport</div>
      <div class="val"><?= date('d/m/Y', strtotime($date_rapport)) ?></div>
    </div>
    <div class="item">
      <div class="lbl">Guichet</div>
      <div class="val"><?= htmlspecialchars($caissier['numero_phone'] ?? 'Principal') ?></div>
    </div>
    <div class="item">
      <div class="lbl">Agence</div>
      <div class="val">Kinshasa — Gombe</div>
    </div>
  </div>

  <!-- ============ KPIs ============ -->
  <div class="kpi-row">
    <div class="kpi-box blue">
      <div class="kpi-lbl">Total opérations</div>
      <div class="kpi-val"><?= count($operations) ?></div>
    </div>
    <div class="kpi-box green">
      <div class="kpi-lbl">Dépôts (<?= $nb_depots ?>)</div>
      <div class="kpi-val">+<?= number_format($total_depots, 0, ',', ' ') ?></div>
    </div>
    <div class="kpi-box red">
      <div class="kpi-lbl">Retraits (<?= $nb_retraits ?>)</div>
      <div class="kpi-val">−<?= number_format($total_retraits, 0, ',', ' ') ?></div>
    </div>
    <div class="kpi-box gold">
      <div class="kpi-lbl">Solde net de caisse</div>
      <div class="kpi-val"><?= number_format($total_depots - $total_retraits, 0, ',', ' ') ?></div>
    </div>
  </div>

  <!-- ============ TABLEAU ============ -->
  <div class="section-title">
    📋 Détail des opérations du jour (<?= count($operations) ?>)
  </div>

  <table class="ops">
    <thead>
      <tr>
        <th style="width:35px">#</th>
        <th style="width:65px">Heure</th>
        <th>Référence</th>
        <th>Client</th>
        <th>N° Compte</th>
        <th>Téléphone</th>
        <th>Type</th>
        <th style="text-align:right;width:90px">Montant</th>
        <th style="text-align:right;width:90px">Solde après</th>
      </tr>
    </thead>
    <tbody>
      <?php if (!$operations): ?>
        <tr>
          <td colspan="9" class="empty">
            📭 Aucune opération enregistrée pour cette date
          </td>
        </tr>
      <?php else: foreach ($operations as $i => $op): ?>
        <tr>
          <td style="text-align:center;font-weight:700"><?= $i + 1 ?></td>
          <td><?= date('H:i:s', strtotime($op['date_transaction'])) ?></td>
          <td><code style="font-size:9px;background:#f0f0f0;padding:2px 5px;border-radius:3px"><?= htmlspecialchars($op['reference']) ?></code></td>
          <td><strong><?= htmlspecialchars($op['nom'] . ' ' . $op['postnom']) ?></strong></td>
          <td><?= htmlspecialchars($op['numero_compte']) ?></td>
          <td><?= htmlspecialchars($op['numero_phone'] ?? '—') ?></td>
          <td>
            <?php if ($op['type_transaction'] === 'Depot'): ?>
              <span style="color:#059669;font-weight:700">➕ Dépôt</span>
            <?php else: ?>
              <span style="color:#DC2626;font-weight:700">➖ Retrait</span>
            <?php endif; ?>
          </td>
          <td style="text-align:right" class="<?= $op['type_transaction'] === 'Retrait' ? 'amount-neg' : 'amount-pos' ?>">
            <?= $op['type_transaction'] === 'Retrait' ? '−' : '+' ?>
            <?= number_format((float)$op['montant'], 2, ',', ' ') ?>
          </td>
          <td style="text-align:right;font-weight:700">
            <?= number_format((float)$op['solde_apres'], 2, ',', ' ') ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="7" style="text-align:right">TOTAUX DU JOUR →</td>
        <td style="text-align:right;color:#059669">
          +<?= number_format($total_depots, 2, ',', ' ') ?>
        </td>
        <td style="text-align:right;color:#DC2626">
          −<?= number_format($total_retraits, 2, ',', ' ') ?>
        </td>
      </tr>
    </tfoot>
  </table>

  <!-- ============ SYNTHÈSE ============ -->
  <div class="synthese">
    <h3>📊 Synthèse de la journée</h3>
    <div class="synthese-grid">
      <div>
        <div class="line"><span class="k">Total dépôts</span><span class="v">+ <?= number_format($total_depots, 2, ',', ' ') ?> CDF</span></div>
        <div class="line"><span class="k">Total retraits</span><span class="v">− <?= number_format($total_retraits, 2, ',', ' ') ?> CDF</span></div>
        <div class="line"><span class="k">Solde net de caisse</span><span class="v"><?= number_format($total_depots - $total_retraits, 2, ',', ' ') ?> CDF</span></div>
      </div>
      <div>
        <div class="line"><span class="k">Nombre d'opérations</span><span class="v"><?= count($operations) ?></span></div>
        <div class="line"><span class="k">Dépôts</span><span class="v"><?= $nb_depots ?></span></div>
        <div class="line"><span class="k">Retraits</span><span class="v"><?= $nb_retraits ?></span></div>
        <div class="line"><span class="k">Montant le plus élevé</span><span class="v"><?= number_format($montant_max, 0, ',', ' ') ?> CDF</span></div>
      </div>
    </div>
  </div>

  <!-- ============ SIGNATURES ============ -->
  <div class="signatures">
    <div class="signature-item">
      <div class="sig-title">Le Caissier</div>
      <div class="sig-line"><?= htmlspecialchars($caissier['nom'] . ' ' . $caissier['postnom']) ?></div>
    </div>
    <div class="signature-item">
      <div class="sig-title">Le Superviseur</div>
      <div class="sig-line">_________________________</div>
    </div>
    <div class="signature-item">
      <div class="sig-title">Le Directeur d'Agence</div>
      <div class="sig-line">M. Jean-Paul KATEMBO</div>
    </div>
  </div>

  <!-- ============ PIED DE PAGE OFFICIEL ============ -->
  <div class="print-footer">
    <div class="footer-grid">
      <div class="footer-block">
        <strong>🏦 Coordonnées bancaires</strong>
        Rawbank — Agence Kinshasa-Gombe<br>
        Compte CDF : <strong>00012-12345-6789012-34</strong><br>
        Compte USD : <strong>00012-98765-4321098-76</strong><br>
        IBAN : CD12 0001 2123 4567 8901 2345<br>
        SWIFT : <strong>RAWBCDKI</strong>
      </div>
      <div class="footer-block">
        <strong>📞 Contact</strong>
        Avenue de la Paix n°45, Commune de la Gombe<br>
        Kinshasa — République Démocratique du Congo<br>
        Tél : +243 123 456 789 / +243 990 000 000<br>
        Email : contact@africantrustfund.cd<br>
        Site : www.africantrustfund.cd
      </div>
    </div>
    <div class="footer-note">
      <div class="official-stamp">
        Document officiel — AFRICAN TRUST FUND · Société Coopérative d'Épargne et de Crédit
      </div>
      Généré automatiquement par le système de gestion ATF le <?= date('d/m/Y à H:i:s') ?> ·
      Réf. RPT-CAI-<?= date('Ymd', strtotime($date_rapport)) ?>-<?= str_pad((string)$caissier_id, 4, '0', STR_PAD_LEFT) ?><br>
      Ce document fait foi des opérations effectuées pendant la journée mentionnée.
      Toute reproduction non autorisée est strictement interdite.
    </div>
  </div>

</div>

<!-- ============================================================
     JAVASCRIPT — Boutons d'action
     ============================================================ -->
<script>
/* ============================================================
   1. IMPRESSION (équivalent Ctrl+P)
   ============================================================ */
function printDocument() {
  // Sauvegarde le titre original
  const originalTitle = document.title;

  // Modifie le titre pour un meilleur nom de fichier PDF
  document.title = 'Rapport_Journalier_ATF_<?= date('Y-m-d', strtotime($date_rapport)) ?>_<?= htmlspecialchars($caissier['nom'] ?? 'Caissier') ?>';

  // Force l'impression avec un petit délai pour le rendu
  setTimeout(() => {
    window.print();
    // Restaure le titre après impression
    setTimeout(() => { document.title = originalTitle; }, 1000);
  }, 100);
}

/* ============================================================
   2. TÉLÉCHARGER PDF (via impression navigateur → Enregistrer en PDF)
   ============================================================ */
function downloadPDF() {
  // Affiche un message d'instruction
  showToast('💡 Dans la fenêtre d\'impression, choisissez « Enregistrer au format PDF »', 5000);

  // Lance l'impression après un court délai
  setTimeout(() => printDocument(), 800);
}

/* ============================================================
   3. AUJOURD'HUI
   ============================================================ */
function goToday() {
  const today = new Date().toISOString().split('T')[0];
  window.location.href = '?date=' + today;
}

/* ============================================================
   4. SÉLECTION DE DATE
   ============================================================ */
document.getElementById('datePicker').addEventListener('change', function () {
  if (this.value) {
    window.location.href = '?date=' + this.value;
  }
});

/* ============================================================
   5. RACCOURCIS CLAVIER
   ============================================================ */
document.addEventListener('keydown', function (e) {
  // Ctrl+P ou Cmd+P → Imprimer
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'p') {
    e.preventDefault();
    printDocument();
  }

  // Ctrl+S ou Cmd+S → Télécharger PDF
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
    e.preventDefault();
    downloadPDF();
  }

  // Échap → Retour
  if (e.key === 'Escape') {
    window.location.href = '<?= $base ?>/caissier/dashboard.php';
  }

  // Flèches gauche/droite → Jour précédent/suivant
  if (e.key === 'ArrowLeft') {
    const d = new Date('<?= $date_rapport ?>');
    d.setDate(d.getDate() - 1);
    window.location.href = '?date=' + d.toISOString().split('T')[0];
  }
  if (e.key === 'ArrowRight') {
    const d = new Date('<?= $date_rapport ?>');
    d.setDate(d.getDate() + 1);
    const maxDate = new Date();
    if (d <= maxDate) {
      window.location.href = '?date=' + d.toISOString().split('T')[0];
    }
  }
});

/* ============================================================
   6. TOAST DE NOTIFICATION
   ============================================================ */
function showToast(message, duration = 3000) {
  // Supprimer un ancien toast s'il existe
  const old = document.getElementById('atfToast');
  if (old) old.remove();

  const toast = document.createElement('div');
  toast.id = 'atfToast';
  toast.style.cssText = `
    position: fixed;
    bottom: 30px;
    left: 50%;
    transform: translateX(-50%) translateY(120%);
    background: linear-gradient(135deg, #F5B800, #D4AF37);
    color: #0B0E14;
    padding: 16px 28px;
    border-radius: 999px;
    font-weight: 700;
    font-size: 13px;
    box-shadow: 0 12px 40px rgba(245,184,0,.5);
    z-index: 99999;
    transition: transform .4s ease;
    font-family: 'Inter', Arial, sans-serif;
    max-width: 90%;
    text-align: center;
  `;
  toast.textContent = message;

  document.body.appendChild(toast);

  requestAnimationFrame(() => {
    toast.style.transform = 'translateX(-50%) translateY(0)';
  });

  setTimeout(() => {
    toast.style.transform = 'translateX(-50%) translateY(120%)';
    setTimeout(() => toast.remove(), 500);
  }, duration);
}

/* ============================================================
   7. FEEDBACK VISUEL DES BOUTONS
   ============================================================ */
document.querySelectorAll('.btn-action').forEach(btn => {
  btn.addEventListener('click', function () {
    this.style.transform = 'scale(0.95)';
    setTimeout(() => this.style.transform = '', 150);
  });
});

/* ============================================================
   8. MESSAGE D'ACCUEIL
   ============================================================ */
window.addEventListener('load', () => {
  setTimeout(() => {
    showToast('💡 Astuce : Ctrl+P pour imprimer · Ctrl+S pour PDF · ←/→ pour naviguer', 5000);
  }, 800);
});

console.log('%c📄 Rapport Journalier ATF — Prêt pour impression', 'color:#F5B800;font-weight:bold;font-size:14px');
console.log('%c💡 Raccourcis : Ctrl+P (Imprimer) · Ctrl+S (PDF) · ←/→ (Jours)', 'color:#10B981;font-size:12px');
</script>

</body>
</html>