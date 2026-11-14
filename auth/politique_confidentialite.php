<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/paths.php';

sendSecurityHeaders();
startSecureSession();

$base = BASE_URL;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Politique de Confidentialité — AFRICAN TRUST FUND</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<style>
/* ============================================================
   LAYOUT LÉGAL (identique à CGU)
   ============================================================ */
.legal-wrapper { max-width: 950px; margin: 40px auto; padding: 0 20px; }

.legal-header {
    padding: 32px 36px;
    border-radius: 20px;
    background: linear-gradient(135deg, rgba(139,92,246,.15), rgba(59,130,246,.08));
    border: 1px solid rgba(139,92,246,.35);
    margin-bottom: 28px;
    position: relative;
    overflow: hidden;
}
.legal-header::before {
    content: "";
    position: absolute;
    top: -40%; right: -10%;
    width: 300px; height: 300px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(139,92,246,.2), transparent 70%);
}
.legal-header .badge-top {
    display: inline-block;
    padding: 4px 14px;
    border-radius: 999px;
    background: rgba(139,92,246,.2);
    color: #C4B5FD;
    border: 1px solid rgba(139,92,246,.4);
    font-size: .72rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    margin-bottom: 14px;
    position: relative;
    z-index: 1;
}
.legal-header h1 {
    color: #fff;
    font-size: 1.9rem;
    margin-bottom: 10px;
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.legal-header h1 .ico { font-size: 2rem; }
.legal-header p { color: var(--muted); font-size: .92rem; position: relative; z-index: 1; }

.legal-toc {
    padding: 22px 26px;
    border-radius: 16px;
    background: linear-gradient(145deg, var(--card), var(--card-2));
    border: 1px solid var(--border);
    margin-bottom: 24px;
}
.legal-toc h3 {
    color: #C4B5FD;
    font-size: 1rem;
    margin-bottom: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.legal-toc ol {
    list-style: none;
    padding: 0;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px 20px;
}
.legal-toc li a {
    color: var(--text);
    text-decoration: none;
    font-size: .85rem;
    padding: 6px 0;
    display: block;
    transition: color .2s;
}
.legal-toc li a:hover { color: #C4B5FD; }
.legal-toc li a::before {
    content: "▸ ";
    color: #A78BFA;
    font-weight: 800;
    margin-right: 4px;
}

.legal-section {
    padding: 26px 30px;
    border-radius: 16px;
    background: linear-gradient(145deg, var(--card), var(--card-2));
    border: 1px solid var(--border);
    margin-bottom: 18px;
    scroll-margin-top: 20px;
}
.legal-section h2 {
    color: #C4B5FD;
    font-size: 1.15rem;
    margin-bottom: 16px;
    padding-bottom: 10px;
    border-bottom: 2px solid rgba(139,92,246,.25);
    display: flex;
    align-items: center;
    gap: 10px;
}
.legal-section h3 {
    color: #93B4FF;
    font-size: .98rem;
    margin: 18px 0 10px;
}
.legal-section p {
    color: var(--text);
    font-size: .9rem;
    line-height: 1.7;
    margin-bottom: 12px;
}
.legal-section ul, .legal-section ol {
    padding-left: 22px;
    margin-bottom: 12px;
}
.legal-section li {
    color: var(--text);
    font-size: .88rem;
    line-height: 1.7;
    margin-bottom: 6px;
}
.legal-section strong { color: #fff; }

/* Tableau des données */
.data-table {
    width: 100%;
    border-collapse: collapse;
    margin: 14px 0;
    font-size: .85rem;
    border-radius: 10px;
    overflow: hidden;
}
.data-table thead { background: rgba(139,92,246,.15); }
.data-table th {
    padding: 10px 14px;
    text-align: left;
    color: #C4B5FD;
    font-weight: 800;
    font-size: .75rem;
    text-transform: uppercase;
    letter-spacing: .5px;
}
.data-table td {
    padding: 10px 14px;
    border-bottom: 1px solid var(--border);
    color: var(--text);
    font-size: .85rem;
}
.data-table tr:last-child td { border-bottom: none; }

/* Box importante */
.legal-notice {
    padding: 16px 18px;
    border-radius: 12px;
    background: rgba(59,130,246,.08);
    border-left: 4px solid #3B82F6;
    margin: 16px 0;
    font-size: .88rem;
    color: #93B4FF;
    line-height: 1.6;
}
.legal-notice.success {
    background: rgba(16,185,129,.08);
    border-left-color: #10B981;
    color: #6EE7B7;
}
.legal-notice.warning {
    background: rgba(245,184,0,.08);
    border-left-color: #F5B800;
    color: #FCD34D;
}
.legal-notice.danger {
    background: rgba(239,68,68,.08);
    border-left-color: #EF4444;
    color: #FCA5A5;
}
.legal-notice strong { color: #fff; display: block; margin-bottom: 4px; }

/* Droits */
.droits-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 12px;
    margin: 16px 0;
}
.droit-card {
    padding: 16px;
    border-radius: 12px;
    background: rgba(139,92,246,.08);
    border: 1px solid rgba(139,92,246,.25);
}
.droit-card .ico {
    font-size: 1.5rem;
    margin-bottom: 8px;
}
.droit-card .title {
    color: #C4B5FD;
    font-weight: 800;
    font-size: .88rem;
    margin-bottom: 6px;
}
.droit-card .desc {
    color: var(--muted);
    font-size: .78rem;
    line-height: 1.5;
}

.legal-footer {
    padding: 24px 28px;
    border-radius: 16px;
    background: linear-gradient(145deg, var(--card), var(--card-2));
    border: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
    flex-wrap: wrap;
    margin-bottom: 30px;
}
.legal-footer .info { color: var(--muted); font-size: .82rem; }
.btn-legal {
    padding: 14px 26px;
    border-radius: 999px;
    font-weight: 800;
    font-size: .9rem;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all .25s;
}
.btn-legal.primary {
    background: linear-gradient(135deg, #8B5CF6, #7C3AED);
    color: #fff;
    box-shadow: 0 6px 20px rgba(139,92,246,.4);
}
.btn-legal.primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 28px rgba(139,92,246,.5);
    color: #fff;
}
.btn-legal.ghost {
    background: rgba(255,255,255,.08);
    color: var(--text);
    border: 1px solid var(--border);
}
.btn-legal.ghost:hover { background: rgba(255,255,255,.14); }

.legal-date {
    text-align: center;
    color: var(--muted);
    font-size: .78rem;
    padding: 16px;
    border-top: 1px solid var(--border);
    margin-top: 20px;
}

@media (max-width: 640px) {
    .legal-wrapper { margin: 20px auto; padding: 0 12px; }
    .legal-header { padding: 22px 20px; }
    .legal-header h1 { font-size: 1.4rem; }
    .legal-section { padding: 20px 18px; }
    .legal-toc ol { grid-template-columns: 1fr; }
    .legal-footer { flex-direction: column; align-items: stretch; }
    .btn-legal { justify-content: center; }
}
</style>
</head>
<body class="dark-auth">

<?php require __DIR__ . '/../includes/header.php'; ?>

<div class="legal-wrapper">

    <!-- HEADER -->
    <div class="legal-header">
        <span class="badge-top">🔒 Document légal</span>
        <h1><span class="ico">🛡️</span> Politique de Confidentialité</h1>
        <p>Version 2.0 · En vigueur depuis le <?= date('d/m/Y') ?> · AFRICAN TRUST FUND</p>
    </div>

    <!-- SOMMAIRE -->
    <div class="legal-toc">
        <h3>📑 Sommaire</h3>
        <ol>
            <li><a href="#art-1">1. Introduction</a></li>
            <li><a href="#art-2">2. Données collectées</a></li>
            <li><a href="#art-3">3. Finalités du traitement</a></li>
            <li><a href="#art-4">4. Base légale</a></li>
            <li><a href="#art-5">5. Partage des données</a></li>
            <li><a href="#art-6">6. Durée de conservation</a></li>
            <li><a href="#art-7">7. Sécurité des données</a></li>
            <li><a href="#art-8">8. Vos droits</a></li>
            <li><a href="#art-9">9. Cookies</a></li>
            <li><a href="#art-10">10. Transferts internationaux</a></li>
            <li><a href="#art-11">11. Mineurs</a></li>
            <li><a href="#art-12">12. Modifications</a></li>
            <li><a href="#art-13">13. Contact DPO</a></li>
        </ol>
    </div>

    <!-- ARTICLE 1 -->
    <div class="legal-section" id="art-1">
        <h2>1. Introduction</h2>
        <p>
            <strong>AFRICAN TRUST FUND</strong> (ci-après « ATF », « nous ») s'engage 
            à protéger la vie privée et les données personnelles de ses utilisateurs 
            (ci-après « vous »).
        </p>
        <p>
            La présente Politique de Confidentialité explique :
        </p>
        <ul>
            <li>Quelles données nous collectons</li>
            <li>Pourquoi nous les collectons</li>
            <li>Comment nous les utilisons et les protégeons</li>
            <li>Vos droits sur vos données</li>
        </ul>
        <div class="legal-notice success">
            <strong>✅ Notre engagement</strong>
            ATF respecte la <strong>Loi n°20/017 du 25 novembre 2020</strong> relative aux 
            télécommunications et aux technologies de l'information et de la communication 
            en RDC, ainsi que le Règlement Général sur la Protection des Données (RGPD) 
            pour les utilisateurs européens.
        </div>
    </div>

    <!-- ARTICLE 2 -->
    <div class="legal-section" id="art-2">
        <h2>2. Données collectées</h2>
        <p>Nous collectons les catégories de données suivantes :</p>

        <h3>2.1 Données d'identification</h3>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Type de données</th>
                    <th>Exemples</th>
                    <th>Obligatoire</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Identité</td>
                    <td>Nom, post-nom, prénom</td>
                    <td>✅ Oui</td>
                </tr>
                <tr>
                    <td>Contact</td>
                    <td>Téléphone, email, adresse</td>
                    <td>✅ Oui</td>
                </tr>
                <tr>
                    <td>Documents</td>
                    <td>Carte d'électeur, passeport, photo</td>
                    <td>✅ Oui</td>
                </tr>
                <tr>
                    <td>Financières</td>
                    <td>Soldes, transactions, prêts</td>
                    <td>✅ Oui</td>
                </tr>
                <tr>
                    <td>Entreprise (PM)</td>
                    <td>RCCM, NIF, statuts, bilans</td>
                    <td>✅ Oui</td>
                </tr>
            </tbody>
        </table>

        <h3>2.2 Données techniques</h3>
        <ul>
            <li>Adresse IP</li>
            <li>Type d'appareil et navigateur</li>
            <li>Date et heure de connexion</li>
            <li>Système d'exploitation</li>
        </ul>

        <h3>2.3 Données d'usage</h3>
        <ul>
            <li>Historique des transactions</li>
            <li>Pages consultées</li>
            <li>Actions effectuées</li>
            <li>Préférences utilisateur</li>
        </ul>
        <h3>Détection des appareils</h3>
<p>
    Pour votre sécurité, ATF collecte automatiquement :
</p>
<ul>
    <li>Votre adresse IP</li>
    <li>Le type d'appareil et navigateur</li>
    <li>La localisation approximative (par IP)</li>
    <li>Les tentatives de connexion</li>
</ul>
<p>
    Ces données sont utilisées uniquement pour <strong>détecter les fraudes</strong> 
    et <strong>protéger votre compte</strong>. Elles ne sont jamais vendues ni 
    partagées à des fins commerciales.
</p>
    </div>


    <!-- ARTICLE 3 -->
    <div class="legal-section" id="art-3">
        <h2>3. Finalités du traitement</h2>
        <p>Vos données sont utilisées pour :</p>
        <ul>
            <li>🔐 <strong>Vérifier votre identité</strong> (KYC - Know Your Customer)</li>
            <li>💼 <strong>Gérer votre compte</strong> et vos opérations financières</li>
            <li>📊 <strong>Analyser vos demandes de prêt</strong> et calculer votre score</li>
            <li>📢 <strong>Vous informer</strong> des changements de service</li>
            <li>🛡️ <strong>Prévenir la fraude</strong> et sécuriser la plateforme</li>
            <li>📋 <strong>Respecter nos obligations légales</strong> (BCC, autorités fiscales)</li>
            <li>📈 <strong>Améliorer nos services</strong> et statistiques anonymisées</li>
        </ul>
    </div>

    <!-- ARTICLE 4 -->
    <div class="legal-section" id="art-4">
        <h2>4. Base légale du traitement</h2>
        <p>Le traitement de vos données repose sur :</p>
        <ul>
            <li><strong>Votre consentement</strong> (création de compte)</li>
            <li><strong>L'exécution d'un contrat</strong> (services financiers)</li>
            <li><strong>Le respect d'obligations légales</strong> (KYC, BCC)</li>
            <li><strong>Nos intérêts légitimes</strong> (sécurité, anti-fraude)</li>
        </ul>
    </div>

    <!-- ARTICLE 5 -->
    <div class="legal-section" id="art-5">
        <h2>5. Partage des données</h2>
        <p>ATF ne vend <strong>jamais</strong> vos données. Elles peuvent être partagées uniquement avec :</p>
        <ul>
            <li><strong>Autorités légales :</strong> Banque Centrale du Congo, justice, fisc</li>
            <li><strong>Prestataires techniques :</strong> hébergeurs, développeurs (sous contrat)</li>
            <li><strong>Partenaires bancaires :</strong> pour transferts interbancaires</li>
            <li><strong>Auditeurs :</strong> dans le cadre de la conformité</li>
        </ul>
        <div class="legal-notice warning">
            <strong>⚠️ Vos droits</strong>
            Aucune donnée n'est partagée à des fins commerciales ou publicitaires sans votre 
            consentement explicite.
        </div>
    </div>

    <!-- ARTICLE 6 -->
    <div class="legal-section" id="art-6">
        <h2>6. Durée de conservation</h2>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Type de données</th>
                    <th>Durée</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>Compte actif</td>
                    <td>Pendant toute la durée du compte</td>
                </tr>
                <tr>
                    <td>Transactions financières</td>
                    <td>10 ans (obligation légale)</td>
                </tr>
                <tr>
                    <td>Documents KYC</td>
                    <td>5 ans après fermeture du compte</td>
                </tr>
                <tr>
                    <td>Logs de connexion</td>
                    <td>1 an</td>
                </tr>
                <tr>
                    <td>Compte inactif</td>
                    <td>Supprimé après 3 ans d'inactivité</td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- ARTICLE 7 -->
    <div class="legal-section" id="art-7">
        <h2>7. Sécurité des données</h2>
        <p>ATF met en œuvre les mesures de sécurité suivantes :</p>
        <ul>
            <li>🔐 <strong>Chiffrement SSL/TLS</strong> de toutes les communications</li>
            <li>🔑 <strong>Hashage bcrypt</strong> des mots de passe (cost 12)</li>
            <li>🛡️ <strong>Firewall</strong> et protection anti-DDoS</li>
            <li>📊 <strong>Surveillance 24/7</strong> des accès</li>
            <li>📝 <strong>Audit trail</strong> de toutes les actions</li>
            <li>💾 <strong>Sauvegardes chiffrées</strong> quotidiennes</li>
            <li>👥 <strong>Accès limité</strong> aux données (principe du moindre privilège)</li>
        </ul>
        <div class="legal-notice danger">
            <strong>🚨 En cas de violation</strong>
            Si une violation de données survient, ATF s'engage à vous informer dans un 
            délai de 72 heures conformément à la loi.
        </div>
    </div>

    <!-- ARTICLE 8 -->
    <div class="legal-section" id="art-8">
        <h2>8. Vos droits</h2>
        <p>Conformément à la loi, vous disposez des droits suivants :</p>
        
        <div class="droits-grid">
            <div class="droit-card">
                <div class="ico">👁️</div>
                <div class="title">Droit d'accès</div>
                <div class="desc">Consulter toutes vos données personnelles</div>
            </div>
            <div class="droit-card">
                <div class="ico">✏️</div>
                <div class="title">Droit de rectification</div>
                <div class="desc">Corriger des données inexactes</div>
            </div>
            <div class="droit-card">
                <div class="ico">🗑️</div>
                <div class="title">Droit à l'effacement</div>
                <div class="desc">Supprimer vos données (sauf obligations légales)</div>
            </div>
            <div class="droit-card">
                <div class="ico">⛔</div>
                <div class="title">Droit d'opposition</div>
                <div class="desc">Refuser certains traitements</div>
            </div>
            <div class="droit-card">
                <div class="ico">📦</div>
                <div class="title">Droit à la portabilité</div>
                <div class="desc">Récupérer vos données dans un format standard</div>
            </div>
            <div class="droit-card">
                <div class="ico">🔒</div>
                <div class="title">Droit à la limitation</div>
                <div class="desc">Limiter l'usage de vos données</div>
            </div>
        </div>

        <p style="margin-top:16px">
            Pour exercer ces droits, contactez : <strong>dpo@africantrustfund.cd</strong><br>
            Délai de réponse : 30 jours maximum.
        </p>
    </div>

    <!-- ARTICLE 9 -->
    <div class="legal-section" id="art-9">
        <h2>9. Cookies</h2>
        <p>ATF utilise des cookies pour :</p>
        <ul>
            <li><strong>Cookies essentiels :</strong> Session, authentification (obligatoires)</li>
            <li><strong>Cookies de sécurité :</strong> Protection CSRF</li>
            <li><strong>Cookies de préférences :</strong> Langue, thème</li>
        </ul>
        <p>Vous pouvez gérer les cookies via les paramètres de votre navigateur. 
        La désactivation des cookies essentiels peut empêcher l'accès au service.</p>
    </div>

    <!-- ARTICLE 10 -->
    <div class="legal-section" id="art-10">
        <h2>10. Transferts internationaux</h2>
        <p>
            Vos données sont hébergées en <strong>République Démocratique du Congo</strong>. 
            Aucun transfert vers un pays tiers n'est effectué sans garanties appropriées.
        </p>
    </div>

    <!-- ARTICLE 11 -->
    <div class="legal-section" id="art-11">
        <h2>11. Mineurs</h2>
        <p>
            La Plateforme ATF est réservée aux personnes <strong>majeures (18 ans et plus)</strong>. 
            Nous ne collectons pas sciemment de données concernant des mineurs.
        </p>
    </div>

    <!-- ARTICLE 12 -->
    <div class="legal-section" id="art-12">
        <h2>12. Modifications</h2>
        <p>
            ATF peut modifier cette Politique de Confidentialité. En cas de changement 
            majeur, vous serez informé par email ou notification 30 jours avant l'entrée 
            en vigueur.
        </p>
    </div>

    <!-- ARTICLE 13 -->
    <div class="legal-section" id="art-13">
        <h2>13. Contact DPO</h2>
        <p>
            <strong>Délégué à la Protection des Données (DPO)</strong><br>
            📧 dpo@africantrustfund.cd<br>
            📞 +243 992 863 230<br>
            📍 Avenue de la Paix n°45, Kinshasa-Gombe, RDC
        </p>
        <p style="margin-top:12px">
            Vous pouvez également adresser une réclamation à l'autorité compétente 
            en matière de protection des données en RDC.
        </p>
    </div>

    <!-- FOOTER ACTIONS -->
    <div class="legal-footer">
        <div class="info">
            📅 Dernière mise à jour : <?= date('d/m/Y') ?>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
            <a href="<?= $base ?>/auth/connexion_client.php" class="btn-legal ghost">
                ← Retour à la connexion
            </a>
            <a href="<?= $base ?>/auth/conditions_utilisation.php" class="btn-legal primary">
                📋 Conditions d'utilisation
            </a>
        </div>
    </div>

    <div class="legal-date">
        © <?= date('Y') ?> AFRICAN TRUST FUND — Tous droits réservés
    </div>

</div>

<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>