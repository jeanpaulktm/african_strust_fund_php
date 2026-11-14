<?php
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
requireRole('admin');

$pdo = Database::getConnection();
$admin_id = (int)$_SESSION['admin_id'];
$errors = [];
$success = '';

/* ============================================================
   CRÉATION D'UN CAISSIER
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) throw new RuntimeException("Jeton invalide.");

        $nom      = cleanString($_POST['nom'] ?? '', 60);
        $postnom  = cleanString($_POST['postnom'] ?? '', 60);
        $prenom   = cleanString($_POST['post_prenom'] ?? '', 60);
        $phone    = cleanString($_POST['numero_phone'] ?? '', 25);
        $password = (string)($_POST['mot_de_passe'] ?? '');
        $password2= (string)($_POST['mot_de_passe2'] ?? '');

        if (!$nom || !$postnom || !$prenom)                $errors[] = "Tous les noms sont obligatoires.";
        if (!preg_match('/^\+?[0-9 ]{9,20}$/', $phone))    $errors[] = "Téléphone invalide (9-20 chiffres).";
        if (!validerMotDePasse($password))                  $errors[] = "Mot de passe faible (8+, maj, min, chiffre, symbole).";
        if ($password !== $password2)                       $errors[] = "Les mots de passe ne correspondent pas.";

        if (!$errors) {
            $chk = $pdo->prepare("SELECT 1 FROM Caissier WHERE numero_phone = ?");
            $chk->execute([$phone]);
            if ($chk->fetchColumn()) {
                $errors[] = "Ce numéro de téléphone est déjà utilisé.";
            } else {
                $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
                $pdo->prepare("INSERT INTO Caissier (nom, postnom, post_prenom, numero_phone, mot_de_passe, statut, created_by)
                               VALUES (?,?,?,?,?,'actif',?)")
                    ->execute([$nom, $postnom, $prenom, $phone, $hash, $admin_id]);

                $id_new = (int)$pdo->lastInsertId();
                logAction($pdo, 'admin', $admin_id, 'CAISSIER_CREATE',
                    "Caissier #$id_new : $nom $postnom ($phone)");

                $_SESSION['flash_success'] = "✅ Caissier créé !<br>
                    <strong>Identifiant :</strong> " . htmlspecialchars($nom) . "<br>
                    <strong>Téléphone :</strong> " . htmlspecialchars($phone) . "<br>
                    Le caissier peut maintenant se connecter sur /auth/connexion_caissier.php";
                header('Location: ' . url('admin/caissiers.php')); exit;
            }
        }
    } catch (Throwable $e) { $errors[] = $e->getMessage(); }
}

/* ============================================================
   TOGGLE STATUT (POST + token)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) throw new RuntimeException("Jeton invalide.");
        $id_caissier = recupererIdSecurise('caissier');
        if (!$id_caissier) throw new RuntimeException("Session expirée. Rechargez la page.");

        $pdo->prepare("UPDATE Caissier SET statut = IF(statut='actif','inactif','actif') WHERE id_caissier = ?")
            ->execute([$id_caissier]);

        logAction($pdo, 'admin', $admin_id, 'CAISSIER_TOGGLE', "ID: $id_caissier");
        $_SESSION['flash_success'] = "Statut du caissier mis à jour.";
        header('Location: ' . url('admin/caissiers.php')); exit;
    } catch (Throwable $e) { $errors[] = $e->getMessage(); }
}

/* ============================================================
   RÉINITIALISER LE MOT DE PASSE (POST + token)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_pwd') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) throw new RuntimeException("Jeton invalide.");
        $id_caissier = recupererIdSecurise('caissier');
        $new_pwd = (string)($_POST['new_password'] ?? '');

        if (!$id_caissier) throw new RuntimeException("Session expirée.");
        if (!validerMotDePasse($new_pwd)) throw new RuntimeException("Nouveau mot de passe faible.");

        $hash = password_hash($new_pwd, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare("UPDATE Caissier SET mot_de_passe = ?, failed_attempts = 0, locked_until = NULL WHERE id_caissier = ?")
            ->execute([$hash, $id_caissier]);

        logAction($pdo, 'admin', $admin_id, 'CAISSIER_RESET_PWD', "ID: $id_caissier");
        $_SESSION['flash_success'] = "🔑 Mot de passe réinitialisé.";
        header('Location: ' . url('admin/caissiers.php')); exit;
    } catch (Throwable $e) { $errors[] = $e->getMessage(); }
}

/* ============================================================
   SUPPRESSION (POST + token)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) throw new RuntimeException("Jeton invalide.");
        $id_caissier = recupererIdSecurise('caissier');
        if (!$id_caissier) throw new RuntimeException("Session expirée.");

        // Vérifier qu'il n'a pas de transactions liées
        $chk = $pdo->prepare("SELECT COUNT(*) FROM Transactions WHERE id_caissier = ?");
        $chk->execute([$id_caissier]);
        if ((int)$chk->fetchColumn() > 0) {
            // Désactiver au lieu de supprimer
            $pdo->prepare("UPDATE Caissier SET statut='inactif' WHERE id_caissier = ?")->execute([$id_caissier]);
            $_SESSION['flash_success'] = "Ce caissier a des transactions — il a été désactivé au lieu d'être supprimé.";
        } else {
            $pdo->prepare("DELETE FROM Caissier WHERE id_caissier = ?")->execute([$id_caissier]);
            $_SESSION['flash_success'] = "✅ Caissier supprimé.";
        }

        logAction($pdo, 'admin', $admin_id, 'CAISSIER_DELETE', "ID: $id_caissier");
        header('Location: ' . url('admin/caissiers.php')); exit;
    } catch (Throwable $e) { $errors[] = $e->getMessage(); }
}

$success = '';
if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

/* ============================================================
   STATISTIQUES
   ============================================================ */
$stats = [
    'total'   => (int)$pdo->query("SELECT COUNT(*) FROM Caissier")->fetchColumn(),
    'actifs'  => (int)$pdo->query("SELECT COUNT(*) FROM Caissier WHERE statut='actif'")->fetchColumn(),
    'inactifs'=> (int)$pdo->query("SELECT COUNT(*) FROM Caissier WHERE statut='inactif'")->fetchColumn(),
];

/* ============================================================
   LISTE DES CAISSIERS avec statistiques d'activité
   ============================================================ */
$caissiers = $pdo->query("
    SELECT c.*,
           COALESCE((SELECT COUNT(*) FROM Transactions t WHERE t.id_caissier = c.id_caissier), 0) AS nb_transactions,
           COALESCE((SELECT SUM(t.montant) FROM Transactions t WHERE t.id_caissier = c.id_caissier), 0) AS volume_total,
           (SELECT MAX(t.date_transaction) FROM Transactions t WHERE t.id_caissier = c.id_caissier) AS derniere_operation,
           a.nom AS created_by_nom
    FROM Caissier c
    LEFT JOIN Admin_Principal a ON a.id_admin = c.created_by
    ORDER BY c.created_at DESC
")->fetchAll();

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Caissiers — ATF Admin</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/admin-premium.css">
</head>
<body class="dark-auth">

<div class="top-progress" id="topProgress"></div>
<?php require __DIR__ . '/../includes/header.php'; ?>

<div class="admin-layout">
  <aside class="admin-sidebar animate-left">
    <h3>📊 Navigation</h3>
    <a href="<?= $base ?>/admin/dashboard.php"><span class="ico">📊</span> Tableau de bord</a>
    <a href="<?= $base ?>/admin/clients.php"><span class="ico">👥</span> Clients</a>
    <a href="<?= $base ?>/admin/caissiers.php" class="active"><span class="ico">💼</span> Caissiers</a>
    <a href="<?= $base ?>/admin/produits.php"><span class="ico">📈</span> Produits</a>
    <a href="<?= $base ?>/admin/placements.php"><span class="ico">💰</span> Placements</a>
    <a href="<?= $base ?>/admin/commissions.php"><span class="ico">💵</span> Commissions</a>
    <a href="<?= $base ?>/admin/statistiques.php"><span class="ico">📈</span> Statistiques</a>
    <a href="<?= $base ?>/admin/transactions.php"><span class="ico">💳</span> Transactions</a>
    <a href="<?= $base ?>/admin/logs.php"><span class="ico">📜</span> Journal</a>
    <a href="<?= $base ?>/auth/logout.php"><span class="ico">🚪</span> Déconnexion</a>
  </aside>

  <main class="admin-main">

    <div class="welcome-head">
      <div>
        <h2>💼 Gestion des caissiers</h2>
        <p class="sub"><?= $stats['total'] ?> caissier(s) — <?= $stats['actifs'] ?> actif(s)</p>
      </div>
    </div>

    <?php if ($success): ?><div class="alert success"><?= $success ?></div><?php endif; ?>
    <?php if ($errors): ?><div class="alert error"><ul><?php foreach ($errors as $e) echo "<li>" . htmlspecialchars($e) . "</li>"; ?></ul></div><?php endif; ?>

    <!-- KPIs -->
    <section class="kpi-grid">
      <div class="stat-block gold animate-in delay-1">
        <div class="ico">💼</div>
        <div class="meta">
          <strong><?= $stats['total'] ?></strong>
          <span>Total caissiers</span>
        </div>
      </div>
      <div class="stat-block green animate-in delay-2">
        <div class="ico">✅</div>
        <div class="meta">
          <strong><?= $stats['actifs'] ?></strong>
          <span>Actifs</span>
        </div>
      </div>
      <div class="stat-block red animate-in delay-3">
        <div class="ico">⏸️</div>
        <div class="meta">
          <strong><?= $stats['inactifs'] ?></strong>
          <span>Inactifs</span>
        </div>
      </div>
    </section>

    <!-- Formulaire de création -->
    <section class="card animate-in delay-2">
      <h3 style="margin-bottom:20px">➕ Créer un nouveau caissier</h3>

      <form method="POST" novalidate autocomplete="off" data-validate>
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">

        <fieldset>
          <legend>Identité du caissier</legend>
          <div class="grid-3">
            <label class="field"><span>Nom *</span>
              <input type="text" name="nom" required maxlength="60" placeholder="Ex: KABILA"></label>
            <label class="field"><span>Post-nom *</span>
              <input type="text" name="postnom" required maxlength="60" placeholder="Ex: MUKENDI"></label>
            <label class="field"><span>Prénom *</span>
              <input type="text" name="post_prenom" required maxlength="60" placeholder="Ex: Jean"></label>
          </div>

          <label class="field"><span>Téléphone *</span>
            <input type="text" name="numero_phone" required maxlength="25" placeholder="+243 8XX XXX XXX">
          </label>
        </fieldset>

        <fieldset>
          <legend>Mot de passe sécurisé</legend>
          <div class="grid-2">
            <label class="field"><span>Mot de passe *</span>
              <input type="password" id="pwd1" name="mot_de_passe" required minlength="8" autocomplete="new-password"></label>
            <label class="field"><span>Confirmation *</span>
              <input type="password" id="pwd2" name="mot_de_passe2" required minlength="8" autocomplete="new-password"></label>
          </div>
          <div class="pwd-meter"><div id="pwdBar"></div></div>
          <p class="hint">📋 8 caractères min. — 1 majuscule, 1 minuscule, 1 chiffre, 1 symbole (ex: <code>Caissier@2025</code>)</p>
        </fieldset>

        <button class="btn-gold full" type="submit" style="margin-top:10px">
          💼 Créer le compte caissier
        </button>
      </form>
    </section>

    <!-- Liste des caissiers -->
    <section class="card animate-in delay-3">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:10px">
        <h3 style="margin:0">📋 Liste des caissiers</h3>
        <input type="text" placeholder="🔍 Rechercher..." data-table-filter="caissiersTable" style="max-width:280px">
      </div>

      <div class="table-wrap">
        <table id="caissiersTable">
          <thead>
            <tr>
              <th>Nom complet</th><th>Téléphone</th><th>Statut</th>
              <th style="text-align:right">Transactions</th>
              <th style="text-align:right">Volume traité</th>
              <th>Dernière opération</th>
              <th>Créé le</th><th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$caissiers): ?>
              <tr><td colspan="8" class="muted center" style="padding:30px">Aucun caissier enregistré.</td></tr>
            <?php else: foreach ($caissiers as $c):
              $token_toggle = genererToken('caissier', (int)$c['id_caissier'], ['action' => 'toggle']);
              $token_delete = genererToken('caissier', (int)$c['id_caissier'], ['action' => 'delete']);
              $token_reset  = genererToken('caissier', (int)$c['id_caissier'], ['action' => 'reset']);
            ?>
              <tr>
                <td>
                  <strong><?= htmlspecialchars($c['nom'] . ' ' . $c['postnom'] . ' ' . $c['post_prenom']) ?></strong><br>
                  <small class="muted">Créé par <?= htmlspecialchars($c['created_by_nom'] ?? 'Système') ?></small>
                </td>
                <td><?= htmlspecialchars($c['numero_phone']) ?></td>
                <td>
                  <span class="badge <?= $c['statut'] === 'actif' ? 'green' : 'red' ?>">
                    ● <?= htmlspecialchars($c['statut']) ?>
                  </span>
                </td>
                <td style="text-align:right"><?= (int)$c['nb_transactions'] ?></td>
                <td style="text-align:right;color:var(--gold);font-weight:700">
                  <?= number_format((float)$c['volume_total'], 0, ',', ' ') ?>
                </td>
                <td>
                  <?= $c['derniere_operation']
                      ? date('d/m/Y H:i', strtotime($c['derniere_operation']))
                      : '<span class="muted">Aucune</span>' ?>
                </td>
                <td><?= date('d/m/Y', strtotime($c['created_at'])) ?></td>
                <td>
                  <div style="display:flex;gap:6px;flex-wrap:wrap">
                    <!-- Toggle statut -->
                    <form method="POST" style="display:inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="toggle">
                      <input type="hidden" name="token" value="<?= htmlspecialchars($token_toggle) ?>">
                      <button class="btn-ghost btn-sm" type="submit">
                        <?= $c['statut'] === 'actif' ? '⏸ Désactiver' : '▶ Activer' ?>
                      </button>
                    </form>

                    <!-- Reset mot de passe -->
                    <button class="btn-ghost btn-sm" type="button"
                            onclick="ouvrirReset(<?= (int)$c['id_caissier'] ?>, '<?= htmlspecialchars(addslashes($c['nom'])) ?>', '<?= htmlspecialchars($token_reset) ?>')">
                      🔑 Reset
                    </button>

                    <!-- Supprimer -->
                    <form method="POST" style="display:inline" onsubmit="return confirm('Supprimer ce caissier ?')">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="delete">
                      <input type="hidden" name="token" value="<?= htmlspecialchars($token_delete) ?>">
                      <button class="btn-ghost btn-sm" type="submit" style="color:#FCA5A5">🗑</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </section>

    <!-- Info -->
    <div class="alert info" style="margin-top:20px">
      <strong>ℹ️ Droits du caissier :</strong>
      <ul style="margin-top:8px;margin-left:20px">
        <li>🔍 Rechercher des clients (nom ou numéro de compte)</li>
        <li>💰 Consulter le solde et l'historique récent</li>
        <li>➕➖ Effectuer des dépôts et retraits</li>
        <li>❌ <strong>Ne peut PAS</strong> modifier les profils clients</li>
        <li>❌ <strong>Ne peut PAS</strong> créer d'autres caissiers ou produits</li>
      </ul>
    </div>

  </main>
</div>

<!-- Modal reset mot de passe -->
<div id="modalReset" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:9999;align-items:center;justify-content:center">
  <div style="background:var(--card);border:1px solid var(--border-strong);border-radius:20px;padding:32px;max-width:460px;width:90%">
    <h3 style="margin-bottom:20px">🔑 Réinitialiser mot de passe</h3>
    <p class="muted" id="resetNom" style="margin-bottom:20px"></p>

    <form method="POST" id="formReset" novalidate>
      <?= csrfField() ?>
      <input type="hidden" name="action" value="reset_pwd">
      <input type="hidden" name="token" id="resetToken">
      <input type="hidden" name="id_caissier_temp" id="resetId">

      <label class="field"><span>Nouveau mot de passe</span>
        <input type="password" name="new_password" required minlength="8" id="pwdReset">
      </label>

      <div style="display:flex;gap:10px;margin-top:20px">
        <button class="btn-gold" type="submit" style="flex:1">Réinitialiser</button>
        <button class="btn-ghost" type="button" onclick="fermerReset()">Annuler</button>
      </div>
    </form>
  </div>
</div>

<footer class="atf-footer">© <?= date('Y') ?> AFRICAN TRUST FUND</footer>

<script>
/* Barre de progression */
window.addEventListener('scroll', () => {
  const h = document.documentElement.scrollHeight - window.innerHeight;
  document.getElementById('topProgress').style.width = (window.scrollY / h) * 100 + '%';
});

/* Modal reset */
function ouvrirReset(id, nom, token) {
  document.getElementById('resetNom').innerHTML = 'Caissier : <strong>' + nom + '</strong>';
  document.getElementById('resetToken').value = token;
  document.getElementById('resetId').value = id;
  document.getElementById('modalReset').style.display = 'flex';
  setTimeout(() => document.getElementById('pwdReset').focus(), 100);
}
function fermerReset() {
  document.getElementById('modalReset').style.display = 'none';
  document.getElementById('formReset').reset();
}

/* Fermer modal sur clic externe */
document.getElementById('modalReset').addEventListener('click', (e) => {
  if (e.target.id === 'modalReset') fermerReset();
});
</script>
<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>  