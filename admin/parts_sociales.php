<?php
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/actionnaires.php';
requireRole('admin');

$pdo = Database::getConnection();
$admin_id = (int)$_SESSION['admin_id'];

$errors = [];
$success = '';

/* ============================================================
   VALIDER UNE SOUSCRIPTION EN ATTENTE
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'valider') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) throw new RuntimeException("Jeton invalide.");
        $id_part = (int)($_POST['id_part'] ?? 0);

        $stmt = $pdo->prepare("SELECT * FROM Parts_Sociales WHERE id_part = ? AND statut = 'en_attente'");
        $stmt->execute([$id_part]);
        $part = $stmt->fetch();

        if (!$part) throw new RuntimeException("Souscription introuvable ou déjà traitée.");

        $pdo->beginTransaction();

        $pdo->prepare("
            UPDATE Parts_Sociales 
            SET statut = 'valide', valide_par = ?, date_validation = NOW()
            WHERE id_part = ?
        ")->execute([$admin_id, $id_part]);

        /* Mettre à jour l'actionnaire */
        $colonne = $part['devise'] === 'USD' ? 'capital_investi_usd' : 'capital_investi_cdf';
        $pdo->prepare("
            UPDATE Actionnaires 
            SET nombre_parts = nombre_parts + ?, $colonne = $colonne + ?
            WHERE id_actionnaire = ?
        ")->execute([(int)$part['nombre_parts'], (float)$part['montant_total'], (int)$part['id_actionnaire']]);

        /* Mettre à jour le capital */
        $pdo->prepare("
            UPDATE Capital_ATF 
            SET nombre_parts_vendues = nombre_parts_vendues + ?,
                capital_social = capital_social + ?
            WHERE annee_fiscale = YEAR(NOW())
        ")->execute([(int)$part['nombre_parts'], (float)$part['montant_total']]);

        $pdo->commit();

        logAction($pdo, 'admin', $admin_id, 'PART_VALIDE', "Part #$id_part validée");
        $_SESSION['flash_success'] = "✅ Souscription validée !";
        header('Location: ' . url('admin/parts_sociales.php'));
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = $e->getMessage();
    }
}

/* ============================================================
   ANNULER UNE SOUSCRIPTION
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'annuler') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) throw new RuntimeException("Jeton invalide.");
        $id_part = (int)($_POST['id_part'] ?? 0);

        $pdo->prepare("UPDATE Parts_Sociales SET statut = 'annule' WHERE id_part = ?")
            ->execute([$id_part]);

        logAction($pdo, 'admin', $admin_id, 'PART_ANNULE', "Part #$id_part annulée");
        $_SESSION['flash_success'] = "Souscription annulée.";
        header('Location: ' . url('admin/parts_sociales.php'));
        exit;

    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$success = '';
if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

/* ============================================================
   FILTRES
   ============================================================ */
$filtre_statut = in_array($_GET['statut'] ?? '', ['en_attente','valide','annule','transfere']) ? $_GET['statut'] : '';

$sql = "SELECT p.*, a.nom, a.postnom, a.prenom, a.numero_actionnaire
        FROM Parts_Sociales p
        JOIN Actionnaires a ON a.id_actionnaire = p.id_actionnaire";
$params = [];

if ($filtre_statut !== '') {
    $sql .= " WHERE p.statut = ?";
    $params[] = $filtre_statut;
}
$sql .= " ORDER BY p.date_souscription DESC LIMIT 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$parts = $stmt->fetchAll();

/* Statistiques */
$stats = [
    'total'      => (int)$pdo->query("SELECT COUNT(*) FROM Parts_Sociales")->fetchColumn(),
    'en_attente' => (int)$pdo->query("SELECT COUNT(*) FROM Parts_Sociales WHERE statut='en_attente'")->fetchColumn(),
    'validees'   => (int)$pdo->query("SELECT COUNT(*) FROM Parts_Sociales WHERE statut='valide'")->fetchColumn(),
    'total_parts'=> (int)$pdo->query("SELECT COALESCE(SUM(nombre_parts),0) FROM Parts_Sociales WHERE statut='valide'")->fetchColumn(),
];

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Parts sociales — ATF Admin</title>
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
    <a href="<?= $base ?>/admin/caissiers.php"><span class="ico">💼</span> Caissiers</a>
    <a href="<?= $base ?>/admin/actionnaires.php"><span class="ico">🏛️</span> Actionnaires</a>
    <a href="<?= $base ?>/admin/parts_sociales.php" class="active"><span class="ico">📜</span> Parts sociales</a>
    <a href="<?= $base ?>/admin/dividendes.php"><span class="ico">💰</span> Dividendes</a>
    <a href="<?= $base ?>/admin/logs.php"><span class="ico">📜</span> Journal</a>
    <a href="<?= $base ?>/auth/logout.php"><span class="ico">🚪</span> Déconnexion</a>
  </aside>

  <main class="admin-main">

    <div class="welcome-head">
      <div>
        <h2>📜 Gestion des parts sociales</h2>
        <p class="sub"><?= $stats['total'] ?> mouvement(s) — <?= $stats['en_attente'] ?> en attente</p>
      </div>
    </div>

    <?php if ($success): ?><div class="alert success"><?= $success ?></div><?php endif; ?>
    <?php if ($errors): ?><div class="alert error"><ul><?php foreach ($errors as $e) echo "<li>" . htmlspecialchars($e) . "</li>"; ?></ul></div><?php endif; ?>

    <section class="kpi-grid">
      <div class="stat-block gold animate-in delay-1">
        <div class="ico">📜</div>
        <div class="meta"><strong><?= $stats['total'] ?></strong><span>Total mouvements</span></div>
      </div>
      <div class="stat-block red animate-in delay-2">
        <div class="ico">⏳</div>
        <div class="meta"><strong><?= $stats['en_attente'] ?></strong><span>En attente</span></div>
      </div>
      <div class="stat-block green animate-in delay-3">
        <div class="ico">✅</div>
        <div class="meta"><strong><?= $stats['validees'] ?></strong><span>Validées</span></div>
      </div>
      <div class="stat-block blue animate-in delay-4">
        <div class="ico">💼</div>
        <div class="meta"><strong><?= number_format($stats['total_parts'], 0, ',', ' ') ?></strong><span>Parts vendues</span></div>
      </div>
    </section>

    <div class="admin-tabs">
      <a href="?" class="admin-tab <?= $filtre_statut==='' ? 'active' : '' ?>">Tous</a>
      <a href="?statut=en_attente" class="admin-tab <?= $filtre_statut==='en_attente' ? 'active' : '' ?>">⏳ En attente</a>
      <a href="?statut=valide" class="admin-tab <?= $filtre_statut==='valide' ? 'active' : '' ?>">✅ Validées</a>
      <a href="?statut=annule" class="admin-tab <?= $filtre_statut==='annule' ? 'active' : '' ?>">❌ Annulées</a>
    </div>

    <section class="card animate-in delay-2">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Date</th>
              <th>Référence</th>
              <th>Actionnaire</th>
              <th style="text-align:right">Parts</th>
              <th style="text-align:right">Prix unit.</th>
              <th style="text-align:right">Montant</th>
              <th>Type</th>
              <th>Statut</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$parts): ?>
              <tr><td colspan="9" class="muted center" style="padding:30px">Aucun mouvement.</td></tr>
            <?php else: foreach ($parts as $p): ?>
              <tr>
                <td><?= date('d/m/Y H:i', strtotime($p['date_souscription'])) ?></td>
                <td><code><?= htmlspecialchars($p['reference']) ?></code></td>
                <td>
                  <strong><?= htmlspecialchars($p['nom'] . ' ' . $p['postnom']) ?></strong><br>
                  <small class="muted"><?= htmlspecialchars($p['numero_actionnaire']) ?></small>
                </td>
                <td style="text-align:right;font-weight:700"><?= number_format((int)$p['nombre_parts'], 0, ',', ' ') ?></td>
                <td style="text-align:right"><?= number_format((float)$p['prix_unitaire'], 0, ',', ' ') ?></td>
                <td style="text-align:right;color:var(--gold);font-weight:700">
                  <?= number_format((float)$p['montant_total'], 0, ',', ' ') ?> <?= $p['devise'] ?>
                </td>
                <td><span class="badge blue"><?= htmlspecialchars($p['type_mouvement']) ?></span></td>
                <td>
                  <span class="badge <?= $p['statut']==='valide' ? 'green' : ($p['statut']==='en_attente' ? 'gold' : 'red') ?>">
                    <?= htmlspecialchars($p['statut']) ?>
                  </span>
                </td>
                <td>
                  <?php if ($p['statut'] === 'en_attente'): ?>
                    <div style="display:flex;gap:6px">
                      <form method="POST" style="display:inline">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="valider">
                        <input type="hidden" name="id_part" value="<?= (int)$p['id_part'] ?>">
                        <button class="btn-green btn-sm" type="submit">✓ Valider</button>
                      </form>
                      <form method="POST" style="display:inline" onsubmit="return confirm('Annuler cette souscription ?')">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="annuler">
                        <input type="hidden" name="id_part" value="<?= (int)$p['id_part'] ?>">
                        <button class="btn-ghost btn-sm" type="submit" style="color:#FCA5A5">✕</button>
                      </form>
                    </div>
                  <?php else: ?>
                    <span class="muted" style="font-size:.75rem">—</span>
                  <?php endif; ?>
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
