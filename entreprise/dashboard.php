<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth_guard_entreprise.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/prets.php';

$pdo = Database::getConnection();
$id = (int)$_SESSION['entreprise_id'];

/* ============================================================
   CHARGER LES INFOS ENTREPRISE
   ============================================================ */
$stmt = $pdo->prepare("
    SELECT c.*, e.id_entreprise, e.raison_sociale, e.forme_juridique, 
           e.rccm, e.nif, e.secteur_activite, e.capital_social, e.devise_capital,
           e.dirigeant_nom, e.dirigeant_phone, e.statut_verification,
           e.date_creation, e.nombre_employes
    FROM Client c
    LEFT JOIN Entreprises e ON e.id_client = c.id_client
    WHERE c.id_client = ?
");
$stmt->execute([$id]);
$entreprise = $stmt->fetch();

if (!$entreprise) {
    session_destroy();
    header('Location: /african-trust-fund/entreprise/login.php');
    exit;
}

/* Soldes */
$solde_cdf = (float)($entreprise['solde_cdf'] ?? 0);
$solde_usd = (float)($entreprise['solde_usd'] ?? 0);
$taux_usd_cdf = 2800;
$solde_total_cdf = $solde_cdf + ($solde_usd * $taux_usd_cdf);

/* ============================================================
   STATS PLACEMENTS
   ============================================================ */
$stmt = $pdo->prepare("
    SELECT 
        COUNT(CASE WHEN statut = 'actif' THEN 1 END) AS nb_actifs,
        COALESCE(SUM(CASE WHEN devise = 'CDF' AND statut = 'actif' THEN montant_initial ELSE 0 END), 0) AS capital_cdf,
        COALESCE(SUM(CASE WHEN devise = 'USD' AND statut = 'actif' THEN montant_initial ELSE 0 END), 0) AS capital_usd,
        COALESCE(SUM(interets_cumules), 0) AS total_interets
    FROM Placements WHERE id_client = ?
");
$stmt->execute([$id]);
$stats_epargne = $stmt->fetch();

$capital_cdf = (float)$stats_epargne['capital_cdf'];
$capital_usd = (float)$stats_epargne['capital_usd'];
$capital_total_equiv_cdf = $capital_cdf + ($capital_usd * $taux_usd_cdf);

/* ============================================================
   STATS PRÊTS
   ============================================================ */
$stats_prets = [
    'nb_total' => 0, 'nb_actifs' => 0, 'nb_en_attente' => 0,
    'nb_retard' => 0, 'nb_soldes' => 0,
    'encours_cdf' => 0, 'encours_usd' => 0,
];

try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) AS nb_total,
            COUNT(CASE WHEN statut IN ('decaisse','en_cours') THEN 1 END) AS nb_actifs,
            COUNT(CASE WHEN statut IN ('soumis','en_analyse','approuve') THEN 1 END) AS nb_en_attente,
            COUNT(CASE WHEN statut = 'en_retard' THEN 1 END) AS nb_retard,
            COUNT(CASE WHEN statut = 'solde' THEN 1 END) AS nb_soldes,
            COALESCE(SUM(CASE WHEN statut IN ('decaisse','en_cours','en_retard') AND devise='CDF' THEN montant_restant_du ELSE 0 END), 0) AS encours_cdf,
            COALESCE(SUM(CASE WHEN statut IN ('decaisse','en_cours','en_retard') AND devise='USD' THEN montant_restant_du ELSE 0 END), 0) AS encours_usd
        FROM Prets WHERE id_client = ?
    ");
    $stmt->execute([$id]);
    $stats_prets = $stmt->fetch();
} catch (Throwable $e) { /* */ }

/* Prêt actif */
$pret_actif = null;
$prochaine_echeance = null;
try {
    $stmt = $pdo->prepare("
        SELECT p.*, pr.nom AS produit_nom
        FROM Prets p
        JOIN Produits_Pret pr ON pr.id_produit = p.id_produit
        WHERE p.id_client = ? AND p.statut IN ('decaisse','en_cours','en_retard')
        ORDER BY p.date_decaissement DESC LIMIT 1
    ");
    $stmt->execute([$id]);
    $pret_actif = $stmt->fetch();

    if ($pret_actif) {
        $stmt = $pdo->prepare("
            SELECT * FROM Echeances_Pret
            WHERE id_pret = ? AND statut IN ('en_attente','partiel','retard')
            ORDER BY date_echeance ASC LIMIT 1
        ");
        $stmt->execute([(int)$pret_actif['id_pret']]);
        $prochaine_echeance = $stmt->fetch();
        
        $pret_actif['progression'] = (float)($pret_actif['montant_total_du'] ?? 0) > 0
            ? min(100, ((float)$pret_actif['montant_paye'] / (float)$pret_actif['montant_total_du']) * 100)
            : 0;
    }
} catch (Throwable $e) { /* */ }

/* Demande en cours */
$demande_en_cours = null;
try {
    $stmt = $pdo->prepare("
        SELECT p.*, pr.nom AS produit_nom
        FROM Prets p
        JOIN Produits_Pret pr ON pr.id_produit = p.id_produit
        WHERE p.id_client = ? AND p.statut IN ('soumis','en_analyse','approuve')
        ORDER BY p.date_demande DESC LIMIT 1
    ");
    $stmt->execute([$id]);
    $demande_en_cours = $stmt->fetch();
} catch (Throwable $e) { /* */ }

/* Dernières transactions */
$stmt = $pdo->prepare("
    SELECT * FROM Transactions WHERE id_client = ?
    ORDER BY date_transaction DESC LIMIT 6
");
$stmt->execute([$id]);
$transactions = $stmt->fetchAll();

/* Initiales */
$initiales = strtoupper(mb_substr($entreprise['raison_sociale'] ?? $entreprise['nom'], 0, 2));

$base = '/african-trust-fund';

/* Log */
try {
    logAction($pdo, 'entreprise', $id, 'DASHBOARD_VIEW', 'Consultation dashboard entreprise');
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0,user-scalable=no">
<meta name="theme-color" content="#0B0E14">
<title>Dashboard Entreprise — ATF</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<style>
:root { --nav-height: 76px; --nav-safe-area: env(safe-area-inset-bottom, 0px); }
body.app-mode { padding-bottom: calc(var(--nav-height) + var(--nav-safe-area) + 20px); }

/* Hero */
.ent-hero {
    padding: 28px 30px;
    border-radius: 22px;
    background: linear-gradient(135deg, rgba(139,92,246,.15), rgba(76,29,149,.05)),
                radial-gradient(circle at 80% 20%, rgba(167,139,250,.18), transparent 60%);
    border: 1px solid rgba(139,92,246,.35);
    margin-bottom: 24px;
    position: relative;
    overflow: hidden;
}
.ent-hero::before {
    content: "";
    position: absolute;
    top: -40%; right: -10%;
    width: 320px; height: 320px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(139,92,246,.22), transparent 70%);
    animation: float 8s ease-in-out infinite;
}
.ent-hero h1 {
    color: #C4B5FD;
    font-size: 1.5rem;
    margin-bottom: 8px;
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.ent-hero p { color: var(--muted); font-size: .92rem; position: relative; z-index: 1; }
.ent-hero .badges {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 14px;
    position: relative;
    z-index: 1;
}

/* Soldes */
.soldes-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 24px;
}
.solde-card {
    padding: 24px 26px;
    border-radius: 20px;
    position: relative;
    overflow: hidden;
    border: 1px solid;
    transition: transform .3s;
}
.solde-card:hover { transform: translateY(-4px); }
.solde-card.usd {
    background: linear-gradient(135deg, rgba(59,130,246,.15), rgba(30,64,175,.05));
    border-color: rgba(59,130,246,.4);
}
.solde-card.cdf {
    background: linear-gradient(135deg, rgba(245,184,0,.15), rgba(180,120,0,.05));
    border-color: rgba(245,184,0,.4);
}
.solde-card .head {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;
}
.solde-card .flag { font-size: 1.6rem; }
.solde-card .lbl {
    color: var(--muted);
    font-size: .75rem;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    font-weight: 700;
}
.solde-card .amount {
    font-size: 1.9rem;
    font-weight: 900;
    line-height: 1.1;
    letter-spacing: -.5px;
}
.solde-card.usd .amount { color: #93B4FF; }
.solde-card.cdf .amount { color: var(--gold); }

/* KPIs */
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 14px;
    margin-bottom: 24px;
}
.kpi-card {
    padding: 18px;
    border-radius: 16px;
    background: linear-gradient(145deg, var(--card), var(--card-2));
    border: 1px solid var(--border);
    position: relative;
    overflow: hidden;
    transition: all .3s;
}
.kpi-card::before {
    content: "";
    position: absolute;
    top: 0; left: 0;
    width: 4px; height: 100%;
    background: var(--accent, var(--gold));
}
.kpi-card:hover {
    transform: translateY(-3px);
    border-color: var(--accent, var(--gold));
}
.kpi-card.gold { --accent: #F5B800; }
.kpi-card.green { --accent: #10B981; }
.kpi-card.blue { --accent: #3B82F6; }
.kpi-card.purple { --accent: #8B5CF6; }
.kpi-card.red { --accent: #EF4444; }

.kpi-card .ico {
    font-size: 1.5rem;
    margin-bottom: 8px;
}
.kpi-card .val {
    font-size: 1.4rem;
    font-weight: 900;
    color: #fff;
    line-height: 1;
    letter-spacing: -.5px;
}
.kpi-card .lbl {
    font-size: .72rem;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 1px;
    font-weight: 700;
    margin-top: 6px;
}

/* Actions rapides */
.actions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 12px;
    margin-bottom: 24px;
}
.action-btn {
    padding: 18px 16px;
    border-radius: 14px;
    background: linear-gradient(145deg, var(--card), var(--card-2));
    border: 1px solid var(--border);
    text-decoration: none;
    color: var(--text);
    text-align: center;
    transition: all .3s;
}
.action-btn:hover {
    transform: translateY(-4px);
    border-color: var(--gold);
    box-shadow: 0 14px 36px rgba(245,184,0,.15);
}
.action-btn .ico {
    font-size: 1.7rem;
    display: block;
    margin-bottom: 8px;
}
.action-btn .lbl {
    font-weight: 700;
    font-size: .85rem;
    display: block;
}
.action-btn .sub {
    color: var(--muted);
    font-size: .7rem;
    margin-top: 3px;
    display: block;
}

/* Prêt actif */
.pret-card {
    padding: 20px;
    border-radius: 16px;
    background: linear-gradient(135deg, rgba(59,130,246,.1), rgba(30,64,175,.02));
    border: 1px solid rgba(59,130,246,.3);
    margin-bottom: 20px;
}
.pret-card .head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 14px;
}
.pret-card .title { font-weight: 800; color: #93B4FF; font-size: 1rem; }
.pret-card .ref { font-family: monospace; color: var(--muted); font-size: .75rem; margin-top: 2px; }
.pret-progress {
    height: 8px;
    background: rgba(0,0,0,.4);
    border-radius: 999px;
    overflow: hidden;
    margin: 12px 0 8px;
}
.pret-progress > div {
    height: 100%;
    background: linear-gradient(90deg, #3B82F6, #60A5FA);
    border-radius: 999px;
    transition: width .6s;
}
.pret-info-row {
    display: flex;
    justify-content: space-between;
    font-size: .8rem;
    color: var(--muted);
    flex-wrap: wrap;
    gap: 8px;
}
.pret-info-row strong { color: #93B4FF; }

/* Nav mobile */
.mobile-nav {
    display: none;
    position: fixed;
    bottom: 0; left: 0; right: 0;
    height: calc(var(--nav-height) + var(--nav-safe-area));
    padding-bottom: var(--nav-safe-area);
    background: rgba(11,14,20,.95);
    backdrop-filter: blur(20px);
    border-top: 1px solid rgba(139,92,246,.2);
    z-index: 9000;
    box-shadow: 0 -8px 30px rgba(0,0,0,.5);
}
.mobile-nav-inner {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr 1fr 1fr;
    height: var(--nav-height);
    align-items: center;
    padding: 0 8px;
    max-width: 600px;
    margin: 0 auto;
}
.nav-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 4px;
    padding: 10px 6px;
    text-decoration: none;
    color: var(--muted);
    font-weight: 700;
    text-transform: uppercase;
    border-radius: 14px;
    position: relative;
}
.nav-item .nav-icon { font-size: 1.5rem; line-height: 1; }
.nav-item .nav-label { font-size: .62rem; font-weight: 700; }
.nav-item.active { color: #C4B5FD; }
.nav-item.active .nav-icon {
    transform: scale(1.15) translateY(-2px);
    filter: drop-shadow(0 0 12px rgba(139,92,246,.6));
}
.nav-item.active::before {
    content: "";
    position: absolute;
    top: 0; left: 50%;
    transform: translateX(-50%);
    width: 32px; height: 3px;
    background: linear-gradient(90deg, #8B5CF6, #A78BFA);
    border-radius: 0 0 3px 3px;
}

.nav-fab-wrapper { position: relative; display: flex; align-items: center; justify-content: center; height: 100%; }
.nav-fab {
    position: absolute;
    top: -22px;
    width: 60px; height: 60px;
    border-radius: 50%;
    background: linear-gradient(135deg, #8B5CF6, #7C3AED);
    color: #fff;
    border: 4px solid #0B0E14;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.8rem;
    font-weight: 900;
    box-shadow: 0 10px 30px rgba(139,92,246,.5), 0 0 0 6px rgba(139,92,246,.15);
    z-index: 2;
}
.nav-fab.active { transform: rotate(45deg); }

.fab-menu {
    display: none;
    position: fixed;
    bottom: calc(var(--nav-height) + var(--nav-safe-area) + 20px);
    left: 50%;
    transform: translateX(-50%) translateY(20px);
    background: linear-gradient(180deg, #1A2130, #121824);
    border-radius: 20px;
    padding: 16px;
    box-shadow: 0 20px 60px rgba(0,0,0,.7);
    border: 1px solid rgba(139,92,246,.25);
    z-index: 8999;
    opacity: 0;
    min-width: 240px;
}
.fab-menu.open {
    display: block;
    opacity: 1;
    transform: translateX(-50%) translateY(0);
}
.fab-menu-item {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 18px;
    border-radius: 12px;
    color: var(--text);
    text-decoration: none;
    font-size: .9rem;
    font-weight: 700;
}
.fab-menu-item .ico {
    width: 40px; height: 40px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.3rem;
    background: rgba(139,92,246,.15);
    color: #A78BFA;
}
.fab-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.5);
    backdrop-filter: blur(4px);
    z-index: 8998;
}
.fab-overlay.open { display: block; }

@keyframes float {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-15px); }
}

@media (max-width: 900px) {
    .mobile-nav { display: block; }
    .soldes-grid { grid-template-columns: 1fr; }
    .dash { padding: 16px 14px !important; }
    .ent-hero h1 { font-size: 1.25rem; }
}
@media (max-width: 480px) {
    .solde-card .amount { font-size: 1.5rem; }
    .kpi-card .val { font-size: 1.15rem; }
    .nav-item .nav-icon { font-size: 1.3rem; }
    .nav-item .nav-label { font-size: .58rem; }
    .nav-fab { width: 54px; height: 54px; font-size: 1.6rem; }
}
</style>
</head>
<body class="dark-auth app-mode">

<?php require __DIR__ . '/../includes/header_entreprise.php'; ?>

<main class="dash">

    <!-- HERO -->
    <section class="ent-hero">
        <h1>🏢 Bienvenue <?= htmlspecialchars($entreprise['raison_sociale'] ?? $entreprise['nom']) ?></h1>
        <p><?= htmlspecialchars($entreprise['forme_juridique'] ?? 'Entreprise') ?> · <?= htmlspecialchars($entreprise['numero_compte']) ?></p>
        
        <div class="badges">
            <span class="badge <?= $entreprise['statut'] === 'actif' ? 'green' : 'red' ?>">
                ● <?= htmlspecialchars($entreprise['statut']) ?>
            </span>
            <?php if ($entreprise['statut_verification']): ?>
                <span class="badge <?= $entreprise['statut_verification'] === 'verifie' ? 'green' : 'gold' ?>">
                    <?= $entreprise['statut_verification'] === 'verifie' ? '✅' : '⏳' ?> 
                    <?= strtoupper($entreprise['statut_verification']) ?>
                </span>
            <?php endif; ?>
            <?php if ($entreprise['rccm']): ?>
                <span class="badge blue">📋 <?= htmlspecialchars($entreprise['rccm']) ?></span>
            <?php endif; ?>
        </div>
    </section>

    <!-- SOLDES -->
    <section class="soldes-grid">
        <div class="solde-card usd">
            <div class="head">
                <span class="flag">🇺🇸</span>
                <span class="lbl">Solde USD</span>
            </div>
            <div class="amount">$ <?= number_format($solde_usd, 2, '.', ',') ?></div>
        </div>
        <div class="solde-card cdf">
            <div class="head">
                <span class="flag">🇨🇩</span>
                <span class="lbl">Solde CDF</span>
            </div>
            <div class="amount"><?= number_format($solde_cdf, 0, ',', ' ') ?></div>
        </div>
    </section>

    <!-- KPIs -->
    <section class="kpi-grid">
        <div class="kpi-card purple">
            <div class="ico">💎</div>
            <div class="val"><?= number_format($capital_total_equiv_cdf, 0, ',', ' ') ?></div>
            <div class="lbl">Capital investi</div>
        </div>
        <div class="kpi-card green">
            <div class="ico">📈</div>
            <div class="val">+<?= number_format((float)$stats_epargne['total_interets'], 0, ',', ' ') ?></div>
            <div class="lbl">Intérêts cumulés</div>
        </div>
        <div class="kpi-card blue">
            <div class="ico">💳</div>
            <div class="val"><?= (int)$stats_prets['nb_total'] ?></div>
            <div class="lbl">Prêts totaux</div>
        </div>
        <div class="kpi-card gold">
            <div class="ico">💰</div>
            <div class="val"><?= number_format((float)$stats_prets['encours_cdf'], 0, ',', ' ') ?></div>
            <div class="lbl">Encours CDF</div>
        </div>
        <?php if ((int)$stats_prets['nb_en_attente'] > 0): ?>
            <div class="kpi-card red">
                <div class="ico">⏳</div>
                <div class="val"><?= (int)$stats_prets['nb_en_attente'] ?></div>
                <div class="lbl">Demandes en attente</div>
            </div>
        <?php endif; ?>
    </section>

    <!-- ACTIONS RAPIDES -->
    <div style="margin-bottom:16px">
        <h2 style="color:#C4B5FD;font-size:1.05rem;margin:0 0 12px 0">⚡ Actions rapides</h2>
    </div>
    <section class="actions-grid">
        <a href="<?= $base ?>/entreprise/demande_pret.php" class="action-btn">
            <span class="ico">💳</span>
            <span class="lbl">Demander un prêt</span>
            <span class="sub">Nouvelle demande</span>
        </a>
        <a href="<?= $base ?>/entreprise/mes_prets.php" class="action-btn">
            <span class="ico">📋</span>
            <span class="lbl">Mes prêts</span>
            <span class="sub"><?= (int)$stats_prets['nb_total'] ?> dossier(s)</span>
        </a>
        <a href="<?= $base ?>/entreprise/documents.php" class="action-btn">
            <span class="ico">📎</span>
            <span class="lbl">Documents</span>
            <span class="sub">KYC entreprise</span>
        </a>
        <a href="<?= $base ?>/entreprise/transactions.php" class="action-btn">
            <span class="ico">📊</span>
            <span class="lbl">Transactions</span>
            <span class="sub">Historique</span>
        </a>
        <a href="<?= $base ?>/entreprise/profil.php" class="action-btn">
            <span class="ico">👤</span>
            <span class="lbl">Mon profil</span>
            <span class="sub">Modifier infos</span>
        </a>
        <a href="<?= $base ?>/entreprise/releve.php" class="action-btn">
            <span class="ico">📄</span>
            <span class="lbl">Relevé</span>
            <span class="sub">Télécharger</span>
        </a>
    </section>

    <!-- PRÊT ACTIF -->
    <?php if ($pret_actif): ?>
        <h2 style="color:#C4B5FD;font-size:1.05rem;margin:24px 0 12px 0">💰 Prêt en cours</h2>
        <div class="pret-card">
            <div class="head">
                <div>
                    <div class="title"><?= htmlspecialchars($pret_actif['produit_nom']) ?></div>
                    <div class="ref"><?= htmlspecialchars($pret_actif['reference']) ?></div>
                </div>
                <div style="text-align:right">
                    <div style="font-size:.7rem;color:var(--muted);text-transform:uppercase;font-weight:700">Montant</div>
                    <div style="font-size:1.15rem;font-weight:800;color:var(--gold)">
                        <?= htmlspecialchars(formaterMontant((float)$pret_actif['montant_accorde'], $pret_actif['devise'])) ?>
                    </div>
                </div>
            </div>

            <div class="pret-info-row">
                <span>Remboursé : <strong><?= htmlspecialchars(formaterMontant((float)$pret_actif['montant_paye'], $pret_actif['devise'])) ?></strong></span>
                <span>Restant : <strong style="color:#FCA5A5"><?= htmlspecialchars(formaterMontant((float)$pret_actif['montant_restant_du'], $pret_actif['devise'])) ?></strong></span>
            </div>

            <div class="pret-progress">
                <div style="width:<?= number_format((float)$pret_actif['progression'], 1) ?>%"></div>
            </div>

            <div class="pret-info-row">
                <span>Progression : <strong><?= number_format((float)$pret_actif['progression'], 1) ?>%</strong></span>
                <span>Durée : <strong><?= (int)$pret_actif['duree_mois'] ?> mois</strong></span>
            </div>

            <?php if ($prochaine_echeance): 
                $jours = (int)floor((strtotime($prochaine_echeance['date_echeance']) - time()) / 86400);
                $urgent = $jours <= 3;
            ?>
                <div style="margin-top:12px;padding:12px 14px;border-radius:10px;background:<?= $urgent ? 'rgba(239,68,68,.08)' : 'rgba(245,184,0,.08)' ?>;border:1px solid <?= $urgent ? 'rgba(239,68,68,.3)' : 'rgba(245,184,0,.3)' ?>;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px">
                    <div>
                        <div style="color:var(--muted);font-size:.7rem;text-transform:uppercase;font-weight:700">📅 Prochaine échéance</div>
                        <div style="color:var(--muted);font-size:.78rem;margin-top:2px">
                            <?= date('d/m/Y', strtotime($prochaine_echeance['date_echeance'])) ?>
                            <?php if ($jours > 0): ?> · dans <strong><?= $jours ?>j</strong>
                            <?php elseif ($jours === 0): ?> · <strong>AUJOURD'HUI</strong>
                            <?php else: ?> · <strong>RETARD <?= abs($jours) ?>j</strong><?php endif; ?>
                        </div>
                    </div>
                    <div style="text-align:right">
                        <div style="color:var(--muted);font-size:.7rem;text-transform:uppercase;font-weight:700">Montant dû</div>
                        <div style="color:<?= $urgent ? '#FCA5A5' : '#FCD34D' ?>;font-weight:800">
                            <?= htmlspecialchars(formaterMontant((float)$prochaine_echeance['montant_total'], $pret_actif['devise'])) ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- DEMANDE EN ATTENTE -->
    <?php if ($demande_en_cours): ?>
        <div class="pret-card" style="border-color:rgba(245,184,0,.4);background:rgba(245,184,0,.05)">
            <div class="head">
                <div>
                    <div class="title" style="color:#FCD34D">⏳ Demande en attente</div>
                    <div class="ref"><?= htmlspecialchars($demande_en_cours['reference']) ?></div>
                </div>
                <div style="text-align:right">
                    <div style="font-size:.7rem;color:var(--muted);text-transform:uppercase;font-weight:700">Montant demandé</div>
                    <div style="font-size:1.15rem;font-weight:800;color:#FCD34D">
                        <?= htmlspecialchars(formaterMontant((float)$demande_en_cours['montant_demande'], $demande_en_cours['devise'])) ?>
                    </div>
                </div>
            </div>
            <div class="pret-info-row" style="margin-top:10px">
                <span>Statut : <strong style="color:#FCD34D"><?= strtoupper(str_replace('_', ' ', $demande_en_cours['statut'])) ?></strong></span>
                <span>Date : <strong><?= date('d/m/Y', strtotime($demande_en_cours['date_demande'])) ?></strong></span>
            </div>
        </div>
    <?php endif; ?>

    <!-- TRANSACTIONS -->
    <?php if (!empty($transactions)): ?>
        <h2 style="color:#C4B5FD;font-size:1.05rem;margin:24px 0 12px 0">📊 Dernières transactions</h2>
        <div class="card">
            <?php foreach ($transactions as $t):
                $is_retrait = $t['type_transaction'] === 'Retrait';
                $is_usd = ($t['devise'] ?? 'CDF') === 'USD';
                $icon = $is_retrait ? '➖' : ($t['type_transaction'] === 'Depot' ? '➕' : '⇄');
                $color = $is_retrait ? '#FCA5A5' : '#6EE7B7';
            ?>
                <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 0;border-bottom:1px solid var(--border);gap:12px;flex-wrap:wrap">
                    <div style="display:flex;align-items:center;gap:12px;flex:1;min-width:180px">
                        <div style="width:36px;height:36px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1rem;background:rgba(11,14,20,.5);border:1px solid var(--border);color:<?= $color ?>"><?= $icon ?></div>
                        <div>
                            <div style="font-weight:700;font-size:.85rem"><?= htmlspecialchars($t['type_transaction']) ?></div>
                            <div style="font-family:monospace;color:var(--gold);font-size:.7rem"><?= htmlspecialchars($t['reference']) ?></div>
                        </div>
                    </div>
                    <div style="text-align:right">
                        <div style="font-weight:800;color:<?= $color ?>">
                            <?= $is_retrait ? '−' : '+' ?><?= $is_usd ? '$ ' : '' ?>
                            <?= number_format((float)$t['montant'], 2, $is_usd ? '.' : ',', $is_usd ? ',' : ' ') ?>
                            <?= !$is_usd ? ' CDF' : '' ?>
                        </div>
                        <div style="font-size:.7rem;color:var(--muted)"><?= date('d/m/Y H:i', strtotime($t['date_transaction'])) ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

</main>

<!-- NAV MOBILE -->
<nav class="mobile-nav">
    <div class="mobile-nav-inner">
        <a href="<?= $base ?>/entreprise/dashboard.php" class="nav-item active">
            <span class="nav-icon">🏠</span>
            <span class="nav-label">Accueil</span>
        </a>
        <a href="<?= $base ?>/entreprise/mes_prets.php" class="nav-item">
            <span class="nav-icon">💳</span>
            <span class="nav-label">Prêts</span>
        </a>
        <div class="nav-fab-wrapper">
            <button class="nav-fab" id="navFab" type="button">＋</button>
        </div>
        <a href="<?= $base ?>/entreprise/transactions.php" class="nav-item">
            <span class="nav-icon">📊</span>
            <span class="nav-label">Trans.</span>
        </a>
        <a href="<?= $base ?>/entreprise/profil.php" class="nav-item">
            <span class="nav-icon">👤</span>
            <span class="nav-label">Profil</span>
        </a>
    </div>
</nav>

<div class="fab-overlay" id="fabOverlay"></div>

<div class="fab-menu" id="fabMenu">
    <a href="<?= $base ?>/entreprise/demande_pret.php" class="fab-menu-item">
        <span class="ico">💳</span><span>Demander un prêt</span>
    </a>
    <a href="<?= $base ?>/entreprise/mes_prets.php" class="fab-menu-item">
        <span class="ico">📋</span><span>Mes prêts</span>
    </a>
    <a href="<?= $base ?>/entreprise/documents.php" class="fab-menu-item">
        <span class="ico">📎</span><span>Documents</span>
    </a>
    <a href="<?= $base ?>/entreprise/profil.php" class="fab-menu-item">
        <span class="ico">👤</span><span>Profil</span>
    </a>
</div>

<script>
(function() {
    const fab = document.getElementById('navFab');
    const menu = document.getElementById('fabMenu');
    const overlay = document.getElementById('fabOverlay');
    if (!fab || !menu || !overlay) return;

    function toggle() {
        const open = menu.classList.contains('open');
        if (open) {
            menu.classList.remove('open');
            overlay.classList.remove('open');
            fab.classList.remove('active');
        } else {
            menu.classList.add('open');
            overlay.classList.add('open');
            fab.classList.add('active');
        }
    }
    fab.addEventListener('click', toggle);
    overlay.addEventListener('click', toggle);
    menu.querySelectorAll('a').forEach(a => a.addEventListener('click', toggle));
})();
</script>
<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>