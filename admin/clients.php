<?php
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
requireRole('admin');

$pdo = Database::getConnection();
$admin_id = (int)$_SESSION['admin_id'];

/* ============================================================
   TRAITEMENT DES ACTIONS SÉCURISÉES (Voir / PDF)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifyCsrf($_POST['csrf'] ?? null)) {
        logAction($pdo, 'admin', $admin_id, 'SECURITY_DENIED', 'CSRF invalide sur clients.php');
        refuserAcces("Jeton de sécurité invalide.");
    }

    /* --- Action : VOIR la fiche client --- */
    if (($_POST['action'] ?? '') === 'view') {
        $id_client = (int)($_POST['view_client_id'] ?? 0);
        if ($id_client > 0) {
            logAction($pdo, 'admin', $admin_id, 'CLIENT_VIEW_REQUEST', "Client #$id_client");
            redirigerSecurise('client', $id_client, url('admin/client_details.php'));
        }
    }

    /* --- Action : VOIR le relevé PDF --- */
    if (($_POST['action'] ?? '') === 'pdf') {
        $id_client = (int)($_POST['view_client_id'] ?? 0);
        if ($id_client > 0) {
            logAction($pdo, 'admin', $admin_id, 'RELEVE_PDF_REQUEST', "Client #$id_client");
            $_SESSION['one_shot_pdf_client_id'] = $id_client;
            header('Location: ' . url('admin/releve_pdf.php'));
            exit;
        }
    }
}

/* ============================================================
   RECHERCHE
   ============================================================ */
$search = trim($_GET['q'] ?? '');
$sql = "SELECT * FROM Client";
$params = [];

if ($search !== '') {
    $sql .= " WHERE nom LIKE ? OR postnom LIKE ? OR prenom LIKE ? OR numero_compte LIKE ? OR numero_phone LIKE ?";
    $like = "%$search%";
    $params = [$like, $like, $like, $like, $like];
}
$sql .= " ORDER BY created_at DESC LIMIT 100";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$clients = $stmt->fetchAll();

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Clients — ATF Admin</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/admin-premium.css">
</head>
<body class="dark-auth">

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

    <div class="welcome-head">
      <div>
        <h2>👥 Gestion des clients</h2>
        <p class="sub"><?= count($clients) ?> client(s) — Toutes les actions sont sécurisées par token</p>
      </div>
      <form method="GET" style="display:flex;gap:10px;max-width:400px">
        <input type="text" name="q" placeholder="🔍 Rechercher..."
               value="<?= htmlspecialchars($search) ?>">
        <button class="btn-gold" type="submit">Chercher</button>
      </form>
    </div>

    <section class="card animate-in">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Photo</th><th>Nom complet</th><th>Compte</th>
              <th>Téléphone</th><th>Solde</th><th>Statut</th><th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$clients): ?>
              <tr><td colspan="7" class="muted center" style="padding:30px">Aucun client.</td></tr>
            <?php else: foreach ($clients as $c): ?>
              <tr>
                <td>
                  <?php if (!empty($c['photo_profil']) && file_exists(__DIR__ . '/../' . $c['photo_profil'])): ?>
                    <img src="<?= $base ?>/<?= htmlspecialchars($c['photo_profil']) ?>"
                         style="width:40px;height:40px;border-radius:50%;object-fit:cover;border:2px solid var(--gold)">
                  <?php else: ?>
                    <div style="width:40px;height:40px;border-radius:50%;background:var(--gold-soft);color:var(--gold);display:flex;align-items:center;justify-content:center;font-weight:800">
                      <?= strtoupper(mb_substr($c['nom'], 0, 1)) ?>
                    </div>
                  <?php endif; ?>
                </td>
                <td>
                  <strong><?= htmlspecialchars($c['nom'] . ' ' . $c['postnom']) ?></strong><br>
                  <small class="muted"><?= htmlspecialchars($c['prenom']) ?></small>
                </td>
                <td><code><?= htmlspecialchars($c['numero_compte']) ?></code></td>
                <td><?= htmlspecialchars($c['numero_phone']) ?></td>
                <td><strong style="color:var(--gold)">
                  <?= number_format((float)$c['solde'], 2, ',', ' ') ?> <?= htmlspecialchars($c['devise']) ?>
                </strong></td>
                <td>
                  <span class="badge <?= $c['statut'] === 'actif' ? 'green' : 'red' ?>">
                    <?= htmlspecialchars($c['statut']) ?>
                  </span>
                </td>
                <td>
                  <div style="display:flex;gap:6px;flex-wrap:wrap">
                    <!-- ✅ VOIR : ID en POST + token -->
                    <form method="POST" style="display:inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="view">
                      <input type="hidden" name="view_client_id" value="<?= (int)$c['id_client'] ?>">
                      <button class="btn-gold btn-sm" type="submit">👁 Voir</button>
                    </form>

                    <!-- ✅ PDF : ID en POST + token -->
                    <form method="POST" style="display:inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="pdf">
                      <input type="hidden" name="view_client_id" value="<?= (int)$c['id_client'] ?>">
                      <button class="btn-ghost btn-sm" type="submit">📄 PDF</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>
</div>

<footer class="atf-footer">© <?= date('Y') ?> AFRICAN TRUST FUND</footer>
<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>