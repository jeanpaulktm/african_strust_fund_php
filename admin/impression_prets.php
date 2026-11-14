<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/prets.php';
requireRole('admin');

$pdo = Database::getConnection();
$admin_id = (int)$_SESSION['admin_id'];

$annee  = (int)($_GET['annee'] ?? date('Y'));
$format = $_GET['format'] ?? 'complet';

if ($annee < 2020 || $annee > 2100) $annee = (int)date('Y');
$debut = "$annee-01-01";
$fin   = "$annee-12-31";

$TAUX_USD_CDF = 2800.00;

/* ============================================================
   ✅ CHARGEMENT TOUS LES CLIENTS (CORRIGÉ)
   ============================================================ */
$clients = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            c.id_client, c.nom, c.postnom, c.prenom, c.numero_compte, c.numero_phone,
            c.adresse_complete, c.statut AS client_statut,
            COUNT(p.id_pret) AS nb_prets,
            SUM(CASE WHEN p.statut IN ('decaisse','en_cours') THEN 1 ELSE 0 END) AS nb_actifs,
            SUM(CASE WHEN p.statut = 'solde' THEN 1 ELSE 0 END) AS nb_soldes,
            SUM(CASE WHEN p.statut IN ('en_retard','contentieux') THEN 1 ELSE 0 END) AS nb_retard,
            COALESCE(SUM(CASE WHEN p.devise='CDF' THEN p.montant_accorde ELSE 0 END), 0) AS total_cdf,
            COALESCE(SUM(CASE WHEN p.devise='USD' THEN p.montant_accorde ELSE 0 END), 0) AS total_usd,
            COALESCE(SUM(CASE WHEN p.devise='CDF' THEN p.montant_paye ELSE 0 END), 0) AS paye_cdf,
            COALESCE(SUM(CASE WHEN p.devise='USD' THEN p.montant_paye ELSE 0 END), 0) AS paye_usd,
            COALESCE(SUM(CASE WHEN p.devise='CDF' THEN p.montant_restant_du ELSE 0 END), 0) AS restant_cdf,
            COALESCE(SUM(CASE WHEN p.devise='USD' THEN p.montant_restant_du ELSE 0 END), 0) AS restant_usd,
            COALESCE(SUM(CASE WHEN p.devise='CDF' THEN p.penalites_cumulees ELSE 0 END), 0) AS pen_cdf,
            COALESCE(SUM(CASE WHEN p.devise='USD' THEN p.penalites_cumulees ELSE 0 END), 0) AS pen_usd
        FROM Client c
        JOIN Prets p ON p.id_client = c.id_client
        WHERE YEAR(p.date_demande) = ?
          AND p.statut NOT IN ('rejete','annule')
        GROUP BY c.id_client, c.nom, c.postnom, c.prenom, c.numero_compte, 
                 c.numero_phone, c.adresse_complete, c.statut
        ORDER BY (
            COALESCE(SUM(CASE WHEN p.devise='CDF' THEN p.montant_accorde ELSE 0 END), 0)
            + COALESCE(SUM(CASE WHEN p.devise='USD' THEN p.montant_accorde ELSE 0 END), 0) * ?
        ) DESC
    ");
    $stmt->execute([$annee, $TAUX_USD_CDF]);
    $clients = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {
    error_log('[impression_prets] ' . $e->getMessage());
}

/* Stats globales */
$total_clients     = count($clients);
$total_prets_an    = array_sum(array_column($clients, 'nb_prets'));
$total_cdf_an      = array_sum(array_column($clients, 'total_cdf'));
$total_usd_an      = array_sum(array_column($clients, 'total_usd'));
$total_paye_cdf    = array_sum(array_column($clients, 'paye_cdf'));
$total_paye_usd    = array_sum(array_column($clients, 'paye_usd'));
$total_restant_cdf = array_sum(array_column($clients, 'restant_cdf'));
$total_restant_usd = array_sum(array_column($clients, 'restant_usd'));
$total_pen_cdf     = array_sum(array_column($clients, 'pen_cdf'));
$total_pen_usd     = array_sum(array_column($clients, 'pen_usd'));

$total_cdf_equivalent = $total_cdf_an + ($total_usd_an * $TAUX_USD_CDF);

try {
    logAction($pdo, 'admin', $admin_id, 'IMPRESSION_PRETS_GLOBAL',
        "Année {$annee} | Format {$format} | {$total_clients} client(s)");
} catch (Throwable $e) {}

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Rapport Global Prêts <?= $annee ?> — ATF</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
    font-family: 'Inter', 'Segoe UI', system-ui, sans-serif;
    background: #0B0E14; color: #E5E7EB; padding: 30px;
    font-size: 12px; line-height: 1.5;
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
.btn-float.print:hover { transform: translateY(-3px); }
.btn-float.back { left: 20px; background: #0D1B5E; color: #F5B800; border: 2px solid #F5B800; }
.btn-float.back:hover { background: #F5B800; color: #0D1B5E; }

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
    font-size: 1.5rem; color: #0B0E14; font-weight: 900;
    box-shadow: 0 8px 24px rgba(245,184,0,.35);
}
.page-header h1 { color: #F5B800; font-size: 1.5rem; font-weight: 900; margin-bottom: 4px; }
.page-header .slogan { color: #94A3B8; font-size: .8rem; font-style: italic; }
.page-header .ref { text-align: right; font-size: .8rem; color: #94A3B8; }
.page-header .ref strong { color: #F5B800; font-size: 1rem; display: block; margin-bottom: 6px; }

.global-stats {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px; margin-bottom: 28px;
}
.g-stat {
    padding: 20px; border-radius: 14px;
    background: linear-gradient(145deg, #1A2130, #121824);
    border: 1px solid rgba(255,255,255,.08); text-align: center;
}
.g-stat .lbl {
    font-size: .7rem; color: #94A3B8;
    text-transform: uppercase; letter-spacing: 1.5px;
    font-weight: 800; margin-bottom: 8px;
}
.g-stat .val {
    font-size: 1.5rem; font-weight: 900; color: #F5B800;
    font-variant-numeric: tabular-nums;
}
.g-stat .val-cdf {
    font-size: .8rem; color: #94A3B8;
    font-weight: 700; margin-top: 4px; display: block;
}
.g-stat.green .val { color: #6EE7B7; }
.g-stat.blue .val { color: #93B4FF; }
.g-stat.red .val { color: #FCA5A5; }
.g-stat.purple .val { color: #A78BFA; }

.section-title {
    color: #F5B800; font-size: 1.15rem; font-weight: 800;
    margin: 32px 0 16px; padding-bottom: 10px;
    border-bottom: 2px solid rgba(245,184,0,.3);
    display: flex; align-items: center; gap: 10px;
}

table.global-table {
    width: 100%; border-collapse: collapse; font-size: .8rem;
    background: rgba(11,14,20,.4); border-radius: 12px; overflow: hidden;
}
table.global-table thead { background: #0D1B5E; color: #fff; }
table.global-table th {
    padding: 10px 8px; text-align: left;
    font-size: .65rem; text-transform: uppercase;
    letter-spacing: .5px; font-weight: 800; white-space: nowrap;
}
table.global-table td {
    padding: 10px 8px;
    border-bottom: 1px solid rgba(255,255,255,.05);
    vertical-align: middle;
}
table.global-table tbody tr:hover { background: rgba(245,184,0,.05); }
table.global-table .right { text-align: right; }
table.global-table .center { text-align: center; }
table.global-table .cdf { color: #93B4FF; font-weight: 700; }
table.global-table .usd { color: #6EE7B7; font-weight: 700; }
table.global-table code {
    font-family: 'JetBrains Mono', monospace;
    font-size: .72rem; color: #93B4FF;
    background: rgba(11,14,20,.6); padding: 2px 6px; border-radius: 4px;
}
table.global-table tr.total-row {
    background: rgba(245,184,0,.12);
    border-top: 3px solid #F5B800; font-weight: 900;
}
table.global-table tr.total-row td {
    padding: 14px 8px; color: #F5B800; font-size: .85rem;
}

.footer-print {
    margin-top: 40px; padding-top: 20px;
    border-top: 2px solid #F5B800;
    text-align: center; color: #94A3B8;
    font-size: .75rem; line-height: 1.8;
}
.footer-print strong { color: #F5B800; }

.taux-info {
    padding: 12px 18px; background: rgba(245,184,0,.08);
    border-left: 4px solid #F5B800; border-radius: 8px;
    margin-bottom: 20px; font-size: .82rem; color: #FCD34D;
    display: flex; align-items: center; gap: 10px;
}
.taux-info strong { color: #F5B800; }

@media print {
    body { background: #fff; color: #000; padding: 0; font-size: 10px; }
    .btn-float { display: none !important; }
    .container { background: #fff; border: none; border-radius: 0; padding: 0; max-width: 100%; }
    .page-header { border-bottom: 3px solid #F5B800; padding-bottom: 16px; }
    .page-header h1 { color: #0D1B5E; }
    .page-header .slogan { color: #666; }
    .page-header .ref { color: #333; }
    .page-header .ref strong { color: #0D1B5E; }
    .page-header .logo-img { background: #F5B800; color: #0D1B5E; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .g-stat { background: #f5f5f5; border: 1px solid #ddd; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .g-stat .val { color: #0D1B5E !important; }
    .g-stat .lbl { color: #666; }
    .taux-info { background: #FFFBEB; border-left: 4px solid #F5B800; color: #92400E; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    table.global-table { background: #fff; border: 1px solid #ddd; }
    table.global-table thead { background: #0D1B5E !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    table.global-table td { border-bottom: 1px solid #eee; color: #000; }
    table.global-table .cdf { color: #1E3A8A !important; }
    table.global-table .usd { color: #065F46 !important; }
    table.global-table tr.total-row { background: #FFFBEB !important; border-top: 3px solid #F5B800; }
    table.global-table tr.total-row td { color: #92400E !important; }
    table.global-table code { background: #f0f0f0; color: #0D1B5E; }
    .section-title { color: #0D1B5E; border-bottom: 2px solid #F5B800; }
    .footer-print { border-top: 2px solid #F5B800; color: #666; }
    .footer-print strong { color: #0D1B5E; }
    tr { page-break-inside: avoid; }
    thead { display: table-header-group; }
    @page { margin: 1.5cm; size: A4; }
}
@media (max-width: 768px) {
    body { padding: 15px; }
    .container { padding: 20px; border-radius: 14px; }
    .page-header { flex-direction: column; }
    .page-header .ref { text-align: left; }
    .btn-float { padding: 10px 16px; font-size: .78rem; }
    table.global-table { display: block; overflow-x: auto; white-space: nowrap; }
}
</style>
</head>
<body>

<button class="btn-float print" onclick="window.print()">🖨️ Imprimer / Enregistrer PDF</button>
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
            <strong>RAPPORT GLOBAL PRÊTS <?= $annee ?></strong>
            Période : <?= date('d/m/Y', strtotime($debut)) ?> au <?= date('d/m/Y', strtotime($fin)) ?><br>
            Édité le : <?= date('d/m/Y à H:i') ?><br>
            Réf. : ATF-GLB-<?= date('Ymd') ?>-<?= $annee ?>
        </div>
    </div>

    <div class="taux-info">
        <span style="font-size:1.2rem">💱</span>
        <div>
            Taux de change appliqué : <strong>1 USD = <?= number_format($TAUX_USD_CDF, 0, ',', ' ') ?> CDF</strong>
            &nbsp;·&nbsp; Les totaux sont affichés dans les 2 devises : <strong>CDF</strong> et <strong>USD</strong>
        </div>
    </div>

    <h2 class="section-title">📊 Statistiques globales <?= $annee ?></h2>

    <div class="global-stats">
        <div class="g-stat blue">
            <div class="lbl">👥 Clients emprunteurs</div>
            <div class="val"><?= $total_clients ?></div>
        </div>
        <div class="g-stat">
            <div class="lbl">📋 Total prêts</div>
            <div class="val"><?= $total_prets_an ?></div>
        </div>
        <div class="g-stat cdf">
            <div class="lbl">💰 Décaissé CDF</div>
            <div class="val" style="color:#93B4FF"><?= number_format($total_cdf_an, 0, ',', ' ') ?> FC</div>
        </div>
        <div class="g-stat usd">
            <div class="lbl">💵 Décaissé USD</div>
            <div class="val" style="color:#6EE7B7">$ <?= number_format($total_usd_an, 2, ',', ' ') ?></div>
        </div>
    </div>

    <div class="global-stats">
        <div class="g-stat green">
            <div class="lbl">✅ Remboursé CDF</div>
            <div class="val"><?= number_format($total_paye_cdf, 0, ',', ' ') ?> FC</div>
        </div>
        <div class="g-stat green">
            <div class="lbl">✅ Remboursé USD</div>
            <div class="val">$ <?= number_format($total_paye_usd, 2, ',', ' ') ?></div>
        </div>
        <div class="g-stat red">
            <div class="lbl">🔴 Reste dû CDF</div>
            <div class="val"><?= number_format($total_restant_cdf, 0, ',', ' ') ?> FC</div>
        </div>
        <div class="g-stat red">
            <div class="lbl">🔴 Reste dû USD</div>
            <div class="val">$ <?= number_format($total_restant_usd, 2, ',', ' ') ?></div>
        </div>
    </div>

    <h2 class="section-title">📋 Détail par client emprunteur (<?= $total_clients ?>)</h2>

    <?php if (empty($clients)): ?>
        <div style="text-align:center;padding:60px 20px;color:#94A3B8;font-style:italic;
                    background:rgba(11,14,20,.4);border-radius:12px;border:1px dashed rgba(255,255,255,.1)">
            <div style="font-size:3rem;opacity:.4;margin-bottom:12px">📭</div>
            Aucun client emprunteur pour l'année <?= $annee ?>.
        </div>
    <?php else: ?>
        <table class="global-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Client</th>
                    <th>Compte</th>
                    <th class="center">Prêts</th>
                    <th class="right">💰 Décaissé CDF</th>
                    <th class="right">💵 Décaissé USD</th>
                    <th class="right">✅ Payé CDF</th>
                    <th class="right">✅ Payé USD</th>
                    <th class="right">🔴 Reste CDF</th>
                    <th class="right">🔴 Reste USD</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($clients as $i => $c): ?>
                    <tr>
                        <td><strong style="color:#F5B800"><?= $i + 1 ?></strong></td>
                        <td>
                            <strong><?= htmlspecialchars($c['prenom'] . ' ' . $c['nom']) ?></strong><br>
                            <small style="color:#94A3B8"><?= htmlspecialchars($c['numero_phone'] ?? '—') ?></small>
                        </td>
                        <td><code><?= htmlspecialchars($c['numero_compte']) ?></code></td>
                        <td class="center"><?= (int)$c['nb_prets'] ?></td>
                        <td class="right cdf"><?= number_format((float)$c['total_cdf'], 0, ',', ' ') ?> FC</td>
                        <td class="right usd">$ <?= number_format((float)$c['total_usd'], 2, ',', ' ') ?></td>
                        <td class="right cdf"><?= number_format((float)$c['paye_cdf'], 0, ',', ' ') ?> FC</td>
                        <td class="right usd">$ <?= number_format((float)$c['paye_usd'], 2, ',', ' ') ?></td>
                        <td class="right cdf"><?= number_format((float)$c['restant_cdf'], 0, ',', ' ') ?> FC</td>
                        <td class="right usd">$ <?= number_format((float)$c['restant_usd'], 2, ',', ' ') ?></td>
                    </tr>
                <?php endforeach; ?>

                <tr class="total-row">
                    <td colspan="4" style="text-align:right">TOTAL GÉNÉRAL :</td>
                    <td class="right"><?= number_format($total_cdf_an, 0, ',', ' ') ?> FC</td>
                    <td class="right">$ <?= number_format($total_usd_an, 2, ',', ' ') ?></td>
                    <td class="right"><?= number_format($total_paye_cdf, 0, ',', ' ') ?> FC</td>
                    <td class="right">$ <?= number_format($total_paye_usd, 2, ',', ' ') ?></td>
                    <td class="right"><?= number_format($total_restant_cdf, 0, ',', ' ') ?> FC</td>
                    <td class="right">$ <?= number_format($total_restant_usd, 2, ',', ' ') ?></td>
                </tr>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($format === 'complet'): ?>
    <h2 class="section-title">💼 Synthèse consolidée</h2>

    <div class="global-stats">
        <div class="g-stat">
            <div class="lbl">💰 Total décaissé (CDF équivalent)</div>
            <div class="val"><?= number_format($total_cdf_equivalent, 0, ',', ' ') ?> FC</div>
            <div class="val-cdf">
                soit $ <?= number_format($total_cdf_equivalent / $TAUX_USD_CDF, 2, ',', ' ') ?> USD
            </div>
        </div>

        <div class="g-stat green">
            <div class="lbl">✅ Total remboursé</div>
            <div class="val"><?= number_format($total_paye_cdf + ($total_paye_usd * $TAUX_USD_CDF), 0, ',', ' ') ?> FC</div>
            <div class="val-cdf">
                soit $ <?= number_format($total_paye_cdf / $TAUX_USD_CDF + $total_paye_usd, 2, ',', ' ') ?> USD
            </div>
        </div>

        <div class="g-stat red">
            <div class="lbl">🔴 Encours total</div>
            <div class="val"><?= number_format($total_restant_cdf + ($total_restant_usd * $TAUX_USD_CDF), 0, ',', ' ') ?> FC</div>
            <div class="val-cdf">
                soit $ <?= number_format($total_restant_cdf / $TAUX_USD_CDF + $total_restant_usd, 2, ',', ' ') ?> USD
            </div>
        </div>

        <div class="g-stat purple">
            <div class="lbl">📊 Taux de recouvrement</div>
            <?php
                $total_du_global = $total_cdf_an + ($total_usd_an * $TAUX_USD_CDF);
                $total_recu_global = $total_paye_cdf + ($total_paye_usd * $TAUX_USD_CDF);
                $taux = $total_du_global > 0 ? ($total_recu_global / $total_du_global) * 100 : 0;
            ?>
            <div class="val"><?= number_format($taux, 2) ?>%</div>
        </div>
    </div>
    <?php endif; ?>

    <div class="footer-print">
        <p><strong>AFRICAN TRUST FUND</strong> — Société de Gestion d'Actifs (S.G.A)</p>
        <p>Rapport confidentiel — Toute reproduction non autorisée est interdite.</p>
        <p style="margin-top:8px;font-size:.7rem">
            Réf. ATF-GLB-<?= date('Ymd') ?>-<?= $annee ?>
            — Généré par admin #<?= $admin_id ?>
            le <?= date('d/m/Y à H:i:s') ?><br>
            Taux appliqué : 1 USD = <?= number_format($TAUX_USD_CDF, 0, ',', ' ') ?> CDF
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