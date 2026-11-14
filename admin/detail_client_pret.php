<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/prets.php';
requireRole('admin');

$pdo = Database::getConnection();
$admin_id = (int)$_SESSION['admin_id'];

$id_client = (int)($_GET['id'] ?? 0);
$annee = (int)($_GET['annee'] ?? date('Y'));
if ($annee < 2020 || $annee > 2100) $annee = (int)date('Y');

if ($id_client <= 0) {
    header('Location: ' . url('admin/rapport_prets.php'));
    exit;
}

/* Chargement client */
$stmt = $pdo->prepare("SELECT * FROM Client WHERE id_client = ? LIMIT 1");
$stmt->execute([$id_client]);
$client = $stmt->fetch();

if (!$client) {
    header('Location: ' . url('admin/rapport_prets.php'));
    exit;
}

/* Prêts de l'année */
$prets = [];
try {
    $stmt = $pdo->prepare("
        SELECT p.*, pr.nom AS produit_nom, pr.taux_annuel, pr.type_interet
        FROM Prets p
        LEFT JOIN Produits_Pret pr ON pr.id_produit = p.id_produit
        WHERE p.id_client = ?
          AND YEAR(p.date_demande) = ?
        ORDER BY p.date_demande DESC
    ");
    $stmt->execute([$id_client, $annee]);
    $prets = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {}

/* Stats globales client */
$stats_client = [
    'total_prets' => 0, 'actifs' => 0, 'soldes' => 0, 'en_retard' => 0,
    'total_cdf' => 0, 'total_usd' => 0,
    'paye_cdf' => 0, 'paye_usd' => 0,
    'restant_cdf' => 0, 'restant_usd' => 0,
    'pen_cdf' => 0, 'pen_usd' => 0,
    'total_du_cdf' => 0, 'total_du_usd' => 0,
];

try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) AS total_prets,
            COUNT(CASE WHEN statut IN ('decaisse','en_cours') THEN 1 END) AS actifs,
            COUNT(CASE WHEN statut = 'solde' THEN 1 END) AS soldes,
            COUNT(CASE WHEN statut IN ('en_retard','contentieux') THEN 1 END) AS en_retard,
            COALESCE(SUM(CASE WHEN devise='CDF' THEN montant_accorde ELSE 0 END), 0) AS total_cdf,
            COALESCE(SUM(CASE WHEN devise='USD' THEN montant_accorde ELSE 0 END), 0) AS total_usd,
            COALESCE(SUM(CASE WHEN devise='CDF' THEN montant_paye ELSE 0 END), 0) AS paye_cdf,
            COALESCE(SUM(CASE WHEN devise='USD' THEN montant_paye ELSE 0 END), 0) AS paye_usd,
            COALESCE(SUM(CASE WHEN devise='CDF' THEN montant_restant_du ELSE 0 END), 0) AS restant_cdf,
            COALESCE(SUM(CASE WHEN devise='USD' THEN montant_restant_du ELSE 0 END), 0) AS restant_usd,
            COALESCE(SUM(CASE WHEN devise='CDF' THEN penalites_cumulees ELSE 0 END), 0) AS pen_cdf,
            COALESCE(SUM(CASE WHEN devise='USD' THEN penalites_cumulees ELSE 0 END), 0) AS pen_usd,
            COALESCE(SUM(CASE WHEN devise='CDF' THEN montant_total_du ELSE 0 END), 0) AS total_du_cdf,
            COALESCE(SUM(CASE WHEN devise='USD' THEN montant_total_du ELSE 0 END), 0) AS total_du_usd
        FROM Prets
        WHERE id_client = ?
    ");
    $stmt->execute([$id_client]);
    $row = $stmt->fetch();
    if ($row) {
        foreach ($stats_client as $k => $v) {
            $stats_client[$k] = $row[$k] ?? 0;
        }
    }
} catch (Throwable $e) {
    error_log('[detail_client_pret - stats] ' . $e->getMessage());
}

/* Échéances */
$echeances = [];
try {
    $stmt = $pdo->prepare("
        SELECT e.*, p.reference AS pret_reference, p.devise
        FROM Prets_Echeances e
        JOIN Prets p ON p.id_pret = e.id_pret
        WHERE p.id_client = ?
          AND YEAR(e.date_echeance) = ?
        ORDER BY e.date_echeance ASC
    ");
    $stmt->execute([$id_client, $annee]);
    $echeances = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {}

/* Paiements */
$paiements = [];
try {
    $stmt = $pdo->prepare("
        SELECT r.*, p.reference AS pret_reference, p.devise
        FROM Prets_Remboursements r
        JOIN Prets p ON p.id_pret = r.id_pret
        WHERE p.id_client = ?
          AND YEAR(r.date_paiement) = ?
        ORDER BY r.date_paiement DESC
    ");
    $stmt->execute([$id_client, $annee]);
    $paiements = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {}

try {
    logAction($pdo, 'admin', $admin_id, 'DETAIL_CLIENT_PRET',
        "Client #{$id_client} - Année {$annee}");
} catch (Throwable $e) {}

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Détail Client Prêt — <?= htmlspecialchars($client['numero_compte']) ?> — ATF</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    background: #0B0E14; color: #E5E7EB; padding: 30px; font-size: 13px; line-height: 1.6;
}
.btn-float {
    position: fixed; top: 20px; z-index: 9999;
    padding: 12px 22px; border-radius: 999px;
    font-weight: 800; font-size: .9rem; cursor: pointer;
    border: none; font-family: inherit; text-decoration: none;
    display: inline-flex; align-items: center; gap: 8px;
    transition: all .25s; box-shadow: 0 8px 24px rgba(0,0,0,.4);
}
.btn-float.print { right: 20px; background: linear-gradient(135deg, #F5B800, #D4AF37); color: #0B0E14; }
.btn-float.print:hover { transform: translateY(-3px); box-shadow: 0 12px 32px rgba(245,184,0,.6); }
.btn-float.back { left: 20px; background: #0D1B5E; color: #F5B800; border: 2px solid #F5B800; }
.btn-float.back:hover { background: #F5B800; color: #0D1B5E; transform: translateY(-3px); }

.container {
    max-width: 1400px; margin: 0 auto;
    background: #121721; border-radius: 20px; padding: 32px;
    border: 1px solid rgba(255,255,255,.08);
}
.page-header {
    display: flex; justify-content: space-between; align-items: flex-start;
    gap: 20px; padding-bottom: 24px; border-bottom: 3px solid #F5B800;
    margin-bottom: 28px; flex-wrap: wrap;
}
.page-header .logo-block { display: flex; align-items: center; gap: 16px; }
.page-header .logo-img {
    width: 70px; height: 70px; border-radius: 16px;
    background: linear-gradient(135deg, #F5B800, #D4AF37);
    display: flex; align-items: center; justify-content: center;
    font-size: 1.6rem; color: #0B0E14; font-weight: 900;
    box-shadow: 0 8px 24px rgba(245,184,0,.35);
}
.page-header h1 { color: #F5B800; font-size: 1.5rem; font-weight: 900; margin-bottom: 4px; }
.page-header .slogan { color: #94A3B8; font-size: .8rem; font-style: italic; }
.page-header .ref { text-align: right; font-size: .8rem; color: #94A3B8; }
.page-header .ref strong { color: #F5B800; font-size: 1rem; display: block; margin-bottom: 6px; }

.section-title {
    color: #F5B800; font-size: 1.05rem; font-weight: 800;
    margin: 28px 0 16px; padding-bottom: 8px;
    border-bottom: 2px solid rgba(245,184,0,.3);
    display: flex; align-items: center; gap: 10px;
}

.info-grid-2 {
    display: grid; grid-template-columns: 1fr 1fr; gap: 20px;
    padding: 22px; background: linear-gradient(145deg, rgba(13,27,94,.15), rgba(11,14,20,.4));
    border-radius: 16px; border-left: 4px solid #F5B800; margin-bottom: 24px;
}
.info-grid-2 .col-info { display: flex; flex-direction: column; gap: 12px; }
.info-grid-2 .item { display: flex; flex-direction: column; gap: 3px; }
.info-grid-2 .item .lbl {
    color: #94A3B8; font-size: .7rem; text-transform: uppercase;
    letter-spacing: 1px; font-weight: 800;
}
.info-grid-2 .item .val { color: #E5E7EB; font-weight: 700; font-size: .95rem; }
.info-grid-2 .item .val.gold { color: #F5B800; }
.info-grid-2 .item .val.green { color: #6EE7B7; }
.info-grid-2 .item .val.red { color: #FCA5A5; }

.soldes-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 28px; }
.solde-card {
    padding: 24px 28px; border-radius: 18px;
    position: relative; overflow: hidden;
    box-shadow: 0 8px 24px rgba(0,0,0,.3);
}
.solde-card::before {
    content: ""; position: absolute; top: -40px; right: -40px;
    width: 140px; height: 140px; border-radius: 50%; opacity: .15;
}
.solde-card.usd { background: linear-gradient(135deg, #065F46 0%, #059669 100%); color: #fff; }
.solde-card.usd::before { background: #fff; }
.solde-card.cdf { background: linear-gradient(135deg, #0D1B5E 0%, #1E3A8A 100%); color: #fff; }
.solde-card.cdf::before { background: #F5B800; }
.solde-card .flag {
    display: flex; align-items: center; gap: 10px;
    font-size: .75rem; font-weight: 800;
    text-transform: uppercase; letter-spacing: 2px;
    opacity: .9; margin-bottom: 12px;
}
.solde-card .flag .icon { font-size: 1.5rem; }
.solde-card .devise {
    font-size: .7rem; opacity: .7; letter-spacing: 2px;
    font-weight: 700; margin-bottom: 4px;
}
.solde-card .montant {
    font-size: 2rem; font-weight: 900;
    letter-spacing: -0.5px; margin-bottom: 10px;
    font-variant-numeric: tabular-nums;
}
.solde-card .details {
    font-size: .75rem; opacity: .9;
    display: grid; grid-template-columns: 1fr 1fr; gap: 6px;
    margin-top: 12px; padding-top: 12px;
    border-top: 1px solid rgba(255,255,255,.2);
}
.solde-card .details .d-item .d-lbl {
    font-size: .65rem; opacity: .7;
    text-transform: uppercase; letter-spacing: 1px;
}
.solde-card .details .d-item .d-val {
    font-size: .85rem; font-weight: 800;
    font-variant-numeric: tabular-nums;
}

.stats-mini-grid {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 12px; margin-bottom: 24px;
}
.stat-mini {
    padding: 16px; border-radius: 12px;
    background: rgba(11,14,20,.6);
    border: 1px solid rgba(255,255,255,.08); text-align: center;
}
.stat-mini .ico { font-size: 1.5rem; margin-bottom: 6px; }
.stat-mini .val {
    font-size: 1.4rem; font-weight: 900; color: #F5B800;
    font-variant-numeric: tabular-nums;
}
.stat-mini .lbl {
    font-size: .68rem; color: #94A3B8;
    text-transform: uppercase; letter-spacing: 1px;
    font-weight: 800; margin-top: 4px;
}
.stat-mini.green .val { color: #6EE7B7; }
.stat-mini.red .val { color: #FCA5A5; }
.stat-mini.blue .val { color: #93B4FF; }

table.data {
    width: 100%; border-collapse: collapse; font-size: .82rem;
    background: rgba(11,14,20,.4); border-radius: 12px;
    overflow: hidden; margin-bottom: 24px;
}
table.data thead { background: #0D1B5E; color: #fff; }
table.data th {
    padding: 11px 10px; text-align: left;
    font-size: .68rem; text-transform: uppercase;
    letter-spacing: .5px; font-weight: 800; white-space: nowrap;
}
table.data td {
    padding: 11px 10px;
    border-bottom: 1px solid rgba(255,255,255,.05);
    vertical-align: middle;
}
table.data tbody tr:hover { background: rgba(245,184,0,.04); }
table.data .right { text-align: right; }
table.data .center { text-align: center; }
table.data .pos { color: #6EE7B7; font-weight: 700; }
table.data .neg { color: #FCA5A5; font-weight: 700; }
table.data code {
    font-family: 'JetBrains Mono', monospace;
    font-size: .74rem; color: #93B4FF;
    background: rgba(11,14,20,.6); padding: 3px 7px; border-radius: 5px;
}

.badge-statut {
    padding: 4px 11px; border-radius: 999px;
    font-size: .68rem; font-weight: 800;
    text-transform: uppercase; letter-spacing: .5px;
    display: inline-block; white-space: nowrap;
}
.badge-statut.decaisse, .badge-statut.en_cours { background: rgba(245,184,0,.2); color: #FCD34D; border: 1px solid rgba(245,184,0,.5); }
.badge-statut.solde { background: rgba(16,185,129,.2); color: #6EE7B7; border: 1px solid rgba(16,185,129,.5); }
.badge-statut.en_retard, .badge-statut.contentieux { background: rgba(239,68,68,.2); color: #FCA5A5; border: 1px solid rgba(239,68,68,.5); }
.badge-statut.rejete, .badge-statut.annule { background: rgba(107,114,128,.2); color: #9CA3AF; border: 1px solid rgba(107,114,128,.4); }
.badge-statut.approuve, .badge-statut.signe { background: rgba(16,185,129,.15); color: #6EE7B7; border: 1px solid rgba(16,185,129,.4); }
.badge-statut.soumis, .badge-statut.en_analyse { background: rgba(139,92,246,.2); color: #A78BFA; border: 1px solid rgba(139,92,246,.5); }

.empty-msg {
    text-align: center; padding: 40px 20px;
    color: #94A3B8; font-style: italic;
    background: rgba(11,14,20,.4);
    border-radius: 12px; border: 1px dashed rgba(255,255,255,.1);
}
.empty-msg .ico { font-size: 3rem; opacity: .4; margin-bottom: 12px; display: block; }

.footer-print {
    margin-top: 40px; padding-top: 20px;
    border-top: 2px solid #F5B800;
    text-align: center; color: #94A3B8;
    font-size: .75rem; line-height: 1.8;
}
.footer-print strong { color: #F5B800; }

@media print {
    body { background: #fff; color: #000; padding: 0; font-size: 11px; }
    .btn-float { display: none !important; }
    .container { background: #fff; border: none; border-radius: 0; padding: 0; max-width: 100%; box-shadow: none; }
    .page-header { border-bottom: 3px solid #F5B800; padding-bottom: 16px; }
    .page-header h1 { color: #0D1B5E; }
    .page-header .slogan { color: #666; }
    .page-header .ref { color: #333; }
    .page-header .ref strong { color: #0D1B5E; }
    .page-header .logo-img { background: #F5B800; color: #0D1B5E; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .info-grid-2 { background: #f9f9f9; border-left: 4px solid #F5B800; }
    .info-grid-2 .item .lbl { color: #666; }
    .info-grid-2 .item .val { color: #000; }
    .info-grid-2 .item .val.gold { color: #92400E; }
    .info-grid-2 .item .val.green { color: #065F46; }
    .info-grid-2 .item .val.red { color: #991B1B; }
    .solde-card { -webkit-print-color-adjust: exact; print-color-adjust: exact; box-shadow: none; border: 2px solid #ccc; page-break-inside: avoid; }
    .solde-card.usd { background: #065F46 !important; }
    .solde-card.cdf { background: #0D1B5E !important; }
    .solde-card .montant { color: #fff !important; }
    .stat-mini { background: #f5f5f5; border: 1px solid #ddd; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .stat-mini .val { color: #0D1B5E !important; }
    table.data { background: #fff; border: 1px solid #ddd; }
    table.data thead { background: #0D1B5E !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    table.data td { border-bottom: 1px solid #eee; color: #000; }
    table.data .pos { color: #065F46 !important; }
    table.data .neg { color: #991B1B !important; }
    table.data code { background: #f0f0f0; color: #0D1B5E; }
    .badge-statut { -webkit-print-color-adjust: exact; print-color-adjust: exact; border: 1px solid #999 !important; }
    .footer-print { border-top: 2px solid #F5B800; color: #666; }
    .footer-print strong { color: #0D1B5E; }
    .section-title { color: #0D1B5E; border-bottom: 2px solid #F5B800; }
    @page { margin: 1.5cm; size: A4; }
}
@media (max-width: 768px) {
    body { padding: 15px; }
    .container { padding: 20px; border-radius: 14px; }
    .soldes-grid { grid-template-columns: 1fr; }
    .info-grid-2 { grid-template-columns: 1fr; }
    .page-header { flex-direction: column; }
    .page-header .ref { text-align: left; }
    .btn-float { padding: 10px 16px; font-size: .78rem; }
    .btn-float.print { right: 10px; }
    .btn-float.back { left: 10px; }
    table.data thead { display: none; }
    table.data tbody tr { display: block; margin-bottom: 12px; padding: 12px; border: 1px solid rgba(255,255,255,.1); border-radius: 10px; }
    table.data td { display: flex; justify-content: space-between; padding: 6px 0; border: none; }
    table.data td::before { content: attr(data-label); color: #94A3B8; font-size: .7rem; font-weight: 700; text-transform: uppercase; }
}
</style>
</head>
<body>

<button class="btn-float print" onclick="window.print()">🖨️ Imprimer / PDF</button>
<a class="btn-float back" href="<?= $base ?>/admin/rapport_prets.php?annee=<?= $annee ?>">← Retour</a>

<div class="container">

    <div class="page-header">
        <div class="logo-block">
            <div class="logo-img">ATF</div>
            <div>
                <h1>AFRICAN TRUST FUND</h1>
                <div class="slogan">L'argent de l'Afrique pour construire l'Afrique</div>
            </div>
        </div>
        <div class="ref">
            <strong>DÉTAIL EMPRUNTEUR</strong>
            Période : Année <?= $annee ?><br>
            Édité le : <?= date('d/m/Y à H:i') ?><br>
            Réf. : ATF-DET-<?= date('Ymd') ?>-<?= (int)$client['id_client'] ?>
        </div>
    </div>

    <div class="info-grid-2">
        <div class="col-info">
            <div class="item">
                <span class="lbl">👤 Client</span>
                <span class="val"><?= htmlspecialchars($client['nom'] . ' ' . $client['postnom'] . ' ' . $client['prenom']) ?></span>
            </div>
            <div class="item">
                <span class="lbl">🆔 N° de compte</span>
                <span class="val gold"><?= htmlspecialchars($client['numero_compte']) ?></span>
            </div>
            <div class="item">
                <span class="lbl">📞 Téléphone</span>
                <span class="val"><?= htmlspecialchars($client['numero_phone'] ?? '—') ?></span>
            </div>
        </div>
        <div class="col-info">
            <div class="item">
                <span class="lbl">📅 Inscrit depuis</span>
                <span class="val"><?= date('d/m/Y', strtotime($client['created_at'] ?? 'now')) ?></span>
            </div>
            <div class="item">
                <span class="lbl">💰 Solde compte</span>
                <span class="val green">
                    <?= strtoupper($client['devise'] ?? 'USD') === 'CDF'
                        ? number_format((float)($client['solde'] ?? 0), 0, ',', ' ') . ' FC'
                        : '$ ' . number_format((float)($client['solde'] ?? 0), 2, ',', ' ') ?>
                </span>
            </div>
            <div class="item">
                <span class="lbl">📍 Statut</span>
                <span class="val <?= ($client['statut'] ?? '') === 'actif' ? 'green' : 'red' ?>">
                    <?= strtoupper($client['statut'] ?? 'N/A') ?>
                </span>
            </div>
        </div>
    </div>

    <h2 class="section-title">💵 Situation globale des emprunts</h2>

    <div class="soldes-grid">
        <div class="solde-card usd">
            <div class="flag">
                <span class="icon">🇺🇸</span>
                <span>Dollar Américain</span>
            </div>
            <div class="devise">USD</div>
            <div class="montant">$ <?= number_format((float)$stats_client['total_usd'], 2, ',', ' ') ?></div>

            <div class="details">
                <div class="d-item">
                    <div class="d-lbl">💰 Payé</div>
                    <div class="d-val">$ <?= number_format((float)$stats_client['paye_usd'], 2, ',', ' ') ?></div>
                </div>
                <div class="d-item">
                    <div class="d-lbl">🔴 Reste</div>
                    <div class="d-val">$ <?= number_format((float)$stats_client['restant_usd'], 2, ',', ' ') ?></div>
                </div>
                <div class="d-item">
                    <div class="d-lbl">⚠️ Pénalités</div>
                    <div class="d-val">$ <?= number_format((float)$stats_client['pen_usd'], 2, ',', ' ') ?></div>
                </div>
                <div class="d-item">
                    <div class="d-lbl">📊 Total dû</div>
                    <div class="d-val">$ <?= number_format((float)$stats_client['total_du_usd'], 2, ',', ' ') ?></div>
                </div>
            </div>
        </div>

        <div class="solde-card cdf">
            <div class="flag">
                <span class="icon">🇨🇩</span>
                <span>Franc Congolais</span>
            </div>
            <div class="devise">CDF</div>
            <div class="montant"><?= number_format((float)$stats_client['total_cdf'], 0, ',', ' ') ?> FC</div>

            <div class="details">
                <div class="d-item">
                    <div class="d-lbl">💰 Payé</div>
                    <div class="d-val"><?= number_format((float)$stats_client['paye_cdf'], 0, ',', ' ') ?> FC</div>
                </div>
                <div class="d-item">
                    <div class="d-lbl">🔴 Reste</div>
                    <div class="d-val"><?= number_format((float)$stats_client['restant_cdf'], 0, ',', ' ') ?> FC</div>
                </div>
                <div class="d-item">
                    <div class="d-lbl">⚠️ Pénalités</div>
                    <div class="d-val"><?= number_format((float)$stats_client['pen_cdf'], 0, ',', ' ') ?> FC</div>
                </div>
                <div class="d-item">
                    <div class="d-lbl">📊 Total dû</div>
                    <div class="d-val"><?= number_format((float)$stats_client['total_du_cdf'], 0, ',', ' ') ?> FC</div>
                </div>
            </div>
        </div>
    </div>

    <div class="stats-mini-grid">
        <div class="stat-mini blue">
            <div class="ico">📋</div>
            <div class="val"><?= (int)$stats_client['total_prets'] ?></div>
            <div class="lbl">Total prêts</div>
        </div>
        <div class="stat-mini green">
            <div class="ico">✅</div>
            <div class="val"><?= (int)$stats_client['soldes'] ?></div>
            <div class="lbl">Soldés</div>
        </div>
        <div class="stat-mini">
            <div class="ico">🔄</div>
            <div class="val"><?= (int)$stats_client['actifs'] ?></div>
            <div class="lbl">En cours</div>
        </div>
        <div class="stat-mini red">
            <div class="ico">⚠️</div>
            <div class="val"><?= (int)$stats_client['en_retard'] ?></div>
            <div class="lbl">En retard</div>
        </div>
    </div>

    <h2 class="section-title">📋 Prêts de l'année <?= $annee ?> (<?= count($prets) ?>)</h2>

    <?php if (empty($prets)): ?>
        <div class="empty-msg">
            <span class="ico">📭</span>
            Aucun prêt enregistré pour cette année.
        </div>
    <?php else: ?>
        <table class="data">
            <thead>
                <tr>
                    <th>Référence</th>
                    <th>Produit</th>
                    <th class="right">Montant</th>
                    <th class="right">Payé</th>
                    <th class="right">Reste</th>
                    <th class="right">Taux</th>
                    <th>Date</th>
                    <th>Statut</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($prets as $p): ?>
                    <tr>
                        <td data-label="Référence"><code><?= htmlspecialchars($p['reference']) ?></code></td>
                        <td data-label="Produit"><?= htmlspecialchars($p['produit_nom'] ?? '—') ?></td>
                        <td data-label="Montant" class="right">
                            <strong><?= htmlspecialchars($p['devise']) === 'CDF'
                                ? number_format((float)$p['montant_accorde'], 0, ',', ' ') . ' FC'
                                : '$ ' . number_format((float)$p['montant_accorde'], 2, ',', ' ') ?></strong>
                        </td>
                        <td data-label="Payé" class="right pos">
                            <?= htmlspecialchars($p['devise']) === 'CDF'
                                ? number_format((float)$p['montant_paye'], 0, ',', ' ') . ' FC'
                                : '$ ' . number_format((float)$p['montant_paye'], 2, ',', ' ') ?>
                        </td>
                        <td data-label="Reste" class="right neg">
                            <?= htmlspecialchars($p['devise']) === 'CDF'
                                ? number_format((float)$p['montant_restant_du'], 0, ',', ' ') . ' FC'
                                : '$ ' . number_format((float)$p['montant_restant_du'], 2, ',', ' ') ?>
                        </td>
                        <td data-label="Taux" class="right">
                            <?= number_format((float)($p['taux_annuel'] ?? 0) * 100, 2) ?>%
                        </td>
                        <td data-label="Date"><?= date('d/m/Y', strtotime($p['date_demande'])) ?></td>
                        <td data-label="Statut">
                            <span class="badge-statut <?= htmlspecialchars($p['statut']) ?>">
                                <?= htmlspecialchars(str_replace('_', ' ', $p['statut'])) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if (!empty($echeances)): ?>
        <h2 class="section-title">📅 Échéances de l'année <?= $annee ?> (<?= count($echeances) ?>)</h2>
        <table class="data">
            <thead>
                <tr>
                    <th>Date échéance</th>
                    <th>Prêt</th>
                    <th class="right">Montant prévu</th>
                    <th class="right">Payé</th>
                    <th>Statut</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($echeances as $e): ?>
                    <tr>
                        <td data-label="Date"><?= date('d/m/Y', strtotime($e['date_echeance'])) ?></td>
                        <td data-label="Prêt"><code><?= htmlspecialchars($e['pret_reference']) ?></code></td>
                        <td data-label="Montant" class="right">
                            <?= htmlspecialchars($e['devise']) === 'CDF'
                                ? number_format((float)($e['montant_echeance'] ?? 0), 0, ',', ' ') . ' FC'
                                : '$ ' . number_format((float)($e['montant_echeance'] ?? 0), 2, ',', ' ') ?>
                        </td>
                        <td data-label="Payé" class="right pos">
                            <?= htmlspecialchars($e['devise']) === 'CDF'
                                ? number_format((float)($e['montant_paye'] ?? 0), 0, ',', ' ') . ' FC'
                                : '$ ' . number_format((float)($e['montant_paye'] ?? 0), 2, ',', ' ') ?>
                        </td>
                        <td data-label="Statut">
                            <span class="badge-statut <?= ($e['statut'] ?? '') === 'payee' ? 'solde' : (($e['statut'] ?? '') === 'en_retard' ? 'en_retard' : 'en_cours') ?>">
                                <?= htmlspecialchars(str_replace('_', ' ', $e['statut'] ?? 'En attente')) ?>
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if (!empty($paiements)): ?>
        <h2 class="section-title">💵 Paiements effectués en <?= $annee ?> (<?= count($paiements) ?>)</h2>
        <table class="data">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Prêt</th>
                    <th class="right">Montant</th>
                    <th>Mode</th>
                    <th>Référence</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($paiements as $pa): ?>
                    <tr>
                        <td data-label="Date"><?= date('d/m/Y H:i', strtotime($pa['date_paiement'])) ?></td>
                        <td data-label="Prêt"><code><?= htmlspecialchars($pa['pret_reference']) ?></code></td>
                        <td data-label="Montant" class="right pos">
                            <?= htmlspecialchars($pa['devise']) === 'CDF'
                                ? number_format((float)($pa['montant'] ?? 0), 0, ',', ' ') . ' FC'
                                : '$ ' . number_format((float)($pa['montant'] ?? 0), 2, ',', ' ') ?>
                        </td>
                        <td data-label="Mode"><?= htmlspecialchars($pa['mode_paiement'] ?? '—') ?></td>
                        <td data-label="Référence">
                            <code><?= htmlspecialchars($pa['reference'] ?? '—') ?></code>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div class="footer-print">
        <p><strong>AFRICAN TRUST FUND</strong> — Société de Gestion d'Actifs (S.G.A)</p>
        <p>Document confidentiel — Toute reproduction non autorisée est interdite.</p>
        <p style="margin-top:8px;font-size:.7rem">
            Réf. ATF-DET-<?= date('Ymd') ?>-<?= (int)$client['id_client'] ?> 
            — Généré par admin #<?= $admin_id ?>
            le <?= date('d/m/Y à H:i:s') ?>
        </p>
    </div>

</div>

<script>
document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
        e.preventDefault();
        window.print();
    }
});
</script>

</body>
</html>