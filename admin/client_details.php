<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/interets.php';
requireRole('admin');

$pdo = Database::getConnection();
$admin_id = (int)$_SESSION['admin_id'];

/* ============================================================
   RÉCUPÉRATION SÉCURISÉE DE L'ID
   ============================================================ */
$id_client = recupererIdSecurise('client');

if (!$id_client) {
    logAction($pdo, 'admin', $admin_id, 'SECURITY_DENIED', 'Accès non autorisé à client_details.php');
    refuserAcces("Session expirée ou accès non autorisé. Veuillez repasser par la liste des clients.");
}

/* ============================================================
   CHARGEMENT CLIENT
   ============================================================ */
$stmt = $pdo->prepare("SELECT * FROM Client WHERE id_client = ? LIMIT 1");
$stmt->execute([$id_client]);
$client = $stmt->fetch();

if (!$client) {
    logAction($pdo, 'admin', $admin_id, 'CLIENT_404', "Client #$id_client introuvable");
    refuserAcces("Client introuvable.");
}

logAction($pdo, 'admin', $admin_id, 'CLIENT_VIEW_OK',
    "Consultation fiche client #$id_client ({$client['numero_compte']})");

/* ============================================================
   💵 DEUX SOLDES : USD et CDF (SÉPARÉS)
   ============================================================ */
$solde_usd = 0.0;
$solde_cdf = 0.0;

/* Récupération depuis colonnes si elles existent */
if (array_key_exists('solde_usd', $client)) {
    $solde_usd = (float)($client['solde_usd'] ?? 0);
}
if (array_key_exists('solde_cdf', $client)) {
    $solde_cdf = (float)($client['solde_cdf'] ?? 0);
}

/* Fallback : si seule la colonne "solde" existe, on l'assigne selon la devise principale */
if ($solde_usd == 0 && $solde_cdf == 0) {
    $devise_principale = strtoupper($client['devise'] ?? 'USD');
    if ($devise_principale === 'CDF') {
        $solde_cdf = (float)($client['solde'] ?? 0);
    } else {
        $solde_usd = (float)($client['solde'] ?? 0);
    }
}

/* Calcul des soldes depuis les transactions (fallback dynamique) */
try {
    /* Solde USD : dépôts - retraits sur la devise USD */
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN type_transaction='Depot' THEN montant ELSE 0 END), 0) AS total_depots,
            COALESCE(SUM(CASE WHEN type_transaction='Retrait' THEN montant ELSE 0 END), 0) AS total_retraits
        FROM Transactions
        WHERE id_client = ? AND devise = 'USD'
    ");
    $stmt->execute([$id_client]);
    $tx_usd = $stmt->fetch();
    
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN type_transaction='Depot' THEN montant ELSE 0 END), 0) AS total_depots,
            COALESCE(SUM(CASE WHEN type_transaction='Retrait' THEN montant ELSE 0 END), 0) AS total_retraits
        FROM Transactions
        WHERE id_client = ? AND devise = 'CDF'
    ");
    $stmt->execute([$id_client]);
    $tx_cdf = $stmt->fetch();
} catch (Throwable $e) {
    $tx_usd = ['total_depots' => 0, 'total_retraits' => 0];
    $tx_cdf = ['total_depots' => 0, 'total_retraits' => 0];
}

/* ============================================================
   PAGINATION DES TRANSACTIONS (10 par page)
   ============================================================ */
$tx_par_page = 10;
$tx_page = max(1, (int)($_GET['tx_page'] ?? 1));

/* Total transactions */
$stmt = $pdo->prepare("SELECT COUNT(*) FROM Transactions WHERE id_client = ?");
$stmt->execute([$id_client]);
$tx_total = (int)$stmt->fetchColumn();

$tx_total_pages = max(1, (int)ceil($tx_total / $tx_par_page));
if ($tx_page > $tx_total_pages) $tx_page = $tx_total_pages;
$tx_offset = ($tx_page - 1) * $tx_par_page;

/* Récupération paginée */
$stmt = $pdo->prepare("
    SELECT * FROM Transactions 
    WHERE id_client = ? 
    ORDER BY date_transaction DESC 
    LIMIT $tx_par_page OFFSET $tx_offset
");
$stmt->execute([$id_client]);
$transactions = $stmt->fetchAll();

/* ============================================================
   AUTRES DONNÉES
   ============================================================ */
/* Placements */
$stmt = $pdo->prepare("
    SELECT p.*, pr.nom AS produit_nom, pr.type_interet, pr.taux_annuel
    FROM Placements p
    JOIN Produits_Epargne pr ON pr.id_produit = p.id_produit
    WHERE p.id_client = ? ORDER BY p.created_at DESC
");
$stmt->execute([$id_client]);
$placements = $stmt->fetchAll();

/* Intérêts */
$stmt = $pdo->prepare("
    SELECT ih.*, p.reference AS placement_ref
    FROM Interets_Historique ih
    JOIN Placements p ON p.id_placement = ih.id_placement
    WHERE p.id_client = ? ORDER BY ih.date_calcul DESC LIMIT 20
");
$stmt->execute([$id_client]);
$interets = $stmt->fetchAll();

/* Commissions */
$stmt = $pdo->prepare("SELECT * FROM Commissions WHERE id_client = ? ORDER BY date_commission DESC LIMIT 20");
$stmt->execute([$id_client]);
$commissions = $stmt->fetchAll();

/* Stats transactions */
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) AS nb_transactions,
        COALESCE(SUM(CASE WHEN type_transaction='Depot' THEN montant ELSE 0 END),0) AS total_depots,
        COALESCE(SUM(CASE WHEN type_transaction='Retrait' THEN montant ELSE 0 END),0) AS total_retraits,
        COALESCE(SUM(CASE WHEN type_transaction='Virement' THEN montant ELSE 0 END),0) AS total_virements
    FROM Transactions WHERE id_client = ?
");
$stmt->execute([$id_client]);
$stats = $stmt->fetch();

/* Stats épargne */
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) AS nb_placements,
        COALESCE(SUM(CASE WHEN statut='actif' THEN montant_actuel ELSE 0 END),0) AS capital_actif,
        COALESCE(SUM(interets_cumules),0) AS interets_total
    FROM Placements WHERE id_client = ?
");
$stmt->execute([$id_client]);
$stats_epargne = $stmt->fetch();

/* Token PDF */
$token_pdf = genererToken('client', $id_client, ['action' => 'pdf']);

$base = '/african-trust-fund';

/* ============================================================
   HELPER : Construire URL avec paramètres actuels
   ============================================================ */
function buildTxUrl(int $page): string {
    $params = $_GET;
    $params['tx_page'] = $page;
    return '?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Fiche client — ATF Admin</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/admin-premium.css">
<style>
.client-header{
  display:flex;gap:26px;align-items:center;flex-wrap:wrap;
  padding:28px;border-radius:20px;
  background:linear-gradient(135deg,rgba(13,27,94,.6),rgba(30,41,59,.4)),
             radial-gradient(circle at 90% 10%,rgba(245,184,0,.15),transparent 60%);
  border:1px solid var(--border-strong);margin-bottom:28px;
  position:relative;overflow:hidden;animation:fadeInUp .6s ease-out both;
}
.client-header::before{
  content:"";position:absolute;top:-30%;right:-10%;
  width:280px;height:280px;border-radius:50%;
  background:radial-gradient(circle,rgba(245,184,0,.18),transparent 70%);
  animation:float 7s ease-in-out infinite;
}
.client-photo-large{
  width:130px;height:130px;border-radius:50%;object-fit:cover;
  border:4px solid var(--gold);box-shadow:0 0 40px rgba(245,184,0,.4);
  position:relative;z-index:1;background:var(--bg-1);transition:transform .4s;
}
.client-photo-large:hover{transform:scale(1.08) rotate(-3deg)}
.client-photo-placeholder-large{
  width:130px;height:130px;border-radius:50%;
  display:flex;align-items:center;justify-content:center;
  border:4px solid var(--gold);background:var(--bg-1);
  font-size:3rem;font-weight:800;color:var(--gold);
  box-shadow:0 0 40px rgba(245,184,0,.4);
  position:relative;z-index:1;transition:transform .4s;
}
.client-info{flex:1;min-width:280px;position:relative;z-index:1}
.client-info h1{font-size:1.8rem;font-weight:800;margin-bottom:8px}
.client-info .compte{color:var(--gold);font-family:monospace;font-size:1.1rem;letter-spacing:1px;margin-bottom:14px}

/* ============================================================
   💵 DOUBLE SOLDE : USD + CDF
   ============================================================ */
.soldes-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 30px;
    animation: fadeInUp .6s ease-out both;
}
.solde-rubrique {
    padding: 26px 28px;
    border-radius: 20px;
    position: relative;
    overflow: hidden;
    box-shadow: 0 12px 40px rgba(0,0,0,.35);
    transition: transform .3s;
    border: 2px solid transparent;
}
.solde-rubrique:hover {
    transform: translateY(-4px);
}
.solde-rubrique::before {
    content: "";
    position: absolute;
    top: -50px; right: -50px;
    width: 180px; height: 180px;
    border-radius: 50%;
    opacity: .12;
}
.solde-rubrique.usd {
    background: linear-gradient(135deg, #065F46 0%, #059669 50%, #10B981 100%);
    color: #FFFFFF;
    border-color: rgba(16,185,129,.5);
}
.solde-rubrique.usd::before { background: #FFFFFF; }
.solde-rubrique.cdf {
    background: linear-gradient(135deg, #0D1B5E 0%, #1E3A8A 50%, #2563EB 100%);
    color: #FFFFFF;
    border-color: rgba(59,130,246,.5);
}
.solde-rubrique.cdf::before { background: #F5B800; }

.solde-rubrique .flag-row {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 14px;
    position: relative;
    z-index: 1;
}
.solde-rubrique .flag-icon {
    font-size: 2rem;
    line-height: 1;
}
.solde-rubrique .flag-label {
    font-size: .82rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 2px;
    opacity: .95;
}
.solde-rubrique .devise-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 999px;
    background: rgba(255,255,255,.2);
    color: #FFFFFF;
    font-size: .68rem;
    font-weight: 900;
    letter-spacing: 1.5px;
    margin-left: auto;
    border: 1px solid rgba(255,255,255,.3);
}
.solde-rubrique .montant {
    font-size: 2.4rem;
    font-weight: 900;
    letter-spacing: -1px;
    line-height: 1.1;
    margin-bottom: 6px;
    font-variant-numeric: tabular-nums;
    position: relative;
    z-index: 1;
    text-shadow: 0 2px 8px rgba(0,0,0,.2);
}
.solde-rubrique .montant .unite {
    font-size: 1.2rem;
    opacity: .85;
    margin-left: 6px;
    font-weight: 800;
}
.solde-rubrique .libelle {
    font-size: .78rem;
    opacity: .85;
    font-weight: 600;
    margin-bottom: 18px;
    position: relative;
    z-index: 1;
}
.solde-rubrique .details-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
    padding-top: 18px;
    border-top: 1px solid rgba(255,255,255,.25);
    position: relative;
    z-index: 1;
}
.solde-rubrique .detail-item {
    display: flex;
    flex-direction: column;
    gap: 3px;
}
.solde-rubrique .detail-item .d-lbl {
    font-size: .68rem;
    opacity: .75;
    text-transform: uppercase;
    letter-spacing: 1px;
    font-weight: 800;
}
.solde-rubrique .detail-item .d-val {
    font-size: .95rem;
    font-weight: 900;
    font-variant-numeric: tabular-nums;
}
.solde-rubrique .detail-item .d-val.pos { color: #D1FAE5; }
.solde-rubrique .detail-item .d-val.neg { color: #FECACA; }
.solde-rubrique.cdf .detail-item .d-val.pos { color: #FCD34D; }
.solde-rubrique.cdf .detail-item .d-val.neg { color: #FCA5A5; }

@media (max-width: 768px) {
    .soldes-grid { grid-template-columns: 1fr; }
    .solde-rubrique .montant { font-size: 2rem; }
}

/* ============================================================
   ONGLETS
   ============================================================ */
.tabs-header{
  display:flex;gap:8px;margin-bottom:24px;padding:6px;
  background:var(--bg-1);border-radius:999px;
  border:1px solid var(--border);overflow-x:auto;scrollbar-width:none;
}
.tabs-header::-webkit-scrollbar{display:none}
.tab-btn{
  padding:10px 22px;border-radius:999px;font-size:.85rem;
  font-weight:700;color:var(--muted);cursor:pointer;white-space:nowrap;
  transition:.3s;background:transparent;border:none;font-family:inherit;
}
.tab-btn:hover{color:var(--gold);background:var(--gold-soft)}
.tab-btn.active{
  background:linear-gradient(135deg,var(--gold),var(--gold-2));
  color:#0B0E14;box-shadow:0 6px 20px rgba(245,184,0,.35)
}
.tab-content{display:none;animation:fadeInUp .4s ease-out both}
.tab-content.active{display:block}

/* ============================================================
   TABLEAU TRANSACTIONS AMÉLIORÉ
   ============================================================ */
.tx-container {
    background: linear-gradient(145deg, var(--card), var(--card-2));
    border: 1px solid var(--border);
    border-radius: 16px;
    overflow: hidden;
}
.tx-header {
    padding: 18px 22px;
    border-bottom: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    background: linear-gradient(135deg, rgba(245,184,0,.05), transparent);
}
.tx-header h3 {
    margin: 0;
    font-size: 1rem;
    color: var(--gold);
    display: flex;
    align-items: center;
    gap: 8px;
}
.tx-header .counter {
    padding: 5px 12px;
    border-radius: 999px;
    background: rgba(245,184,0,.12);
    color: var(--gold);
    font-size: .74rem;
    font-weight: 800;
    border: 1px solid rgba(245,184,0,.3);
}
table.tx-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .85rem;
}
table.tx-table thead {
    background: rgba(11,14,20,.55);
}
table.tx-table thead th {
    padding: 12px 14px;
    text-align: left;
    font-size: .68rem;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 1px;
    font-weight: 800;
    border-bottom: 2px solid var(--border);
    white-space: nowrap;
}
table.tx-table tbody tr {
    border-bottom: 1px solid rgba(255,255,255,.04);
    transition: background .2s;
}
table.tx-table tbody tr:hover {
    background: rgba(245,184,0,.05);
}
table.tx-table td {
    padding: 12px 14px;
    color: var(--text);
    vertical-align: middle;
}
table.tx-table code {
    font-family: 'Courier New', monospace;
    color: var(--gold);
    font-weight: 700;
    font-size: .78rem;
    background: rgba(245,184,0,.08);
    padding: 3px 8px;
    border-radius: 5px;
}
table.tx-table .amount-pos { color: #6EE7B7; font-weight: 800; text-align: right; }
table.tx-table .amount-neg { color: #FCA5A5; font-weight: 800; text-align: right; }
table.tx-table .solde-pos { color: var(--muted); text-align: right; }

/* ============================================================
   PAGINATION TRANSACTIONS
   ============================================================ */
.tx-pagination {
    padding: 18px 22px;
    border-top: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    background: rgba(11,14,20,.35);
}
.tx-pagination .info {
    color: var(--muted);
    font-size: .82rem;
    font-weight: 600;
}
.tx-pagination .info strong { color: var(--gold); font-weight: 800; }
.tx-pagination .controls {
    display: flex;
    gap: 6px;
    align-items: center;
    flex-wrap: wrap;
}
.tx-page-btn {
    min-width: 38px;
    height: 38px;
    padding: 0 12px;
    border-radius: 10px;
    background: rgba(255,255,255,.04);
    border: 1px solid var(--border);
    color: var(--text);
    font-weight: 800;
    font-size: .82rem;
    font-family: inherit;
    cursor: pointer;
    transition: all .2s;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    gap: 5px;
    white-space: nowrap;
}
.tx-page-btn:hover:not(:disabled):not(.active) {
    background: rgba(245,184,0,.12);
    border-color: rgba(245,184,0,.5);
    color: var(--gold);
    transform: translateY(-2px);
}
.tx-page-btn.active {
    background: linear-gradient(135deg, var(--gold), #D4AF37);
    color: #0B0E14;
    border-color: var(--gold);
    box-shadow: 0 6px 18px rgba(245,184,0,.4);
    pointer-events: none;
}
.tx-page-btn:disabled { opacity: .3; cursor: not-allowed; }
.tx-page-btn.prev-next {
    background: rgba(59,130,246,.1);
    border-color: rgba(59,130,246,.3);
    color: #93B4FF;
}
.tx-page-btn.prev-next:hover:not(:disabled) {
    background: rgba(59,130,246,.2);
    border-color: rgba(59,130,246,.5);
}
.tx-page-dots {
    color: var(--muted);
    padding: 0 4px;
    font-weight: 800;
    user-select: none;
}

/* ============================================================
   AUTRES
   ============================================================ */
.security-banner{
  padding:14px 20px;border-radius:14px;margin-bottom:24px;
  background:linear-gradient(90deg,rgba(16,185,129,.1),rgba(16,185,129,.02));
  border:1px solid rgba(16,185,129,.3);color:#6EE7B7;
  font-size:.85rem;display:flex;align-items:center;gap:12px;
}
.security-banner .ico{font-size:1.3rem}

.docs-grid{
  display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
  gap:16px;margin-top:16px;
}
.doc-card{
  padding:20px;border-radius:14px;
  background:linear-gradient(145deg,var(--card),var(--card-2));
  border:1px solid var(--border);text-align:center;
  transition:.3s;text-decoration:none;color:var(--text);
  display:flex;flex-direction:column;gap:10px;align-items:center;
}
.doc-card:hover{border-color:var(--gold);transform:translateY(-4px);box-shadow:0 12px 30px rgba(245,184,0,.2)}
.doc-card .doc-ico{font-size:2.5rem}
.doc-card .doc-label{font-weight:700;font-size:.9rem}
.doc-card .doc-status{font-size:.75rem;color:var(--muted)}

@media (max-width: 900px) {
    table.tx-table { font-size: .78rem; }
    table.tx-table td, table.tx-table thead th { padding: 9px 10px; }
    .tx-pagination { flex-direction: column; }
}
</style>
</head>
<body class="dark-auth">

<div class="top-progress" id="topProgress"></div>

<?php require __DIR__ . '/../includes/header.php'; ?>

<div class="admin-layout">
  <aside class="admin-sidebar animate-left">
    <h3>📊 Navigation</h3>
    <a href="<?= $base ?>/admin/dashboard.php"><span class="ico">📊</span> Tableau de bord</a>
    <a href="<?= $base ?>/admin/clients.php" class="active"><span class="ico">👥</span> Clients</a>
    <a href="<?= $base ?>/admin/caissiers.php"><span class="ico">💼</span> Caissiers</a>
    <a href="<?= $base ?>/admin/produits.php"><span class="ico">📈</span> Produits</a>
    <a href="<?= $base ?>/admin/placements.php"><span class="ico">💰</span> Placements</a>
    <a href="<?= $base ?>/admin/commissions.php"><span class="ico">💵</span> Commissions</a>
    <a href="<?= $base ?>/admin/statistiques.php"><span class="ico">📈</span> Statistiques</a>
    <a href="<?= $base ?>/admin/transactions.php"><span class="ico">💳</span> Transactions</a>
    <a href="<?= $base ?>/admin/logs.php"><span class="ico">📜</span> Journal</a>
    <a href="<?= $base ?>/auth/logout.php"><span class="ico">🚪</span> Déconnexion</a>
  </aside>

  <main class="admin-main">

    <div class="security-banner">
      <span class="ico">🔒</span>
      <div>
        <strong>Session sécurisée</strong> — ID protégé par token one-shot (expire en 5 min).<br>
        <small style="opacity:.8">Consulté le <?= date('d/m/Y H:i:s') ?> — IP <?= htmlspecialchars($_SERVER['REMOTE_ADDR']) ?></small>
      </div>
    </div>

    <!-- EN-TÊTE CLIENT -->
    <div class="client-header">
      <?php if (!empty($client['photo_profil']) && file_exists(__DIR__ . '/../' . $client['photo_profil'])): ?>
        <img src="<?= $base ?>/<?= htmlspecialchars($client['photo_profil']) ?>" alt="Photo" class="client-photo-large">
      <?php else: ?>
        <div class="client-photo-placeholder-large">
          <?= strtoupper(mb_substr($client['nom'], 0, 1) . mb_substr($client['prenom'], 0, 1)) ?>
        </div>
      <?php endif; ?>

      <div class="client-info">
        <h1><?= htmlspecialchars($client['nom'] . ' ' . $client['postnom'] . ' ' . $client['prenom']) ?></h1>
        <div class="compte"><?= htmlspecialchars($client['numero_compte']) ?></div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-top:16px">
          <div>
            <div style="color:var(--muted);font-size:.78rem;text-transform:uppercase;letter-spacing:1px">📞 Téléphone</div>
            <div style="font-weight:700;margin-top:4px"><?= htmlspecialchars($client['numero_phone']) ?></div>
          </div>
          <div>
            <div style="color:var(--muted);font-size:.78rem;text-transform:uppercase;letter-spacing:1px">📍 Adresse</div>
            <div style="font-weight:700;margin-top:4px;font-size:.9rem"><?= htmlspecialchars($client['adresse_complete']) ?></div>
          </div>
        </div>

        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
          <span class="badge <?= $client['statut'] === 'actif' ? 'green' : 'red' ?>">● <?= htmlspecialchars($client['statut']) ?></span>
          <span class="badge blue">Inscrit le <?= date('d/m/Y', strtotime($client['created_at'])) ?></span>
        </div>
      </div>

      <div style="display:flex;flex-direction:column;gap:10px;z-index:1">
        <form method="POST" action="<?= $base ?>/admin/releve_pdf.php" target="_blank" style="display:inline">
          <?= csrfField() ?>
          <input type="hidden" name="token" value="<?= htmlspecialchars($token_pdf) ?>">
          <button class="btn-gold" type="submit">📄 Relevé PDF</button>
        </form>
        <a class="btn-ghost" href="<?= $base ?>/admin/clients.php">← Retour</a>
      </div>
    </div>

    <!-- ============================================================
         💵 DEUX RUBRIQUES DE SOLDE : USD & CDF
         ============================================================ -->
    <div class="soldes-grid">

      <!-- SOLDE USD -->
      <div class="solde-rubrique usd">
        <div class="flag-row">
          <span class="flag-icon">🇺🇸</span>
          <span class="flag-label">Dollar Américain</span>
          <span class="devise-badge">USD</span>
        </div>
        <div class="montant">
          $ <?= number_format($solde_usd, 2, ',', ' ') ?>
          <span class="unite">USD</span>
        </div>
        <div class="libelle">💰 Solde disponible en dollars américains</div>

        <div class="details-grid">
          <div class="detail-item">
            <div class="d-lbl">📥 Total dépôts</div>
            <div class="d-val pos">+$ <?= number_format((float)$tx_usd['total_depots'], 2, ',', ' ') ?></div>
          </div>
          <div class="detail-item">
            <div class="d-lbl">📤 Total retraits</div>
            <div class="d-val neg">−$ <?= number_format((float)$tx_usd['total_retraits'], 2, ',', ' ') ?></div>
          </div>
        </div>
      </div>

      <!-- SOLDE CDF -->
      <div class="solde-rubrique cdf">
        <div class="flag-row">
          <span class="flag-icon">🇨🇩</span>
          <span class="flag-label">Franc Congolais</span>
          <span class="devise-badge">CDF</span>
        </div>
        <div class="montant">
          <?= number_format($solde_cdf, 0, ',', ' ') ?>
          <span class="unite">FC</span>
        </div>
        <div class="libelle">💰 Solde disponible en francs congolais</div>

        <div class="details-grid">
          <div class="detail-item">
            <div class="d-lbl">📥 Total dépôts</div>
            <div class="d-val pos">+<?= number_format((float)$tx_cdf['total_depots'], 0, ',', ' ') ?> FC</div>
          </div>
          <div class="detail-item">
            <div class="d-lbl">📤 Total retraits</div>
            <div class="d-val neg">−<?= number_format((float)$tx_cdf['total_retraits'], 0, ',', ' ') ?> FC</div>
          </div>
        </div>
      </div>

    </div>

    <!-- KPIs SECONDAIRES -->
    <section class="kpi-grid" style="margin-bottom:30px">
      <div class="stat-block green animate-in delay-1">
        <div class="ico">📈</div>
        <div class="meta">
          <strong><?= number_format((float)$stats_epargne['interets_total'], 0, ',', ' ') ?></strong>
          <span>Intérêts cumulés</span>
        </div>
      </div>
      <div class="stat-block blue animate-in delay-2">
        <div class="ico">💼</div>
        <div class="meta">
          <strong><?= (int)$stats_epargne['nb_placements'] ?></strong>
          <span>Placements</span>
        </div>
      </div>
      <div class="stat-block gold animate-in delay-3">
        <div class="ico">💳</div>
        <div class="meta">
          <strong><?= (int)$stats['nb_transactions'] ?></strong>
          <span>Transactions</span>
        </div>
      </div>
      <div class="stat-block purple animate-in delay-4">
        <div class="ico">🎯</div>
        <div class="meta">
          <strong><?= (int)$stats_epargne['nb_placements'] > 0 ? 'Actif' : 'Nouveau' ?></strong>
          <span>Statut épargne</span>
        </div>
      </div>
    </section>

    <!-- ONGLETS -->
    <div class="tabs-header">
      <button class="tab-btn active" data-tab="tab-transactions">💳 Transactions</button>
      <button class="tab-btn" data-tab="tab-placements">💰 Placements</button>
      <button class="tab-btn" data-tab="tab-interets">📈 Intérêts</button>
      <button class="tab-btn" data-tab="tab-commissions">💵 Commissions</button>
      <button class="tab-btn" data-tab="tab-documents">📎 Documents</button>
      <button class="tab-btn" data-tab="tab-mandataire">👤 Mandataire</button>
    </div>

    <!-- ============================================================
         ONGLET TRANSACTIONS AVEC PAGINATION
         ============================================================ -->
    <div class="tab-content active" id="tab-transactions">
      <div class="tx-container animate-in">

        <div class="tx-header">
          <h3>
            💳 Historique des transactions
            <span class="counter">Page <?= $tx_page ?> / <?= $tx_total_pages ?></span>
          </h3>
          <span style="color:var(--muted);font-size:.8rem">
            <?= count($transactions) ?> sur <?= number_format($tx_total, 0, ',', ' ') ?> transaction(s)
          </span>
        </div>

        <div style="overflow-x:auto">
          <table class="tx-table">
            <thead>
              <tr>
                <th>Référence</th>
                <th>Type</th>
                <th style="text-align:right">Montant</th>
                <th style="text-align:right">Solde après</th>
                <th>Devise</th>
                <th>Mode</th>
                <th>Par</th>
                <th>Date</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$transactions): ?>
                <tr>
                  <td colspan="8" style="text-align:center;padding:40px 20px;color:var(--muted)">
                    <div style="font-size:2.5rem;opacity:.3;margin-bottom:8px">📭</div>
                    <strong>Aucune transaction</strong>
                  </td>
                </tr>
              <?php else: foreach ($transactions as $t):
                  $is_retrait = $t['type_transaction'] === 'Retrait';
                  $sign = $is_retrait ? '−' : '+';
                  $cls = $is_retrait ? 'amount-neg' : 'amount-pos';
                  $devise = $t['devise'] ?? 'USD';
                  $is_cdf = $devise === 'CDF';
                  $montant_fmt = $is_cdf
                      ? number_format((float)$t['montant'], 0, ',', ' ') . ' FC'
                      : '$ ' . number_format((float)$t['montant'], 2, ',', ' ');
                  $solde_fmt = $is_cdf
                      ? number_format((float)$t['solde_apres'], 0, ',', ' ') . ' FC'
                      : '$ ' . number_format((float)$t['solde_apres'], 2, ',', ' ');

                  $tag_icon = match($t['type_transaction']) {
                      'Depot' => '＋',
                      'Retrait' => '－',
                      'Virement' => '⇄',
                      default => '•',
                  };
              ?>
                <tr>
                  <td><code><?= htmlspecialchars($t['reference']) ?></code></td>
                  <td>
                    <span class="tag <?= strtolower($t['type_transaction']) ?>">
                      <?= $tag_icon ?> <?= htmlspecialchars($t['type_transaction']) ?>
                    </span>
                  </td>
                  <td class="<?= $cls ?>">
                    <?= $sign ?><?= $montant_fmt ?>
                  </td>
                  <td class="solde-pos"><?= $solde_fmt ?></td>
                  <td>
                    <span class="badge <?= $is_cdf ? 'blue' : 'gold' ?>" style="font-size:.7rem">
                      <?= $is_cdf ? '🇨🇩 CDF' : '🇺🇸 USD' ?>
                    </span>
                  </td>
                  <td><?= htmlspecialchars($t['mode']) ?></td>
                  <td><span class="badge" style="font-size:.68rem"><?= htmlspecialchars($t['effectue_par']) ?></span></td>
                  <td style="white-space:nowrap;font-size:.78rem;color:var(--muted)">
                    <?= date('d/m/Y', strtotime($t['date_transaction'])) ?><br>
                    <small><?= date('H:i', strtotime($t['date_transaction'])) ?></small>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>

        <!-- ============================================================
             PAGINATION TRANSACTIONS
             ============================================================ -->
        <?php if ($tx_total_pages > 1): ?>
        <div class="tx-pagination">

          <div class="info">
            Page <strong><?= $tx_page ?></strong> sur <strong><?= $tx_total_pages ?></strong>
            · <strong><?= number_format($tx_total, 0, ',', ' ') ?></strong> transactions
          </div>

          <div class="controls">

            <!-- Précédent -->
            <?php if ($tx_page > 1): ?>
              <a href="<?= buildTxUrl($tx_page - 1) ?>" class="tx-page-btn prev-next">← Précédent</a>
            <?php else: ?>
              <button class="tx-page-btn prev-next" disabled>← Précédent</button>
            <?php endif; ?>

            <!-- Numéros de pages -->
            <?php
              $max_visible = 5;
              $start = max(1, $tx_page - 2);
              $end = min($tx_total_pages, $start + $max_visible - 1);

              if ($start > 1) {
                  echo '<a href="' . buildTxUrl(1) . '" class="tx-page-btn">1</a>';
                  if ($start > 2) echo '<span class="tx-page-dots">…</span>';
              }

              for ($p = $start; $p <= $end; $p++) {
                  $active = $p === $tx_page ? ' active' : '';
                  echo '<a href="' . buildTxUrl($p) . '" class="tx-page-btn' . $active . '">' . $p . '</a>';
              }

              if ($end < $tx_total_pages) {
                  if ($end < $tx_total_pages - 1) echo '<span class="tx-page-dots">…</span>';
                  echo '<a href="' . buildTxUrl($tx_total_pages) . '" class="tx-page-btn">' . $tx_total_pages . '</a>';
              }
            ?>

            <!-- Suivant -->
            <?php if ($tx_page < $tx_total_pages): ?>
              <a href="<?= buildTxUrl($tx_page + 1) ?>" class="tx-page-btn prev-next">Suivant →</a>
            <?php else: ?>
              <button class="tx-page-btn prev-next" disabled>Suivant →</button>
            <?php endif; ?>

          </div>

          <!-- Aller à page X -->
          <form method="GET" style="display:flex;gap:6px;align-items:center">
            <?php
              /* Conserver les autres paramètres GET */
              foreach ($_GET as $key => $val) {
                  if ($key === 'tx_page') continue;
                  echo '<input type="hidden" name="' . htmlspecialchars($key) . '" value="' . htmlspecialchars((string)$val) . '">';
              }
            ?>
            <label style="color:var(--muted);font-size:.75rem;font-weight:700;white-space:nowrap">
              Aller à :
            </label>
            <input type="number" name="tx_page" min="1" max="<?= $tx_total_pages ?>"
                   value="<?= $tx_page ?>"
                   style="width:65px;padding:7px 9px;background:var(--bg-1);
                          border:1px solid var(--border);border-radius:8px;
                          color:var(--text);font-family:inherit;font-size:.82rem;
                          text-align:center;font-weight:700">
            <button type="submit" class="tx-page-btn" style="padding:0 12px;height:34px">→</button>
          </form>

        </div>
        <?php endif; ?>

      </div>
    </div>

    <!-- ============================================================
         AUTRES ONGLETS (inchangés)
         ============================================================ -->
    <div class="tab-content" id="tab-placements">
      <section class="card animate-in">
        <h3 style="margin-bottom:16px">💰 Placements (<?= count($placements) ?>)</h3>
        <?php if (!$placements): ?>
          <p class="muted center" style="padding:20px">Aucun placement.</p>
        <?php else: ?>
          <div class="table-wrap"><table>
            <thead><tr><th>Référence</th><th>Produit</th><th style="text-align:right">Capital</th>
              <th style="text-align:right">Valeur</th><th style="text-align:right">Intérêts</th><th>Fin</th><th>Statut</th></tr></thead>
            <tbody>
              <?php foreach ($placements as $p): ?>
                <tr>
                  <td><code><?= htmlspecialchars($p['reference']) ?></code></td>
                  <td><?= htmlspecialchars($p['produit_nom']) ?><br>
                      <small class="muted"><?= htmlspecialchars($p['type_interet']) ?> · <?= number_format((float)$p['taux_annuel'] * 100, 2) ?>%/an</small></td>
                  <td style="text-align:right"><?= number_format((float)$p['montant_initial'], 2, ',', ' ') ?></td>
                  <td style="text-align:right;color:var(--gold);font-weight:700"><?= number_format((float)$p['montant_actuel'], 2, ',', ' ') ?></td>
                  <td style="text-align:right;color:var(--green)">+ <?= number_format((float)$p['interets_cumules'], 2, ',', ' ') ?></td>
                  <td><?= date('d/m/Y', strtotime($p['date_fin'])) ?></td>
                  <td><span class="badge <?= $p['statut']==='actif' ? 'green' : 'red' ?>"><?= htmlspecialchars($p['statut']) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table></div>
        <?php endif; ?>
      </section>
    </div>

    <div class="tab-content" id="tab-interets">
      <section class="card animate-in">
        <h3 style="margin-bottom:16px">📈 Intérêts crédités</h3>
        <?php if (!$interets): ?>
          <p class="muted center" style="padding:20px">Aucun intérêt.</p>
        <?php else: ?>
          <div class="table-wrap"><table>
            <thead><tr><th>Date</th><th>Placement</th><th style="text-align:right">Intérêt</th>
              <th style="text-align:right">Solde après</th><th>Période</th></tr></thead>
            <tbody>
              <?php foreach ($interets as $i): ?>
                <tr>
                  <td><?= date('d/m/Y', strtotime($i['date_calcul'])) ?></td>
                  <td><code><?= htmlspecialchars($i['placement_ref']) ?></code></td>
                  <td style="text-align:right;color:var(--green);font-weight:700">+ <?= number_format((float)$i['montant_interet'], 2, ',', ' ') ?></td>
                  <td style="text-align:right"><?= number_format((float)$i['solde_apres'], 2, ',', ' ') ?></td>
                  <td style="font-size:.82rem"><?= date('d/m', strtotime($i['periode_debut'])) ?> → <?= date('d/m', strtotime($i['periode_fin'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table></div>
        <?php endif; ?>
      </section>
    </div>

    <div class="tab-content" id="tab-commissions">
      <section class="card animate-in">
        <h3 style="margin-bottom:16px">💵 Commissions prélevées</h3>
        <?php if (!$commissions): ?>
          <p class="muted center" style="padding:20px">Aucune commission.</p>
        <?php else: ?>
          <div class="table-wrap"><table>
            <thead><tr><th>Date</th><th style="text-align:right">Brut</th><th style="text-align:right">Taux</th>
              <th style="text-align:right">Commission</th><th style="text-align:right">Net versé</th><th>Devise</th></tr></thead>
            <tbody>
              <?php foreach ($commissions as $c): ?>
                <tr>
                  <td><?= date('d/m/Y H:i', strtotime($c['date_commission'])) ?></td>
                  <td style="text-align:right"><?= number_format((float)$c['montant_brut'], 2, ',', ' ') ?></td>
                  <td style="text-align:right"><?= number_format((float)$c['taux_commission'] * 100, 2) ?>%</td>
                  <td style="text-align:right;color:var(--gold);font-weight:700"><?= number_format((float)$c['montant_commission'], 2, ',', ' ') ?></td>
                  <td style="text-align:right;color:var(--green)"><?= number_format((float)$c['montant_net'], 2, ',', ' ') ?></td>
                  <td><span class="badge" style="font-size:.7rem"><?= htmlspecialchars($c['devise']) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table></div>
        <?php endif; ?>
      </section>
    </div>

    <div class="tab-content" id="tab-documents">
      <section class="card animate-in">
        <h3 style="margin-bottom:16px">📎 Documents joints</h3>
        <div class="docs-grid">
          <div class="doc-card">
            <div class="doc-ico">🪪</div>
            <div class="doc-label">Carte d'identité</div>
            <?php if (!empty($client['file_carte_identite']) && file_exists(__DIR__ . '/../' . $client['file_carte_identite'])): ?>
              <a class="btn-gold btn-sm" href="<?= $base ?>/<?= htmlspecialchars($client['file_carte_identite']) ?>" target="_blank" rel="noopener">📥 Ouvrir</a>
            <?php else: ?><span class="doc-status">Non fourni</span><?php endif; ?>
          </div>
          <div class="doc-card">
            <div class="doc-ico">🗳️</div>
            <div class="doc-label">Carte électeur</div>
            <?php if (!empty($client['file_carte_electeur']) && file_exists(__DIR__ . '/../' . $client['file_carte_electeur'])): ?>
              <a class="btn-gold btn-sm" href="<?= $base ?>/<?= htmlspecialchars($client['file_carte_electeur']) ?>" target="_blank" rel="noopener">📥 Ouvrir</a>
            <?php else: ?><span class="doc-status">Non fourni</span><?php endif; ?>
          </div>
          <div class="doc-card">
            <div class="doc-ico">👤</div>
            <div class="doc-label">Photo de profil</div>
            <?php if (!empty($client['photo_profil']) && file_exists(__DIR__ . '/../' . $client['photo_profil'])): ?>
              <a class="btn-gold btn-sm" href="<?= $base ?>/<?= htmlspecialchars($client['photo_profil']) ?>" target="_blank" rel="noopener">📥 Voir</a>
            <?php else: ?><span class="doc-status">Non fournie</span><?php endif; ?>
          </div>
        </div>
      </section>
    </div>

    <div class="tab-content" id="tab-mandataire">
      <section class="card animate-in">
        <h3 style="margin-bottom:16px">👤 Mandataire</h3>
        <?php if (empty($client['nom_mandataire']) && empty($client['postnom_mandataire'])): ?>
          <p class="muted center" style="padding:20px">Aucun mandataire.</p>
        <?php else: ?>
          <div class="grid-2">
            <div class="field"><span>Nom</span><input type="text" value="<?= htmlspecialchars($client['nom_mandataire'] ?? '') ?>" disabled></div>
            <div class="field"><span>Post-nom</span><input type="text" value="<?= htmlspecialchars($client['postnom_mandataire'] ?? '') ?>" disabled></div>
            <div class="field"><span>Téléphone</span><input type="text" value="<?= htmlspecialchars($client['numero_mandataire'] ?? '') ?>" disabled></div>
            <div class="field"><span>Adresse</span><textarea rows="2" disabled><?= htmlspecialchars($client['adresse_compte_mandataire'] ?? '') ?></textarea></div>
          </div>
        <?php endif; ?>
      </section>
    </div>

  </main>
</div>

<footer class="atf-footer">© <?= date('Y') ?> AFRICAN TRUST FUND — Fiche sécurisée</footer>

<script>
/* ============================================================
   ONGLETS
   ============================================================ */
document.querySelectorAll('.tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById(btn.dataset.tab).classList.add('active');
  });
});

/* ============================================================
   Barre de progression
   ============================================================ */
window.addEventListener('scroll', () => {
  const h = document.documentElement.scrollHeight - window.innerHeight;
  const p = h > 0 ? (window.scrollY / h) * 100 : 0;
  document.getElementById('topProgress').style.width = p + '%';
});

/* ============================================================
   Raccourcis clavier pagination (← →)
   ============================================================ */
document.addEventListener('keydown', function(e) {
  if (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT' || e.target.tagName === 'TEXTAREA') return;

  const currentPage = <?= $tx_page ?>;
  const totalPages = <?= $tx_total_pages ?>;

  if (e.key === 'ArrowLeft' && currentPage > 1) {
    window.location.href = '<?= buildTxUrl($tx_page - 1) ?>';
  } else if (e.key === 'ArrowRight' && currentPage < totalPages) {
    window.location.href = '<?= buildTxUrl($tx_page + 1) ?>';
  }
});

console.log('%c👤 Fiche Client ATF', 'color:#F5B800;font-weight:bold;font-size:14px');
console.log('Solde USD :', <?= json_encode($solde_usd) ?>);
console.log('Solde CDF :', <?= json_encode($solde_cdf) ?>);
console.log('Transactions :', <?= $tx_total ?>, '| Page :', <?= $tx_page ?>, '/', <?= $tx_total_pages ?>);
console.log('%c⌨️ Utilisez ← → pour naviguer entre les pages de transactions', 'color:#10B981;font-weight:bold');
</script>
<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>