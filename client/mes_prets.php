<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/prets.php';
requireRole('client');

$pdo = Database::getConnection();
$id = (int)$_SESSION['client_id'];

$stmt = $pdo->prepare("
    SELECT p.*, pr.nom AS produit_nom
    FROM Prets p
    JOIN Produits_Pret pr ON pr.id_produit = p.id_produit
    WHERE p.id_client = ?
    ORDER BY FIELD(p.statut, 'en_cours','en_retard','decaisse','approuve','soumis','en_analyse','solde','rejete','annule'), p.created_at DESC
");
$stmt->execute([$id]);
$prets = $stmt->fetchAll();

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mes prêts — ATF</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<style>
.pret-card { padding: 20px; border-radius: 16px; background: linear-gradient(145deg, var(--card), var(--card-2)); border: 1px solid var(--border); margin-bottom: 14px; transition: all .3s; }
.pret-card:hover { border-color: var(--gold); }
.pret-card-head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px; flex-wrap: wrap; gap: 10px; }
.pret-card-head .ref { font-family: monospace; color: var(--gold); font-size: .85rem; font-weight: 700; }
.pret-card-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; }
.pret-kpi { padding: 10px 12px; border-radius: 10px; background: rgba(11,14,20,.5); border: 1px solid var(--border); }
.pret-kpi .lbl { font-size: .68rem; color: var(--muted); text-transform: uppercase; font-weight: 700; margin-bottom: 4px; }
.pret-kpi .val { font-size: 1rem; font-weight: 800; color: #fff; }
.pret-kpi .val.gold { color: var(--gold); }
.pret-kpi .val.green { color: #6EE7B7; }
</style>
</head>
<body class="dark-auth app-mode">

<?php require __DIR__ . '/../includes/header.php'; ?>

<main class="dash">

  <div class="welcome-head">
    <div>
      <h2>💳 Mes prêts</h2>
      <p class="sub"><?= count($prets) ?> dossier(s)</p>
    </div>
    <a class="btn-gold" href="<?= $base ?>/client/demande_pret.php" style="padding:12px 20px;border-radius:12px;text-decoration:none;font-weight:800">
      ➕ Nouvelle demande
    </a>
  </div>

  <?php if (!empty($_SESSION['flash_success'])): ?>
    <div class="alert success"><?= $_SESSION['flash_success'] ?></div>
    <?php unset($_SESSION['flash_success']); ?>
  <?php endif; ?>

  <?php if (empty($prets)): ?>
    <div class="card" style="text-align:center;padding:50px">
      <div style="font-size:4rem;opacity:.4;margin-bottom:16px">💼</div>
      <h3>Aucun prêt</h3>
      <p class="muted" style="margin-top:8px">Vous n'avez pas encore de prêt enregistré.</p>
    </div>
  <?php else: ?>
    <?php foreach ($prets as $p): 
      $mensualite = calculerMensualite((float)($p['montant_accorde'] ?? $p['montant_demande']), (float)($p['taux_applique'] ?? 0.24), (int)$p['duree_mois']);
    ?>
      <div class="pret-card">
        <div class="pret-card-head">
          <div>
            <div class="ref"><?= htmlspecialchars($p['reference']) ?></div>
            <div style="color:var(--muted);font-size:.85rem;margin-top:4px"><?= htmlspecialchars($p['produit_nom']) ?></div>
          </div>
          <?= badgeStatutPret($p['statut']) ?>
        </div>
        <div class="pret-card-grid">
          <div class="pret-kpi"><div class="lbl">Montant demandé</div><div class="val gold"><?= htmlspecialchars(formaterMontant((float)$p['montant_demande'], $p['devise'])) ?></div></div>
          <div class="pret-kpi"><div class="lbl">Durée</div><div class="val"><?= (int)$p['duree_mois'] ?> mois</div></div>
          <div class="pret-kpi"><div class="lbl">Mensualité</div><div class="val green"><?= htmlspecialchars(formaterMontant($mensualite, $p['devise'])) ?></div></div>
          <div class="pret-kpi"><div class="lbl">Date demande</div><div class="val" style="font-size:.88rem"><?= date('d/m/Y', strtotime($p['date_demande'])) ?></div></div>
        </div>
        <div style="margin-top:14px;display:flex;gap:10px;flex-wrap:wrap">
          <a class="btn-ghost" href="<?= $base ?>/client/pret_details.php?token=<?= htmlspecialchars(genererToken('pret', (int)$p['id_pret'])) ?>" style="padding:10px 18px;border-radius:10px;text-decoration:none;font-weight:700">
            👁 Voir détails
          </a>
        </div>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

</main>

<footer class="atf-footer" style="padding:20px;text-align:center;color:var(--muted)">© <?= date('Y') ?> AFRICAN TRUST FUND</footer>
<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>