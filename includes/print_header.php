<?php
/**
 * EN-TÊTE IMPRIMABLE ATF — à inclure dans tous les documents
 * Variables attendues : $titre_document (string)
 */
if (!defined('ATF_NOM')) {
    require_once __DIR__ . '/../config/entreprise.php';
}

$titre_document = $titre_document ?? 'Document';
$base = '/african-trust-fund';
?>
<div class="print-header">
  <!-- Côté gauche : Logo + Nom -->
  <div class="print-header-left">
    <img src="<?= $base ?>/assets/img/logo.png"
         alt="<?= ATF_NOM ?>"
         class="print-logo"
         onerror="this.src='data:image/svg+xml;utf8,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><circle cx=%2250%22 cy=%2250%22 r=%2245%22 fill=%22%23F5B800%22/><text x=%2250%22 y=%2262%22 font-size=%2234%22 text-anchor=%22middle%22 fill=%22%230B0E14%22 font-weight=%22bold%22 font-family=%22Arial%22>ATF</text></svg>'">
    <div class="print-brand">
      <div class="print-brand-name"><?= ATF_NOM ?></div>
      <div class="print-brand-slogan"><?= ATF_SLOGAN ?></div>
      <div class="print-brand-legal"><?= ATF_FORME_JURIDIQUE ?></div>
    </div>
  </div>

  <!-- Côté droit : Coordonnées -->
  <div class="print-header-right">
    <div class="print-contact-line">📍 <?= ATF_ADRESSE ?></div>
    <div class="print-contact-line">🏙️ <?= ATF_VILLE ?>, <?= ATF_PAYS ?></div>
    <div class="print-contact-line">📞 <?= ATF_TEL_FIXE ?> / <?= ATF_TEL_MOBILE ?></div>
    <div class="print-contact-line">✉️ <?= ATF_EMAIL ?></div>
    <div class="print-contact-line">🌐 <?= ATF_SITE_WEB ?></div>
  </div>
</div>

<!-- Bandeau identifiants légaux -->
<div class="print-legal-bar">
  <span><strong>RCCM :</strong> <?= ATF_RCCM ?></span>
  <span><strong>ID Nat :</strong> <?= ATF_ID_NAT ?></span>
  <span><strong>NIF :</strong> <?= ATF_NIF ?></span>
  <span><strong>SWIFT :</strong> <?= ATF_SWIFT ?></span>
</div>

<!-- Bandeau titre document -->
<div class="print-doc-title">
  <h1><?= htmlspecialchars($titre_document) ?></h1>
</div>