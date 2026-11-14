<?php
declare(strict_types=1);

/* ============================================================
   🔒 SÉCURITÉ
   ============================================================ */
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
requireRole('admin');

if (empty($_SESSION['admin_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header('Location: /african-trust-fund/auth/login_admin.php');
    exit;
}

$pdo = Database::getConnection();
$admin_id = (int)$_SESSION['admin_id'];

/* ============================================================
   🛡️ HELPERS SÉCURISÉS
   ============================================================ */
function h(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function safeDate(?string $date, string $fallback): string
{
    if ($date === null || $date === '' || !is_string($date)) return $fallback;
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return $fallback;
    $ts = strtotime($date);
    if ($ts === false || $ts < 0) return $fallback;
    return date('Y-m-d', $ts);
}

/* ============================================================
   🔴 INITIALISATION DE TOUTES LES VARIABLES (AVANT TOUT USAGE)
   ============================================================ */
$errors        = [];
$success       = '';
$f_cat         = '';
$f_dev         = '';
$f_statut      = '';
$f_debut       = date('Y-m-01');
$f_fin         = date('Y-m-d');
$search        = '';
$page          = 1;
$total_rows    = 0;
$total_pages   = 1;
$offset        = 0;
$par_page      = 20;

$depenses      = [];
$par_categorie = [];
$categories    = [];

$kpis = [
    'total_usd' => 0.0,
    'total_cdf' => 0.0,
    'nb_total'  => 0,
    'nb_usd'    => 0,
    'nb_cdf'    => 0,
];

/* ============================================================
   TRAITEMENT POST — AJOUT / SUPPRESSION / STATUT
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) {
            throw new RuntimeException("Jeton CSRF invalide.");
        }

        $action = $_POST['action'] ?? '';

        /* ---------- AJOUTER UNE DÉPENSE (formulaire rapide) ---------- */
        if ($action === 'ajouter') {
            $categorie    = cleanString($_POST['categorie'] ?? '', 50);
            $sous_cat     = cleanString($_POST['sous_categorie'] ?? '', 100);
            $description  = cleanString($_POST['description'] ?? '', 1000);
            $montant      = (float)($_POST['montant'] ?? 0);
            $devise       = in_array($_POST['devise'] ?? '', ['USD', 'CDF'], true) ? $_POST['devise'] : 'USD';
            $beneficiaire = cleanString($_POST['beneficiaire'] ?? '', 200);
            $mode         = in_array($_POST['mode_paiement'] ?? '', ['especes', 'virement', 'cheque', 'mobile_money', 'carte'], true)
                            ? $_POST['mode_paiement'] : 'especes';
            $ref_paie     = cleanString($_POST['reference_paiement'] ?? '', 100);
            $urgence      = in_array($_POST['urgence'] ?? '', ['normale', 'haute', 'critique'], true)
                            ? $_POST['urgence'] : 'normale';
            $statut       = in_array($_POST['statut'] ?? '', ['en_attente', 'validee'], true)
                            ? $_POST['statut'] : 'validee';
            $date_dep     = $_POST['date_depense'] ?? date('Y-m-d');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_dep)) {
                $date_dep = date('Y-m-d');
            }

            if ($categorie === '')   throw new RuntimeException("Catégorie obligatoire.");
            if ($description === '') throw new RuntimeException("Description obligatoire.");
            if ($montant <= 0)       throw new RuntimeException("Montant doit être supérieur à 0.");

            $ref = 'DEP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

            $stmt = $pdo->prepare("
                INSERT INTO Depenses
                (reference, categorie, sous_categorie, description, montant, devise,
                 date_depense, beneficiaire, mode_paiement, reference_paiement,
                 statut, urgence, cree_par)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $ref, $categorie, $sous_cat ?: null, $description, $montant, $devise,
                $date_dep . ' ' . date('H:i:s'), $beneficiaire ?: null,
                $mode, $ref_paie ?: null, $statut, $urgence, $admin_id
            ]);

            try {
                logAction($pdo, 'admin', $admin_id, 'DEPENSE_AJOUTEE',
                    "Réf: $ref | {$montant} {$devise} | Cat: $categorie");
            } catch (Throwable $e) {}

            $_SESSION['flash_success'] = "✅ Dépense <strong>$ref</strong> enregistrée (" .
                number_format($montant, 2, ',', ' ') . " $devise).";
        }

        /* ---------- SUPPRIMER UNE DÉPENSE ---------- */
        if ($action === 'supprimer') {
            $id = (int)($_POST['id_depense'] ?? 0);
            if ($id <= 0) throw new RuntimeException("ID invalide.");

            $stmt = $pdo->prepare("SELECT reference, montant, devise FROM Depenses WHERE id_depense = ?");
            $stmt->execute([$id]);
            $dep = $stmt->fetch();

            if ($dep) {
                $pdo->prepare("DELETE FROM Depenses WHERE id_depense = ?")->execute([$id]);
                try {
                    logAction($pdo, 'admin', $admin_id, 'DEPENSE_SUPPRIMEE',
                        "Réf: {$dep['reference']} | {$dep['montant']} {$dep['devise']}");
                } catch (Throwable $e) {}
                $_SESSION['flash_success'] = "🗑 Dépense supprimée.";
            }
        }

        /* ---------- CHANGER STATUT ---------- */
        if ($action === 'changer_statut') {
            $id      = (int)($_POST['id_depense'] ?? 0);
            $nouveau = $_POST['nouveau_statut'] ?? '';
            if (!in_array($nouveau, ['en_attente', 'validee', 'rejetee', 'payee'], true)) {
                throw new RuntimeException("Statut invalide.");
            }
            $pdo->prepare("UPDATE Depenses SET statut = ?, validee_par = ?, date_validation = NOW() WHERE id_depense = ?")
                ->execute([$nouveau, $admin_id, $id]);
            $_SESSION['flash_success'] = "✅ Statut mis à jour.";
        }

        header('Location: ' . url('admin/depenses.php'));
        exit;

    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

/* ============================================================
   RÉCUPÉRATION DES FILTRES (GET)
   ============================================================ */
$f_cat    = trim((string)($_GET['cat'] ?? ''));
$f_dev    = trim((string)($_GET['devise'] ?? ''));
$f_statut = trim((string)($_GET['statut'] ?? ''));
$f_debut  = safeDate($_GET['debut'] ?? '', date('Y-m-01'));
$f_fin    = safeDate($_GET['fin'] ?? '', date('Y-m-d'));
$search   = trim((string)($_GET['q'] ?? ''));
$page     = max(1, (int)($_GET['page'] ?? 1));

if ($f_debut > $f_fin) {
    [$f_debut, $f_fin] = [$f_fin, $f_debut];
}

/* ============================================================
   CONSTRUCTION REQUÊTE
   ============================================================ */
$where  = " WHERE 1=1";
$params = [];

if ($f_cat !== '') {
    $where .= " AND categorie = ?";
    $params[] = $f_cat;
}
if ($f_dev !== '') {
    $where .= " AND devise = ?";
    $params[] = $f_dev;
}
if ($f_statut !== '') {
    $where .= " AND statut = ?";
    $params[] = $f_statut;
}
if ($f_debut !== '') {
    $where .= " AND DATE(date_depense) >= ?";
    $params[] = $f_debut;
}
if ($f_fin !== '') {
    $where .= " AND DATE(date_depense) <= ?";
    $params[] = $f_fin;
}
if ($search !== '') {
    $where .= " AND (reference LIKE ? OR description LIKE ? OR beneficiaire LIKE ?)";
    $like = "%$search%";
    array_push($params, $like, $like, $like);
}

/* Count total */
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM Depenses" . $where);
    $stmt->execute($params);
    $total_rows = (int)$stmt->fetchColumn();
} catch (Throwable $e) {
    $total_rows = 0;
    error_log('[depenses count] ' . $e->getMessage());
}

$total_pages = max(1, (int)ceil($total_rows / $par_page));
if ($page > $total_pages) $page = $total_pages;
$offset = ($page - 1) * $par_page;

/* Liste paginée */
try {
    $stmt = $pdo->prepare("SELECT * FROM Depenses" . $where . " ORDER BY date_depense DESC LIMIT $par_page OFFSET $offset");
    $stmt->execute($params);
    $depenses = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {
    $depenses = [];
    error_log('[depenses list] ' . $e->getMessage());
}

/* KPIs */
try {
    $stmt = $pdo->prepare("
        SELECT devise, COUNT(*) AS nb, COALESCE(SUM(montant), 0) AS total
        FROM Depenses
        WHERE DATE(date_depense) BETWEEN ? AND ?
        GROUP BY devise
    ");
    $stmt->execute([$f_debut, $f_fin]);
    foreach ($stmt->fetchAll() as $r) {
        $d = strtoupper((string)$r['devise']);
        if ($d === 'USD') {
            $kpis['total_usd'] = (float)$r['total'];
            $kpis['nb_usd']    = (int)$r['nb'];
        } else {
            $kpis['total_cdf'] = (float)$r['total'];
            $kpis['nb_cdf']    = (int)$r['nb'];
        }
        $kpis['nb_total'] += (int)$r['nb'];
    }
} catch (Throwable $e) {
    error_log('[depenses kpis] ' . $e->getMessage());
}

/* Par catégorie */
try {
    $stmt = $pdo->prepare("
        SELECT categorie,
               COALESCE(SUM(CASE WHEN devise='USD' THEN montant ELSE 0 END), 0) AS usd,
               COALESCE(SUM(CASE WHEN devise='CDF' THEN montant ELSE 0 END), 0) AS cdf,
               COUNT(*) AS nb
        FROM Depenses
        WHERE DATE(date_depense) BETWEEN ? AND ?
        GROUP BY categorie
        ORDER BY (usd * 2800 + cdf) DESC
    ");
    $stmt->execute([$f_debut, $f_fin]);
    $par_categorie = $stmt->fetchAll() ?: [];
} catch (Throwable $e) {
    $par_categorie = [];
    error_log('[depenses par_cat] ' . $e->getMessage());
}

/* Catégories disponibles */
try {
    $categories = $pdo->query("
        SELECT * FROM Categories_Depenses
        WHERE est_actif = TRUE
        ORDER BY ordre_affichage, libelle
    ")->fetchAll() ?: [];
} catch (Throwable $e) {
    $categories = [];
}

/* Fallback si table vide ou inexistante */
if (empty($categories)) {
    $categories = [
        ['code' => 'salaires',       'libelle' => 'Salaires & Primes',     'icone' => '💼'],
        ['code' => 'loyer',          'libelle' => 'Loyer & Charges',       'icone' => '🏢'],
        ['code' => 'electricite',    'libelle' => 'Électricité & Eau',     'icone' => '💡'],
        ['code' => 'internet',       'libelle' => 'Internet & Télécom',    'icone' => '🌐'],
        ['code' => 'fournitures',    'libelle' => 'Fournitures de bureau', 'icone' => '📎'],
        ['code' => 'maintenance',    'libelle' => 'Maintenance',           'icone' => '🔧'],
        ['code' => 'transport',      'libelle' => 'Transport & Carburant', 'icone' => '🚗'],
        ['code' => 'securite',       'libelle' => 'Sécurité',              'icone' => '🛡️'],
        ['code' => 'marketing',      'libelle' => 'Marketing',             'icone' => '📢'],
        ['code' => 'formation',      'libelle' => 'Formation',             'icone' => '🎓'],
        ['code' => 'frais_bancaires','libelle' => 'Frais bancaires',       'icone' => '🏦'],
        ['code' => 'impots',         'libelle' => 'Impôts & Taxes',        'icone' => '📋'],
        ['code' => 'assurance',      'libelle' => 'Assurance',             'icone' => '📄'],
        ['code' => 'informatique',   'libelle' => 'Informatique',          'icone' => '💻'],
        ['code' => 'juridique',      'libelle' => 'Frais juridiques',      'icone' => '⚖️'],
        ['code' => 'divers',         'libelle' => 'Dépenses diverses',     'icone' => '📁'],
    ];
}

/* Flash */
if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

try { logAction($pdo, 'admin', $admin_id, 'DEPENSES_VIEW', "Filtres: $f_debut → $f_fin"); } catch (Throwable $e) {}

$base = '/african-trust-fund';

/* ============================================================
   HELPERS FINAUX
   ============================================================ */
function buildUrl(array $params = []): string
{
    $current = [
        'cat'    => $_GET['cat']    ?? '',
        'devise' => $_GET['devise'] ?? '',
        'statut' => $_GET['statut'] ?? '',
        'debut'  => $_GET['debut']  ?? '',
        'fin'    => $_GET['fin']    ?? '',
        'q'      => $_GET['q']      ?? '',
        'page'   => $_GET['page']   ?? 1,
    ];
    $merged = array_filter(array_merge($current, $params), fn($v) => $v !== '' && $v !== null);
    return '?' . http_build_query($merged);
}

function getCatInfo(string $code, array $categories): array
{
    foreach ($categories as $c) {
        if (($c['code'] ?? '') === $code) {
            return [
                'icone'   => $c['icone']   ?? '📁',
                'libelle' => $c['libelle'] ?? ucfirst(str_replace('_', ' ', $code)),
            ];
        }
    }
    return [
        'icone'   => '📁',
        'libelle' => ucfirst(str_replace('_', ' ', $code)),
    ];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>💸 Dépenses Système — ATF Admin</title>
<link rel="stylesheet" href="<?= h($base) ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= h($base) ?>/assets/css/admin-premium.css">
<style>
/* ============================================================
   TOOLBAR FILTRES
   ============================================================ */
.toolbar {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
    padding: 16px 20px;
    background: linear-gradient(145deg, var(--card), var(--card-2));
    border: 1px solid var(--border);
    border-radius: 14px;
    margin-bottom: 20px;
}
.toolbar input,
.toolbar select {
    padding: 10px 14px;
    background: var(--bg-1);
    border: 1px solid var(--border);
    border-radius: 10px;
    color: var(--text);
    font-family: inherit;
    font-size: .85rem;
}
.toolbar input:focus,
.toolbar select:focus {
    outline: none;
    border-color: var(--gold);
}
.toolbar input[type="text"] {
    flex: 2;
    min-width: 180px;
}
.toolbar .btn-filter {
    padding: 10px 20px;
    border-radius: 10px;
    background: linear-gradient(135deg, var(--gold), #D4AF37);
    color: #0B0E14;
    font-weight: 800;
    border: none;
    cursor: pointer;
    font-family: inherit;
    font-size: .85rem;
    white-space: nowrap;
}
.toolbar .btn-filter:hover {
    transform: translateY(-1px);
    box-shadow: 0 6px 18px rgba(245,184,0,.4);
}
.toolbar .btn-reset {
    padding: 10px 16px;
    background: rgba(255,255,255,.05);
    color: var(--muted);
    border: 1px solid var(--border);
    border-radius: 10px;
    text-decoration: none;
    font-weight: 700;
    font-size: .82rem;
    white-space: nowrap;
    transition: all .2s;
}
.toolbar .btn-reset:hover {
    background: rgba(239,68,68,.15);
    color: #FCA5A5;
    border-color: rgba(239,68,68,.3);
}

/* ============================================================
   KPI DUAL DEVISE
   ============================================================ */
.kpi-dual {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 16px;
    margin-bottom: 24px;
}
.kpi-card-big {
    padding: 26px 28px;
    border-radius: 20px;
    color: #fff;
    position: relative;
    overflow: hidden;
    box-shadow: 0 15px 40px rgba(0,0,0,.35);
    transition: transform .3s;
}
.kpi-card-big:hover { transform: translateY(-4px); }
.kpi-card-big::before {
    content: "";
    position: absolute;
    top: -50px; right: -50px;
    width: 180px; height: 180px;
    border-radius: 50%;
    opacity: .15;
}
.kpi-card-big.usd {
    background: linear-gradient(135deg, #DC2626 0%, #EF4444 60%, #F87171 100%);
    border: 2px solid rgba(239,68,68,.5);
}
.kpi-card-big.usd::before { background: #fff; }
.kpi-card-big.cdf {
    background: linear-gradient(135deg, #B45309 0%, #D97706 60%, #F59E0B 100%);
    border: 2px solid rgba(245,158,11,.5);
}
.kpi-card-big.cdf::before { background: #fff; }
.kpi-card-big .flag-row {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 14px;
    position: relative;
    z-index: 1;
}
.kpi-card-big .flag-icon { font-size: 2rem; }
.kpi-card-big .flag-label {
    font-size: .8rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 2px;
    opacity: .95;
}
.kpi-card-big .devise-tag {
    margin-left: auto;
    padding: 4px 12px;
    border-radius: 999px;
    background: rgba(255,255,255,.2);
    border: 1px solid rgba(255,255,255,.3);
    font-size: .7rem;
    font-weight: 900;
    letter-spacing: 2px;
}
.kpi-card-big .amount {
    font-size: 2.4rem;
    font-weight: 900;
    letter-spacing: -1.5px;
    line-height: 1;
    margin-bottom: 6px;
    position: relative;
    z-index: 1;
    font-variant-numeric: tabular-nums;
}
.kpi-card-big .amount-unit {
    font-size: 1.2rem;
    opacity: .85;
    margin-left: 6px;
    font-weight: 800;
}
.kpi-card-big .sub {
    font-size: .8rem;
    opacity: .85;
    position: relative;
    z-index: 1;
}

/* ============================================================
   FORMULAIRE AJOUT RAPIDE
   ============================================================ */
.form-add {
    display: grid;
    grid-template-columns: 2fr 1fr 1fr 1fr auto;
    gap: 10px;
    padding: 16px;
    background: linear-gradient(145deg, rgba(16,185,129,.05), rgba(11,14,20,.4));
    border: 2px solid rgba(16,185,129,.3);
    border-radius: 14px;
    margin-bottom: 20px;
}
.form-add input,
.form-add select {
    padding: 10px 12px;
    background: var(--bg-1);
    border: 1px solid var(--border);
    border-radius: 9px;
    color: var(--text);
    font-family: inherit;
    font-size: .85rem;
}
.form-add input:focus,
.form-add select:focus {
    outline: none;
    border-color: var(--gold);
}
.form-add button {
    padding: 11px 22px;
    background: linear-gradient(135deg, #10B981, #059669);
    color: #fff;
    border: none;
    border-radius: 10px;
    font-weight: 800;
    cursor: pointer;
    font-family: inherit;
    white-space: nowrap;
    transition: all .2s;
}
.form-add button:hover {
    transform: translateY(-1px);
    box-shadow: 0 8px 20px rgba(16,185,129,.4);
}

/* ============================================================
   TABLEAU
   ============================================================ */
table.dep-table {
    width: 100%;
    border-collapse: collapse;
    font-size: .85rem;
    background: linear-gradient(145deg, var(--card), var(--card-2));
    border-radius: 14px;
    overflow: hidden;
}
table.dep-table thead { background: rgba(11,14,20,.5); }
table.dep-table thead th {
    padding: 12px;
    text-align: left;
    font-size: .68rem;
    color: var(--muted);
    text-transform: uppercase;
    letter-spacing: 1px;
    font-weight: 800;
    border-bottom: 2px solid var(--border);
    white-space: nowrap;
}
table.dep-table tbody tr {
    border-bottom: 1px solid rgba(255,255,255,.04);
    transition: background .2s;
}
table.dep-table tbody tr:hover { background: rgba(239,68,68,.04); }
table.dep-table td {
    padding: 12px;
    color: var(--text);
    vertical-align: middle;
}
table.dep-table code {
    font-family: 'Courier New', monospace;
    color: var(--gold);
    font-weight: 700;
    font-size: .76rem;
}
table.dep-table .amount-usd {
    color: #FCA5A5;
    font-weight: 800;
    text-align: right;
    font-variant-numeric: tabular-nums;
}
table.dep-table .amount-cdf {
    color: #FCD34D;
    font-weight: 800;
    text-align: right;
    font-variant-numeric: tabular-nums;
}

.badge-cat {
    padding: 4px 10px;
    border-radius: 6px;
    font-size: .72rem;
    font-weight: 800;
    background: rgba(139,92,246,.15);
    color: #A78BFA;
    border: 1px solid rgba(139,92,246,.3);
    white-space: nowrap;
}
.badge-statut {
    padding: 4px 10px;
    border-radius: 999px;
    font-size: .68rem;
    font-weight: 800;
    text-transform: uppercase;
}
.badge-statut.validee {
    background: rgba(16,185,129,.15);
    color: #6EE7B7;
    border: 1px solid rgba(16,185,129,.4);
}
.badge-statut.en_attente {
    background: rgba(245,184,0,.15);
    color: #FCD34D;
    border: 1px solid rgba(245,184,0,.4);
}
.badge-statut.rejetee {
    background: rgba(239,68,68,.15);
    color: #FCA5A5;
    border: 1px solid rgba(239,68,68,.4);
}
.badge-statut.payee {
    background: rgba(59,130,246,.15);
    color: #93B4FF;
    border: 1px solid rgba(59,130,246,.4);
}
.badge-urgence {
    padding: 3px 8px;
    border-radius: 6px;
    font-size: .66rem;
    font-weight: 800;
    text-transform: uppercase;
}
.badge-urgence.normale {
    background: rgba(107,114,128,.2);
    color: #9CA3AF;
}
.badge-urgence.haute {
    background: rgba(245,158,11,.2);
    color: #FCD34D;
}
.badge-urgence.critique {
    background: rgba(239,68,68,.25);
    color: #FCA5A5;
    border: 1px solid rgba(239,68,68,.5);
    animation: pulseRed 2s infinite;
}
@keyframes pulseRed {
    0%, 100% { box-shadow: 0 0 0 0 rgba(239,68,68,.5); }
    50%      { box-shadow: 0 0 0 6px rgba(239,68,68,0); }
}

/* ============================================================
   PAGINATION
   ============================================================ */
.pagination {
    display: flex;
    gap: 6px;
    justify-content: center;
    align-items: center;
    margin-top: 20px;
    flex-wrap: wrap;
}
.page-btn {
    min-width: 38px;
    height: 38px;
    padding: 0 12px;
    border-radius: 10px;
    background: rgba(255,255,255,.04);
    border: 1px solid var(--border);
    color: var(--text);
    font-weight: 800;
    font-size: .82rem;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all .2s;
    font-family: inherit;
    cursor: pointer;
}
.page-btn:hover:not(.active):not(.disabled) {
    background: rgba(245,184,0,.12);
    border-color: rgba(245,184,0,.5);
    color: var(--gold);
}
.page-btn.active {
    background: linear-gradient(135deg, var(--gold), #D4AF37);
    color: #0B0E14;
    border-color: var(--gold);
    pointer-events: none;
}
.page-btn.disabled {
    opacity: .3;
    pointer-events: none;
}
.page-dots {
    color: var(--muted);
    padding: 0 4px;
    font-weight: 800;
}

/* ============================================================
   TOP CATÉGORIES
   ============================================================ */
.cat-list {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.cat-item {
    padding: 14px 18px;
    border-radius: 12px;
    background: rgba(11,14,20,.5);
    border: 1px solid var(--border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    transition: all .2s;
}
.cat-item:hover {
    border-color: var(--gold);
    transform: translateX(4px);
}
.cat-item .cat-name {
    display: flex;
    align-items: center;
    gap: 10px;
    font-weight: 800;
    color: var(--text);
}
.cat-item .cat-values {
    text-align: right;
    font-family: 'Courier New', monospace;
    font-size: .82rem;
}
.cat-item .cat-values .usd {
    color: #FCA5A5;
    font-weight: 800;
}
.cat-item .cat-values .cdf {
    color: #FCD34D;
    font-weight: 800;
}
.cat-item .cat-values .nb {
    color: var(--muted);
    font-size: .72rem;
}

/* ============================================================
   ALERTES
   ============================================================ */
.alert {
    padding: 14px 20px;
    border-radius: 12px;
    font-size: .9rem;
    margin-bottom: 20px;
    font-weight: 600;
}
.alert.success {
    background: rgba(16,185,129,.1);
    border: 1px solid rgba(16,185,129,.3);
    color: #6EE7B7;
}
.alert.error {
    background: rgba(239,68,68,.1);
    border: 1px solid rgba(239,68,68,.3);
    color: #FCA5A5;
}

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 900px) {
    .kpi-dual { grid-template-columns: 1fr; }
    .form-add { grid-template-columns: 1fr; }
    table.dep-table { font-size: .76rem; }
    table.dep-table thead th,
    table.dep-table td { padding: 8px 6px; }
    .toolbar input[type="text"] { min-width: 100%; }
}
</style>
</head>
<body class="dark-auth">

<?php require __DIR__ . '/../includes/header.php'; ?>

<div class="admin-layout">
  <aside class="admin-sidebar animate-left">
    <h3>📊 Navigation</h3>
    <a href="<?= h($base) ?>/admin/dashboard.php"><span class="ico">📊</span> Tableau de bord</a>
    <a href="<?= h($base) ?>/admin/clients.php"><span class="ico">👥</span> Clients</a>
    <a href="<?= h($base) ?>/admin/gains_systeme.php"><span class="ico">💰</span> Gains système</a>
    <a href="<?= h($base) ?>/admin/depenses.php" class="active"><span class="ico">💸</span> Dépenses</a>
    <a href="<?= h($base) ?>/admin/caissiers.php"><span class="ico">💼</span> Caissiers</a>
    <a href="<?= h($base) ?>/admin/produits.php"><span class="ico">📈</span> Produits</a>
    <a href="<?= h($base) ?>/admin/placements.php"><span class="ico">💰</span> Placements</a>
    <a href="<?= h($base) ?>/admin/commissions.php"><span class="ico">💵</span> Commissions</a>
    <a href="<?= h($base) ?>/admin/statistiques.php"><span class="ico">📈</span> Statistiques</a>
    <a href="<?= h($base) ?>/admin/transactions.php"><span class="ico">💳</span> Transactions</a>
    <a href="<?= h($base) ?>/admin/logs.php"><span class="ico">📜</span> Journal</a>
    <a href="<?= h($base) ?>/auth/logout.php"><span class="ico">🚪</span> Déconnexion</a>
  </aside>

  <main class="admin-main">

    <div class="welcome-head">
      <div>
        <h2>💸 Gestion des Dépenses</h2>
        <p class="sub">Suivi complet des sorties de fonds du système</p>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <a href="<?= h($base) ?>/admin/depenses_ajouter.php"
           style="padding:12px 22px;border-radius:12px;background:linear-gradient(135deg,var(--gold),#D4AF37);color:#0B0E14;font-weight:800;text-decoration:none;display:inline-flex;align-items:center;gap:8px;box-shadow:0 8px 24px rgba(245,184,0,.35)">
          ➕ Nouvelle dépense
        </a>
        <a href="<?= h($base) ?>/admin/depenses_imprimer.php?debut=<?= urlencode($f_debut) ?>&fin=<?= urlencode($f_fin) ?>"
           target="_blank" rel="noopener"
           style="padding:12px 22px;border-radius:12px;background:rgba(59,130,246,.15);color:#93B4FF;border:1px solid rgba(59,130,246,.4);text-decoration:none;font-weight:800;font-size:.85rem;display:inline-flex;align-items:center;gap:8px">
          🖨️ Imprimer
        </a>
      </div>
    </div>

    <?php if (!empty($success)): ?>
      <div class="alert success"><?= $success ?></div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
      <div class="alert error"><?= implode('<br>', array_map('h', $errors)) ?></div>
    <?php endif; ?>

    <!-- ============================================================
         KPI DUAL DEVISE
         ============================================================ -->
    <div class="kpi-dual">
      <div class="kpi-card-big usd">
        <div class="flag-row">
          <span class="flag-icon">🇺🇸</span>
          <span class="flag-label">Dépenses USD</span>
          <span class="devise-tag">USD</span>
        </div>
        <div class="amount">
          $ <?= number_format($kpis['total_usd'], 2, ',', ' ') ?>
          <span class="amount-unit">USD</span>
        </div>
        <div class="sub">
          <?= (int)$kpis['nb_usd'] ?> dépense(s) en dollars ·
          Période : <?= date('d/m/Y', strtotime($f_debut)) ?> → <?= date('d/m/Y', strtotime($f_fin)) ?>
        </div>
      </div>
      <div class="kpi-card-big cdf">
        <div class="flag-row">
          <span class="flag-icon">🇨🇩</span>
          <span class="flag-label">Dépenses CDF</span>
          <span class="devise-tag">CDF</span>
        </div>
        <div class="amount">
          <?= number_format($kpis['total_cdf'], 0, ',', ' ') ?>
          <span class="amount-unit">FC</span>
        </div>
        <div class="sub">
          <?= (int)$kpis['nb_cdf'] ?> dépense(s) en francs ·
          Total : <?= (int)$kpis['nb_total'] ?> op.
        </div>
      </div>
    </div>

    <!-- ============================================================
         FORMULAIRE AJOUT RAPIDE
         ============================================================ -->
    <form method="POST" class="form-add">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="ajouter">

      <input type="text" name="description"
             placeholder="📝 Description de la dépense *"
             required maxlength="1000">

      <input type="number" name="montant"
             placeholder="💰 Montant *"
             min="0.01" step="0.01" required>

      <select name="devise" required>
        <option value="USD">🇺🇸 USD</option>
        <option value="CDF">🇨🇩 CDF</option>
      </select>

      <select name="categorie" required>
        <option value="">📁 Catégorie *</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= h($c['code'] ?? '') ?>">
            <?= h($c['icone'] ?? '📁') ?> <?= h($c['libelle'] ?? '') ?>
          </option>
        <?php endforeach; ?>
      </select>

      <button type="submit">➕ Ajouter</button>
    </form>

    <!-- ============================================================
         FILTRES
         ============================================================ -->
    <form method="GET" class="toolbar">
      <input type="text" name="q"
             placeholder="🔍 Rechercher (référence, description...)"
             value="<?= h($search) ?>">

      <select name="cat">
        <option value="">Toutes catégories</option>
        <?php foreach ($categories as $c): ?>
          <option value="<?= h($c['code'] ?? '') ?>"
                  <?= $f_cat === ($c['code'] ?? '') ? 'selected' : '' ?>>
            <?= h($c['icone'] ?? '📁') ?> <?= h($c['libelle'] ?? '') ?>
          </option>
        <?php endforeach; ?>
      </select>

      <select name="devise">
        <option value="">Toutes devises</option>
        <option value="USD" <?= $f_dev === 'USD' ? 'selected' : '' ?>>🇺🇸 USD</option>
        <option value="CDF" <?= $f_dev === 'CDF' ? 'selected' : '' ?>>🇨🇩 CDF</option>
      </select>

      <select name="statut">
        <option value="">Tous statuts</option>
        <option value="en_attente" <?= $f_statut === 'en_attente' ? 'selected' : '' ?>>⏳ En attente</option>
        <option value="validee"    <?= $f_statut === 'validee'    ? 'selected' : '' ?>>✅ Validée</option>
        <option value="payee"      <?= $f_statut === 'payee'      ? 'selected' : '' ?>>💵 Payée</option>
        <option value="rejetee"    <?= $f_statut === 'rejetee'    ? 'selected' : '' ?>>❌ Rejetée</option>
      </select>

      <input type="date" name="debut" value="<?= h($f_debut) ?>">
      <input type="date" name="fin" value="<?= h($f_fin) ?>">

      <button type="submit" class="btn-filter">🔍 Filtrer</button>
      <a href="?" class="btn-reset">✕ Réinitialiser</a>
    </form>

    <!-- ============================================================
         TOP CATÉGORIES
         ============================================================ -->
    <?php if (!empty($par_categorie)): ?>
    <section style="padding:20px;background:linear-gradient(145deg,var(--card),var(--card-2));border:1px solid var(--border);border-radius:16px;margin-bottom:24px">
      <h3 style="color:var(--gold);font-size:1rem;margin-bottom:16px">📊 Répartition par catégorie</h3>
      <div class="cat-list">
        <?php foreach (array_slice($par_categorie, 0, 8) as $cat):
            $cat_info = getCatInfo((string)$cat['categorie'], $categories);
        ?>
          <div class="cat-item">
            <div class="cat-name">
              <span style="font-size:1.3rem"><?= h($cat_info['icone']) ?></span>
              <span><?= h($cat_info['libelle']) ?></span>
            </div>
            <div class="cat-values">
              <?php if ((float)$cat['usd'] > 0): ?>
                <div class="usd">🇺🇸 $ <?= number_format((float)$cat['usd'], 2, ',', ' ') ?></div>
              <?php endif; ?>
              <?php if ((float)$cat['cdf'] > 0): ?>
                <div class="cdf">🇨🇩 <?= number_format((float)$cat['cdf'], 0, ',', ' ') ?> FC</div>
              <?php endif; ?>
              <div class="nb"><?= (int)$cat['nb'] ?> op.</div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endif; ?>

    <!-- ============================================================
         TABLEAU DES DÉPENSES
         ============================================================ -->
    <section style="background:linear-gradient(145deg,var(--card),var(--card-2));border:1px solid var(--border);border-radius:16px;overflow:hidden">
      <div style="padding:20px 22px;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
        <h3 style="color:var(--gold);margin:0;font-size:1rem">
          📋 Liste des dépenses (<?= number_format($total_rows, 0, ',', ' ') ?>)
        </h3>
        <span style="color:var(--muted);font-size:.8rem">
          Page <?= $page ?> / <?= $total_pages ?>
        </span>
      </div>

      <div style="overflow-x:auto">
        <table class="dep-table">
          <thead>
            <tr>
              <th>Référence</th>
              <th>Date</th>
              <th>Catégorie</th>
              <th>Description</th>
              <th>Bénéficiaire</th>
              <th style="text-align:right">Montant</th>
              <th>Statut</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($depenses)): ?>
              <tr>
                <td colspan="8" style="text-align:center;padding:50px;color:var(--muted)">
                  <div style="font-size:3rem;opacity:.3;margin-bottom:12px">💸</div>
                  <strong>Aucune dépense trouvée</strong>
                  <p style="margin-top:8px;font-size:.85rem">
                    Ajoutez une dépense avec le formulaire ci-dessus
                  </p>
                </td>
              </tr>
            <?php else: ?>
              <?php foreach ($depenses as $d):
                  $cat_info = getCatInfo((string)$d['categorie'], $categories);
                  $is_usd = strtoupper((string)$d['devise']) === 'USD';
              ?>
                <tr>
                  <td><code><?= h($d['reference']) ?></code></td>
                  <td style="color:var(--muted);font-size:.8rem;white-space:nowrap">
                    <?= date('d/m/Y', strtotime($d['date_depense'])) ?><br>
                    <small><?= date('H:i', strtotime($d['date_depense'])) ?></small>
                  </td>
                  <td>
                    <span class="badge-cat">
                      <?= h($cat_info['icone']) ?> <?= h($cat_info['libelle']) ?>
                    </span>
                  </td>
                  <td style="max-width:300px;font-size:.85rem">
                    <?= h(mb_substr((string)$d['description'], 0, 80)) ?><?= mb_strlen((string)$d['description']) > 80 ? '...' : '' ?>
                  </td>
                  <td style="font-size:.82rem;color:var(--muted)">
                    <?= h($d['beneficiaire'] ?? '—') ?>
                  </td>
                  <td class="<?= $is_usd ? 'amount-usd' : 'amount-cdf' ?>">
                    <?= $is_usd
                        ? '$ ' . number_format((float)$d['montant'], 2, ',', ' ')
                        : number_format((float)$d['montant'], 0, ',', ' ') . ' FC' ?>
                  </td>
                  <td>
                    <span class="badge-statut <?= h($d['statut']) ?>">
                      <?= h(str_replace('_', ' ', (string)$d['statut'])) ?>
                    </span>
                    <br>
                    <span class="badge-urgence <?= h($d['urgence']) ?>" style="margin-top:4px;display:inline-block">
                      <?= h($d['urgence']) ?>
                    </span>
                  </td>
                  <td>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Supprimer cette dépense ?')">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="supprimer">
                      <input type="hidden" name="id_depense" value="<?= (int)$d['id_depense'] ?>">
                      <button style="padding:6px 10px;background:rgba(239,68,68,.15);color:#FCA5A5;border:1px solid rgba(239,68,68,.3);border-radius:6px;cursor:pointer;font-size:.72rem;font-weight:800" title="Supprimer">
                        🗑
                      </button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <!-- ============================================================
           PAGINATION
           ============================================================ -->
      <?php if ($total_pages > 1): ?>
      <div style="padding:20px;border-top:1px solid var(--border);background:rgba(11,14,20,.35)">
        <div class="pagination">
          <?php if ($page > 1): ?>
            <a href="<?= buildUrl(['page' => $page - 1]) ?>" class="page-btn">← Précédent</a>
          <?php else: ?>
            <span class="page-btn disabled">← Précédent</span>
          <?php endif; ?>

          <?php
            $start = max(1, $page - 2);
            $end = min($total_pages, $start + 4);

            if ($start > 1) {
                echo '<a href="' . buildUrl(['page' => 1]) . '" class="page-btn">1</a>';
                if ($start > 2) echo '<span class="page-dots">…</span>';
            }

            for ($p = $start; $p <= $end; $p++) {
                $active = $p === $page ? ' active' : '';
                echo '<a href="' . buildUrl(['page' => $p]) . '" class="page-btn' . $active . '">' . $p . '</a>';
            }

            if ($end < $total_pages) {
                if ($end < $total_pages - 1) echo '<span class="page-dots">…</span>';
                echo '<a href="' . buildUrl(['page' => $total_pages]) . '" class="page-btn">' . $total_pages . '</a>';
            }
          ?>

          <?php if ($page < $total_pages): ?>
            <a href="<?= buildUrl(['page' => $page + 1]) ?>" class="page-btn">Suivant →</a>
          <?php else: ?>
            <span class="page-btn disabled">Suivant →</span>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </section>

  </main>
</div>

<footer class="atf-footer">© <?= date('Y') ?> AFRICAN TRUST FUND</footer>

<script>
/* Raccourcis clavier pour pagination */
document.addEventListener('keydown', function(e) {
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'SELECT' || e.target.tagName === 'TEXTAREA') return;
    const currentPage = <?= (int)$page ?>;
    const totalPages  = <?= (int)$total_pages ?>;
    if (e.key === 'ArrowLeft' && currentPage > 1) {
        window.location.href = '<?= buildUrl(['page' => max(1, $page - 1)]) ?>';
    } else if (e.key === 'ArrowRight' && currentPage < totalPages) {
        window.location.href = '<?= buildUrl(['page' => min($total_pages, $page + 1)]) ?>';
    }
});

console.log('%c💸 Dépenses ATF', 'color:#F5B800;font-weight:bold;font-size:14px');
console.log('Page :', <?= (int)$page ?>, '/', <?= (int)$total_pages ?>);
console.log('Total :', <?= (int)$total_rows ?>);
</script>

<script src="<?= h($base) ?>/assets/js/main.js"></script>
</body>
</html>