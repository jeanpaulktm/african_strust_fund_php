<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/prets.php';
requireRole('admin');

$pdo = Database::getConnection();
$admin_id = (int)$_SESSION['admin_id'];

/* Filtres */
$filtre = $_GET['filtre'] ?? 'a_venir';
$recherche = trim($_GET['q'] ?? '');

$sql = "
    SELECT e.*, p.reference AS pret_ref, p.devise AS pret_devise, p.statut AS pret_statut,
           c.id_client, c.nom, c.postnom, c.prenom, c.numero_compte, c.numero_phone,
           pr.nom AS produit_nom,
           DATEDIFF(e.date_echeance, CURDATE()) AS jours_restants
    FROM Echeances_Pret e
    JOIN Prets p ON p.id_pret = e.id_pret
    JOIN Client c ON c.id_client = p.id_client
    JOIN Produits_Pret pr ON pr.id_produit = p.id_produit
    WHERE p.statut IN ('decaisse','en_cours','en_retard')
      AND e.statut IN ('en_attente','partiel','retard')
";
$params = [];

if ($filtre === 'aujourdhui') {
    $sql .= " AND DATE(e.date_echeance) = CURDATE()";
} elseif ($filtre === 'semaine') {
    $sql .= " AND e.date_echeance BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
} elseif ($filtre === 'mois') {
    $sql .= " AND e.date_echeance BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)";
} elseif ($filtre === 'retard') {
    $sql .= " AND e.date_echeance < CURDATE()";
} elseif ($filtre === 'a_venir') {
    $sql .= " AND e.date_echeance >= CURDATE()";
}

if ($recherche !== '') {
    $sql .= " AND (c.nom LIKE ? OR c.postnom LIKE ? OR c.prenom LIKE ? 
                   OR c.numero_compte LIKE ? OR p.reference LIKE ?)";
    $like = "%$recherche%";
    array_push($params, $like, $like, $like, $like, $like);
}

$sql .= " ORDER BY e.date_echeance ASC LIMIT 200";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$echeances = $stmt->fetchAll();

/* Stats */
$stats = [
    'aujourdhui' => (int)$pdo->query("
        SELECT COUNT(*) FROM Echeances_Pret e
        JOIN Prets p ON p.id_pret = e.id_pret
        WHERE p.statut IN ('decaisse','en_cours','en_retard')
          AND e.statut IN ('en_attente','partiel','retard')
          AND DATE(e.date_echeance) = CURDATE()
    ")->fetchColumn(),
    'semaine' => (int)$pdo->query("
        SELECT COUNT(*) FROM Echeances_Pret e
        JOIN Prets p ON p.id_pret = e.id_pret
        WHERE p.statut IN ('decaisse','en_cours','en_retard')
          AND e.statut IN ('en_attente','partiel','retard')
          AND e.date_echeance BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
    ")->fetchColumn(),
    'retard' => (int)$pdo->query("
        SELECT COUNT(*) FROM Echeances_Pret e
        JOIN Prets p ON p.id_pret = e.id_pret
        WHERE p.statut IN ('decaisse','en_cours','en_retard')
          AND e.statut IN ('en_attente','partiel','retard')
          AND e.date_echeance < CURDATE()
    ")->fetchColumn(),
    'montant_retard' => (float)$pdo->query("
        SELECT COALESCE(SUM(e.montant_total - e.montant_paye), 0) 
        FROM Echeances_Pret e
        JOIN Prets p ON p.id_pret = e.id_pret
        WHERE p.statut IN ('decaisse','en_cours','en_retard')
          AND e.statut IN ('en_attente','partiel','retard')
          AND e.date_echeance < CURDATE()
    ")->fetchColumn(),
];

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Échéances Prêts — ATF Admin</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/admin-premium.css">
<style>
.kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 24px; }
.stat-block { padding: 18px; border-radius: 16px; background: linear-gradient(145deg, var(--card), var(--card-2)); border: 1px solid var(--border); display: flex; gap: 12px; align-items: center; }
.stat-block .ico { font-size: 1.8rem; }
.stat-block .meta strong { display: block; font-size: 1.4rem; color: var(--gold); }
.stat-block .meta span { font-size: .72rem; color: var(--muted); text-transform: uppercase; }
.stat-block.red .meta strong { color: #FCA5A5; }
.stat-block.green .meta strong { color: #6EE7B7; }

.filters-tabs {
  display: flex;
  gap: 8px;
  margin-bottom: 20px;
  flex-wrap: wrap;
}
.filters-tabs a {
  padding: 10px 18px;
  border-radius: 999px;
  background: var(--card);
  border: 1px solid var(--border);
  color: var(--text);
  text-decoration: none;
  font-weight: 700;
  font-size: .85rem;
  transition: all .2s;
}
.filters-tabs a:hover { border-color: var(--gold); }
.filters-tabs a.active {
  background: linear-gradient(135deg, #F5B800, #D4AF37);
  color: #0B0E14;
  border-color: transparent;
}

.ech-table { width: 100%; border-collapse: collapse; background: linear-gradient(145deg, var(--card), var(--card-2)); border-radius: 14px; overflow: hidden; }
.ech-table th { padding: 12px; text-align: left; font-size: .72rem; color: var(--muted); text-transform: uppercase; border-bottom: 1px solid var(--border); background: rgba(11,14,20,.4); }
.ech-table td { padding: 14px 12px; border-bottom: 1px solid var(--border); font-size: .85rem; vertical-align: middle; }
.ech-table tr:hover { background: rgba(245,184,0,.03); }

.ech-row.retard { background: rgba(239,68,68,.04); }
.ech-row.retard td { border-bottom-color: rgba(239,68,68,.15); }
.ech-row.urgent { background: rgba(245,184,0,.04); }

.jours-badge {
  display: inline-block;
  padding: 4px 10px;
  border-radius: 999px;
  font-size: .72rem;
  font-weight: 800;
}
.jours-badge.ok { background: rgba(16,185,129,.15); color: #6EE7B7; }
.jours-badge.warn { background: rgba(245,184,0,.15); color: #FCD34D; }
.jours-badge.danger { background: rgba(239,68,68,.15); color: #FCA5A5; }

@media (max-width: 768px) {
  .ech-table thead { display: none; }
  .ech-table tbody tr { display: block; margin-bottom: 12px; padding: 14px; border: 1px solid var(--border); border-radius: 12px; background: var(--card); }
  .ech-table td { display: flex; justify-content: space-between; padding: 6px 0; border: none; text-align: right; }
  .ech-table td::before { content: attr(data-label); color: var(--muted); font-size: .7rem; text-transform: uppercase; font-weight: 700; text-align: left; flex: 1; }
}
</style>
</head>
<body class="dark-auth">

<?php require __DIR__ . '/../includes/header.php'; ?>

<div class="admin-layout">
  <aside class="admin-sidebar animate-left">
    <h3>📊 Navigation</h3>
    <a href="<?= $base ?>/admin/dashboard.php"><span class="ico">📊</span> Tableau de bord</a>
    <a href="<?= $base ?>/admin/prets.php"><span class="ico">💳</span> Prêts</a>
    <a href="<?= $base ?>/admin/prets_produits.php"><span class="ico">📋</span> Produits prêt</a>
    <a href="<?= $base ?>/admin/prets_echeances.php" class="active"><span class="ico">📅</span> Échéances</a>
    <a href="<?= $base ?>/admin/logs.php"><span class="ico">📜</span> Journal</a>
    <a href="<?= $base ?>/auth/logout.php"><span class="ico">🚪</span> Déconnexion</a>
  </aside>

  <main class="admin-main">
    <div class="welcome-head">
      <div>
        <h2>📅 Suivi des Échéances</h2>
        <p class="sub"><?= count($echeances) ?> échéance(s) — <?= $stats['retard'] ?> en retard</p>
      </div>
    </div>

    <!-- KPIs -->
    <section class="kpi-grid">
      <div class="stat-block green">
        <div class="ico">📅</div>
        <div class="meta"><strong><?= $stats['aujourdhui'] ?></strong><span>Aujourd'hui</span></div>
      </div>
      <div class="stat-block">
        <div class="ico">📆</div>
        <div class="meta"><strong><?= $stats['semaine'] ?></strong><span>7 jours</span></div>
      </div>
      <div class="stat-block red">
        <div class="ico">⚠️</div>
        <div class="meta"><strong><?= $stats['retard'] ?></strong><span>En retard</span></div>
      </div>
      <div class="stat-block red">
        <div class="ico">💰</div>
        <div class="meta"><strong><?= number_format($stats['montant_retard'], 0, ',', ' ') ?></strong><span>Montant en retard (CDF)</span></div>
      </div>
    </section>

    <!-- Filtres -->
    <div class="filters-tabs">
      <a href="?filtre=a_venir" class="<?= $filtre === 'a_venir' ? 'active' : '' ?>">🔮 À venir</a>
      <a href="?filtre=aujourdhui" class="<?= $filtre === 'aujourdhui' ? 'active' : '' ?>">📅 Aujourd'hui</a>
      <a href="?filtre=semaine" class="<?= $filtre === 'semaine' ? 'active' : '' ?>">📆 7 jours</a>
      <a href="?filtre=mois" class="<?= $filtre === 'mois' ? 'active' : '' ?>">🗓️ 30 jours</a>
      <a href="?filtre=retard" class="<?= $filtre === 'retard' ? 'active' : '' ?>">⚠️ En retard</a>
    </div>

    <form method="GET" style="margin-bottom:20px;display:flex;gap:10px;flex-wrap:wrap">
      <input type="hidden" name="filtre" value="<?= htmlspecialchars($filtre) ?>">
      <input type="text" name="q" placeholder="🔍 Rechercher client ou référence..." 
             value="<?= htmlspecialchars($recherche) ?>"
             style="flex:1;min-width:200px;padding:12px 16px;background:var(--bg-1);border:1px solid var(--border);border-radius:10px;color:var(--text)">
      <button class="btn-gold" type="submit">Rechercher</button>
    </form>

    <!-- Tableau -->
    <?php if (empty($echeances)): ?>
      <div class="card" style="text-align:center;padding:50px">
        <div style="font-size:3rem;opacity:.5;margin-bottom:12px">📭</div>
        <p class="muted">Aucune échéance trouvée.</p>
      </div>
    <?php else: ?>
      <div class="table-container">
        <table class="ech-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Client</th>
              <th>Prêt</th>
              <th style="text-align:right">Montant dû</th>
              <th style="text-align:right">Déjà payé</th>
              <th>Statut</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($echeances as $e):
              $reste = (float)$e['montant_total'] - (float)$e['montant_paye'];
              $jours = (int)$e['jours_restants'];
              $classe_row = $jours < 0 ? 'retard' : ($jours <= 3 ? 'urgent' : '');
              $badge_cls = $jours < 0 ? 'danger' : ($jours <= 3 ? 'warn' : 'ok');
            ?>
              <tr class="ech-row <?= $classe_row ?>">
                <td data-label="Date">
                  <strong><?= date('d/m/Y', strtotime($e['date_echeance'])) ?></strong><br>
                  <span class="jours-badge <?= $badge_cls ?>">
                    <?php if ($jours < 0): ?>
                      ⚠️ <?= abs($jours) ?>j de retard
                    <?php elseif ($jours === 0): ?>
                      📅 Aujourd'hui
                    <?php else: ?>
                      <?= $jours ?>j
                    <?php endif; ?>
                  </span>
                </td>
                <td data-label="Client">
                  <strong><?= htmlspecialchars($e['prenom'] . ' ' . $e['nom']) ?></strong><br>
                  <small class="muted"><?= htmlspecialchars($e['numero_compte']) ?></small>
                </td>
                <td data-label="Prêt">
                  <code style="color:var(--gold);font-size:.78rem"><?= htmlspecialchars($e['pret_ref']) ?></code><br>
                  <small class="muted">Éch. #<?= (int)$e['numero_echeance'] ?></small>
                </td>
                <td data-label="Montant dû" style="text-align:right;font-weight:800;color:#F5B800">
                  <?= htmlspecialchars(formaterMontant((float)$e['montant_total'], $e['pret_devise'])) ?>
                </td>
                <td data-label="Payé" style="text-align:right;color:#6EE7B7">
                  <?= htmlspecialchars(formaterMontant((float)$e['montant_paye'], $e['pret_devise'])) ?>
                </td>
                <td data-label="Statut">
                  <?= badgeStatutPret($e['statut']) ?>
                </td>
                <td data-label="Actions">
                  <a class="btn-ghost btn-sm" 
                     href="<?= $base ?>/admin/pret_details.php?token=<?= htmlspecialchars(genererToken('pret', (int)$e['id_pret'])) ?>"
                     style="padding:6px 12px;font-size:.72rem">
                    👁 Voir prêt
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

  </main>
</div>

<footer class="atf-footer">© <?= date('Y') ?> AFRICAN TRUST FUND</footer>
<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>