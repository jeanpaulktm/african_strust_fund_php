<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
requireRole('admin');

$pdo = Database::getConnection();
$admin_id = (int)$_SESSION['admin_id'];
function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) throw new RuntimeException("Jeton invalide.");

        $categorie = cleanString($_POST['categorie'] ?? '', 50);
        $sous_cat = cleanString($_POST['sous_categorie'] ?? '', 100);
        $description = cleanString($_POST['description'] ?? '', 1000);
        $montant = (float)($_POST['montant'] ?? 0);
        $devise = in_array($_POST['devise'] ?? '', ['USD','CDF'], true) ? $_POST['devise'] : 'USD';
        $beneficiaire = cleanString($_POST['beneficiaire'] ?? '', 200);
        $mode = in_array($_POST['mode_paiement'] ?? '', ['especes','virement','cheque','mobile_money','carte'], true) ? $_POST['mode_paiement'] : 'especes';
        $ref_paie = cleanString($_POST['reference_paiement'] ?? '', 100);
        $urgence = in_array($_POST['urgence'] ?? '', ['normale','haute','critique'], true) ? $_POST['urgence'] : 'normale';
        $statut = in_array($_POST['statut'] ?? '', ['en_attente','validee'], true) ? $_POST['statut'] : 'validee';
        $date_dep = $_POST['date_depense'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_dep)) $date_dep = date('Y-m-d');

        if ($categorie === '') throw new RuntimeException("Catégorie obligatoire.");
        if ($description === '') throw new RuntimeException("Description obligatoire.");
        if ($montant <= 0) throw new RuntimeException("Montant > 0 requis.");

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

        logAction($pdo, 'admin', $admin_id, 'DEPENSE_AJOUTEE', "Réf: $ref");
        $_SESSION['flash_success'] = "✅ Dépense <strong>$ref</strong> enregistrée avec succès.";
        header('Location: ' . url('admin/depenses.php'));
        exit;

    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$categories = [];
try {
    $categories = $pdo->query("SELECT * FROM Categories_Depenses WHERE est_actif = TRUE ORDER BY ordre_affichage, libelle")->fetchAll();
} catch (Throwable $e) {}

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>➕ Nouvelle Dépense — ATF Admin</title>
<link rel="stylesheet" href="<?= h($base) ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= h($base) ?>/assets/css/admin-premium.css">
<style>
.form-card { max-width:900px; margin:0 auto; padding:32px; background:linear-gradient(145deg,var(--card),var(--card-2)); border:1px solid var(--border); border-radius:20px; }
.form-card h2 { color:var(--gold); font-size:1.3rem; margin-bottom:8px; display:flex; align-items:center; gap:12px; }
.form-card .subtitle { color:var(--muted); font-size:.85rem; margin-bottom:28px; }
.form-grid { display:grid; grid-template-columns:1fr 1fr; gap:20px; }
.form-group { display:flex; flex-direction:column; gap:8px; }
.form-group.full { grid-column: 1 / -1; }
.form-group label { color:var(--muted); font-size:.78rem; text-transform:uppercase; letter-spacing:1px; font-weight:800; }
.form-group label .required { color:#FCA5A5; }
.form-group input, .form-group select, .form-group textarea { padding:12px 14px; background:var(--bg-1); border:1.5px solid var(--border); border-radius:10px; color:var(--text); font-family:inherit; font-size:.9rem; }
.form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline:none; border-color:var(--gold); box-shadow:0 0 0 4px rgba(245,184,0,.1); }
.form-group textarea { resize:vertical; min-height:100px; }
.form-group .hint { font-size:.7rem; color:var(--muted); font-style:italic; }

.devise-selector { display:grid; grid-template-columns:1fr 1fr; gap:10px; }
.devise-btn { padding:14px; border-radius:12px; background:rgba(255,255,255,.04); border:2px solid var(--border); cursor:pointer; text-align:center; transition:all .2s; }
.devise-btn:hover { border-color:var(--gold); }
.devise-btn input { display:none; }
.devise-btn input:checked + .content { color:var(--gold); }
.devise-btn:has(input:checked) { border-color:var(--gold); background:rgba(245,184,0,.1); box-shadow:0 0 0 4px rgba(245,184,0,.15); }
.devise-btn .content { display:flex; flex-direction:column; align-items:center; gap:6px; }
.devise-btn .flag { font-size:1.8rem; }
.devise-btn .code { font-weight:900; font-size:.9rem; }
.devise-btn .label { font-size:.72rem; color:var(--muted); }

.mode-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); gap:8px; }
.mode-opt { padding:12px; border-radius:10px; background:rgba(255,255,255,.04); border:2px solid var(--border); cursor:pointer; text-align:center; font-size:.78rem; font-weight:700; transition:all .2s; }
.mode-opt input { display:none; }
.mode-opt:has(input:checked) { border-color:var(--gold); background:rgba(245,184,0,.1); color:var(--gold); }

.actions-bar { display:flex; gap:12px; justify-content:flex-end; margin-top:28px; padding-top:24px; border-top:1px solid var(--border); }
.btn-cancel { padding:13px 26px; border-radius:12px; background:rgba(255,255,255,.05); color:var(--muted); border:1px solid var(--border); font-weight:700; text-decoration:none; }
.btn-submit { padding:13px 32px; border-radius:12px; background:linear-gradient(135deg,#F5B800,#D4AF37); color:#0B0E14; font-weight:900; border:none; cursor:pointer; font-family:inherit; box-shadow:0 10px 28px rgba(245,184,0,.4); transition:all .25s; }
.btn-submit:hover { transform:translateY(-2px); box-shadow:0 15px 40px rgba(245,184,0,.5); }

@media (max-width: 768px) { .form-grid { grid-template-columns:1fr; } }
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
    <a href="<?= h($base) ?>/admin/logs.php"><span class="ico">📜</span> Journal</a>
    <a href="<?= h($base) ?>/auth/logout.php"><span class="ico">🚪</span> Déconnexion</a>
  </aside>

  <main class="admin-main">
    <div class="form-card">
      <h2>➕ Enregistrer une nouvelle dépense</h2>
      <p class="subtitle">Tous les champs marqués <span style="color:#FCA5A5">*</span> sont obligatoires</p>

      <?php if ($errors): ?>
        <div class="alert error" style="margin-bottom:20px"><?= implode('<br>', array_map('h', $errors)) ?></div>
      <?php endif; ?>

      <form method="POST" id="formDepense">
        <?= csrfField() ?>

        <div class="form-grid">
          <!-- Catégorie -->
          <div class="form-group">
            <label>📁 Catégorie <span class="required">*</span></label>
            <select name="categorie" required>
              <option value="">— Choisir —</option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= h($c['code']) ?>"><?= h($c['icone'] ?? '📁') ?> <?= h($c['libelle']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Sous-catégorie -->
          <div class="form-group">
            <label>🏷️ Sous-catégorie</label>
            <input type="text" name="sous_categorie" maxlength="100" placeholder="Ex: Facture SNEL Janvier">
          </div>

          <!-- Description -->
          <div class="form-group full">
            <label>📝 Description <span class="required">*</span></label>
            <textarea name="description" required maxlength="1000" placeholder="Décrivez précisément la dépense..."></textarea>
          </div>

          <!-- Devise -->
          <div class="form-group full">
            <label>💱 Devise <span class="required">*</span></label>
            <div class="devise-selector">
              <label class="devise-btn">
                <input type="radio" name="devise" value="USD" checked>
                <div class="content">
                  <span class="flag">🇺🇸</span>
                  <span class="code">USD</span>
                  <span class="label">Dollar Américain</span>
                </div>
              </label>
              <label class="devise-btn">
                <input type="radio" name="devise" value="CDF">
                <div class="content">
                  <span class="flag">🇨🇩</span>
                  <span class="code">CDF</span>
                  <span class="label">Franc Congolais</span>
                </div>
              </label>
            </div>
          </div>

          <!-- Montant -->
          <div class="form-group">
            <label>💰 Montant <span class="required">*</span></label>
            <input type="number" name="montant" required min="0.01" step="0.01" placeholder="0.00">
          </div>

          <!-- Date -->
          <div class="form-group">
            <label>📅 Date de la dépense <span class="required">*</span></label>
            <input type="date" name="date_depense" required value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>">
          </div>

          <!-- Bénéficiaire -->
          <div class="form-group">
            <label>👤 Bénéficiaire</label>
            <input type="text" name="beneficiaire" maxlength="200" placeholder="Nom du fournisseur, employé...">
          </div>

          <!-- Référence paiement -->
          <div class="form-group">
            <label>🔖 Référence paiement</label>
            <input type="text" name="reference_paiement" maxlength="100" placeholder="N° chèque, transaction...">
          </div>

          <!-- Mode paiement -->
          <div class="form-group full">
            <label>💳 Mode de paiement</label>
            <div class="mode-grid">
              <label class="mode-opt"><input type="radio" name="mode_paiement" value="especes" checked> 💵 Espèces</label>
              <label class="mode-opt"><input type="radio" name="mode_paiement" value="virement"> 🏦 Virement</label>
              <label class="mode-opt"><input type="radio" name="mode_paiement" value="cheque"> 📝 Chèque</label>
              <label class="mode-opt"><input type="radio" name="mode_paiement" value="mobile_money"> 📱 Mobile Money</label>
              <label class="mode-opt"><input type="radio" name="mode_paiement" value="carte"> 💳 Carte</label>
            </div>
          </div>

          <!-- Urgence -->
          <div class="form-group">
            <label>⚡ Urgence</label>
            <select name="urgence">
              <option value="normale">🟢 Normale</option>
              <option value="haute">🟡 Haute</option>
              <option value="critique">🔴 Critique</option>
            </select>
          </div>

          <!-- Statut -->
          <div class="form-group">
            <label>📊 Statut</label>
            <select name="statut">
              <option value="validee">✅ Validée</option>
              <option value="en_attente">⏳ En attente</option>
            </select>
          </div>
        </div>

        <div class="actions-bar">
          <a href="<?= h($base) ?>/admin/depenses.php" class="btn-cancel">← Annuler</a>
          <button type="submit" class="btn-submit">💾 Enregistrer la dépense</button>
        </div>
      </form>
    </div>
  </main>
</div>

<footer class="atf-footer">© <?= date('Y') ?> AFRICAN TRUST FUND</footer>
<script src="<?= h($base) ?>/assets/js/main.js"></script>
</body>
</html>