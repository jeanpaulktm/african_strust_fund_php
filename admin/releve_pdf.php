<?php
/**
 * RELEVÉ PDF CLIENT — Sécurisé (token en POST)
 * Aucun ID dans l'URL — Token one-shot + CSRF
 * 💵 Affichage DOUBLE SOLDE : USD + CDF
 */
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/interets.php';
requireRole('admin');

$pdo = Database::getConnection();
$admin_id = (int)$_SESSION['admin_id'];

/* ============================================================
   💱 TAUX DE CHANGE USD → CDF (modifiable via config)
   ============================================================ */
$TAUX_USD_CDF = 2800.00; // 1 USD = 2800 CDF (à ajuster selon le taux du jour)

/* Charger depuis la config si disponible */
if (function_exists('getTauxChange')) {
    $TAUX_USD_CDF = (float)getTauxChange('USD', 'CDF', $TAUX_USD_CDF);
}

/* ============================================================
   RÉCUPÉRATION SÉCURISÉE DE L'ID CLIENT
   ============================================================ */
$id_client = 0;
$erreur_securite = '';

/* Méthode A : Token en POST (recommandé) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['token'])) {
    if (!verifyCsrf($_POST['csrf'] ?? null)) {
        $erreur_securite = "Jeton CSRF invalide.";
    } else {
        $data = consommerToken('client', $_POST['token']);
        if ($data) {
            $id_client = (int)$data['id'];
        } else {
            $erreur_securite = "Token invalide ou expiré.";
        }
    }
}
/* Méthode B : Session one-shot (fallback depuis clients.php) */
elseif (!empty($_SESSION['one_shot_pdf_client_id'])) {
    $id_client = (int)$_SESSION['one_shot_pdf_client_id'];
    unset($_SESSION['one_shot_pdf_client_id']);
}
/* Méthode C : Session one-shot pour client (fallback) */
elseif (!empty($_SESSION['one_shot_client_id'])) {
    $id_client = (int)$_SESSION['one_shot_client_id'];
    unset($_SESSION['one_shot_client_id']);
}

/* ============================================================
   VÉRIFICATION FINALE
   ============================================================ */
if ($erreur_securite) {
    logAction($pdo, 'admin', $admin_id, 'SECURITY_DENIED', "releve_pdf : $erreur_securite");
    refuserAcces($erreur_securite);
}

if ($id_client <= 0) {
    logAction($pdo, 'admin', $admin_id, 'SECURITY_DENIED', "releve_pdf : aucun token valide");
    refuserAcces("Accès non autorisé. Passez par la liste des clients.");
}

/* ============================================================
   CHARGEMENT DU CLIENT
   ============================================================ */
$stmt = $pdo->prepare("SELECT * FROM Client WHERE id_client = ? LIMIT 1");
$stmt->execute([$id_client]);
$client = $stmt->fetch();

if (!$client) {
    logAction($pdo, 'admin', $admin_id, 'RELEVE_PDF_404', "Client #$id_client introuvable");
    refuserAcces("Client introuvable.");
}

/* Audit */
logAction($pdo, 'admin', $admin_id, 'RELEVE_PDF_OK',
    "Relevé PDF client #$id_client ({$client['numero_compte']})");

/* ============================================================
   💱 CALCUL DES 2 SOLDES (USD + CDF)
   ============================================================ */
$solde_principal = (float)($client['solde'] ?? 0);
$devise_principale = strtoupper($client['devise'] ?? 'USD');

/* Déterminer le solde USD et le solde CDF selon la devise principale */
if ($devise_principale === 'CDF') {
    $solde_cdf = $solde_principal;
    $solde_usd = $TAUX_USD_CDF > 0 ? $solde_principal / $TAUX_USD_CDF : 0;
} else {
    /* USD par défaut */
    $solde_usd = $solde_principal;
    $solde_cdf = $solde_principal * $TAUX_USD_CDF;
}

/* Arrondi à 2 décimales */
$solde_usd = round($solde_usd, 2);
$solde_cdf = round($solde_cdf, 2);

/* ============================================================
   PÉRIODE (paramétrable via POST, fallback = année en cours)
   ============================================================ */
$debut = $_POST['debut'] ?? date('Y-01-01');
$fin   = $_POST['fin']   ?? date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $debut)) $debut = date('Y-01-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fin))   $fin   = date('Y-m-d');

/* ============================================================
   CHARGEMENT DES DONNÉES
   ============================================================ */

/* Transactions */
$stmt = $pdo->prepare("
    SELECT * FROM Transactions 
    WHERE id_client = ? AND DATE(date_transaction) BETWEEN ? AND ?
    ORDER BY date_transaction ASC
");
$stmt->execute([$id_client, $debut, $fin]);
$transactions = $stmt->fetchAll();

/* Placements */
$stmt = $pdo->prepare("
    SELECT p.*, pr.nom AS produit_nom, pr.type_interet, pr.taux_annuel
    FROM Placements p
    JOIN Produits_Epargne pr ON pr.id_produit = p.id_produit
    WHERE p.id_client = ?
    ORDER BY p.created_at DESC
");
$stmt->execute([$id_client]);
$placements = $stmt->fetchAll();

/* Intérêts */
$stmt = $pdo->prepare("
    SELECT ih.*, p.reference AS placement_ref, pr.nom AS produit_nom
    FROM Interets_Historique ih
    JOIN Placements p ON p.id_placement = ih.id_placement
    JOIN Produits_Epargne pr ON pr.id_produit = p.id_produit
    WHERE p.id_client = ? AND DATE(ih.date_calcul) BETWEEN ? AND ?
    ORDER BY ih.date_calcul ASC
");
$stmt->execute([$id_client, $debut, $fin]);
$interets = $stmt->fetchAll();

/* Commissions */
$stmt = $pdo->prepare("
    SELECT * FROM Commissions
    WHERE id_client = ? AND DATE(date_commission) BETWEEN ? AND ?
    ORDER BY date_commission ASC
");
$stmt->execute([$id_client, $debut, $fin]);
$commissions = $stmt->fetchAll();

/* ============================================================
   TOTAUX
   ============================================================ */
$total_depots = 0;
$total_retraits = 0;
$total_virements = 0;

foreach ($transactions as $t) {
    if ($t['type_transaction'] === 'Depot')    $total_depots    += (float)$t['montant'];
    if ($t['type_transaction'] === 'Retrait')  $total_retraits  += (float)$t['montant'];
    if ($t['type_transaction'] === 'Virement') $total_virements += (float)$t['montant'];
}

$total_interets    = array_sum(array_map(fn($i) => (float)$i['montant_interet'], $interets));
$total_commissions = array_sum(array_map(fn($c) => (float)$c['montant_commission'], $commissions));

/* 💱 Conversion des totaux dans les 2 devises */
function convertirTotaux(float $montant, float $taux, string $devise_principale): array
{
    if (strtoupper($devise_principale) === 'CDF') {
        return [
            'usd' => $taux > 0 ? round($montant / $taux, 2) : 0,
            'cdf' => round($montant, 2),
        ];
    }
    return [
        'usd' => round($montant, 2),
        'cdf' => round($montant * $taux, 2),
    ];
}

$totaux_usd_cdf = [
    'depots'      => convertirTotaux($total_depots, $TAUX_USD_CDF, $devise_principale),
    'retraits'    => convertirTotaux($total_retraits, $TAUX_USD_CDF, $devise_principale),
    'interets'    => convertirTotaux($total_interets, $TAUX_USD_CDF, $devise_principale),
    'commissions' => convertirTotaux($total_commissions, $TAUX_USD_CDF, $devise_principale),
];

/* Helper formatage devise */
function formatMontant(float $montant, string $devise): string
{
    $devise = strtoupper($devise);
    if ($devise === 'CDF') {
        return number_format($montant, 0, ',', ' ') . ' FC';
    }
    return '$' . number_format($montant, 2, ',', ' ');
}

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Relevé <?= htmlspecialchars($client['numero_compte']) ?> — AFRICAN TRUST FUND</title>
<style>
/* ============================================================
   STYLES D'IMPRESSION PDF — Rendu impeccable sur papier
   ============================================================ */
*{box-sizing:border-box;margin:0;padding:0}
body{
  font-family:'Inter','Segoe UI',system-ui,sans-serif;
  color:#1a1a1a;background:#fff;
  padding:30px;font-size:12px;line-height:1.5;
}

/* En-tête */
.header{
  display:flex;justify-content:space-between;align-items:center;
  border-bottom:3px solid #F5B800;padding-bottom:20px;margin-bottom:24px;
  page-break-inside:avoid;
}
.header .logo-block{display:flex;align-items:center;gap:16px}
.header .logo-block img{
  height:60px;width:60px;object-fit:contain;
  border:2px solid #F5B800;border-radius:12px;padding:4px;
}
.header h1{color:#0D1B5E;font-size:22px;margin-bottom:4px;letter-spacing:1px}
.header .slogan{color:#666;font-style:italic;font-size:11px}
.header .ref{text-align:right;font-size:11px;color:#444}
.header .ref strong{color:#0D1B5E;font-size:14px;display:block;margin-bottom:4px}

/* Info client */
.info-box{
  display:grid;grid-template-columns:1fr 1fr;gap:16px;
  padding:16px;background:#f9f9f9;border-radius:10px;
  margin-bottom:22px;border-left:4px solid #F5B800;
  page-break-inside:avoid;
}
.info-box div{margin-bottom:6px}
.info-box .label{color:#666;font-size:11px}
.info-box .value{color:#0D1B5E;font-weight:700;font-size:13px}

/* ============================================================
   💵 DOUBLE SOLDE (USD + CDF) — Design premium
   ============================================================ */
.soldes-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
  margin-bottom: 22px;
  page-break-inside: avoid;
}
.solde-card {
  padding: 20px 22px;
  border-radius: 14px;
  position: relative;
  overflow: hidden;
  box-shadow: 0 4px 16px rgba(0,0,0,.08);
}
.solde-card::before {
  content: "";
  position: absolute;
  top: -30px;
  right: -30px;
  width: 100px;
  height: 100px;
  border-radius: 50%;
  opacity: .12;
}
.solde-card.usd {
  background: linear-gradient(135deg, #065F46 0%, #059669 100%);
  color: #fff;
}
.solde-card.usd::before { background: #fff; }
.solde-card.cdf {
  background: linear-gradient(135deg, #0D1B5E 0%, #1E3A8A 100%);
  color: #fff;
}
.solde-card.cdf::before { background: #F5B800; }

.solde-card .flag {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  font-size: 11px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 1.5px;
  opacity: .85;
  margin-bottom: 8px;
}
.solde-card .flag .icon {
  font-size: 16px;
}
.solde-card .devise {
  font-size: 10px;
  opacity: .7;
  letter-spacing: 2px;
  font-weight: 600;
  margin-bottom: 4px;
}
.solde-card .montant {
  font-size: 26px;
  font-weight: 900;
  letter-spacing: -0.5px;
  margin-bottom: 6px;
  font-variant-numeric: tabular-nums;
}
.solde-card .sub {
  font-size: 10px;
  opacity: .75;
  font-style: italic;
}

/* Taux de change */
.taux-info {
  padding: 10px 16px;
  background: #FFFBEB;
  border-left: 4px solid #F5B800;
  border-radius: 8px;
  margin-bottom: 20px;
  font-size: 11px;
  color: #92400E;
  display: flex;
  align-items: center;
  gap: 10px;
  page-break-inside: avoid;
}
.taux-info .icon { font-size: 16px; }
.taux-info strong { color: #78350F; font-weight: 800; }

/* Sections */
.section{margin-bottom:24px;page-break-inside:avoid}
.section h2{
  color:#0D1B5E;font-size:14px;margin-bottom:12px;
  border-bottom:2px solid #F5B800;padding-bottom:6px;
  letter-spacing:.5px;
}

/* Tableaux */
table{width:100%;border-collapse:collapse;font-size:11px}
th{
  background:#0D1B5E;color:#fff;padding:8px;
  text-align:left;font-weight:600;
}
td{padding:8px;border-bottom:1px solid #e5e5e5}
tr:nth-child(even) td{background:#fafafa}
.amount-pos{color:#059669;font-weight:700}
.amount-neg{color:#dc2626;font-weight:700}
code{
  font-family:'Courier New',monospace;font-size:10px;
  background:#f0f0f0;padding:1px 4px;border-radius:3px;
}
.dual-amount {
  font-size: 10px;
  color: #64748B;
  display: block;
  margin-top: 2px;
  font-weight: 500;
}

/* Totaux */
.totaux{
  display:grid;grid-template-columns:repeat(4,1fr);gap:12px;
  margin:20px 0;padding:16px;background:#0D1B5E;border-radius:10px;
  color:#fff;page-break-inside:avoid;
}
.totaux .item{text-align:center}
.totaux .item .lbl{
  font-size:10px;opacity:.8;text-transform:uppercase;letter-spacing:1px;
  margin-bottom:6px;
}
.totaux .item .val{
  font-size:15px;font-weight:800;color:#F5B800;
  font-variant-numeric: tabular-nums;
}
.totaux .item .val-cdf {
  font-size: 10px;
  color: #94A3B8;
  font-weight: 600;
  margin-top: 3px;
  display: block;
}

/* Pied de page */
.footer{
  margin-top:40px;padding-top:16px;border-top:2px solid #F5B800;
  text-align:center;font-size:10px;color:#666;
}

/* Bouton d'impression */
.btn-print{
  position:fixed;top:20px;right:20px;z-index:9999;
  background:linear-gradient(135deg,#F5B800,#D4AF37);
  color:#0B0E14;border:none;padding:12px 22px;
  border-radius:999px;font-weight:700;cursor:pointer;
  box-shadow:0 8px 24px rgba(245,184,0,.4);
  font-family:inherit;font-size:14px;transition:all .3s;
}
.btn-print:hover{transform:translateY(-2px);box-shadow:0 12px 32px rgba(245,184,0,.6)}

/* Bouton retour */
.btn-back{
  position:fixed;top:20px;left:20px;z-index:9999;
  background:#0D1B5E;color:#F5B800;border:2px solid #F5B800;
  padding:10px 18px;border-radius:999px;font-weight:700;
  text-decoration:none;font-size:13px;transition:all .3s;
}
.btn-back:hover{background:#F5B800;color:#0D1B5E}

/* Impression */
@media print{
  body{padding:0;font-size:11px}
  .btn-print,.btn-back{display:none}
  .section{page-break-inside:avoid}
  .header{page-break-after:avoid}
  .totaux{page-break-inside:avoid}
  .soldes-grid{page-break-inside:avoid}
  .solde-card {
    box-shadow: none;
    border: 1px solid #ccc;
  }
  @page{margin:1.5cm;size:A4}
}

@media (max-width: 600px) {
  .soldes-grid { grid-template-columns: 1fr; }
  .info-box { grid-template-columns: 1fr; }
}
</style>
</head>
<body>

<!-- Boutons (masqués à l'impression) -->
<button class="btn-print" onclick="window.print()">🖨️ Imprimer / Enregistrer PDF</button>
<a class="btn-back" href="<?= $base ?>/admin/clients.php">← Retour</a>

<!-- ============================================================
     EN-TÊTE
     ============================================================ -->
<div class="header">
  <div class="logo-block">
    <img src="<?= $base ?>/assets/img/logo.png" alt="AFRICAN TRUST FUND"
         onerror="this.src='data:image/svg+xml;utf8,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><circle cx=%2250%22 cy=%2250%22 r=%2245%22 fill=%22%23F5B800%22/><text x=%2250%22 y=%2262%22 font-size=%2234%22 text-anchor=%22middle%22 fill=%22%230B0E14%22 font-weight=%22bold%22 font-family=%22Arial%22>ATF</text></svg>'">
    <div>
      <h1>AFRICAN TRUST FUND</h1>
      <div class="slogan">L'argent de l'Afrique pour construire l'Afrique</div>
    </div>
  </div>
  <div class="ref">
    <strong>RELEVÉ DE COMPTE</strong>
    Période : <?= date('d/m/Y', strtotime($debut)) ?> au <?= date('d/m/Y', strtotime($fin)) ?><br>
    Édité le : <?= date('d/m/Y à H:i') ?><br>
    Réf. : ATF-REL-<?= date('Ymd') ?>-<?= (int)$client['id_client'] ?>
  </div>
</div>

<!-- ============================================================
     INFORMATIONS CLIENT
     ============================================================ -->
<div class="info-box">
  <div>
    <div class="label">Client</div>
    <div class="value"><?= htmlspecialchars($client['nom'] . ' ' . $client['postnom'] . ' ' . $client['prenom']) ?></div>

    <div class="label" style="margin-top:8px">Numéro de compte</div>
    <div class="value"><?= htmlspecialchars($client['numero_compte']) ?></div>

    <div class="label" style="margin-top:8px">Téléphone</div>
    <div class="value"><?= htmlspecialchars($client['numero_phone']) ?></div>

    <div class="label" style="margin-top:8px">Adresse</div>
    <div class="value" style="font-weight:400;font-size:11px">
      <?= htmlspecialchars($client['adresse_complete']) ?>
    </div>
  </div>
  <div>
    <div class="label">Devise principale</div>
    <div class="value" style="font-size:18px">
      <?= $devise_principale === 'CDF' ? '🇨🇩 Franc Congolais (CDF)' : '🇺🇸 Dollar Américain (USD)' ?>
    </div>

    <div class="label" style="margin-top:12px">Statut du compte</div>
    <div class="value" style="color:<?= $client['statut'] === 'actif' ? '#059669' : '#dc2626' ?>">
      <?= strtoupper($client['statut']) ?>
    </div>

    <div class="label" style="margin-top:12px">Date d'inscription</div>
    <div class="value"><?= date('d/m/Y', strtotime($client['created_at'])) ?></div>
  </div>
</div>

<!-- ============================================================
     💵 DOUBLE SOLDE — DOLLAR & FRANC CONGOLAIS
     ============================================================ -->
<div class="soldes-grid">

  <!-- SOLDE USD -->
  <div class="solde-card usd">
    <div class="flag">
      <span class="icon">🇺🇸</span> Dollar Américain
    </div>
    <div class="devise">USD</div>
    <div class="montant">$<?= number_format($solde_usd, 2, ',', ' ') ?></div>
    <div class="sub">Solde disponible en dollars</div>
  </div>

  <!-- SOLDE CDF -->
  <div class="solde-card cdf">
    <div class="flag">
      <span class="icon">🇨🇩</span> Franc Congolais
    </div>
    <div class="devise">CDF</div>
    <div class="montant"><?= number_format($solde_cdf, 0, ',', ' ') ?> FC</div>
    <div class="sub">Solde équivalent en francs</div>
  </div>

</div>

<!-- Taux de change -->
<div class="taux-info">
  <span class="icon">💱</span>
  <div>
    Taux de change appliqué : <strong>1 USD = <?= number_format($TAUX_USD_CDF, 0, ',', ' ') ?> CDF</strong>
    &nbsp;·&nbsp; Le montant affiché en devise secondaire est fourni à titre indicatif.
  </div>
</div>

<!-- ============================================================
     TOTAUX DE LA PÉRIODE
     ============================================================ -->
<div class="totaux">
  <div class="item">
    <div class="lbl">📥 Dépôts</div>
    <div class="val">+$<?= number_format($totaux_usd_cdf['depots']['usd'], 2, ',', ' ') ?></div>
    <span class="val-cdf">+<?= number_format($totaux_usd_cdf['depots']['cdf'], 0, ',', ' ') ?> FC</span>
  </div>
  <div class="item">
    <div class="lbl">📤 Retraits</div>
    <div class="val">−$<?= number_format($totaux_usd_cdf['retraits']['usd'], 2, ',', ' ') ?></div>
    <span class="val-cdf">−<?= number_format($totaux_usd_cdf['retraits']['cdf'], 0, ',', ' ') ?> FC</span>
  </div>
  <div class="item">
    <div class="lbl">📈 Intérêts</div>
    <div class="val">+$<?= number_format($totaux_usd_cdf['interets']['usd'], 2, ',', ' ') ?></div>
    <span class="val-cdf">+<?= number_format($totaux_usd_cdf['interets']['cdf'], 0, ',', ' ') ?> FC</span>
  </div>
  <div class="item">
    <div class="lbl">💵 Commissions</div>
    <div class="val">$<?= number_format($totaux_usd_cdf['commissions']['usd'], 2, ',', ' ') ?></div>
    <span class="val-cdf"><?= number_format($totaux_usd_cdf['commissions']['cdf'], 0, ',', ' ') ?> FC</span>
  </div>
</div>

<!-- ============================================================
     TRANSACTIONS
     ============================================================ -->
<div class="section">
  <h2>📊 TRANSACTIONS DE LA PÉRIODE (<?= count($transactions) ?>)</h2>
  <?php if (!$transactions): ?>
    <p style="color:#666;font-style:italic">Aucune transaction sur cette période.</p>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Date</th>
          <th>Référence</th>
          <th>Type</th>
          <th style="text-align:right">Montant</th>
          <th style="text-align:right">Solde après</th>
          <th>Mode</th>
          <th>Par</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($transactions as $t):
          $montant = (float)$t['montant'];
          $est_retrait = $t['type_transaction'] === 'Retrait';
          $montant_alt = strtoupper($client['devise'] ?? 'USD') === 'CDF'
              ? ($TAUX_USD_CDF > 0 ? $montant / $TAUX_USD_CDF : 0)
              : $montant * $TAUX_USD_CDF;
          $devise_alt = strtoupper($client['devise'] ?? 'USD') === 'CDF' ? 'USD' : 'CDF';
        ?>
          <tr>
            <td><?= date('d/m/Y H:i', strtotime($t['date_transaction'])) ?></td>
            <td><code><?= htmlspecialchars($t['reference']) ?></code></td>
            <td><?= htmlspecialchars($t['type_transaction']) ?></td>
            <td style="text-align:right" class="<?= $est_retrait ? 'amount-neg' : 'amount-pos' ?>">
              <?= $est_retrait ? '−' : '+' ?><?= formatMontant($montant, $client['devise']) ?>
              <span class="dual-amount">
                ≈ <?= $devise_alt === 'CDF'
                      ? number_format($montant_alt, 0, ',', ' ') . ' FC'
                      : '$' . number_format($montant_alt, 2, ',', ' ') ?>
              </span>
            </td>
            <td style="text-align:right;font-weight:600">
              <?= formatMontant((float)$t['solde_apres'], $client['devise']) ?>
            </td>
            <td><?= htmlspecialchars($t['mode']) ?></td>
            <td><?= htmlspecialchars($t['effectue_par']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<!-- ============================================================
     PLACEMENTS
     ============================================================ -->
<div class="section">
  <h2>💰 PLACEMENTS D'ÉPARGNE (<?= count($placements) ?>)</h2>
  <?php if (!$placements): ?>
    <p style="color:#666;font-style:italic">Aucun placement.</p>
  <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>Référence</th>
          <th>Produit</th>
          <th style="text-align:right">Capital</th>
          <th style="text-align:right">Valeur actuelle</th>
          <th style="text-align:right">Intérêts</th>
          <th>Début</th>
          <th>Fin</th>
          <th>Statut</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($placements as $p): ?>
          <tr>
            <td><code><?= htmlspecialchars($p['reference']) ?></code></td>
            <td><?= htmlspecialchars($p['produit_nom']) ?></td>
            <td style="text-align:right"><?= formatMontant((float)$p['montant_initial'], $client['devise']) ?></td>
            <td style="text-align:right;font-weight:700">
              <?= formatMontant((float)$p['montant_actuel'], $client['devise']) ?>
            </td>
            <td style="text-align:right" class="amount-pos">
              +<?= formatMontant((float)$p['interets_cumules'], $client['devise']) ?>
            </td>
            <td><?= date('d/m/Y', strtotime($p['date_debut'])) ?></td>
            <td><?= date('d/m/Y', strtotime($p['date_fin'])) ?></td>
            <td><?= htmlspecialchars($p['statut']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<!-- ============================================================
     INTÉRÊTS DÉTAILLÉS
     ============================================================ -->
<?php if ($interets): ?>
<div class="section">
  <h2>📈 INTÉRÊTS CRÉDITÉS (<?= count($interets) ?>)</h2>
  <table>
    <thead>
      <tr>
        <th>Date</th>
        <th>Placement</th>
        <th>Produit</th>
        <th style="text-align:right">Intérêt</th>
        <th style="text-align:right">Solde après</th>
        <th>Période</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($interets as $i): ?>
        <tr>
          <td><?= date('d/m/Y', strtotime($i['date_calcul'])) ?></td>
          <td><code><?= htmlspecialchars($i['placement_ref']) ?></code></td>
          <td><?= htmlspecialchars($i['produit_nom']) ?></td>
          <td style="text-align:right" class="amount-pos">
            +<?= formatMontant((float)$i['montant_interet'], $client['devise']) ?>
          </td>
          <td style="text-align:right;font-weight:600">
            <?= formatMontant((float)$i['solde_apres'], $client['devise']) ?>
          </td>
          <td style="font-size:10px">
            <?= date('d/m/Y', strtotime($i['periode_debut'])) ?> → <?= date('d/m/Y', strtotime($i['periode_fin'])) ?>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- ============================================================
     COMMISSIONS
     ============================================================ -->
<?php if ($commissions): ?>
<div class="section">
  <h2>💵 COMMISSIONS PRÉLEVÉES (<?= count($commissions) ?>)</h2>
  <table>
    <thead>
      <tr>
        <th>Date</th>
        <th style="text-align:right">Montant brut</th>
        <th style="text-align:right">Taux</th>
        <th style="text-align:right">Commission</th>
        <th style="text-align:right">Net versé</th>
        <th>Devise</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($commissions as $c): ?>
        <tr>
          <td><?= date('d/m/Y H:i', strtotime($c['date_commission'])) ?></td>
          <td style="text-align:right"><?= formatMontant((float)$c['montant_brut'], $c['devise']) ?></td>
          <td style="text-align:right"><?= number_format((float)$c['taux_commission'] * 100, 2) ?>%</td>
          <td style="text-align:right" class="amount-neg">
            <?= formatMontant((float)$c['montant_commission'], $c['devise']) ?>
          </td>
          <td style="text-align:right"><?= formatMontant((float)$c['montant_net'], $c['devise']) ?></td>
          <td><?= htmlspecialchars($c['devise']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<!-- ============================================================
     PIED DE PAGE
     ============================================================ -->
<div class="footer">
  <p><strong>AFRICAN TRUST FUND</strong> — Société de Gestion d'Actifs (S.G.A)</p>
  <p>Ce relevé est généré automatiquement et fait foi de vos opérations.</p>
  <p style="margin-top:8px;font-size:9px;color:#999">
    Document confidentiel — Toute reproduction non autorisée est interdite.<br>
    Réf. ATF-REL-<?= date('Ymd') ?>-<?= (int)$client['id_client'] ?> — Généré par admin #<?= $admin_id ?><br>
    Taux appliqué : 1 USD = <?= number_format($TAUX_USD_CDF, 0, ',', ' ') ?> CDF
  </p>
</div>

</body>
</html>