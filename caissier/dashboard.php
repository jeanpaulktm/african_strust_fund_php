<?php
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/interets.php';
requireRole('caissier');

$pdo = Database::getConnection();
$caissier_id = (int)$_SESSION['caissier_id'];

/* ============================================================
   RECHERCHE CLIENT (via GET q — pas d'ID sensible)
   ============================================================ */
$q = trim($_GET['q'] ?? '');
$clients_trouves = [];
$client_selectionne = null;

if ($q !== '' && mb_strlen($q) >= 2) {
    $stmt = $pdo->prepare("
        SELECT id_client, nom, postnom, prenom, numero_compte, numero_phone,
               solde, devise, statut, photo_profil, created_at
        FROM Client
        WHERE nom LIKE ? OR postnom LIKE ? OR prenom LIKE ? OR numero_compte LIKE ?
        ORDER BY nom ASC
        LIMIT 20
    ");
    $like = "%$q%";
    $stmt->execute([$like, $like, $like, $like]);
    $clients_trouves = $stmt->fetchAll();

    logAction($pdo, 'caissier', $caissier_id, 'CLIENT_SEARCH', "Recherche: '$q'");
}

/* ============================================================
   SÉLECTION D'UN CLIENT (POST avec token)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'select_client') {
    if (!verifyCsrf($_POST['csrf'] ?? null)) refuserAcces("CSRF invalide.");

    $id_client_sel = (int)($_POST['id_client'] ?? 0);

    if ($id_client_sel > 0) {
        // Vérifier que le client existe
        $chk = $pdo->prepare("SELECT id_client FROM Client WHERE id_client = ?");
        $chk->execute([$id_client_sel]);

        if ($chk->fetchColumn()) {
            // Stocker en session one-shot
            $_SESSION['one_shot_client_id'] = $id_client_sel;

            // Token pour opérations
            $token_depot   = genererTokenOperation($id_client_sel, 'depot', 10000000);
            $token_retrait = genererTokenOperation($id_client_sel, 'retrait', 0);

            $_SESSION['op_token_depot']   = $token_depot;
            $_SESSION['op_token_retrait'] = $token_retrait;

            logAction($pdo, 'caissier', $caissier_id, 'CLIENT_SELECT', "Client #$id_client_sel");

            header('Location: ' . url('caissier/transaction.php'));
            exit;
        }
    }
}

/* ============================================================
   STATISTIQUES CAISSIER
   ============================================================ */
$stats = [
    'operations_aujourdhui' => (int)$pdo->query("
        SELECT COUNT(*) FROM Transactions 
        WHERE id_caissier = $caissier_id 
          AND DATE(date_transaction) = CURDATE()
    ")->fetchColumn(),

    'volume_aujourdhui' => (float)$pdo->query("
        SELECT COALESCE(SUM(montant),0) FROM Transactions 
        WHERE id_caissier = $caissier_id 
          AND DATE(date_transaction) = CURDATE()
    ")->fetchColumn(),

    'total_operations' => (int)$pdo->query("
        SELECT COUNT(*) FROM Transactions WHERE id_caissier = $caissier_id
    ")->fetchColumn(),

    'volume_total' => (float)$pdo->query("
        SELECT COALESCE(SUM(montant),0) FROM Transactions WHERE id_caissier = $caissier_id
    ")->fetchColumn(),
];

/* ============================================================
   DERNIÈRES OPÉRATIONS DU CAISSIER
   ============================================================ */
$stmt = $pdo->prepare("
    SELECT t.*, c.nom, c.postnom, c.numero_compte
    FROM Transactions t
    JOIN Client c ON c.id_client = t.id_client
    WHERE t.id_caissier = ?
    ORDER BY t.date_transaction DESC
    LIMIT 10
");
$stmt->execute([$caissier_id]);
$dernieres_ops = $stmt->fetchAll();

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Espace Caissier — AFRICAN TRUST FUND</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/admin-premium.css">
</head>
<body class="dark-auth">

<div class="top-progress" id="topProgress"></div>
<?php require __DIR__ . '/../includes/header.php'; ?>

<div class="admin-layout">
  <aside class="admin-sidebar animate-left">
    <h3>💼 Guichet</h3>
    <a href="<?= $base ?>/caissier/dashboard.php" class="active">
      <span class="ico">🔍</span> Rechercher client
    </a>
    <a href="<?= $base ?>/caissier/historique.php">
      <span class="ico">📋</span> Mes opérations
    </a>
    
<a class="btn-gold" href="<?= $base ?>/caissier/rapport_journalier.php">
  📊 Imprimer le rapport du jour
</a>
    <a href="<?= $base ?>/auth/logout.php">
      <span class="ico">🚪</span> Déconnexion
    </a>

    <div style="padding:16px 24px;margin-top:20px;border-top:1px solid var(--border)">
      <div style="color:var(--muted);font-size:.72rem;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px">Session</div>
      <div class="user-chip" style="width:100%;justify-content:flex-start">
        <span class="dot"></span>
        <?= htmlspecialchars($_SESSION['caissier_nom'] ?? 'Caissier') ?>
      </div>
    </div>
  </aside>

  <main class="admin-main">

    <!-- Bannière -->
    <div class="welcome-banner">
      <h2 class="animate-in delay-1">
        Bonjour <span style="color:var(--gold)"><?= htmlspecialchars($_SESSION['caissier_nom'] ?? 'Caissier') ?></span> 💼
      </h2>
      <p class="sub animate-in delay-2">
        Recherchez un client pour effectuer un dépôt ou un retrait.
      </p>
      <div class="badges animate-in delay-3">
        <span class="badge gold">📅 <?= date('d/m/Y') ?></span>
        <span class="badge green">✅ <?= $stats['operations_aujourdhui'] ?> opération(s) aujourd'hui</span>
        <span class="badge blue">💰 <?= number_format($stats['volume_aujourdhui'], 0, ',', ' ') ?> traités</span>
      </div>
    </div>

    <!-- KPIs -->
    <section class="kpi-grid">
      <div class="stat-block gold animate-in delay-1">
        <div class="ico">📅</div>
        <div class="meta">
          <strong><?= $stats['operations_aujourdhui'] ?></strong>
          <span>Opérations aujourd'hui</span>
        </div>
      </div>
      <div class="stat-block green animate-in delay-2">
        <div class="ico">💰</div>
        <div class="meta">
          <strong><?= number_format($stats['volume_aujourdhui'], 0, ',', ' ') ?></strong>
          <span>Volume aujourd'hui</span>
        </div>
      </div>
      <div class="stat-block blue animate-in delay-3">
        <div class="ico">📊</div>
        <div class="meta">
          <strong><?= $stats['total_operations'] ?></strong>
          <span>Total opérations</span>
        </div>
      </div>
      <div class="stat-block gold animate-in delay-4">
        <div class="ico">📈</div>
        <div class="meta">
            
          <strong><?= number_format($stats['volume_total'], 0, ',', ' ') ?></strong>
          <span>Volume total</span>
        </div>
      </div>
    </section>

    <!-- Barre de recherche principale -->
    <section class="card animate-in delay-2" style="background:linear-gradient(135deg,rgba(13,27,94,.5),rgba(30,41,59,.4));border-color:var(--border-strong)">
      <h3 style="margin-bottom:16px;text-align:center">🔍 Rechercher un client</h3>

      <form method="GET" style="display:flex;gap:12px;flex-wrap:wrap">
        <input type="text" name="q" 
               placeholder="Nom, post-nom, prénom ou numéro de compte (ex: ATF-2025-XXXXXX)"
               value="<?= htmlspecialchars($q) ?>"
               style="flex:1;min-width:240px;padding:16px 20px;font-size:1rem" required autofocus>
        <button class="btn-gold" type="submit" style="padding:16px 32px">🔍 Rechercher</button>
      </form>

      <p class="muted center" style="margin-top:12px;font-size:.82rem">
        💡 Tapez au moins 2 caractères — recherche insensible à la casse
      </p>
    </section>

    <!-- Résultats de recherche -->
    <?php if ($q !== '' && mb_strlen($q) >= 2): ?>
      <section class="card animate-in delay-3">
        <h3 style="margin-bottom:16px">
          📋 <?= count($clients_trouves) ?> client(s) trouvé(s) pour « <?= htmlspecialchars($q) ?> »
        </h3>

        <?php if (!$clients_trouves): ?>
          <div class="alert info">
            Aucun client trouvé. Vérifiez le nom ou le numéro de compte.
          </div>
        <?php else: ?>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>Photo</th><th>Nom complet</th><th>Numéro de compte</th>
                  <th>Téléphone</th><th style="text-align:right">Solde</th>
                  <th>Statut</th><th>Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($clients_trouves as $c):
                  $init = strtoupper(mb_substr($c['nom'], 0, 1) . mb_substr($c['prenom'], 0, 1));
                ?>
                  <tr>
                    <td>
                      <?php if (!empty($c['photo_profil']) && file_exists(__DIR__ . '/../' . $c['photo_profil'])): ?>
                        <img src="<?= $base ?>/<?= htmlspecialchars($c['photo_profil']) ?>"
                             style="width:42px;height:42px;border-radius:50%;object-fit:cover;border:2px solid var(--gold)">
                      <?php else: ?>
                        <div style="width:42px;height:42px;border-radius:50%;background:var(--gold-soft);color:var(--gold);display:flex;align-items:center;justify-content:center;font-weight:800">
                          <?= $init ?>
                        </div>
                      <?php endif; ?>
                    </td>
                    <td>
                      <strong><?= htmlspecialchars($c['nom'] . ' ' . $c['postnom']) ?></strong><br>
                      <small class="muted"><?= htmlspecialchars($c['prenom']) ?></small>
                    </td>
                    <td><code><?= htmlspecialchars($c['numero_compte']) ?></code></td>
                    <td><?= htmlspecialchars($c['numero_phone']) ?></td>
                    <td style="text-align:right">
                      <strong style="color:var(--gold);font-size:1.05rem">
                        <?= number_format((float)$c['solde'], 2, ',', ' ') ?>
                      </strong>
                      <br>
                      <small class="muted"><?= htmlspecialchars($c['devise']) ?></small>
                    </td>
                    <td>
                      <span class="badge <?= $c['statut'] === 'actif' ? 'green' : 'red' ?>">
                        <?= htmlspecialchars($c['statut']) ?>
                      </span>
                    </td>
                    <td>
                      <!-- ✅ Sélection client via POST + token -->
                      <form method="POST" style="display:inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="select_client">
                        <input type="hidden" name="id_client" value="<?= (int)$c['id_client'] ?>">
                        <button class="btn-gold btn-sm" type="submit">💰 Traiter</button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <!-- Mes dernières opérations -->
    <section class="card animate-in delay-4">
      <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
        <h3 style="margin:0">📋 Mes 10 dernières opérations</h3>
        <a class="btn-ghost btn-sm" href="<?= $base ?>/caissier/historique.php">Voir tout →</a>
      </div>

      <?php if (!$dernieres_ops): ?>
        <p class="muted center" style="padding:20px">Aucune opération effectuée.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Référence</th><th>Client</th><th>Type</th>
                <th style="text-align:right">Montant</th><th>Date</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($dernieres_ops as $op): ?>
                <tr>
                  <td><code><?= htmlspecialchars($op['reference']) ?></code></td>
                  <td>
                    <?= htmlspecialchars($op['nom'] . ' ' . $op['postnom']) ?><br>
                    <small class="muted"><?= htmlspecialchars($op['numero_compte']) ?></small>
                  </td>
                  <td>
                    <span class="tag <?= strtolower($op['type_transaction']) ?>">
                      <?= htmlspecialchars($op['type_transaction']) ?>
                    </span>
                  </td>
                  <td style="text-align:right;font-weight:700;color:<?= $op['type_transaction']==='Retrait' ? '#FCA5A5' : 'var(--green)' ?>">
                    <?= $op['type_transaction']==='Retrait' ? '−' : '+' ?>
                    <?= number_format((float)$op['montant'], 2, ',', ' ') ?>
                  </td>
                  <td><?= date('d/m/Y H:i', strtotime($op['date_transaction'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <!-- Restrictions -->
    <div class="alert warning" style="margin-top:20px">
      <strong>🔒 Rappel des restrictions :</strong>
      <ul style="margin-top:8px;margin-left:20px">
        <li>Vous ne pouvez PAS modifier les informations d'un client</li>
        <li>Vous ne pouvez PAS supprimer de données</li>
        <li>Toutes vos opérations sont <strong>tracées et auditées</strong></li>
      </ul>
    </div>

  </main>
</div>

<footer class="atf-footer">© <?= date('Y') ?> AFRICAN TRUST FUND — Guichet sécurisé</footer>

<script>
window.addEventListener('scroll', () => {
  const h = document.documentElement.scrollHeight - window.innerHeight;
  document.getElementById('topProgress').style.width = (window.scrollY / h) * 100 + '%';
});
</script>
<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>