<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/prets.php';
requireRole('admin');

$pdo = Database::getConnection();
$admin_id = (int)$_SESSION['admin_id'];
$token = $_GET['token'] ?? '';

if (!$token) { header('Location: ' . url('admin/entreprises.php')); exit; }
$data = consommerToken('entreprise', $token);
if (!$data) { $_SESSION['flash_error'] = "Token invalide."; header('Location: ' . url('admin/entreprises.php')); exit; }

$id_entreprise = (int)$data['id'];

/* Charger entreprise */
$stmt = $pdo->prepare("
    SELECT e.*, c.numero_compte, c.numero_phone, c.email, c.statut AS client_statut, c.id_client
    FROM Entreprises e
    JOIN Client c ON c.id_client = e.id_client
    WHERE e.id_entreprise = ?
");
$stmt->execute([$id_entreprise]);
$e = $stmt->fetch();

if (!$e) { $_SESSION['flash_error'] = "Entreprise introuvable."; header('Location: ' . url('admin/entreprises.php')); exit; }

/* Dirigeants */
$stmt = $pdo->prepare("SELECT * FROM Entreprise_Dirigeants WHERE id_entreprise = ? ORDER BY role, nom");
$stmt->execute([$id_entreprise]);
$dirigeants = $stmt->fetchAll();

/* Actionnaires */
$stmt = $pdo->prepare("SELECT * FROM Entreprise_Actionnaires WHERE id_entreprise = ? ORDER BY pourcentage DESC");
$stmt->execute([$id_entreprise]);
$actionnaires = $stmt->fetchAll();

/* Bilans */
$stmt = $pdo->prepare("SELECT * FROM Entreprise_Bilans WHERE id_entreprise = ? ORDER BY annee_fiscale DESC");
$stmt->execute([$id_entreprise]);
$bilans = $stmt->fetchAll();

/* Prêts */
$stmt = $pdo->prepare("
    SELECT p.*, pr.nom AS produit_nom 
    FROM Prets p
    JOIN Produits_Pret pr ON pr.id_produit = p.id_produit
    WHERE p.id_client = ?
    ORDER BY p.created_at DESC
");
$stmt->execute([(int)$e['id_client']]);
$prets = $stmt->fetchAll();

/* Documents */
$stmt = $pdo->prepare("SELECT * FROM Entreprise_Documents WHERE id_entreprise = ? ORDER BY created_at DESC");
$stmt->execute([$id_entreprise]);
$documents = $stmt->fetchAll();

/* Calcul ratios (dernier bilan) */
$dernier_bilan = $bilans[0] ?? null;
$ratios = null;
if ($dernier_bilan) {
    $actifs_courants = (float)$dernier_bilan['actifs_courants'];
    $passifs_courants = (float)$dernier_bilan['passifs_courants'];
    $total_passifs = (float)$dernier_bilan['total_passifs'];
    $capitaux_propres = (float)$dernier_bilan['capitaux_propres'];
    $benefice_net = (float)$dernier_bilan['benefice_net'];
    $ca = (float)$dernier_bilan['chiffre_affaires'];
    
    $ratios = [
        'liquidite' => $passifs_courants > 0 ? round($actifs_courants / $passifs_courants, 2) : 0,
        'endettement' => $capitaux_propres > 0 ? round($total_passifs / $capitaux_propres, 2) : 0,
        'rentabilite' => $ca > 0 ? round(($benefice_net / $ca) * 100, 2) : 0,
    ];
}

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($e['raison_sociale']) ?> — ATF Admin</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/admin-premium.css">
<style>
.ent-header {
  padding: 24px;
  border-radius: 20px;
  background: linear-gradient(135deg, rgba(139,92,246,.15), rgba(76,29,149,.05));
  border: 1px solid rgba(139,92,246,.35);
  margin-bottom: 24px;
  display: flex;
  gap: 20px;
  align-items: center;
  flex-wrap: wrap;
}
.ent-logo {
  width: 90px; height: 90px;
  border-radius: 20px;
  background: linear-gradient(135deg, #8B5CF6, #7C3AED);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 2rem;
  font-weight: 900;
  flex-shrink: 0;
  border: 3px solid #fff;
}
.ent-info { flex: 1; min-width: 220px; }
.ent-info h1 { color: #C4B5FD; font-size: 1.4rem; margin-bottom: 6px; }
.ent-info .meta { color: var(--muted); font-size: .85rem; margin-top: 4px; }

.info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-bottom: 24px; }
.info-box { padding: 16px; border-radius: 12px; background: var(--card); border: 1px solid var(--border); }
.info-box .lbl { font-size: .7rem; color: var(--muted); text-transform: uppercase; font-weight: 700; margin-bottom: 4px; }
.info-box .val { font-size: 1.1rem; font-weight: 800; color: #fff; }
.info-box .val.gold { color: var(--gold); }
.info-box .val.green { color: #6EE7B7; }
.info-box .val.blue { color: #93B4FF; }
.info-box .val.red { color: #FCA5A5; }

.ratio-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-bottom: 24px; }
.ratio-box { padding: 16px; border-radius: 12px; background: linear-gradient(145deg, var(--card), var(--card-2)); border: 1px solid var(--border); text-align: center; }
.ratio-box .lbl { font-size: .68rem; color: var(--muted); text-transform: uppercase; font-weight: 700; }
.ratio-box .val { font-size: 1.5rem; font-weight: 900; margin-top: 6px; }
.ratio-box.ok .val { color: #6EE7B7; }
.ratio-box.warn .val { color: #FCD34D; }
.ratio-box.bad .val { color: #FCA5A5; }
.ratio-box .seuil { font-size: .68rem; color: var(--muted); margin-top: 4px; }

table.data { width: 100%; border-collapse: collapse; background: var(--card); border-radius: 12px; overflow: hidden; margin-bottom: 20px; }
table.data thead { background: #0D1B5E; color: #fff; }
table.data th { padding: 10px 12px; text-align: left; font-size: .72rem; text-transform: uppercase; }
table.data td { padding: 12px; border-bottom: 1px solid var(--border); font-size: .85rem; }
table.data tr:hover { background: rgba(245,184,0,.03); }
</style>
</head>
<body class="dark-auth">

<?php require __DIR__ . '/../includes/header.php'; ?>

<div class="admin-layout">
  <aside class="admin-sidebar">
    <h3>📊 Navigation</h3>
    <a href="<?= $base ?>/admin/entreprises.php"><span class="ico">🏢</span> Entreprises</a>
    <a href="<?= $base ?>/admin/prets.php"><span class="ico">💳</span> Prêts</a>
    <a href="<?= $base ?>/auth/logout.php"><span class="ico">🚪</span> Déconnexion</a>
  </aside>

  <main class="admin-main">
    <div class="welcome-head">
      <div><h2>🏢 Fiche entreprise</h2></div>
      <a class="btn-ghost" href="<?= $base ?>/admin/entreprises.php">← Retour</a>
    </div>

    <!-- En-tête -->
    <div class="ent-header">
      <div class="ent-logo"><?= strtoupper(mb_substr($e['raison_sociale'], 0, 2)) ?></div>
      <div class="ent-info">
        <h1><?= htmlspecialchars($e['raison_sociale']) ?></h1>
        <div class="meta">📋 <?= htmlspecialchars($e['forme_juridique']) ?> · Compte <?= htmlspecialchars($e['numero_compte']) ?></div>
        <div class="meta">📞 <?= htmlspecialchars($e['numero_phone']) ?><?= $e['email'] ? ' · ✉️ ' . htmlspecialchars($e['email']) : '' ?></div>
        <div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">
          <span class="badge <?= $e['statut_verification'] === 'verifie' ? 'green' : ($e['statut_verification'] === 'rejete' ? 'red' : 'gold') ?>">
            <?= strtoupper(htmlspecialchars($e['statut_verification'])) ?>
          </span>
          <span class="badge blue"><?= htmlspecialchars($e['secteur_activite'] ?? 'Secteur N/A') ?></span>
        </div>
      </div>
      <div style="text-align:right">
        <div style="font-size:.72rem;color:var(--muted);text-transform:uppercase;font-weight:700">Capital social</div>
        <div style="font-size:1.6rem;font-weight:900;color:#C4B5FD">
          <?= number_format((float)$e['capital_social'], 0, ',', ' ') ?>
        </div>
        <div style="color:var(--muted);font-size:.8rem"><?= htmlspecialchars($e['devise_capital']) ?></div>
      </div>
    </div>

    <!-- Infos légales -->
    <h3 style="margin-bottom:14px;color:var(--gold)">📋 Informations légales</h3>
    <div class="info-grid">
      <div class="info-box"><div class="lbl">RCCM</div><div class="val"><?= htmlspecialchars($e['rccm'] ?? '—') ?></div></div>
      <div class="info-box"><div class="lbl">NIF</div><div class="val"><?= htmlspecialchars($e['nif'] ?? '—') ?></div></div>
      <div class="info-box"><div class="lbl">Date création</div><div class="val"><?= $e['date_creation'] ? date('d/m/Y', strtotime($e['date_creation'])) : '—' ?></div></div>
      <div class="info-box"><div class="lbl">Employés</div><div class="val"><?= (int)($e['nombre_employes'] ?? 0) ?></div></div>
    </div>

    <!-- Dirigeant -->
    <?php if (!empty($e['dirigeant_nom'])): ?>
    <h3 style="margin:24px 0 14px;color:var(--gold)">👤 Dirigeant principal</h3>
    <div class="info-grid">
      <div class="info-box"><div class="lbl">Nom</div><div class="val"><?= htmlspecialchars($e['dirigeant_nom']) ?></div></div>
      <div class="info-box"><div class="lbl">CNI</div><div class="val"><?= htmlspecialchars($e['dirigeant_cni'] ?? '—') ?></div></div>
      <div class="info-box"><div class="lbl">Téléphone</div><div class="val"><?= htmlspecialchars($e['dirigeant_phone'] ?? '—') ?></div></div>
    </div>
    <?php endif; ?>

    <!-- Ratios financiers -->
    <?php if ($ratios && $dernier_bilan): ?>
      <h3 style="margin:24px 0 14px;color:var(--gold)">📊 Ratios financiers (<?= (int)$dernier_bilan['annee_fiscale'] ?>)</h3>
      <div class="ratio-grid">
        <div class="ratio-box <?= $ratios['liquidite'] >= 1.5 ? 'ok' : ($ratios['liquidite'] >= 1 ? 'warn' : 'bad') ?>">
          <div class="lbl">💧 Liquidité</div>
          <div class="val"><?= number_format($ratios['liquidite'], 2) ?></div>
          <div class="seuil">Seuil : ≥ 1.5</div>
        </div>
        <div class="ratio-box <?= $ratios['endettement'] <= 1 ? 'ok' : ($ratios['endettement'] <= 2 ? 'warn' : 'bad') ?>">
          <div class="lbl">⚖️ Endettement</div>
          <div class="val"><?= number_format($ratios['endettement'], 2) ?></div>
          <div class="seuil">Seuil : ≤ 1</div>
        </div>
        <div class="ratio-box <?= $ratios['rentabilite'] >= 10 ? 'ok' : ($ratios['rentabilite'] >= 5 ? 'warn' : 'bad') ?>">
          <div class="lbl">📈 Rentabilité</div>
          <div class="val"><?= number_format($ratios['rentabilite'], 1) ?>%</div>
          <div class="seuil">Seuil : ≥ 10%</div>
        </div>
      </div>
    <?php endif; ?>

    <!-- Dirigeants -->
    <?php if (!empty($dirigeants)): ?>
      <h3 style="margin:24px 0 14px;color:var(--gold)">👥 Dirigeants / Associés (<?= count($dirigeants) ?>)</h3>
      <table class="data">
        <thead>
          <tr><th>Nom</th><th>Rôle</th><th>Parts</th><th>CNI</th><th>Téléphone</th></tr>
        </thead>
        <tbody>
          <?php foreach ($dirigeants as $d): ?>
            <tr>
              <td><strong><?= htmlspecialchars($d['nom'] . ' ' . ($d['postnom'] ?? '') . ' ' . ($d['prenom'] ?? '')) ?></strong></td>
              <td><span class="badge gold"><?= htmlspecialchars($d['role']) ?></span></td>
              <td><strong><?= number_format((float)$d['pourcentage_parts'], 2) ?>%</strong></td>
              <td><code><?= htmlspecialchars($d['cni'] ?? '—') ?></code></td>
              <td><?= htmlspecialchars($d['telephone'] ?? '—') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <!-- Actionnaires -->
    <?php if (!empty($actionnaires)): ?>
      <h3 style="margin:24px 0 14px;color:var(--gold)">📜 Actionnaires (<?= count($actionnaires) ?>)</h3>
      <table class="data">
        <thead>
          <tr><th>Nom / Raison sociale</th><th>Type</th><th>%</th><th>Parts</th></tr>
        </thead>
        <tbody>
          <?php foreach ($actionnaires as $a): ?>
            <tr>
              <td><strong><?= htmlspecialchars($a['nom_complet']) ?></strong></td>
              <td><?= htmlspecialchars($a['type_personne']) ?></td>
              <td><strong style="color:var(--gold)"><?= number_format((float)$a['pourcentage'], 2) ?>%</strong></td>
              <td><?= number_format((float)$a['montant_parts'], 0, ',', ' ') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <!-- Prêts -->
    <?php if (!empty($prets)): ?>
      <h3 style="margin:24px 0 14px;color:var(--gold)">💳 Prêts de l'entreprise (<?= count($prets) ?>)</h3>
      <table class="data">
        <thead>
          <tr><th>Référence</th><th>Produit</th><th>Montant</th><th>Statut</th><th>Date</th></tr>
        </thead>
        <tbody>
          <?php foreach ($prets as $p): ?>
            <tr>
              <td><code style="color:var(--gold)"><?= htmlspecialchars($p['reference']) ?></code></td>
              <td><?= htmlspecialchars($p['produit_nom']) ?></td>
              <td><?= htmlspecialchars(formaterMontant((float)$p['montant_demande'], $p['devise'])) ?></td>
              <td><?= badgeStatutPret($p['statut']) ?></td>
              <td><?= date('d/m/Y', strtotime($p['date_demande'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <!-- Documents -->
    <?php if (!empty($documents)): ?>
      <h3 style="margin:24px 0 14px;color:var(--gold)">📎 Documents (<?= count($documents) ?>)</h3>
      <table class="data">
        <thead>
          <tr><th>Type</th><th>Numéro</th><th>Fichier</th><th>Statut</th></tr>
        </thead>
        <tbody>
          <?php foreach ($documents as $d): ?>
            <tr>
              <td><?= htmlspecialchars($d['type_document']) ?></td>
              <td><?= htmlspecialchars($d['numero_document'] ?? '—') ?></td>
              <td><a href="<?= $base ?>/<?= htmlspecialchars($d['fichier_path']) ?>" target="_blank" style="color:#93B4FF">👁 Voir</a></td>
              <td><span class="badge <?= $d['verifie'] ? 'green' : 'gold' ?>"><?= $d['verifie'] ? '✅ Vérifié' : '⏳ En attente' ?></span></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

  </main>
</div>

<footer class="atf-footer">© <?= date('Y') ?> AFRICAN TRUST FUND</footer>
<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>