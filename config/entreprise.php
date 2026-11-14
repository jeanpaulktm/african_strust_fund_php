<?php
declare(strict_types=1);

/**
 * ============================================================
 * INFORMATIONS DE L'ENTREPRISE — AFRICAN TRUST FUND
 * Modifiez ces valeurs selon vos besoins réels
 * ============================================================
 */

/* Identité */
define('ATF_NOM',           'AFRICAN TRUST FUND');
define('ATF_SLOGAN',        "L'argent de l'Afrique pour construire l'Afrique");
define('ATF_FORME_JURIDIQUE', 'Société de gestion d\'actifs ()');
define('ATF_RCCM',          'CD/KIN/RCCM/24-B-01234');
define('ATF_ID_NAT',        '01-234-N56789A');
define('ATF_NIF',           'A0123456Z');

/* Adresse */
define('ATF_ADRESSE',       'Avenue de la Paix n°45, Commune de la Gombe');
define('ATF_VILLE',         'BENI');
define('ATF_PAYS',          'République Démocratique du Congo');
define('ATF_BP',            'BP 1234 BENI');

/* Contact */
define('ATF_TEL_FIXE',      '+243 097 272 623');
define('ATF_TEL_MOBILE',    '+243 992 863 230');
define('ATF_EMAIL',         'contact@africantrustfund.cd');
define('ATF_SITE_WEB',      'www.africantrustfund.cd');

/* Coordonnées bancaires */
define('ATF_BANQUE_NOM',    'Rawbank');
define('ATF_BANQUE_AGENCE', 'Agence BENI');
define('ATF_NUM_COMPTE_CDF','00012-12345-6789012-34');
define('ATF_NUM_COMPTE_USD','00012-98765-4321098-76');
define('ATF_IBAN',          'CD12 0001 2123 4567 8901 2345');
define('ATF_SWIFT',         'RAWBCDKI');
define('ATF_NUM_IMPOT',     'A/2024/123456');

/* Direction */
define('ATF_DIRECTEUR',     'M. Jean-Paul KATEMBO');
define('ATF_TITRE_DIR',     'Directeur Général');

/* Devise & taux */
define('ATF_DEVISE',        'CDF');
define('ATF_DEVISE_SEC',    'USD');
define('TAUX_USD_CDF',      2300);

/**
 * Retourne le bloc d'informations bancaires formaté
 * Utilisable dans les reçus et rapports
 */
function getInfosBancaires(): array
{
    return [
        'banque'      => ATF_BANQUE_NOM,
        'agence'      => ATF_BANQUE_AGENCE,
        'compte_cdf'  => ATF_NUM_COMPTE_CDF,
        'compte_usd'  => ATF_NUM_COMPTE_USD,
        'iban'        => ATF_IBAN,
        'swift'       => ATF_SWIFT,
        'rccm'        => ATF_RCCM,
        'id_nat'      => ATF_ID_NAT,
        'nif'         => ATF_NIF,
    ];
}
?>