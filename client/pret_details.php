<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/prets.php';
requireRole('client');

$pdo = Database::getConnection();
$id_client = (int)$_SESSION['client_id'];
$token = $_GET['token'] ?? '';

if (!$token) { header('Location: ' . url('client/mes_prets.php')); exit; }
$data = consommerToken('pret', $token);
if (!$data) { $_SESSION['flash_error'] = "Token invalide."; header('Location: ' . url('client/mes_prets.php')); exit; }

$id_pret = (int)$data['id'];

/* Vérifier que le prêt appartient au client */
$stmt = $pdo->prepare("
    SELECT p.*, pr.nom AS produit_nom
    FROM Prets p
    JOIN Produits_Pret pr ON pr.id_produit = p.id_produit
    WHERE p.id_pret = ? AND p.id_client = ?
");
$stmt->execute([$id_pret, $id_client]);
$p = $stmt->fetch();

if (!$p) { $_SESSION['flash_error'] = "Prêt introuvable."; header('Location: ' . url('client/mes_prets.php')); exit; }

/* Échéances */
$stmt = $pdo->prepare("SELECT * FROM Echeances_Pret WHERE id_pret = ? ORDER BY numero_echeance ASC");
$stmt->execute([$id_pret]);
$echeances = $stmt->fetchAll();

/* Remboursements */
$stmt = $pdo->prepare("SELECT * FROM Remboursements WHERE id_pret = ? ORDER BY date_remboursement DESC");
$stmt->execute([$id_pret]);
$remboursements = $stmt->fetchAll();

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Prêt <?= htmlspecialchars($p['reference']) ?> — ATF</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<style>
.info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 20px; }
.info-box { padding: 14px; border-radius: 12px; background: linear-gradient(145deg, var(--card), var(--card-2)); border: 1px solid var(--border); }
.info-box .lbl { font-size: .68rem; color: var(--muted); text-transform: uppercase; font-weight: 700; margin-bottom: 4px; }
.info-box .val { font-size: 1.1rem; font-weight: 800; color: #fff; }
.info-box .val.gold { color: var(--gold); }
.info-box .val.green { color: #6EE7B7; }

.ech-row { display: flex; justify-content: space-between; align-items: center; padding: 12px; border-bottom: 1px solid var(--border); flex-wrap: wrap; gap: 8px; }
.ech-row.paye { background: rgba(16,185,129,.04); }
.ech-row.retard { background: rgba(239,68,68,.04); }
.ech-num { font-weight: 800; color: var(--gold); min-width: 40px; }
.ech-date { color: var(--muted); font-size: .82rem; }
.ech-montant { font-weight: 800; }
@media (max-width: 500px) {
    .ech-row { flex-direction: column; align-items: stretch; }
}
</style>
</head>
<body class="dark-auth app-mode">

<?php require __DIR__ . '/../includes/header.php'; ?>

<main class="dash">

  <div class="welcome-head">
    <div>
      <h2>💳 Prêt <?= htmlspecialchars($p['reference']) ?></h2>
      <p class="sub"><?= htmlspecialchars($p['produit_nom']) ?></p>
    </div>
    <a class="btn-ghost" href="<?= $base ?>/client/mes_prets.php">← Retour</a>
  </div>

  <div style="margin-bottom:16px"><?= badgeStatutPret($p['statut']) ?></div>

  <div class="info-grid">
    <div class="info-box"><div class="lbl">Montant</div><div class="val gold"><?= htmlspecialchars(formaterMontant((float)($p['montant_accorde'] ?? $p['montant_demande']), $p['devise'])) ?></div></div>
    <div class="info-box"><div class="lbl">Durée</div><div class="val"><?= (int)$p['duree_mois'] ?> mois</div></div>
    <div class="info-box"><div class="lbl">Taux</div><div class="val"><?= $p['taux_applique'] ? number_format((float)$p['taux_applique'] * 100, 2) . '%/an' : '—' ?></div></div>
    <div class="info-box"><div class="lbl">Mensualité</div><div class="val green"><?= htmlspecialchars(formaterMontant(calculerMensualite((float)($p['montant_accorde'] ?? $p['montant_demande']), (float)($p['taux_applique'] ?? 0.24), (int)$p['duree_mois']), $p['devise'])) ?></div></div>
    <?php if ($p['montant_restant_du']): ?>
      <div class="info-box"><div class="lbl">Restant dû</div><div class="val" style="color:#FCA5A5"><?= htmlspecialchars(formaterMontant((float)$p['montant_restant_du'], $p['devise'])) ?></div></div>
    <?php endif; ?>
  </div>

  <!-- Motif -->
  <?php if ($p['motif_pret']): ?>
  <div class="card">
    <h3 style="margin-bottom:10px">📝 Motif</h3>
    <p style="color:var(--text);line-height:1.6"><?= nl2br(htmlspecialchars($p['motif_pret'])) ?></p>
  </div>
  <?php endif; ?>

  <!-- Motif de rejet -->
  <?php if ($p['statut'] === 'rejete' && $p['motif_rejet']): ?>
  <div class="card" style="border-color:rgba(239,68,68,.4);background:rgba(239,68,68,.05)">
    <h3 style="margin-bottom:10px;color:#FCA5A5">❌ Motif du rejet</h3>
    <p style="color:#FCA5A5"><?= nl2br(htmlspecialchars($p['motif_rejet'])) ?></p>
  </div>
  <?php endif; ?>

  <!-- Échéancier -->
  <?php if (!empty($echeances)): ?>
    <section class="card">
      <h3 style="margin-bottom:14px">📅 Échéancier (<?= count($echeances) ?>)</h3>
      <?php foreach ($echeances as $e): ?>
        <div class="ech-row <?= htmlspecialchars($e['statut']) ?>">
          <div style="display:flex;gap:14px;align-items:center;flex:1">
            <span class="ech-num">#<?= (int)$e['numero_echeance'] ?></span>
            <span class="ech-date"><?= date('d/m/Y', strtotime($e['date_echeance'])) ?></span>
          </div>
          <div style="display:flex;gap:14px;align-items:center">
            <span class="ech-montant"><?= htmlspecialchars(formaterMontant((float)$e['montant_total'], $p['devise'])) ?></span>
            <span class="badge <?= $e['statut'] === 'paye' ? 'green' : ($e['statut'] === 'retard' ? 'red' : 'gold') ?>" style="font-size:.68rem">
              <?= htmlspecialchars($e['statut']) ?>
            </span>
          </div>
        </div>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>

  <!-- Remboursements -->
  <?php if (!empty($remboursements)): ?>
    <section class="card">
      <h3 style="margin-bottom:14px">💰 Historique des remboursements</h3>
      <?php foreach ($remboursements as $r): ?>
        <div style="padding:10px 0;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px">
          <div>
            <strong style="color:#6EE7B7"><?= htmlspecialchars(formaterMontant((float)$r['montant'], $r['devise'])) ?></strong>
            <div class="muted" style="font-size:.78rem;margin-top:3px"><?= htmlspecialchars($r['mode']) ?> · <?= htmlspecialchars($r['reference']) ?></div>
          </div>
          <div class="muted" style="font-size:.82rem"><?= date('d/m/Y H:i', strtotime($r['date_remboursement'])) ?></div>
        </div>
      <?php endforeach; ?>
    </section>
  <?php endif; ?>

</main>

<footer class="atf-footer" style="padding:20px;text-align:center;color:var(--muted)">© <?= date('Y') ?> AFRICAN TRUST FUND</footer>
<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>