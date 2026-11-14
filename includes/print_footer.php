<?php
$base = '/african-trust-fund';
?>
<div class="print-footer">
  <div class="print-footer-coords">
    <div>
      <strong>Coordonnées bancaires</strong><br>
      <?= ATF_BANQUE_NOM ?> — <?= ATF_BANQUE_AGENCE ?><br>
      Compte CDF : <strong><?= ATF_NUM_COMPTE_CDF ?></strong><br>
      Compte USD : <strong><?= ATF_NUM_COMPTE_USD ?></strong><br>
      IBAN : <?= ATF_IBAN ?> · SWIFT : <?= ATF_SWIFT ?>
    </div>
    <div style="text-align:right">
      <strong>Signature & cachet</strong><br>
      <div class="print-signature-box"></div>
      <div><?= ATF_DIRECTEUR ?> — <?= ATF_TITRE_DIR ?></div>
    </div>
  </div>

  <div class="print-footer-note">
    Document généré automatiquement par <?= ATF_NOM ?> — Fait foi de vos opérations.<br>
    Page <span class="page-number"></span> · Imprimé le <?= date('d/m/Y à H:i') ?>
  </div>
</div>