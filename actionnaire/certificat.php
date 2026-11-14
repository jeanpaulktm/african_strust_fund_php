<?php
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/actionnaires.php';
require_once __DIR__ . '/../config/nombres.php';
requireActionnaire();

$pdo = Database::getConnection();
$id  = (int)$_SESSION['actionnaire_id'];

/* Vérifier token */
if (empty($_GET['token'])) {
    refuserAcces("Token manquant.");
}
$data = consommerToken('actionnaire', $_GET['token']);
if (!$data || (int)$data['id'] !== $id) {
    refuserAcces("Session expirée.");
}

$stmt = $pdo->prepare("SELECT * FROM Actionnaires WHERE id_actionnaire = ?");
$stmt->execute([$id]);
$actionnaire = $stmt->fetch();

if (!$actionnaire) refuserAcces("Actionnaire introuvable.");

$pourcentage = getPourcentageDetention($pdo, (int)$actionnaire['nombre_parts']);
$capital = getCapitalCourant($pdo);

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Certificat de parts — ATF</title>
<style>
@page { size: A4 landscape; margin: 15mm; }
* { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: 'Inter', Georgia, serif;
  background: #e8e8e8;
  padding: 30px 20px;
  color: #1a1a1a;
}

.certificat {
  max-width: 1000px;
  margin: 0 auto;
  background: #fff;
  padding: 50px 60px;
  border: 8px double #F5B800;
  border-radius: 8px;
  box-shadow: 0 10px 40px rgba(0,0,0,.2);
  position: relative;
  overflow: hidden;
}

.certificat::before {
  content: "";
  position: absolute;
  top: -100px; right: -100px;
  width: 400px; height: 400px;
  background: radial-gradient(circle, rgba(245,184,0,.1), transparent 70%);
  border-radius: 50%;
}
.certificat::after {
  content: "";
  position: absolute;
  bottom: -100px; left: -100px;
  width: 400px; height: 400px;
  background: radial-gradient(circle, rgba(13,27,94,.08), transparent 70%);
  border-radius: 50%;
}

.certificat > * { position: relative; z-index: 1; }

.header-cert {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding-bottom: 20px;
  border-bottom: 3px solid #0D1B5E;
  margin-bottom: 30px;
}

.logo-cert {
  display: flex;
  align-items: center;
  gap: 16px;
}

.logo-cert img {
  width: 80px; height: 80px;
  object-fit: contain;
  border: 3px solid #F5B800;
  border-radius: 50%;
  padding: 5px;
}

.brand-cert h1 {
  color: #0D1B5E;
  font-size: 24px;
  letter-spacing: 2px;
  font-weight: 900;
}
.brand-cert p {
  color: #666;
  font-size: 11px;
  font-style: italic;
  margin-top: 4px;
}

.rdc-cert {
  text-align: right;
  font-size: 10px;
  color: #444;
  line-height: 1.6;
}
.rdc-cert strong { color: #0D1B5E; font-size: 12px; display: block; margin-bottom: 4px; }

.titre-cert {
  text-align: center;
  margin: 30px 0;
}
.titre-cert h2 {
  font-size: 32px;
  color: #0D1B5E;
  letter-spacing: 4px;
  font-weight: 900;
  text-transform: uppercase;
  padding-bottom: 12px;
  border-bottom: 3px double #F5B800;
  display: inline-block;
}
.titre-cert p {
  font-size: 12px;
  color: #666;
  font-style: italic;
  margin-top: 10px;
}

.numero-cert {
  text-align: center;
  margin: 20px 0;
  font-family: 'Courier New', monospace;
  font-size: 16px;
  color: #F5B800;
  background: #FFF8E1;
  padding: 10px;
  border-radius: 8px;
  letter-spacing: 3px;
  font-weight: 800;
  border: 2px dashed #F5B800;
  display: inline-block;
  margin-left: auto;
  margin-right: auto;
  display: table;
}

.corps-cert {
  padding: 30px 0;
  font-size: 14px;
  line-height: 2;
  text-align: justify;
}

.corps-cert .nom-hero {
  font-size: 22px;
  font-weight: 900;
  color: #0D1B5E;
  text-align: center;
  margin: 20px 0;
  padding: 20px;
  background: linear-gradient(135deg, #FFF8E1, #fff);
  border: 2px solid #F5B800;
  border-radius: 12px;
  letter-spacing: 1.5px;
}

.corps-cert .highlight {
  color: #F5B800;
  font-weight: 900;
  font-size: 18px;
}

.corps-cert .amount {
  font-size: 20px;
  color: #059669;
  font-weight: 900;
}

.details-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 20px;
  margin: 30px 0;
  padding: 20px;
  background: #f9f9f9;
  border-radius: 12px;
  border-left: 4px solid #F5B800;
}

.details-grid .item .lbl {
  font-size: 10px;
  color: #666;
  text-transform: uppercase;
  letter-spacing: 1px;
  font-weight: 700;
}
.details-grid .item .val {
  font-size: 16px;
  font-weight: 900;
  color: #0D1B5E;
  margin-top: 6px;
}

.signatures-cert {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 80px;
  margin-top: 50px;
  padding-top: 30px;
  border-top: 2px dashed #ccc;
}

.sig-cert { text-align: center; }
.sig-cert .title {
  font-size: 11px;
  color: #666;
  text-transform: uppercase;
  letter-spacing: 1.5px;
  margin-bottom: 60px;
  font-weight: 700;
}
.sig-cert .line {
  border-top: 1px solid #333;
  padding-top: 8px;
  font-size: 12px;
  font-weight: 700;
  color: #0D1B5E;
}

.footer-cert {
  margin-top: 40px;
  padding-top: 20px;
  border-top: 3px double #F5B800;
  text-align: center;
  font-size: 10px;
  color: #666;
  line-height: 1.6;
}

.btn-print-cert {
  position: fixed;
  top: 20px;
  right: 20px;
  padding: 14px 26px;
  background: linear-gradient(135deg, #F5B800, #D4AF37);
  color: #0B0E14;
  border: none;
  border-radius: 999px;
  font-weight: 800;
  font-size: 14px;
  cursor: pointer;
  box-shadow: 0 8px 24px rgba(245,184,0,.5);
  z-index: 9999;
}

.btn-back-cert {
  position: fixed;
  top: 20px;
  left: 20px;
  padding: 12px 22px;
  background: #0D1B5E;
  color: #F5B800;
  text-decoration: none;
  border: 2px solid #F5B800;
  border-radius: 999px;
  font-weight: 700;
  font-size: 13px;
  z-index: 9999;
}

@media print {
  body { background: #fff; padding: 0; }
  .certificat { box-shadow: none; border-radius: 0; padding: 30px 40px; }
  .btn-print-cert, .btn-back-cert { display: none; }
  * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>
</head>
<body>

<a class="btn-back-cert" href="<?= $base ?>/actionnaire/mes_parts.php">← Retour</a>
<button class="btn-print-cert" onclick="window.print()">🖨️ Imprimer / PDF</button>

<div class="certificat">

  <!-- EN-TÊTE -->
  <div class="header-cert">
    <div class="logo-cert">
      <img src="<?= $base ?>/assets/img/logo.png" alt="ATF"
           onerror="this.src='data:image/svg+xml;utf8,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><circle cx=%2250%22 cy=%2250%22 r=%2245%22 fill=%22%23F5B800%22/><text x=%2250%22 y=%2262%22 font-size=%2234%22 text-anchor=%22middle%22 fill=%22%230B0E14%22 font-weight=%22bold%22>A</text></svg>'">
      <div class="brand-cert">
        <h1>AFRICAN TRUST FUND</h1>
        <p>« L'argent de l'Afrique pour construire l'Afrique »</p>
        <p style="font-style:normal;font-size:10px;color:#888">Société Coopérative d'Épargne et de Crédit (COOPEC)</p>
      </div>
    </div>
    <div class="rdc-cert">
      <strong>RÉPUBLIQUE DÉMOCRATIQUE DU CONGO</strong>
      Ministère des Finances<br>
      RCCM : CD/KIN/RCCM/24-B-01234<br>
      NIF : A0123456Z
    </div>
  </div>

  <!-- TITRE -->
  <div class="titre-cert">
    <h2>CERTIFICAT DE PARTS SOCIALES</h2>
    <p>Document officiel attestant la détention de parts</p>
  </div>

  <!-- NUMÉRO -->
  <div class="numero-cert">
    N° <?= htmlspecialchars($actionnaire['numero_actionnaire']) ?>
  </div>

  <!-- CORPS -->
  <div class="corps-cert">
    <p>Nous, <strong style="color:#0D1B5E">AFRICAN TRUST FUND</strong>, Société Coopérative d'Épargne et de Crédit, certifions que :</p>

    <div class="nom-hero">
      <?= htmlspecialchars(mb_strtoupper($actionnaire['nom'] . ' ' . $actionnaire['postnom'] . ' ' . $actionnaire['prenom'])) ?>
    </div>

    <p style="text-align:center">
      est officiellement enregistré(e) comme <strong class="highlight">ACTIONNAIRE</strong> de la coopérative, 
      sous le numéro <strong style="color:#0D1B5E"><?= htmlspecialchars($actionnaire['numero_actionnaire']) ?></strong>.
    </p>

    <p style="text-align:center;margin-top:20px">
      À ce titre, l'actionnaire détient :
    </p>

    <div class="details-grid">
      <div class="item">
        <div class="lbl">Nombre de parts</div>
        <div class="val"><?= number_format((int)$actionnaire['nombre_parts'], 0, ',', ' ') ?></div>
      </div>
      <div class="item">
        <div class="lbl">Pourcentage de détention</div>
        <div class="val" style="color:#F5B800"><?= number_format($pourcentage, 4) ?>%</div>
      </div>
      <div class="item">
        <div class="lbl">Capital investi</div>
        <div class="val" style="color:#059669"><?= number_format((float)$actionnaire['capital_investi_cdf'], 0, ',', ' ') ?> CDF</div>
      </div>
    </div>

    <p style="text-align:center">
      Soit en toutes lettres : <br>
      <strong style="color:#0D1B5E;font-size:16px">
        <?= htmlspecialchars(mb_strtoupper(nombreEnLettres((int)$actionnaire['nombre_parts']))) ?> PARTS
      </strong>
    </p>

    <p style="text-align:center;margin-top:20px">
      <em>Date d'adhésion : <?= date('d/m/Y', strtotime($actionnaire['date_adhesion'])) ?></em>
    </p>

    <p style="text-align:center;margin-top:30px;font-style:italic;color:#666;font-size:12px">
      Ce certificat est délivré pour servir et valoir ce que de droit.
    </p>
  </div>

  <!-- SIGNATURES -->
  <div class="signatures-cert">
    <div class="sig-cert">
      <div class="title">L'Actionnaire</div>
      <div class="line"><?= htmlspecialchars($actionnaire['nom'] . ' ' . $actionnaire['postnom']) ?></div>
    </div>
    <div class="sig-cert">
      <div class="title">Le Directeur Général</div>
      <div class="line">M. Jean-Paul KATEMBO</div>
    </div>
  </div>

  <!-- PIED -->
  <div class="footer-cert">
    <strong>AFRICAN TRUST FUND</strong> — Kinshasa-Gombe, RDC<br>
    Avenue de la Paix n°45 · Tél : +243 123 456 789 · contact@africantrustfund.cd<br>
    <em>Délivré le <?= date('d/m/Y à H:i') ?> — Réf. CERT-<?= date('Y') ?>-<?= str_pad((string)$id, 4, '0', STR_PAD_LEFT) ?></em>
  </div>

</div>

<script>
if (new URLSearchParams(location.search).has('auto')) {
  window.addEventListener('load', () => setTimeout(() => window.print(), 800));
}
</script>

</body>
</html>