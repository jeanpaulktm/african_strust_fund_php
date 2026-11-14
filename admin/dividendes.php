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
   CALCULER LES DIVIDENDES POUR UNE ANNÉE
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'calculer') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) throw new RuntimeException("Jeton invalide.");

        $annee = (int)($_POST['annee'] ?? date('Y'));
        $benefice = (float)str_replace([' ', ','], ['', '.'], $_POST['benefice'] ?? '0');
        $taux = (float)($_POST['taux_distribution'] ?? 40);

        if ($benefice <= 0) throw new RuntimeException("Le bénéfice doit être positif.");
        if ($taux <= 0 || $taux > 100) throw new RuntimeException("Taux invalide (0-100%).");

        /* Mettre à jour le capital avec bénéfice et taux */
        $pdo->prepare("
            UPDATE Capital_ATF 
            SET benefice_annuel = ?, taux_distribution = ?
            WHERE annee_fiscale = ?
        ")->execute([$benefice, $taux, $annee]);

        $resultat = calculerDividendes($pdo, $annee, $benefice);

        logAction($pdo, 'admin', $admin_id, 'DIVIDENDES_CALCULES',
            "Année $annee | Bénéfice: $benefice | Distribué: {$resultat['montant_a_distribuer']}");

        $_SESSION['flash_success'] = "✅ Dividendes calculés pour $annee<br>
            <strong>Bénéfice total :</strong> " . number_format($benefice, 2, ',', ' ') . " CDF<br>
            <strong>Montant distribué (" . $taux . "%) :</strong> " . number_format($resultat['montant_a_distribuer'], 2, ',', ' ') . " CDF<br>
            <strong>Actionnaires crédités :</strong> " . count($resultat['actionnaires']);

        header('Location: ' . url('admin/dividendes.php'));
        exit;

    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

/* ============================================================
   PAYER UN DIVIDENDE
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'payer') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) throw new RuntimeException("Jeton invalide.");

        $id_dividende = (int)($_POST['id_dividende'] ?? 0);
        $mode = in_array($_POST['mode_paiement'] ?? '', ['especes','virement','credit_compte','cheque'])
                ? $_POST['mode_paiement'] : 'credit_compte';

        $resultat = payerDividende($pdo, $id_dividende, $mode, $admin_id, "Paiement manuel admin");

        logAction($pdo, 'admin', $admin_id, 'DIVIDENDE_PAYE',
            "Dividende #$id_dividende | Réf: {$resultat['reference']}");

        $_SESSION['flash_success'] = "✅ Dividende payé !<br>
            <strong>Référence :</strong> {$resultat['reference']}<br>
            <strong>Montant :</strong> " . number_format($resultat['montant'], 2, ',', ' ') . " {$resultat['devise']}";

        header('Location: ' . url('admin/dividendes.php'));
        exit;

    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

/* ============================================================
   PAYER TOUS LES DIVIDENDES D'UNE ANNÉE
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'payer_tous') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) throw new RuntimeException("Jeton invalide.");
        $annee = (int)($_POST['annee'] ?? date('Y'));

        $stmt = $pdo->prepare("
            SELECT id_dividende FROM Dividendes 
            WHERE annee_fiscale = ? AND statut IN ('calcule','valide')
        ");
        $stmt->execute([$annee]);
        $liste = $stmt->fetchAll();

        $total = 0;
        foreach ($liste as $d) {
            try {
                $r = payerDividende($pdo, (int)$d['id_dividende'], 'credit_compte', $admin_id, "Paiement groupé $annee");
                $total += $r['montant'];
            } catch (Throwable $e) {
                error_log('[ATF DIV PAY] ' . $e->getMessage());
            }
        }

        logAction($pdo, 'admin', $admin_id, 'DIVIDENDES_PAYES_ALL',
            "Année $annee | Total: $total");

        $_SESSION['flash_success'] = "✅ " . count($liste) . " dividende(s) payé(s) pour $annee<br>
            <strong>Total versé :</strong> " . number_format($total, 2, ',', ' ') . " CDF";

        header('Location: ' . url('admin/dividendes.php'));
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
$annee_filtre = (int)($_GET['annee'] ?? date('Y'));
if ($annee_filtre < 2020 || $annee_filtre > 2100) $annee_filtre = (int)date('Y');

$stmt = $pdo->prepare("
    SELECT d.*, a.numero_actionnaire, a.nom, a.postnom, a.prenom
    FROM Dividendes d
    JOIN Actionnaires a ON a.id_actionnaire = d.id_actionnaire
    WHERE d.annee_fiscale = ?
    ORDER BY d.montant_dividende DESC
");
$stmt->execute([$annee_filtre]);
$dividendes = $stmt->fetchAll();

/* Statistiques */
$stmt = $pdo->prepare("
    SELECT 
        COUNT(*) AS total,
        COALESCE(SUM(montant_dividende),0) AS total_montant,
        SUM(CASE WHEN statut='paye' THEN 1 ELSE 0 END) AS payes,
        SUM(CASE WHEN statut IN ('calcule','valide') THEN 1 ELSE 0 END) AS a_payer
    FROM Dividendes WHERE annee_fiscale = ?
");
$stmt->execute([$annee_filtre]);
$stats = $stmt->fetch();

$capital = getCapitalCourant($pdo, $annee_filtre);

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dividendes — ATF Admin</title>
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
    <a href="<?= $base ?>/admin/actionnaires.php"><span class="ico">🏛️</span> Actionnaires</a>
    <a href="<?= $base ?>/admin/parts_sociales.php"><span class="ico">📜</span> Parts sociales</a>
    <a href="<?= $base ?>/admin/dividendes.php" class="active"><span class="ico">💰</span> Dividendes</a>
    <a href="<?= $base ?>/admin/logs.php"><span class="ico">📜</span> Journal</a>
    <a href="<?= $base ?>/auth/logout.php"><span class="ico">🚪</span> Déconnexion</a>
  </aside>

  <main class="admin-main">

    <div class="welcome-head">
      <div>
        <h2>💰 Gestion des dividendes</h2>
        <p class="sub">Année fiscale <?= $annee_filtre ?></p>
      </div>
      <form method="GET" style="display:flex;gap:10px;align-items:center">
        <label style="color:var(--muted);font-size:.85rem">Année :</label>
        <select name="annee" onchange="this.form.submit()" style="padding:10px 16px;background:var(--bg-1);border:1px solid var(--border);border-radius:10px;color:var(--text);font-weight:700">
          <?php for ($a = (int)date('Y'); $a >= 2024; $a--): ?>
            <option value="<?= $a ?>" <?= $a === $annee_filtre ? 'selected' : '' ?>><?= $a ?></option>
          <?php endfor; ?>
        </select>
      </form>
    </div>

    <?php if ($success): ?><div class="alert success"><?= $success ?></div><?php endif; ?>
    <?php if ($errors): ?><div class="alert error"><ul><?php foreach ($errors as $e) echo "<li>" . htmlspecialchars($e) . "</li>"; ?></ul></div><?php endif; ?>

    <section class="kpi-grid">
      <div class="stat-block gold animate-in delay-1">
        <div class="ico">👥</div>
        <div class="meta"><strong><?= (int)$stats['total'] ?></strong><span>Actionnaires</span></div>
      </div>
      <div class="stat-block blue animate-in delay-2">
        <div class="ico">💰</div>
        <div class="meta"><strong><?= number_format((float)$stats['total_montant'], 0, ',', ' ') ?></strong><span>Total dividendes</span></div>
      </div>
      <div class="stat-block green animate-in delay-3">
        <div class="ico">✅</div>
        <div class="meta"><strong><?= (int)$stats['payes'] ?></strong><span>Payés</span></div>
      </div>
      <div class="stat-block red animate-in delay-4">
        <div class="ico">⏳</div>
        <div class="meta"><strong><?= (int)$stats['a_payer'] ?></strong><span>À payer</span></div>
      </div>
    </section>

    <!-- CALCUL DES DIVIDENDES -->
    <section class="card animate-in delay-1">
      <h3 style="margin-bottom:16px">🧮 Calculer les dividendes <?= $annee_filtre ?></h3>

      <?php if ($capital): ?>
        <div class="info-banner" style="margin-bottom:16px">
          Capital social : <strong><?= number_format((float)$capital['capital_social'], 0, ',', ' ') ?> CDF</strong> · 
          Parts vendues : <strong><?= number_format((int)$capital['nombre_parts_vendues'], 0, ',', ' ') ?></strong> · 
          Bénéfice actuel : <strong><?= number_format((float)$capital['benefice_annuel'], 0, ',', ' ') ?> CDF</strong>
        </div>
      <?php endif; ?>

      <form method="POST" novalidate>
        <?= csrfField() ?>
        <input type="hidden" name="action" value="calculer">
        <input type="hidden" name="annee" value="<?= $annee_filtre ?>">

        <div class="grid-2">
          <label class="field">
            <span>Bénéfice annuel (CDF) *</span>
            <input type="text" name="benefice" required placeholder="Ex: 20000000"
                   value="<?= $capital ? (float)$capital['benefice_annuel'] : '' ?>">
          </label>
          <label class="field">
            <span>Taux de distribution (%) *</span>
            <input type="number" name="taux_distribution" required min="1" max="100" step="0.5"
                   value="<?= $capital ? (float)$capital['taux_distribution'] : '40' ?>">
          </label>
        </div>

        <div class="info-banner" style="background:rgba(16,185,129,.08);border-color:rgba(16,185,129,.25);color:#6EE7B7">
          💡 <strong>Calcul :</strong> Montant distribué = Bénéfice × Taux. Chaque actionnaire reçoit 
          (Montant distribué × ses parts) / total parts.
        </div>

        <button class="btn-gold full" type="submit" onclick="return confirm('Calculer les dividendes ? Cette action est irréversible.')">
          🧮 Calculer les dividendes
        </button>
      </form>
    </section>

    <!-- BOUTON PAYER TOUS -->
    <?php if ((int)$stats['a_payer'] > 0): ?>
      <form method="POST" style="margin-bottom:20px">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="payer_tous">
        <input type="hidden" name="annee" value="<?= $annee_filtre ?>">
        <button class="btn-green full" type="submit" onclick="return confirm('Payer TOUS les dividendes en attente ?')">
          💸 Payer tous les <?= (int)$stats['a_payer'] ?> dividendes en attente
        </button>
      </form>
    <?php endif; ?>

    <!-- LISTE -->
    <section class="card animate-in delay-2">
      <h3 style="margin-bottom:16px">📋 Liste des dividendes <?= $annee_filtre ?></h3>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Actionnaire</th>
              <th style="text-align:right">Parts</th>
              <th style="text-align:right">Montant</th>
              <th>Statut</th>
              <th>Date calcul</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$dividendes): ?>
              <tr><td colspan="6" class="muted center" style="padding:30px">Aucun dividende calculé pour <?= $annee_filtre ?>.</td></tr>
            <?php else: foreach ($dividendes as $d): ?>
              <tr>
                <td>
                  <strong><?= htmlspecialchars($d['nom'] . ' ' . $d['postnom']) ?></strong><br>
                  <small class="muted"><?= htmlspecialchars($d['numero_actionnaire']) ?></small>
                </td>
                <td style="text-align:right"><?= number_format((int)$d['nombre_parts'], 0, ',', ' ') ?></td>
                <td style="text-align:right;color:var(--green);font-weight:700">
                  <?= number_format((float)$d['montant_dividende'], 2, ',', ' ') ?> <?= $d['devise'] ?>
                </td>
                <td>
                  <span class="badge <?= $d['statut']==='paye' ? 'green' : ($d['statut']==='calcule' ? 'gold' : 'blue') ?>">
                    <?= htmlspecialchars($d['statut']) ?>
                  </span>
                </td>
                <td><?= date('d/m/Y', strtotime($d['date_calcul'])) ?></td>
                <td>
                  <?php if ($d['statut'] !== 'paye'): ?>
                    <form method="POST" style="display:inline">
                      <?= csrfField() ?>
                      <input type="hidden" name="action" value="payer">
                      <input type="hidden" name="id_dividende" value="<?= (int)$d['id_dividende'] ?>">
                      <input type="hidden" name="mode_paiement" value="credit_compte">
                      <button class="btn-green btn-sm" type="submit">💸 Payer</button>
                    </form>
                  <?php else: ?>
                    <span class="muted" style="font-size:.75rem">
                      Payé le <?= date('d/m/Y', strtotime($d['date_paiement'])) ?>
                    </span>
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