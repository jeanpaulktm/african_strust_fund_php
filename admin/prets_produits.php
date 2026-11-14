<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/prets.php';
requireRole('admin');

$pdo = Database::getConnection();
$errors = [];

/* Création */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) throw new RuntimeException("Jeton invalide.");
        
        $nom = cleanString($_POST['nom'] ?? '', 100);
        $desc = cleanString($_POST['description'] ?? '', 500);
        $type = in_array($_POST['type_pret'] ?? '', ['individuel','groupe','scolaire','agricole','urgence','entreprise'], true) ? $_POST['type_pret'] : 'individuel';
        $type_client = in_array($_POST['type_client'] ?? '', ['physique','morale','tous'], true) ? $_POST['type_client'] : 'tous';
        $taux = (float)($_POST['taux_annuel'] ?? 0) / 100;
        $dmin = (int)($_POST['duree_min_mois'] ?? 1);
        $dmax = (int)($_POST['duree_max_mois'] ?? 24);
        $mmin = (float)str_replace([' ', ','], ['', '.'], $_POST['montant_min'] ?? '0');
        $mmax = (float)str_replace([' ', ','], ['', '.'], $_POST['montant_max'] ?? '0');
        $devise = in_array($_POST['devise'] ?? '', ['CDF','USD'], true) ? $_POST['devise'] : 'CDF';
        $score_min = (int)($_POST['score_min_requis'] ?? 60);
        
        if (!$nom) throw new RuntimeException("Nom obligatoire.");
        if ($taux <= 0 || $taux > 1) throw new RuntimeException("Taux invalide (0-100%).");
        if ($mmin <= 0 || $mmax <= $mmin) throw new RuntimeException("Montants invalides.");
        if ($dmin < 1 || $dmax < $dmin) throw new RuntimeException("Durées invalides.");
        
        $pdo->prepare("
            INSERT INTO Produits_Pret 
                (nom, description, type_pret, type_client, taux_annuel, duree_min_mois, duree_max_mois,
                 montant_min, montant_max, devise, score_min_requis, actif)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ")->execute([$nom, $desc, $type, $type_client, $taux, $dmin, $dmax, $mmin, $mmax, $devise, $score_min]);
        
        logAction($pdo, 'admin', (int)$_SESSION['admin_id'], 'PRODUIT_PRET_CREATE', "Nom: $nom");
        $_SESSION['flash_success'] = "✅ Produit créé !";
        header('Location: ' . url('admin/prets_produits.php'));
        exit;
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

/* Toggle actif */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) throw new RuntimeException("Jeton invalide.");
        $id = (int)($_POST['id_produit'] ?? 0);
        $pdo->prepare("UPDATE Produits_Pret SET actif = NOT actif WHERE id_produit = ?")->execute([$id]);
        $_SESSION['flash_success'] = "✅ Produit modifié.";
    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
    }
    header('Location: ' . url('admin/prets_produits.php'));
    exit;
}

$produits = $pdo->query("SELECT * FROM Produits_Pret ORDER BY actif DESC, devise, nom")->fetchAll();
$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Produits de prêt — ATF</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/admin-premium.css">
</head>
<body class="dark-auth">
<?php require __DIR__ . '/../includes/header.php'; ?>
<div class="admin-layout">
  <aside class="admin-sidebar">
    <h3>📊 Navigation</h3>
    <a href="<?= $base ?>/admin/dashboard.php"><span class="ico">📊</span> Tableau de bord</a>
    <a href="<?= $base ?>/admin/prets.php"><span class="ico">💳</span> Prêts</a>
    <a href="<?= $base ?>/admin/prets_produits.php" class="active"><span class="ico">📋</span> Produits prêt</a>
    <a href="<?= $base ?>/auth/logout.php"><span class="ico">🚪</span> Déconnexion</a>
  </aside>
  <main class="admin-main">
    <div class="welcome-head">
      <div><h2>📋 Produits de prêt</h2><p class="sub"><?= count($produits) ?> produit(s)</p></div>
    </div>

    <?php if (!empty($_SESSION['flash_success'])): ?>
      <div class="alert success"><?= $_SESSION['flash_success'] ?></div><?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>
    <?php if ($errors): ?>
      <div class="alert error"><ul><?php foreach ($errors as $e) echo "<li>" . htmlspecialchars($e) . "</li>"; ?></ul></div>
    <?php endif; ?>

    <section class="card">
      <h3 style="margin-bottom:16px">➕ Nouveau produit</h3>
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">
        <div class="grid-2">
          <label class="field"><span>Nom *</span><input type="text" name="nom" required maxlength="100"></label>
          <label class="field"><span>Devise *</span><select name="devise"><option value="CDF">CDF</option><option value="USD">USD</option></select></label>
        </div>
        <div class="grid-3">
          <label class="field"><span>Type prêt</span>
            <select name="type_pret">
              <option value="individuel">Individuel</option>
              <option value="entreprise">Entreprise</option>
              <option value="agricole">Agricole</option>
              <option value="urgence">Urgence</option>
            </select></label>
          <label class="field"><span>Type client</span>
            <select name="type_client">
              <option value="tous">Tous</option>
              <option value="physique">Physique</option>
              <option value="morale">Morale</option>
            </select></label>
          <label class="field"><span>Taux annuel (%) *</span><input type="number" step="0.01" name="taux_annuel" required min="0.1" max="100" value="24"></label>
        </div>
        <div class="grid-2">
          <label class="field"><span>Montant min *</span><input type="text" name="montant_min" required placeholder="50000"></label>
          <label class="field"><span>Montant max *</span><input type="text" name="montant_max" required placeholder="5000000"></label>
        </div>
        <div class="grid-3">
          <label class="field"><span>Durée min (mois)</span><input type="number" name="duree_min_mois" required min="1" value="3"></label>
          <label class="field"><span>Durée max (mois)</span><input type="number" name="duree_max_mois" required min="1" value="24"></label>
          <label class="field"><span>Score min requis</span><input type="number" name="score_min_requis" min="0" max="100" value="60"></label>
        </div>
        <label class="field"><span>Description</span><textarea name="description" rows="2" maxlength="500"></textarea></label>
        <button class="btn-gold full" type="submit" style="margin-top:14px">➕ Créer le produit</button>
      </form>
    </section>

    <section class="card">
      <h3 style="margin-bottom:16px">📋 Liste (<?= count($produits) ?>)</h3>
      <?php foreach ($produits as $pr): ?>
        <div style="padding:16px;border-radius:12px;border:1px solid <?= $pr['actif'] ? 'rgba(16,185,129,.3)' : 'var(--border)' ?>;background:<?= $pr['actif'] ? 'rgba(16,185,129,.03)' : 'var(--card)' ?>;margin-bottom:10px;display:flex;justify-content:space-between;flex-wrap:wrap;gap:12px;align-items:center">
          <div style="flex:1;min-width:220px">
            <strong style="color:var(--gold)"><?= htmlspecialchars($pr['nom']) ?></strong>
            <span class="badge <?= $pr['devise'] === 'USD' ? 'blue' : 'gold' ?>" style="margin-left:8px"><?= htmlspecialchars($pr['devise']) ?></span>
            <?php if (!$pr['actif']): ?><span class="badge red" style="margin-left:6px">Inactif</span><?php endif; ?>
            <div style="color:var(--muted);font-size:.82rem;margin-top:6px">
              Taux <?= number_format((float)$pr['taux_annuel'] * 100, 2) ?>%/an · 
              <?= (int)$pr['duree_min_mois'] ?>-<?= (int)$pr['duree_max_mois'] ?> mois · 
              <?= htmlspecialchars(formaterMontant((float)$pr['montant_min'], $pr['devise'])) ?> → <?= htmlspecialchars(formaterMontant((float)$pr['montant_max'], $pr['devise'])) ?>
            </div>
          </div>
          <form method="POST" style="display:inline">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="toggle">
            <input type="hidden" name="id_produit" value="<?= (int)$pr['id_produit'] ?>">
            <button class="btn-ghost" type="submit" style="padding:8px 16px;font-size:.82rem">
              <?= $pr['actif'] ? '🔒 Désactiver' : '✅ Activer' ?>
            </button>
          </form>
        </div>
      <?php endforeach; ?>
    </section>
  </main>
</div>
<footer class="atf-footer">© <?= date('Y') ?> AFRICAN TRUST FUND</footer>
<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>