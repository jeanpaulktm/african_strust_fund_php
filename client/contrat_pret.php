<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/prets.php';
requireRole('client');

$pdo = Database::getConnection();
$id = (int)$_SESSION['client_id'];
$token = $_GET['token'] ?? '';

if (!$token) { header('Location: ' . url('client/mes_prets.php')); exit; }
$data = consommerToken('pret', $token);
if (!$data) { $_SESSION['flash_error'] = "Token invalide."; header('Location: ' . url('client/mes_prets.php')); exit; }

$id_pret = (int)$data['id'];

/* Charger le prêt */
$stmt = $pdo->prepare("
    SELECT p.*, pr.nom AS produit_nom, pr.taux_annuel, pr.methode_interet,
           pr.frais_dossier_pct, pr.penalite_retard_pct,
           c.nom, c.postnom, c.prenom, c.numero_compte, c.numero_phone,
           c.adresse_complete, c.email
    FROM Prets p
    JOIN Produits_Pret pr ON pr.id_produit = p.id_produit
    JOIN Client c ON c.id_client = p.id_client
    WHERE p.id_pret = ? AND p.id_client = ?
");
$stmt->execute([$id_pret, $id]);
$p = $stmt->fetch();

if (!$p) { $_SESSION['flash_error'] = "Prêt introuvable."; header('Location: ' . url('client/mes_prets.php')); exit; }

/* Échéances */
$stmt = $pdo->prepare("SELECT * FROM Echeances_Pret WHERE id_pret = ? ORDER BY numero_echeance ASC");
$stmt->execute([$id_pret]);
$echeances = $stmt->fetchAll();

/* Montants */
$montant = (float)$p['montant_accorde'];
$taux = (float)$p['taux_applique'];
$duree = (int)$p['duree_mois'];
$mensualite = calculerMensualite($montant, $taux, $duree);
$total_du = $montant + ($mensualite * $duree - $montant);
$total_interets = $total_du - $montant;

/* Signature hash (immuable) */
$signature_hash = hash('sha256', $p['reference'] . $p['id_client'] . $p['montant_accorde'] . $p['date_approbation']);

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Contrat de prêt <?= htmlspecialchars($p['reference']) ?> — ATF</title>
<style>
@page { size: A4; margin: 15mm; }
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
  background: #e8e8e8;
  color: #1a1a1a;
  padding: 30px 16px;
  font-size: 11px;
  line-height: 1.6;
}

.document {
  max-width: 850px;
  margin: 0 auto;
  background: #fff;
  padding: 40px 50px;
  box-shadow: 0 8px 40px rgba(0,0,0,.18);
  border-top: 6px solid #0D1B5E;
}

.header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  padding-bottom: 16px;
  border-bottom: 3px double #0D1B5E;
  margin-bottom: 20px;
  flex-wrap: wrap;
  gap: 16px;
}
.header-left { font-size: 9px; color: #0D1B5E; line-height: 1.6; }
.header-left .rep { font-weight: 900; text-transform: uppercase; letter-spacing: 1px; }
.header-right { text-align: right; }
.header-right .brand { font-size: 18px; font-weight: 900; color: #0D1B5E; letter-spacing: 2px; }
.header-right .slogan { font-size: 9px; color: #666; font-style: italic; margin-top: 4px; }
.header-right .legal { font-size: 8px; color: #888; }

.titre {
  text-align: center;
  margin: 24px 0 20px;
}
.titre h1 {
  font-size: 18px;
  color: #0D1B5E;
  font-weight: 900;
  letter-spacing: 3px;
  text-transform: uppercase;
  padding-bottom: 8px;
  border-bottom: 3px solid #F5B800;
  display: inline-block;
  min-width: 60%;
}
.titre .sub { font-size: 10px; color: #666; margin-top: 8px; }

.ref-badge {
  text-align: center;
  padding: 12px;
  margin: 16px 0 24px;
  background: #FFF8E1;
  border-left: 4px solid #F5B800;
  border-radius: 6px;
  font-size: 12px;
  color: #0D1B5E;
  font-weight: 700;
}

.section {
  margin-bottom: 22px;
}
.section h2 {
  font-size: 11px;
  color: #0D1B5E;
  text-transform: uppercase;
  letter-spacing: 1.5px;
  font-weight: 900;
  padding-bottom: 6px;
  border-bottom: 2px solid #F5B800;
  margin-bottom: 12px;
}
.section p {
  margin-bottom: 8px;
  text-align: justify;
}

.info-table { width: 100%; border-collapse: collapse; font-size: 11px; }
.info-table td { padding: 6px 0; vertical-align: top; }
.info-table td:first-child { color: #666; width: 45%; }
.info-table td:last-child { font-weight: 700; color: #1a1a1a; }

.montant-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
  margin: 12px 0;
}
.montant-box {
  padding: 14px 16px;
  border-radius: 8px;
  background: #f9f9f9;
  border-left: 4px solid #0D1B5E;
}
.montant-box.gold { background: #FFF8E1; border-left-color: #F5B800; }
.montant-box .lbl { font-size: 8px; color: #666; text-transform: uppercase; font-weight: 700; letter-spacing: 1px; }
.montant-box .val { font-size: 18px; font-weight: 900; color: #0D1B5E; margin-top: 6px; }
.montant-box.gold .val { color: #B8860B; }

table.ech {
  width: 100%;
  border-collapse: collapse;
  font-size: 10px;
  margin-top: 12px;
}
table.ech thead { background: #0D1B5E; color: #fff; }
table.ech th {
  padding: 8px 6px;
  text-align: left;
  font-size: 8px;
  text-transform: uppercase;
  letter-spacing: .5px;
}
table.ech td { padding: 8px 6px; border-bottom: 1px solid #e5e5e5; }
table.ech tbody tr:nth-child(even) { background: #fafafa; }
table.ech tfoot {
  background: #FFF8E1;
  font-weight: 800;
  color: #0D1B5E;
}
table.ech tfoot td {
  border-top: 2px solid #F5B800;
  padding: 10px 6px;
  font-size: 11px;
}
table.ech .right { text-align: right; }

.signature-block {
  margin-top: 40px;
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 40px;
}
.signature-box {
  padding-top: 60px;
  border-top: 1px solid #333;
  text-align: center;
  font-size: 10px;
}
.signature-box .role { font-weight: 800; color: #0D1B5E; margin-bottom: 4px; }
.signature-box .name { font-size: 11px; color: #1a1a1a; }

.hash-box {
  margin-top: 24px;
  padding: 10px 12px;
  border: 1px dashed #ccc;
  border-radius: 6px;
  font-family: monospace;
  font-size: 8px;
  color: #666;
  word-break: break-all;
}

.footer-legal {
  margin-top: 30px;
  padding-top: 12px;
  border-top: 3px double #F5B800;
  text-align: center;
  font-size: 8px;
  color: #666;
  line-height: 1.7;
}

.action-bar {
  position: fixed;
  top: 20px;
  right: 20px;
  display: flex;
  gap: 10px;
  z-index: 999;
}
.action-bar button,
.action-bar a {
  padding: 12px 22px;
  border-radius: 999px;
  border: none;
  font-weight: 700;
  font-size: 13px;
  cursor: pointer;
  font-family: inherit;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  transition: all .25s;
}
.btn-print { background: linear-gradient(135deg, #F5B800, #D4AF37); color: #0B0E14; }
.btn-back { background: rgba(11,14,20,.9); color: #E8ECF3; border: 1px solid rgba(255,255,255,.2); }
.action-bar button:hover,
.action-bar a:hover { transform: translateY(-2px); }

@media print {
  body { background: #fff; padding: 0; }
  .document { box-shadow: none; padding: 0; }
  .action-bar { display: none; }
  * { -webkit-print-color-adjust: exact !important; print-color-adjust: exact !important; }
}

@media (max-width: 640px) {
  .document { padding: 20px 16px; }
  .montant-grid { grid-template-columns: 1fr; }
  .signature-block { grid-template-columns: 1fr; gap: 20px; }
  .header { flex-direction: column; text-align: center; }
  .header-right { text-align: center; }
  .action-bar { top: auto; bottom: 15px; left: 10px; right: 10px; justify-content: center; }
}
</style>
</head>
<body>

<div class="action-bar">
  <button class="btn-print" onclick="window.print()">🖨️ Imprimer / PDF</button>
  <a class="btn-back" href="<?= $base ?>/client/pret_details.php?token=<?= htmlspecialchars(genererToken('pret', $id_pret)) ?>">← Retour</a>
</div>

<div class="document">

  <!-- EN-TÊTE -->
  <div class="header">
    <div class="header-left">
      <div class="rep">République Démocratique du Congo</div>
      <div>« Justice – Paix – Travail »</div>
      <div>Ministère des Finances</div>
      <div>Direction Générale des Institutions Financières</div>
    </div>
    <div class="header-right">
      <div class="brand">AFRICAN TRUST FUND</div>
      <div class="slogan">« L'argent de l'Afrique pour construire l'Afrique »</div>
      <div class="legal">Société de Gestion d'Actifs (S.G.A)</div>
    </div>
  </div>

  <div class="titre">
    <h1>Contrat de Prêt</h1>
    <div class="sub">Document officiel · Édité le <?= date('d/m/Y à H:i') ?></div>
  </div>

  <div class="ref-badge">
    📄 Référence du contrat : <strong><?= htmlspecialchars($p['reference']) ?></strong>
  </div>

  <!-- PARTIES -->
  <div class="section">
    <h2>Article 1 — Parties au contrat</h2>

    <p><strong>LE PRÊTEUR :</strong></p>
    <table class="info-table">
      <tr><td>Raison sociale</td><td>AFRICAN TRUST FUND (ATF)</td></tr>
      <tr><td>RCCM</td><td>CD/KIN/RCCM/24-B-01234</td></tr>
      <tr><td>NIF</td><td>A0123456Z</td></tr>
      <tr><td>Agrément BCC</td><td>N°045/2024</td></tr>
      <tr><td>Siège social</td><td>Av. de la Paix n°45, Kinshasa-Gombe, RDC</td></tr>
    </table>

    <p style="margin-top:14px"><strong>L'EMPRUNTEUR :</strong></p>
    <table class="info-table">
      <tr><td>Nom complet</td><td><?= htmlspecialchars($p['nom'] . ' ' . $p['postnom'] . ' ' . $p['prenom']) ?></td></tr>
      <tr><td>N° de compte</td><td><?= htmlspecialchars($p['numero_compte']) ?></td></tr>
      <tr><td>Téléphone</td><td><?= htmlspecialchars($p['numero_phone']) ?></td></tr>
      <?php if ($p['email']): ?>
        <tr><td>Email</td><td><?= htmlspecialchars($p['email']) ?></td></tr>
      <?php endif; ?>
      <tr><td>Adresse</td><td><?= htmlspecialchars($p['adresse_complete'] ?? '—') ?></td></tr>
    </table>
  </div>

  <!-- MONTANTS -->
  <div class="section">
    <h2>Article 2 — Conditions financières</h2>

    <div class="montant-grid">
      <div class="montant-box gold">
        <div class="lbl">Montant du prêt</div>
        <div class="val"><?= htmlspecialchars(formaterMontant($montant, $p['devise'])) ?></div>
      </div>
      <div class="montant-box">
        <div class="lbl">Taux annuel appliqué</div>
        <div class="val"><?= number_format($taux * 100, 2) ?>%</div>
      </div>
      <div class="montant-box">
        <div class="lbl">Durée</div>
        <div class="val"><?= $duree ?> mois</div>
      </div>
      <div class="montant-box gold">
        <div class="lbl">Mensualité</div>
        <div class="val"><?= htmlspecialchars(formaterMontant($mensualite, $p['devise'])) ?></div>
      </div>
    </div>

    <table class="info-table">
      <tr><td>Produit de prêt</td><td><?= htmlspecialchars($p['produit_nom']) ?></td></tr>
      <tr><td>Méthode de calcul</td><td><?= htmlspecialchars($p['methode_interet']) ?></td></tr>
      <tr><td>Frais de dossier</td><td><?= number_format((float)$p['frais_dossier_pct'] * 100, 2) ?>%</td></tr>
      <tr><td>Pénalité de retard</td><td><?= number_format((float)$p['penalite_retard_pct'] * 100, 2) ?>% par mois</td></tr>
      <tr><td><strong>Total à rembourser</strong></td><td><strong style="color:#B8860B"><?= htmlspecialchars(formaterMontant($total_du, $p['devise'])) ?></strong></td></tr>
    </table>
  </div>

  <!-- ÉCHÉANCIER -->
  <div class="section">
    <h2>Article 3 — Échéancier de remboursement</h2>
    <p>L'emprunteur s'engage à effectuer <?= $duree ?> paiements selon le calendrier ci-dessous :</p>

    <table class="ech">
      <thead>
        <tr>
          <th>N°</th>
          <th>Date</th>
          <th class="right">Capital</th>
          <th class="right">Intérêts</th>
          <th class="right">Mensualité</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($echeances as $e): ?>
          <tr>
            <td><strong>#<?= (int)$e['numero_echeance'] ?></strong></td>
            <td><?= date('d/m/Y', strtotime($e['date_echeance'])) ?></td>
            <td class="right"><?= number_format((float)$e['montant_capital'], 2, ',', ' ') ?></td>
            <td class="right"><?= number_format((float)$e['montant_interet'], 2, ',', ' ') ?></td>
            <td class="right"><strong><?= number_format((float)$e['montant_total'], 2, ',', ' ') ?></strong></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="2">TOTAL</td>
          <td class="right"><?= number_format($montant, 2, ',', ' ') ?></td>
          <td class="right"><?= number_format($total_interets, 2, ',', ' ') ?></td>
          <td class="right"><?= number_format($total_du, 2, ',', ' ') ?> <?= htmlspecialchars($p['devise']) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>

  <!-- CLAUSES -->
  <div class="section">
    <h2>Article 4 — Obligations de l'emprunteur</h2>
    <p>L'emprunteur s'engage à :</p>
    <p>1. Rembourser le capital emprunté et les intérêts selon l'échéancier défini à l'article 3 ;</p>
    <p>2. Respecter les dates de paiement convenues ;</p>
    <p>3. Informer le prêteur de tout changement d'adresse ou de situation ;</p>
    <p>4. Ne pas utiliser les fonds à des fins illicites.</p>
  </div>

  <div class="section">
    <h2>Article 5 — Défaillance</h2>
    <p>En cas de retard de paiement, une pénalité de <?= number_format((float)$p['penalite_retard_pct'] * 100, 2) ?>% par mois sera appliquée. Passé 90 jours de retard, le dossier sera transféré au service contentieux.</p>
  </div>

  <div class="section">
    <h2>Article 6 — Litiges</h2>
    <p>Tout litige relatif à l'interprétation ou à l'exécution du présent contrat sera soumis aux tribunaux compétents de Kinshasa, RDC.</p>
  </div>

  <!-- SIGNATURES -->
  <div class="signature-block">
    <div class="signature-box">
      <div class="role">LE PRÊTEUR</div>
      <div class="name">AFRICAN TRUST FUND</div>
      <div style="font-size:9px;color:#888;margin-top:4px">Signature et cachet</div>
    </div>
    <div class="signature-box">
      <div class="role">L'EMPRUNTEUR</div>
      <div class="name"><?= htmlspecialchars($p['prenom'] . ' ' . $p['nom']) ?></div>
      <div style="font-size:9px;color:#888;margin-top:4px">Signature précédée de "Lu et approuvé"</div>
    </div>
  </div>

  <div class="hash-box">
    🔐 Empreinte numérique : <?= $signature_hash ?><br>
    Généré le : <?= date('d/m/Y H:i:s') ?> · Réf. CTR-<?= htmlspecialchars($p['reference']) ?>
  </div>

  <div class="footer-legal">
    <strong>AFRICAN TRUST FUND</strong> — L'argent de l'Afrique pour construire l'Afrique<br>
    RCCM : CD/KIN/RCCM/24-B-01234 · NIF : A0123456Z · Agrément BCC : 045/2024<br>
    Avenue de la Paix n°45, Kinshasa-Gombe, RDC · contact@africantrustfund.cd<br>
    Ce document est contractuel et fait foi entre les parties.
  </div>

</div>

<script>
document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
        e.preventDefault();
        window.print();
    }
});
</script>
</body>
</html>