<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/prets.php';
requireRole('admin');

$pdo = Database::getConnection();
$admin_id = (int)$_SESSION['admin_id'];
$token = $_GET['token'] ?? '';

if (!$token) { header('Location: ' . url('admin/prets.php')); exit; }
$data = consommerToken('pret', $token);
if (!$data) { $_SESSION['flash_error'] = "Token invalide ou expiré."; header('Location: ' . url('admin/prets.php')); exit; }

$id_pret = (int)$data['id'];

/* Charger le prêt */
$stmt = $pdo->prepare("
    SELECT p.*, c.nom, c.postnom, c.prenom, c.numero_compte, c.numero_phone, c.photo_profil, c.created_at AS client_depuis,
           pr.nom AS produit_nom, pr.taux_annuel
    FROM Prets p
    JOIN Client c ON c.id_client = p.id_client
    JOIN Produits_Pret pr ON pr.id_produit = p.id_produit
    WHERE p.id_pret = ?
");
$stmt->execute([$id_pret]);
$p = $stmt->fetch();

if (!$p) { $_SESSION['flash_error'] = "Prêt introuvable."; header('Location: ' . url('admin/prets.php')); exit; }

/* Échéances */
$stmt = $pdo->prepare("SELECT * FROM Echeances_Pret WHERE id_pret = ? ORDER BY numero_echeance ASC");
$stmt->execute([$id_pret]);
$echeances = $stmt->fetchAll();

/* Documents du client */
$stmt = $pdo->prepare("SELECT * FROM Client_Documents WHERE id_client = ? ORDER BY created_at DESC");
$stmt->execute([(int)$p['id_client']]);
$documents = $stmt->fetchAll();

/* Score crédit */
$score = calculerScoreCredit($pdo, (int)$p['id_client']);

/* Historique */
$stmt = $pdo->prepare("SELECT * FROM Prets_Historique WHERE id_pret = ? ORDER BY date_action DESC");
$stmt->execute([$id_pret]);
$historique = $stmt->fetchAll();

$initiales = strtoupper(mb_substr($p['prenom'], 0, 1) . mb_substr($p['nom'], 0, 1));
$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Prêt <?= htmlspecialchars($p['reference']) ?> — ATF</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/admin-premium.css">
<style>
.pret-header { padding: 24px; border-radius: 20px; background: linear-gradient(135deg, rgba(245,184,0,.1), rgba(139,92,246,.05)); border: 1px solid rgba(245,184,0,.3); margin-bottom: 24px; display: flex; gap: 20px; align-items: center; flex-wrap: wrap; }
.pret-photo { width: 80px; height: 80px; border-radius: 50%; object-fit: cover; border: 3px solid var(--gold); }
.pret-photo-ph { width: 80px; height: 80px; border-radius: 50%; display: flex; align-items: center; justify-content: center; background: linear-gradient(135deg, #0D1B5E, #1E293B); color: var(--gold); font-weight: 800; font-size: 1.5rem; border: 3px solid var(--gold); }
.pret-info { flex: 1; min-width: 200px; }
.pret-info h1 { color: var(--gold); font-size: 1.3rem; margin-bottom: 6px; }
.pret-info .ref { font-family: monospace; color: #93B4FF; font-size: .9rem; }

.info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 14px; margin-bottom: 24px; }
.info-box { padding: 16px; border-radius: 12px; background: linear-gradient(145deg, var(--card), var(--card-2)); border: 1px solid var(--border); }
.info-box .lbl { font-size: .7rem; color: var(--muted); text-transform: uppercase; letter-spacing: 1px; font-weight: 700; margin-bottom: 6px; }
.info-box .val { font-size: 1.2rem; font-weight: 800; color: #fff; }
.info-box .val.gold { color: var(--gold); }
.info-box .val.green { color: #6EE7B7; }
.info-box .val.blue { color: #93B4FF; }
.info-box .val.red { color: #FCA5A5; }

/* Score */
.score-widget { padding: 20px; border-radius: 16px; background: linear-gradient(145deg, var(--card), var(--card-2)); border: 1px solid var(--border); margin-bottom: 24px; }
.score-global { display: flex; align-items: center; gap: 20px; margin-bottom: 16px; flex-wrap: wrap; }
.score-circle { width: 100px; height: 100px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.8rem; font-weight: 900; border: 4px solid; }
.score-circle.excellent { background: rgba(16,185,129,.15); border-color: #10B981; color: #6EE7B7; }
.score-circle.bon { background: rgba(59,130,246,.15); border-color: #3B82F6; color: #93B4FF; }
.score-circle.moyen { background: rgba(245,184,0,.15); border-color: #F5B800; color: #FCD34D; }
.score-circle.faible { background: rgba(239,68,68,.15); border-color: #EF4444; color: #FCA5A5; }
.score-circle.mauvais { background: rgba(153,27,27,.2); border-color: #991B1B; color: #FCA5A5; }

.score-details { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
.score-detail { padding: 12px; border-radius: 10px; background: rgba(11,14,20,.5); border: 1px solid var(--border); }
.score-detail .lbl { font-size: .7rem; color: var(--muted); text-transform: uppercase; font-weight: 700; margin-bottom: 4px; }
.score-detail .val { font-size: 1.1rem; font-weight: 800; color: var(--gold); }

/* Actions */
.actions-panel { padding: 20px; border-radius: 16px; background: linear-gradient(145deg, rgba(16,185,129,.08), rgba(16,185,129,.02)); border: 1px solid rgba(16,185,129,.3); margin-bottom: 24px; }
.actions-row { display: flex; gap: 12px; flex-wrap: wrap; }
.btn-approve { background: linear-gradient(135deg, #10B981, #059669); color: #fff; padding: 12px 24px; border-radius: 999px; border: none; font-weight: 800; cursor: pointer; }
.btn-reject { background: linear-gradient(135deg, #DC2626, #EF4444); color: #fff; padding: 12px 24px; border-radius: 999px; border: none; font-weight: 800; cursor: pointer; }
.btn-cash { background: linear-gradient(135deg, #F5B800, #D4AF37); color: #0B0E14; padding: 12px 24px; border-radius: 999px; border: none; font-weight: 800; cursor: pointer; }

/* Échéances table */
.ech-table { width: 100%; border-collapse: collapse; background: linear-gradient(145deg, var(--card), var(--card-2)); border-radius: 14px; overflow: hidden; }
.ech-table th { padding: 10px 12px; text-align: left; font-size: .7rem; color: var(--muted); text-transform: uppercase; border-bottom: 1px solid var(--border); background: rgba(11,14,20,.4); }
.ech-table td { padding: 12px; border-bottom: 1px solid var(--border); font-size: .85rem; }
.ech-status.paye { color: #6EE7B7; font-weight: 700; }
.ech-status.attente { color: #FCD34D; font-weight: 700; }
.ech-status.retard { color: #FCA5A5; font-weight: 700; }

@media (max-width: 768px) {
    .ech-table thead { display: none; }
    .ech-table tbody tr { display: block; margin-bottom: 10px; padding: 12px; border: 1px solid var(--border); border-radius: 10px; }
    .ech-table td { display: flex; justify-content: space-between; padding: 4px 0; border: none; }
    .ech-table td::before { content: attr(data-label); color: var(--muted); font-size: .7rem; font-weight: 700; text-transform: uppercase; }
    .score-details { grid-template-columns: 1fr; }
}
</style>
</head>
<body class="dark-auth">

<?php require __DIR__ . '/../includes/header.php'; ?>

<div class="admin-layout">
  <aside class="admin-sidebar animate-left">
    <h3>📊 Navigation</h3>
    <a href="<?= $base ?>/admin/dashboard.php"><span class="ico">📊</span> Tableau de bord</a>
    <a href="<?= $base ?>/admin/prets.php" class="active"><span class="ico">💳</span> Prêts</a>
    <a href="<?= $base ?>/admin/logs.php"><span class="ico">📜</span> Journal</a>
    <a href="<?= $base ?>/auth/logout.php"><span class="ico">🚪</span> Déconnexion</a>
  </aside>

  <main class="admin-main">

    <div class="welcome-head">
      <div><h2>💳 Dossier de prêt</h2></div>
      <a class="btn-ghost" href="<?= $base ?>/admin/prets.php">← Retour</a>
    </div>

    <?php if (!empty($_SESSION['flash_success'])): ?>
      <div class="alert success"><?= $_SESSION['flash_success'] ?></div>
      <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>
    <?php if (!empty($_SESSION['flash_error'])): ?>
      <div class="alert error"><?= $_SESSION['flash_error'] ?></div>
      <?php unset($_SESSION['flash_error']); ?>
    <?php endif; ?>

    <!-- En-tête prêt -->
    <div class="pret-header">
      <?php if (!empty($p['photo_profil']) && file_exists(__DIR__ . '/../' . $p['photo_profil'])): ?>
        <img src="<?= $base ?>/<?= htmlspecialchars($p['photo_profil']) ?>" class="pret-photo">
      <?php else: ?>
        <div class="pret-photo-ph"><?= $initiales ?></div>
      <?php endif; ?>
      <div class="pret-info">
        <h1><?= htmlspecialchars($p['prenom'] . ' ' . $p['nom'] . ' ' . $p['postnom']) ?></h1>
        <div class="ref"><?= htmlspecialchars($p['reference']) ?> · <?= htmlspecialchars($p['numero_compte']) ?></div>
        <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">
          <?= badgeStatutPret($p['statut']) ?>
          <span class="badge gold"><?= htmlspecialchars($p['produit_nom']) ?></span>
          <span class="badge blue"><?= htmlspecialchars($p['devise']) ?></span>
        </div>
      </div>
    </div>

    <!-- Score de crédit -->
    <div class="score-widget">
      <h3 style="margin-bottom:16px">📊 Score de crédit</h3>
      <div class="score-global">
        <div class="score-circle <?= htmlspecialchars($score['niveau']) ?>">
          <?= $score['score'] ?>
        </div>
        <div style="flex:1">
          <div style="font-size:1.2rem;font-weight:800;color:var(--gold)">Niveau : <?= strtoupper($score['niveau']) ?></div>
          <div style="color:var(--muted);font-size:.85rem;margin-top:6px">Calculé automatiquement selon l'historique du client</div>
        </div>
      </div>
      <div class="score-details">
        <div class="score-detail"><div class="lbl">Fiabilité</div><div class="val"><?= $score['fiabilite'] ?> / 33</div></div>
        <div class="score-detail"><div class="lbl">Compétence</div><div class="val"><?= $score['competence'] ?> / 33</div></div>
        <div class="score-detail"><div class="lbl">Bonne volonté</div><div class="val"><?= $score['bonne_volonte'] ?> / 34</div></div>
      </div>
    </div>

    <!-- Informations du prêt -->
    <h3 style="margin:24px 0 12px">💰 Informations du prêt</h3>
    <div class="info-grid">
      <div class="info-box"><div class="lbl">Montant demandé</div><div class="val gold"><?= htmlspecialchars(formaterMontant((float)$p['montant_demande'], $p['devise'])) ?></div></div>
      <?php if ($p['montant_accorde']): ?>
      <div class="info-box"><div class="lbl">Montant accordé</div><div class="val green"><?= htmlspecialchars(formaterMontant((float)$p['montant_accorde'], $p['devise'])) ?></div></div>
      <?php endif; ?>
      <div class="info-box"><div class="lbl">Durée</div><div class="val"><?= (int)$p['duree_mois'] ?> mois</div></div>
      <div class="info-box"><div class="lbl">Devise</div><div class="val blue"><?= htmlspecialchars($p['devise']) ?></div></div>
      <div class="info-box"><div class="lbl">Taux appliqué</div><div class="val"><?= $p['taux_applique'] ? number_format((float)$p['taux_applique'] * 100, 2) . '%' : '—' ?></div></div>
      <div class="info-box"><div class="lbl">Mensualité</div><div class="val"><?= htmlspecialchars(formaterMontant(calculerMensualite((float)($p['montant_accorde'] ?? $p['montant_demande']), (float)($p['taux_applique'] ?? 0.24), (int)$p['duree_mois']), $p['devise'])) ?></div></div>
    </div>

    <!-- Motif -->
    <?php if ($p['motif_pret']): ?>
    <div class="card" style="margin-bottom:20px">
      <h3 style="margin-bottom:10px">📝 Motif du prêt</h3>
      <p style="color:var(--text);line-height:1.6"><?= nl2br(htmlspecialchars($p['motif_pret'])) ?></p>
    </div>
    <?php endif; ?>

    <!-- Actions admin (si applicable) -->
    <?php if (in_array($p['statut'], ['soumis','en_analyse'], true)): ?>
    <div class="actions-panel">
      <h3 style="margin-bottom:14px;color:#6EE7B7">✅ Actions disponibles</h3>
      <div class="actions-row">
        <form method="POST" action="<?= $base ?>/admin/prets_action.php" style="display:inline" onsubmit="return confirm('Approuver ce prêt ?')">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="approuver">
          <input type="hidden" name="id_pret" value="<?= (int)$p['id_pret'] ?>">
          <input type="hidden" name="montant_accorde" value="<?= (float)$p['montant_demande'] ?>">
          <input type="hidden" name="taux" value="<?= (float)$p['taux_annuel'] ?>">
          <button class="btn-approve" type="submit">✅ Approuver</button>
        </form>
        <button class="btn-reject" onclick="ouvrirRejet()">❌ Rejeter</button>
      </div>
    </div>
    <?php endif; ?>

    <!-- Échéancier -->
    <?php if (!empty($echeances)): ?>
    <h3 style="margin:24px 0 12px">📅 Échéancier (<?= count($echeances) ?> échéances)</h3>
    <div class="table-container">
      <table class="ech-table">
        <thead>
          <tr>
            <th>N°</th>
            <th>Date</th>
            <th style="text-align:right">Capital</th>
            <th style="text-align:right">Intérêts</th>
            <th style="text-align:right">Total</th>
            <th>Statut</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($echeances as $e): ?>
            <tr>
              <td data-label="N°"><strong>#<?= (int)$e['numero_echeance'] ?></strong></td>
              <td data-label="Date"><?= date('d/m/Y', strtotime($e['date_echeance'])) ?></td>
              <td data-label="Capital" style="text-align:right"><?= number_format((float)$e['montant_capital'], 0, ',', ' ') ?></td>
              <td data-label="Intérêts" style="text-align:right"><?= number_format((float)$e['montant_interet'], 0, ',', ' ') ?></td>
              <td data-label="Total" style="text-align:right;font-weight:800"><?= number_format((float)$e['montant_total'], 0, ',', ' ') ?></td>
              <td data-label="Statut">
                <span class="ech-status <?= $e['statut'] === 'paye' ? 'paye' : ($e['statut'] === 'retard' ? 'retard' : 'attente') ?>">
                  <?= htmlspecialchars($e['statut']) ?>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <!-- Historique -->
    <?php if (!empty($historique)): ?>
    <h3 style="margin:24px 0 12px">📜 Historique du dossier</h3>
    <div class="card">
      <?php foreach ($historique as $h): ?>
        <div style="padding:12px 0;border-bottom:1px solid var(--border)">
          <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:8px">
            <strong style="color:var(--gold)"><?= htmlspecialchars($h['action']) ?></strong>
            <small class="muted"><?= date('d/m/Y à H:i', strtotime($h['date_action'])) ?></small>
          </div>
          <div style="font-size:.82rem;color:var(--muted);margin-top:4px">
            <?= htmlspecialchars($h['effectue_par_role']) ?> · <?= htmlspecialchars($h['commentaire'] ?: 'Aucun commentaire') ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </main>
</div>

<!-- Modal Rejet -->
<div class="modal-overlay" id="modalRejet" style="display:none;position:fixed;inset:0;background:rgba(11,14,20,.9);z-index:99999;align-items:center;justify-content:center;padding:20px">
  <div style="background:linear-gradient(145deg,#1A2130,#121824);border-radius:20px;padding:32px;max-width:500px;width:100%;border:1px solid rgba(239,68,68,.3)">
    <h3 style="color:#FCA5A5;margin-bottom:16px">❌ Rejeter le prêt</h3>
    <form method="POST" action="<?= $base ?>/admin/prets_action.php">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="rejeter">
      <input type="hidden" name="id_pret" value="<?= (int)$p['id_pret'] ?>">
      <label class="field" style="display:block;margin-bottom:16px">
        <span style="color:var(--muted);font-size:.8rem">Motif du rejet *</span>
        <textarea name="motif" required rows="3" style="width:100%;margin-top:6px;padding:10px;background:var(--bg-1);border:1px solid var(--border);border-radius:10px;color:var(--text)"></textarea>
      </label>
      <div style="display:flex;gap:12px;justify-content:flex-end">
        <button type="button" class="btn-ghost" onclick="fermerRejet()">Annuler</button>
        <button type="submit" class="btn-reject">Confirmer le rejet</button>
      </div>
    </form>
  </div>
</div>

<script>
function ouvrirRejet() { document.getElementById('modalRejet').style.display = 'flex'; }
function fermerRejet() { document.getElementById('modalRejet').style.display = 'none'; }
</script>
<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>