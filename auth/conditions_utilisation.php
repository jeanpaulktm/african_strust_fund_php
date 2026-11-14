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
<title>Conditions Générales d'Utilisation — AFRICAN TRUST FUND</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<style>
/* ============================================================
   LAYOUT LÉGAL
   ============================================================ */
.legal-wrapper {
    max-width: 950px;
    margin: 40px auto;
    padding: 0 20px;
}

.legal-header {
    padding: 32px 36px;
    border-radius: 20px;
    background: linear-gradient(135deg, rgba(245,184,0,.12), rgba(139,92,246,.08));
    border: 1px solid rgba(245,184,0,.3);
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
    background: radial-gradient(circle, rgba(245,184,0,.15), transparent 70%);
}
.legal-header .badge-top {
    display: inline-block;
    padding: 4px 14px;
    border-radius: 999px;
    background: rgba(245,184,0,.15);
    color: var(--gold);
    border: 1px solid rgba(245,184,0,.4);
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
.legal-header h1 .ico {
    font-size: 2rem;
}
.legal-header p {
    color: var(--muted);
    font-size: .92rem;
    position: relative;
    z-index: 1;
}

/* Sommaire */
.legal-toc {
    padding: 22px 26px;
    border-radius: 16px;
    background: linear-gradient(145deg, var(--card), var(--card-2));
    border: 1px solid var(--border);
    margin-bottom: 24px;
}
.legal-toc h3 {
    color: var(--gold);
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
.legal-toc li a:hover { color: var(--gold); }
.legal-toc li a::before {
    content: "▸ ";
    color: var(--gold);
    font-weight: 800;
    margin-right: 4px;
}
@media (max-width: 600px) {
    .legal-toc ol { grid-template-columns: 1fr; }
}

/* Sections */
.legal-section {
    padding: 26px 30px;
    border-radius: 16px;
    background: linear-gradient(145deg, var(--card), var(--card-2));
    border: 1px solid var(--border);
    margin-bottom: 18px;
    scroll-margin-top: 20px;
}
.legal-section h2 {
    color: var(--gold);
    font-size: 1.15rem;
    margin-bottom: 16px;
    padding-bottom: 10px;
    border-bottom: 2px solid rgba(245,184,0,.2);
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
.legal-section a { color: #93B4FF; text-decoration: none; }
.legal-section a:hover { text-decoration: underline; }

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

/* Footer actions */
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
.legal-footer .info {
    color: var(--muted);
    font-size: .82rem;
}
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
    border: none;
    cursor: pointer;
    font-family: inherit;
}
.btn-legal.primary {
    background: linear-gradient(135deg, #F5B800, #D4AF37);
    color: #0B0E14;
    box-shadow: 0 6px 20px rgba(245,184,0,.4);
}
.btn-legal.primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 28px rgba(245,184,0,.5);
}
.btn-legal.ghost {
    background: rgba(255,255,255,.08);
    color: var(--text);
    border: 1px solid var(--border);
}
.btn-legal.ghost:hover { background: rgba(255,255,255,.14); }

/* Date */
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
        <span class="badge-top">📜 Document légal</span>
        <h1><span class="ico">📋</span> Conditions Générales d'Utilisation</h1>
        <p>Version 2.0 · En vigueur depuis le <?= date('d/m/Y') ?> · AFRICAN TRUST FUND</p>
    </div>

    <!-- SOMMAIRE -->
    <div class="legal-toc">
        <h3>📑 Sommaire</h3>
        <ol>
            <li><a href="#article-1">1. Objet et acceptation</a></li>
            <li><a href="#article-2">2. Inscription et compte</a></li>
            <li><a href="#article-3">3. Utilisation du service</a></li>
            <li><a href="#article-4">4. Sécurité et confidentialité</a></li>
            <li><a href="#article-5">5. Opérations financières</a></li>
            <li><a href="#article-6">6. Prêts et crédits</a></li>
            <li><a href="#article-7">7. Obligations de l'utilisateur</a></li>
            <li><a href="#article-8">8. Responsabilités</a></li>
            <li><a href="#article-9">9. Suspension et résiliation</a></li>
            <li><a href="#article-10">10. Modification des CGU</a></li>
            <li><a href="#article-11">11. Droit applicable</a></li>
            <li><a href="#article-12">12. Contact</a></li>
        </ol>
    </div>

    <!-- ARTICLE 1 -->
    <div class="legal-section" id="article-1">
        <h2>1. Objet et acceptation</h2>
        <p>
            Les présentes Conditions Générales d'Utilisation (ci-après « CGU ») régissent 
            l'accès et l'utilisation de la plateforme <strong>AFRICAN TRUST FUND</strong> 
            (ci-après « ATF » ou « la Plateforme »), service de microfinance et 
            d'épargne en ligne.
        </p>
        <p>
            En cochant la case d'acceptation lors de la connexion, l'utilisateur reconnaît 
            avoir lu, compris et accepté sans réserve l'intégralité des présentes CGU.
        </p>
        <div class="legal-notice">
            <strong>⚠️ Acceptation obligatoire</strong>
            L'accès aux services de la Plateforme est conditionné à l'acceptation pleine 
            et entière des CGU. En cas de refus, l'utilisateur ne pourra pas accéder à son compte.
        </div>
    </div>

    <!-- ARTICLE 2 -->
    <div class="legal-section" id="article-2">
        <h2>2. Inscription et compte</h2>
        <p>Pour utiliser la Plateforme, l'utilisateur doit :</p>
        <ul>
            <li>Être majeur(e) et juridiquement capable</li>
            <li>Fournir des informations exactes, complètes et à jour</li>
            <li>Créer un compte personnel et confidentiel</li>
            <li>Ne pas créer plusieurs comptes pour une même personne</li>
        </ul>
        <h3>Types de comptes</h3>
        <ul>
            <li><strong>Personne physique :</strong> particulier souhaitant épargner ou emprunter</li>
            <li><strong>Personne morale (Entreprise) :</strong> société, SARL, SA, coopérative, etc.</li>
        </ul>
        <p>
            ATF se réserve le droit de refuser toute inscription ou de suspendre un compte 
            sans justification préalable.
        </p>
    </div>

    <!-- ARTICLE 3 -->
    <div class="legal-section" id="article-3">
        <h2>3. Utilisation du service</h2>
        <p>L'utilisateur s'engage à utiliser la Plateforme uniquement pour des fins légales. 
        Sont strictement interdits :</p>
        <ul>
            <li>Le blanchiment d'argent ou le financement du terrorisme</li>
            <li>La fraude, l'usurpation d'identité ou la falsification de documents</li>
            <li>La tentative de piratage, d'intrusion ou de contournement des mesures de sécurité</li>
            <li>L'utilisation de robots, scripts ou logiciels automatisés</li>
            <li>Toute activité contraire aux lois de la République Démocratique du Congo</li>
        </ul>
        <div class="legal-notice danger">
            <strong>🚫 Activités illégales</strong>
            Toute utilisation frauduleuse entraînera la fermeture immédiate du compte 
            et des poursuites judiciaires conformément à la législation en vigueur.
        </div>
    </div>

    <!-- ARTICLE 4 -->
    <div class="legal-section" id="article-4">
        <h2>4. Sécurité et confidentialité</h2>
        <h3>4.1 Protection des accès</h3>
        <p>L'utilisateur est responsable de :</p>
        <ul>
            <li>La confidentialité de son mot de passe</li>
            <li>La sécurité de son appareil de connexion</li>
            <li>Toute activité effectuée depuis son compte</li>
        </ul>
        <h3>4.2 Mesures de sécurité ATF</h3>
        <p>ATF met en œuvre :</p>
        <ul>
            <li>Chiffrement SSL/TLS de toutes les communications</li>
            <li>Hashage des mots de passe (bcrypt)</li>
            <li>Protection anti-fraude et anti-intrusion</li>
            <li>Surveillance continue des connexions suspectes</li>
        </ul>
        <h3>4.3 Signalement</h3>
        <p>
            Tout accès non autorisé ou tentative de fraude doit être signalé immédiatement 
            à : <strong>securite@africantrustfund.cd</strong>
        </p>
    </div>

    <!-- ARTICLE 5 -->
    <div class="legal-section" id="article-5">
        <h2>5. Opérations financières</h2>
        <h3>5.1 Dépôts</h3>
        <p>Les dépôts sont effectués en CDF ou USD selon la devise choisie. Aucune 
        conversion automatique n'est effectuée.</p>
        <h3>5.2 Retraits</h3>
        <p>Les retraits sont soumis à :</p>
        <ul>
            <li>Vérification d'identité en cas de montant élevé</li>
            <li>Commission de retrait selon grille tarifaire en vigueur</li>
            <li>Délai de traitement (24-48h ouvrables)</li>
        </ul>
        <h3>5.3 Virements</h3>
        <p>Les virements entre comptes ATF sont instantanés et irréversibles. 
        L'utilisateur doit vérifier avec soin le numéro de compte du destinataire.</p>
    </div>

    <!-- ARTICLE 6 -->
    <div class="legal-section" id="article-6">
        <h2>6. Prêts et crédits</h2>
        <h3>6.1 Demande de prêt</h3>
        <p>Toute demande de prêt est soumise à :</p>
        <ul>
            <li>Analyse du dossier par un agent de crédit</li>
            <li>Évaluation du score de crédit</li>
            <li>Vérification des documents fournis</li>
            <li>Approbation par l'administrateur</li>
        </ul>
        <h3>6.2 Remboursement</h3>
        <p>L'utilisateur s'engage à rembourser son prêt selon l'échéancier défini. 
        Tout retard de paiement entraîne :</p>
        <ul>
            <li>Des pénalités de retard</li>
            <li>Une réduction du score de crédit</li>
            <li>Un risque de blocage du compte</li>
        </ul>
        <div class="legal-notice warning">
            <strong>⚠️ Important</strong>
            Les intérêts et pénalités sont calculés automatiquement selon les conditions 
            du produit de prêt souscrit.
        </div>
    </div>

    <!-- ARTICLE 7 -->
    <div class="legal-section" id="article-7">
        <h2>7. Obligations de l'utilisateur</h2>
        <p>L'utilisateur s'engage à :</p>
        <ul>
            <li>Fournir des informations exactes et à jour</li>
            <li>Signaler tout changement de coordonnées</li>
            <li>Ne pas partager ses identifiants</li>
            <li>Respecter les présentes CGU et la loi congolaise</li>
            <li>Ne pas tenter de nuire à la Plateforme</li>
        </ul>
    </div>

    <!-- ARTICLE 8 -->
    <div class="legal-section" id="article-8">
        <h2>8. Responsabilités</h2>
        <h3>8.1 Responsabilité d'ATF</h3>
        <p>ATF ne peut être tenue responsable :</p>
        <ul>
            <li>En cas de force majeure (catastrophe, panne réseau majeure)</li>
            <li>D'une utilisation frauduleuse par un tiers avec les identifiants volés</li>
            <li>Des interruptions temporaires de service pour maintenance</li>
        </ul>
        <h3>8.2 Responsabilité de l'utilisateur</h3>
        <p>L'utilisateur est seul responsable :</p>
        <ul>
            <li>De la confidentialité de ses identifiants</li>
            <li>De l'exactitude des informations fournies</li>
            <li>Des conséquences de ses actes sur la Plateforme</li>
        </ul>
    </div>

    <!-- ARTICLE 9 -->
    <div class="legal-section" id="article-9">
        <h2>9. Suspension et résiliation</h2>
        <p>ATF peut suspendre ou résilier un compte en cas de :</p>
        <ul>
            <li>Violation des présentes CGU</li>
            <li>Activité frauduleuse ou suspecte</li>
            <li>Non-respect des obligations financières</li>
            <li>Demande des autorités compétentes</li>
        </ul>
        <p>L'utilisateur peut également demander la fermeture de son compte à tout moment, 
        sous réserve de régler ses obligations en cours.</p>
    </div>

    <!-- ARTICLE 10 -->
    <div class="legal-section" id="article-10">
        <h2>10. Modification des CGU</h2>
        <p>
            ATF se réserve le droit de modifier les présentes CGU à tout moment. 
            Les utilisateurs seront informés par email ou notification dans l'application. 
            L'utilisation continue du service après modification vaut acceptation.
        </p>
    </div>

    <!-- ARTICLE 11 -->
    <div class="legal-section" id="article-11">
        <h2>11. Droit applicable</h2>
        <p>
            Les présentes CGU sont régies par le droit de la <strong>République Démocratique 
            du Congo</strong>. Tout litige sera soumis aux tribunaux compétents de Kinshasa.
        </p>
    </div>

    <!-- ARTICLE 12 -->
    <div class="legal-section" id="article-12">
        <h2>12. Contact</h2>
        <p>
            <strong>AFRICAN TRUST FUND</strong><br>
            📍 Avenue de la Paix n°45, Kinshasa-Gombe, RDC<br>
            📞 +243 992 863 230 / +243 975 272 623<br>
            ✉️ contact@africantrustfund.cd<br>
            🌐 www.africantrustfund.cd<br>
            RCCM : CD/KIN/RCCM/24-B-01234<br>
            NIF : A0123456Z
        </p>
    </div>

    <!-- FOOTER ACTIONS -->
    <div class="legal-footer">
        <div class="info">
            📅 Dernière mise à jour : <?= date('d/m/Y') ?>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
            <a href="<?= $base ?>/auth/login.php" class="btn-legal ghost">
                ← Retour à la connexion
            </a>
            <a href="<?= $base ?>/auth/politique_confidentialite.php" class="btn-legal primary">
                🔒 Politique de confidentialité
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