<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
requireRole('admin');

$pdo = Database::getConnection();
$admin_id = (int)$_SESSION['admin_id'];
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }

$debut = $_GET['debut'] ?? date('Y-m-01');
$fin = $_GET['fin'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $debut)) $debut = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fin)) $fin = date('Y-m-d');
if ($debut > $fin) [$debut, $fin] = [$fin, $debut];

$TAUX_USD_CDF = 2800;

/* Récupération des dépenses */
$stmt = $pdo->prepare("SELECT * FROM Depenses WHERE DATE(date_depense) BETWEEN ? AND ? ORDER BY date_depense DESC");
$stmt->execute([$debut, $fin]);
$depenses = $stmt->fetchAll();

/* Totaux */
$tot_usd = 0; $tot_cdf = 0; $nb_usd = 0; $nb_cdf = 0;
foreach ($depenses as $d) {
    if (strtoupper($d['devise']) === 'USD') { $tot_usd += (float)$d['montant']; $nb_usd++; }
    else { $tot_cdf += (float)$d['montant']; $nb_cdf++; }
}
$tot_eq = $tot_cdf + ($tot_usd * $TAUX_USD_CDF);

/* Par catégorie */
$par_cat = [];
try {
    $stmt = $pdo->prepare("
        SELECT categorie,
               COALESCE(SUM(CASE WHEN devise='USD' THEN montant ELSE 0 END),0) AS usd,
               COALESCE(SUM(CASE WHEN devise='CDF' THEN montant ELSE 0 END),0) AS cdf,
               COUNT(*) AS nb
        FROM Depenses
        WHERE DATE(date_depense) BETWEEN ? AND ?
        GROUP BY categorie
        ORDER BY (usd * ? + cdf) DESC
    ");
    $stmt->execute([$debut, $fin, $TAUX_USD_CDF]);
    $par_cat = $stmt->fetchAll();
} catch (Throwable $e) {}

try { logAction($pdo, 'admin', $admin_id, 'DEPENSES_IMPRIMER', "$debut → $fin"); } catch (Throwable $e) {}

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Rapport Dépenses ATF — <?= date('d/m/Y') ?></title>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:'Inter',sans-serif; background:#F1F5F9; color:#1E293B; padding:30px 20px; font-size:12px; line-height:1.5; }
.btn-float { position:fixed; top:20px; z-index:9999; padding:12px 22px; border-radius:999px; font-weight:800; font-size:.88rem; cursor:pointer; border:none; font-family:inherit; text-decoration:none; display:inline-flex; align-items:center; gap:8px; transition:all .25s; box-shadow:0 8px 24px rgba(0,0,0,.15); }
.btn-float.print { right:20px; background:linear-gradient(135deg,#F5B800,#D4AF37); color:#0B0E14; }
.btn-float.back { left:20px; background:#0D1B5E; color:#F5B800; border:2px solid #F5B800; }
.page { max-width:1100px; margin:0 auto; background:#FFFFFF; border-radius:12px; padding:40px 45px; box-shadow:0 20px 60px rgba(0,0,0,.08); }
.report-header { display:flex; justify-content:space-between; align-items:flex-start; gap:24px; padding-bottom:22px; border-bottom:3px solid #0D1B5E; margin-bottom:26px; }
.report-header .logo-box { width:72px; height:72px; border-radius:16px; background:linear-gradient(135deg,#F5B800,#D4AF37); display:flex; align-items:center; justify-content:center; color:#0D1B5E; font-weight:900; font-size:1.5rem; }
.report-header .logo-block { display:flex; align-items:center; gap:16px; }
.report-header h1 { color:#0D1B5E; font-size:1.45rem; font-weight:900; }
.report-header .slogan { color:#64748B; font-size:.78rem; font-style:italic; }
.report-header .meta-right { text-align:right; font-size:.78rem; color:#475569; }
.report-header .meta-right .titre { color:#0D1B5E; font-size:1rem; font-weight:900; text-transform:uppercase; letter-spacing:1px; margin-bottom:6px; display:block; }
.report-header .meta-right .ref { font-family:'Courier New',monospace; color:#F5B800; font-weight:800; font-size:.72rem; background:#FFFBEB; padding:3px 8px; border-radius:6px; display:inline-block; margin-top:4px; border:1px solid #F5B800; }
.periode-banner { padding:14px 22px; background:linear-gradient(135deg,#FFFBEB,#FEF3C7); border-left:5px solid #F5B800; border-radius:10px; margin-bottom:24px; }
.hero-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; margin-bottom:26px; }
.hero-print { padding:26px 28px; border-radius:16px; color:#FFFFFF; position:relative; overflow:hidden; page-break-inside:avoid; -webkit-print-color-adjust:exact; print-color-adjust:exact; border:2px solid; }
.hero-print.usd { background:linear-gradient(135deg,#DC2626,#F87171); border-color:#DC2626; }
.hero-print.cdf { background:linear-gradient(135deg,#B45309,#F59E0B); border-color:#D97706; }
.hero-print .flag-row { display:flex; align-items:center; gap:10px; margin-bottom:12px; }
.hero-print .flag-icon { font-size:1.8rem; }
.hero-print .flag-label { font-size:.74rem; font-weight:800; text-transform:uppercase; letter-spacing:2px; }
.hero-print .devise-tag { margin-left:auto; padding:3px 10px; border-radius:999px; background:rgba(255,255,255,.2); border:1px solid rgba(255,255,255,.3); font-size:.68rem; font-weight:900; }
.hero-print .amount { font-size:2.2rem; font-weight:900; letter-spacing:-1px; line-height:1; margin-bottom:6px; font-variant-numeric:tabular-nums; }
.hero-print .amount .unit { font-size:1rem; opacity:.85; margin-left:6px; font-weight:800; }
.hero-print .sub-label { font-size:.78rem; opacity:.85; margin-bottom:14px; }
.eq-banner { padding:16px 24px; background:linear-gradient(135deg,#FFFBEB,#FEF3C7); border:2px solid #F5B800; border-radius:12px; margin-bottom:30px; display:flex; align-items:center; justify-content:center; gap:16px; text-align:center; flex-wrap:wrap; }
.eq-banner .text { color:#78350F; font-size:.88rem; font-weight:700; }
.eq-banner .text strong { color:#92400E; font-size:1.2rem; font-weight:900; display:block; margin-top:4px; }
.section-title { font-size:.95rem; color:#0D1B5E; font-weight:900; text-transform:uppercase; letter-spacing:1.5px; padding-bottom:10px; margin:28px 0 16px; border-bottom:2px solid #F5B800; display:flex; align-items:center; gap:10px; }
.section-title .num { width:28px; height:28px; border-radius:8px; background:#0D1B5E; color:#F5B800; display:flex; align-items:center; justify-content:center; font-size:.78rem; font-weight:900; }
table { width:100%; border-collapse:collapse; font-size:.82rem; background:#FFFFFF; border:1px solid #E2E8F0; border-radius:8px; overflow:hidden; page-break-inside:avoid; }
table thead { background:#0D1B5E; color:#FFFFFF; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
table th { padding:11px 12px; text-align:left; font-size:.68rem; text-transform:uppercase; font-weight:800; }
table td { padding:10px 12px; border-bottom:1px solid #F1F5F9; color:#334155; }
table tbody tr:nth-child(even) { background:#F8FAFC; }
table .right { text-align:right; }
table .center { text-align:center; }
table .usd { color:#991B1B; font-weight:800; text-align:right; font-variant-numeric:tabular-nums; }
table .cdf { color:#92400E; font-weight:800; text-align:right; font-variant-numeric:tabular-nums; }
table .eq { color:#7C2D12; font-weight:900; text-align:right; font-variant-numeric:tabular-nums; }
table tfoot { background:#FFFBEB; border-top:3px solid #F5B800; }
table tfoot td { padding:14px 12px; color:#92400E; font-weight:900; border:none; font-size:.85rem; }
.report-footer { margin-top:40px; padding-top:22px; border-top:2px solid #0D1B5E; text-align:center; color:#64748B; font-size:.72rem; line-height:1.8; }
.report-footer strong { color:#0D1B5E; font-weight:900; }
.signature { margin-top:30px; display:grid; grid-template-columns:1fr 1fr; gap:40px; text-align:center; font-size:.75rem; }
.signature .block { padding-top:50px; border-top:1.5px dashed #94A3B8; color:#475569; font-weight:700; }
.signature .block small { display:block; color:#94A3B8; font-weight:400; margin-top:4px; font-size:.68rem; }
@media print {
    @page { margin:1.2cm; size:A4; }
    body { background:#FFFFFF; padding:0; font-size:10px; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .btn-float { display:none !important; }
    .page { box-shadow:none; border-radius:0; padding:0; max-width:100%; }
    .hero-print, .eq-banner, table, .report-footer { page-break-inside:avoid; }
}
@media (max-width:768px) { .hero-grid { grid-template-columns:1fr; } .report-header { flex-direction:column; } }
</style>
</head>
<body>

<button class="btn-float print" onclick="window.print()">🖨️ Imprimer / PDF</button>
<a class="btn-float back" href="<?= h($base) ?>/admin/depenses.php">← Retour</a>

<div class="page">

    <div class="report-header">
        <div class="logo-block">
            <div class="logo-box">ATF</div>
            <div>
                <h1>AFRICAN TRUST FUND</h1>
                <div class="slogan">L'argent de l'Afrique pour construire l'Afrique</div>
            </div>
        </div>
        <div class="meta-right">
            <span class="titre">Rapport des Dépenses</span>
            Généré le : <strong><?= date('d/m/Y à H:i') ?></strong><br>
            Période : <strong><?= date('d/m/Y', strtotime($debut)) ?> → <?= date('d/m/Y', strtotime($fin)) ?></strong><br>
            <span class="ref">RÉF. ATF-DEP-<?= date('Ymd-His') ?></span>
        </div>
    </div>

    <div class="periode-banner">
        📅 <strong>Période analysée :</strong> du <?= date('d/m/Y', strtotime($debut)) ?> au <?= date('d/m/Y', strtotime($fin)) ?>
        · <strong><?= count($depenses) ?></strong> dépense(s) enregistrée(s)
    </div>

    <!-- HERO USD / CDF -->
    <div class="hero-grid">
        <div class="hero-print usd">
            <div class="flag-row">
                <span class="flag-icon">🇺🇸</span>
                <span class="flag-label">Dépenses USD</span>
                <span class="devise-tag">USD</span>
            </div>
            <div class="amount">$ <?= number_format($tot_usd, 2, ',', ' ') ?><span class="unit">USD</span></div>
            <div class="sub-label"><?= (int)$nb_usd ?> dépense(s) en dollars</div>
        </div>
        <div class="hero-print cdf">
            <div class="flag-row">
                <span class="flag-icon">🇨🇩</span>
                <span class="flag-label">Dépenses CDF</span>
                <span class="devise-tag">CDF</span>
            </div>
            <div class="amount"><?= number_format($tot_cdf, 0, ',', ' ') ?><span class="unit">FC</span></div>
            <div class="sub-label"><?= (int)$nb_cdf ?> dépense(s) en francs</div>
        </div>
    </div>

    <div class="eq-banner">
        <span style="font-size:1.5rem">💱</span>
        <div class="text">
            Équivalent consolidé total · taux 1 USD = <?= number_format($TAUX_USD_CDF, 0, ',', ' ') ?> FC
            <strong><?= number_format($tot_eq, 0, ',', ' ') ?> FC</strong>
        </div>
    </div>

    <!-- SECTION 1 : Par catégorie -->
    <?php if (!empty($par_cat)): ?>
    <h2 class="section-title"><span class="num">1</span> Répartition par catégorie</h2>
    <table>
        <thead>
            <tr>
                <th>Catégorie</th>
                <th class="center">Nb op.</th>
                <th class="right">🇺🇸 USD</th>
                <th class="right">🇨🇩 CDF</th>
                <th class="right">Équivalent CDF</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($par_cat as $cat): 
                $eq_cat = (float)$cat['cdf'] + (float)$cat['usd'] * $TAUX_USD_CDF;
            ?>
                <tr>
                    <td><strong><?= h(ucfirst(str_replace('_', ' ', $cat['categorie']))) ?></strong></td>
                    <td class="center"><?= (int)$cat['nb'] ?></td>
                    <td class="usd">$ <?= number_format((float)$cat['usd'], 2, ',', ' ') ?></td>
                    <td class="cdf"><?= number_format((float)$cat['cdf'], 0, ',', ' ') ?> FC</td>
                    <td class="eq"><?= number_format($eq_cat, 0, ',', ' ') ?> FC</td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td>TOTAL GÉNÉRAL</td>
                <td class="center"><?= (int)($nb_usd + $nb_cdf) ?></td>
                <td class="usd">$ <?= number_format($tot_usd, 2, ',', ' ') ?></td>
                <td class="cdf"><?= number_format($tot_cdf, 0, ',', ' ') ?> FC</td>
                <td class="eq"><?= number_format($tot_eq, 0, ',', ' ') ?> FC</td>
            </tr>
        </tfoot>
    </table>
    <?php endif; ?>

    <!-- SECTION 2 : Détail -->
    <h2 class="section-title"><span class="num">2</span> Détail des dépenses (<?= count($depenses) ?>)</h2>
    <table>
        <thead>
            <tr>
                <th>Référence</th>
                <th>Date</th>
                <th>Catégorie</th>
                <th>Description</th>
                <th>Bénéficiaire</th>
                <th class="right">Montant</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($depenses as $d): 
                $is_usd = strtoupper($d['devise']) === 'USD';
            ?>
                <tr>
                    <td><code style="font-family:'Courier New',monospace;color:#0D1B5E;font-weight:700;font-size:.72rem"><?= h($d['reference']) ?></code></td>
                    <td><?= date('d/m/Y', strtotime($d['date_depense'])) ?></td>
                    <td><strong><?= h(ucfirst(str_replace('_', ' ', $d['categorie']))) ?></strong></td>
                    <td><?= h(mb_substr($d['description'], 0, 60)) ?></td>
                    <td><?= h($d['beneficiaire'] ?? '—') ?></td>
                    <td class="<?= $is_usd ? 'usd' : 'cdf' ?>">
                        <?= $is_usd ? '$ ' . number_format((float)$d['montant'], 2, ',', ' ') : number_format((float)$d['montant'], 0, ',', ' ') . ' FC' ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="5" style="text-align:right">TOTAL PÉRIODE :</td>
                <td class="right" style="color:#92400E;font-weight:900">
                    $ <?= number_format($tot_usd, 2, ',', ' ') ?> + <?= number_format($tot_cdf, 0, ',', ' ') ?> FC
                </td>
            </tr>
        </tfoot>
    </table>

    <div class="report-footer">
        <p><strong>AFRICAN TRUST FUND</strong> — Société de Gestion d'Actifs (S.G.A)</p>
        <p>Document confidentiel — Toute reproduction non autorisée est strictement interdite.</p>
        <p style="margin-top:8px">
            Référence : <strong>ATF-DEP-<?= date('Ymd-His') ?></strong>
            — Généré par admin #<?= $admin_id ?>
            — Le <?= date('d/m/Y à H:i:s') ?>
        </p>
        <div class="signature">
            <div class="block">Le Directeur Général<small>Signature & cachet</small></div>
            <div class="block">Le Responsable Financier<small>Signature & cachet</small></div>
        </div>
    </div>

</div>

<script>
document.addEventListener('keydown', e => {
    if ((e.ctrlKey || e.metaKey) && e.key === 'p') { e.preventDefault(); window.print(); }
});
</script>

</body>
</html>