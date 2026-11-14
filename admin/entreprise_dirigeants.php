<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/prets.php';
requireRole('admin');

$pdo = Database::getConnection();
$admin_id = (int)$_SESSION['admin_id'];

$token = $_GET['token'] ?? $_POST['token'] ?? '';
if (!$token) { header('Location: ' . url('admin/entreprises.php')); exit; }

$data = consommerToken('entreprise', $token, ['keep' => true]);
if (!$data) { $_SESSION['flash_error'] = "Token invalide."; header('Location: ' . url('admin/entreprises.php')); exit; }

$id_entreprise = (int)$data['id'];

$stmt = $pdo->prepare("SELECT * FROM Entreprises WHERE id_entreprise = ?");
$stmt->execute([$id_entreprise]);
$e = $stmt->fetch();
if (!$e) { $_SESSION['flash_error'] = "Entreprise introuvable."; header('Location: ' . url('admin/entreprises.php')); exit; }

$errors = [];
$success = '';

/* Ajouter un dirigeant */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ajouter') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) throw new RuntimeException("Jeton invalide.");
        
        $nom    = cleanString($_POST['nom'] ?? '', 60);
        $postnom = cleanString($_POST['postnom'] ?? '', 60);
        $prenom = cleanString($_POST['prenom'] ?? '', 60);
        $role   = in_array($_POST['role'] ?? '', ['gerant','associe','directeur','representant_legal','pdg','dg'], true)
                    ? $_POST['role'] : 'associe';
        $pct    = (float)($_POST['pourcentage_parts'] ?? 0);
        $cni    = cleanString($_POST['cni'] ?? '', 50);
        $tel    = cleanString($_POST['telephone'] ?? '', 25);
        $email  = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: null;
        $adresse = cleanString($_POST['adresse'] ?? '', 500);
        $date_nom = $_POST['date_nomination'] ?? null;
        $signataire = !empty($_POST['est_signataire']);
        
        if (!$nom) throw new RuntimeException("Le nom est obligatoire.");
        if ($pct < 0 || $pct > 100) throw new RuntimeException("Le pourcentage doit être entre 0 et 100.");
        
        /* Vérifier total % ne dépasse pas 100 */
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(pourcentage_parts), 0) FROM Entreprise_Dirigeants WHERE id_entreprise = ?");
        $stmt->execute([$id_entreprise]);
        $total_pct = (float)$stmt->fetchColumn();
        
        if ($total_pct + $pct > 100) {
            throw new RuntimeException("Le total des parts dépasse 100% (actuel : $total_pct%, ajout : $pct%).");
        }
        
        $pdo->prepare("
            INSERT INTO Entreprise_Dirigeants 
                (id_entreprise, nom, postnom, prenom, role, pourcentage_parts,
                 cni, telephone, email, adresse, date_nomination, est_signataire)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $id_entreprise, $nom, $postnom ?: null, $prenom ?: null,
            $role, $pct, $cni ?: null, $tel ?: null, $email,
            $adresse ?: null, $date_nom ?: null, $signataire
        ]);
        
        logAction($pdo, 'admin', $admin_id, 'ENTREPRISE_DIRIGEANT_ADD',
            "Entreprise: $id_entreprise | Nom: $nom | Rôle: $role");
        
        $_SESSION['flash_success'] = "✅ Dirigeant ajouté !";
        header('Location: ' . url('admin/entreprise_dirigeants.php?token=' . urlencode($token)));
        exit;
        
    } catch (Throwable $ex) {
        $errors[] = $ex->getMessage();
    }
}

/* Supprimer un dirigeant */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'supprimer') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) throw new RuntimeException("Jeton invalide.");
        $id_d = (int)($_POST['id_dirigeant'] ?? 0);
        
        $pdo->prepare("DELETE FROM Entreprise_Dirigeants WHERE id_dirigeant = ? AND id_entreprise = ?")
            ->execute([$id_d, $id_entreprise]);
        
        $_SESSION['flash_success'] = "✅ Dirigeant supprimé.";
        header('Location: ' . url('admin/entreprise_dirigeants.php?token=' . urlencode($token)));
        exit;
        
    } catch (Throwable $ex) {
        $errors[] = $ex->getMessage();
    }
}

/* Charger dirigeants */
$stmt = $pdo->prepare("SELECT * FROM Entreprise_Dirigeants WHERE id_entreprise = ? ORDER BY role, pourcentage_parts DESC");
$stmt->execute([$id_entreprise]);
$dirigeants = $stmt->fetchAll();

/* Total parts */
$total_parts = 0;
foreach ($dirigeants as $d) $total_parts += (float)$d['pourcentage_parts'];

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dirigeants — <?= htmlspecialchars($e['raison_sociale']) ?> | ATF</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/admin-premium.css">
<style>
.ent-banner {
  padding: 18px 22px;
  border-radius: 14px;
  background: linear-gradient(135deg, rgba(139,92,246,.12), rgba(76,29,149,.04));
  border: 1px solid rgba(139,92,246,.3);
  margin-bottom: 20px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
}
.ent-banner h3 { color: #C4B5FD; margin: 0; font-size: 1.05rem; }

.dir-card {
  padding: 16px 18px;
  border-radius: 12px;
  background: linear-gradient(145deg, var(--card), var(--card-2));
  border: 1px solid var(--border);
  margin-bottom: 12px;
  display: flex;
  gap: 16px;
  align-items: flex-start;
  flex-wrap: wrap;
}
.dir-avatar {
  width: 50px; height: 50px;
  border-radius: 50%;
  background: linear-gradient(135deg, #8B5CF6, #7C3AED);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 900;
  font-size: 1.1rem;
  flex-shrink: 0;
}
.dir-info { flex: 1; min-width: 200px; }
.dir-info .name { font-weight: 800; color: var(--text); font-size: .95rem; }
.dir-info .role-badge {
  display: inline-block;
  padding: 2px 10px;
  border-radius: 999px;
  background: rgba(245,184,0,.15);
  color: var(--gold);
  font-size: .68rem;
  font-weight: 800;
  text-transform: uppercase;
  margin-left: 8px;
}
.dir-info .meta { color: var(--muted); font-size: .8rem; margin-top: 6px; }
.dir-info .pct { font-size: 1.3rem; font-weight: 900; color: var(--gold); }

.progress-parts {
  margin-top: 12px;
  padding: 14px;
  border-radius: 12px;
  background: rgba(11,14,20,.5);
  border: 1px solid var(--border);
}
.progress-parts .bar {
  height: 12px;
  background: rgba(0,0,0,.4);
  border-radius: 999px;
  overflow: hidden;
  margin-top: 8px;
}
.progress-parts .bar > div {
  height: 100%;
  background: linear-gradient(90deg, #10B981, #6EE7B7);
  border-radius: 999px;
  transition: width .6s;
}
.progress-parts.warn .bar > div { background: linear-gradient(90deg, #F5B800, #FCD34D); }
.progress-parts.danger .bar > div { background: linear-gradient(90deg, #EF4444, #FCA5A5); }
</style>
</head>
<body class="dark-auth">

<?php require __DIR__ . '/../includes/header.php'; ?>

<div class="admin-layout">
  <aside class="admin-sidebar">
    <h3>📊 Navigation</h3>
    <a href="<?= $base ?>/admin/entreprises.php" class="active"><span class="ico">🏢</span> Entreprises</a>
    <a href="<?= $base ?>/auth/logout.php"><span class="ico">🚪</span> Déconnexion</a>
  </aside>

  <main class="admin-main">
    <div class="welcome-head">
      <div>
        <h2>👥 Dirigeants & Actionnaires</h2>
        <p class="sub"><?= count($dirigeants) ?> personne(s) enregistrée(s)</p>
      </div>
      <a class="btn-ghost" href="<?= $base ?>/admin/entreprises.php">← Retour</a>
    </div>

    <?php if (!empty($_SESSION['flash_success'])): ?>
      <div class="alert success"><?= $_SESSION['flash_success'] ?></div>
      <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>
    <?php if ($errors): ?>
      <div class="alert error"><ul><?php foreach ($errors as $e) echo "<li>" . htmlspecialchars($e) . "</li>"; ?></ul></div>
    <?php endif; ?>

    <div class="ent-banner">
      <div>
        <h3>🏢 <?= htmlspecialchars($e['raison_sociale']) ?></h3>
        <div style="color:var(--muted);font-size:.82rem;margin-top:4px">
          <?= htmlspecialchars($e['forme_juridique']) ?> · RCCM : <?= htmlspecialchars($e['rccm'] ?? '—') ?>
        </div>
      </div>
      <div style="text-align:right">
        <div style="font-size:.72rem;color:var(--muted);text-transform:uppercase;font-weight:700">Total parts</div>
        <div style="font-size:1.5rem;font-weight:900;color:<?= $total_parts >= 100 ? '#6EE7B7' : '#FCD34D' ?>">
          <?= number_format($total_parts, 2) ?>%
        </div>
      </div>
    </div>

    <!-- Barre progression parts -->
    <?php if ($total_parts > 0): ?>
      <div class="progress-parts <?= $total_parts < 100 ? 'warn' : ($total_parts > 100 ? 'danger' : '') ?>">
        <div style="display:flex;justify-content:space-between;font-size:.82rem;color:var(--muted)">
          <span>Répartition du capital</span>
          <span><strong><?= number_format($total_parts, 2) ?>%</strong> attribué</span>
        </div>
        <div class="bar">
          <div style="width:<?= min(100, $total_parts) ?>%"></div>
        </div>
      </div>
    <?php endif; ?>

    <!-- Formulaire ajout -->
    <section class="card" style="margin-top:24px">
      <h3 style="margin-bottom:16px;color:var(--gold)">➕ Ajouter un dirigeant / actionnaire</h3>

      <form method="POST" novalidate>
        <?= csrfField() ?>
        <input type="hidden" name="action" value="ajouter">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

        <div class="grid-3">
          <label class="field"><span>Nom *</span><input type="text" name="nom" required maxlength="60"></label>
          <label class="field"><span>Post-nom</span><input type="text" name="postnom" maxlength="60"></label>
          <label class="field"><span>Prénom</span><input type="text" name="prenom" maxlength="60"></label>
        </div>

        <div class="grid-3">
          <label class="field"><span>Rôle *</span>
            <select name="role" required>
              <option value="gerant">Gérant</option>
              <option value="associe" selected>Associé</option>
              <option value="directeur">Directeur</option>
              <option value="representant_legal">Représentant légal</option>
              <option value="pdg">PDG</option>
              <option value="dg">Directeur Général</option>
            </select>
          </label>
          <label class="field"><span>% de parts *</span>
            <input type="number" name="pourcentage_parts" required min="0" max="100" step="0.01" value="0">
          </label>
          <label class="field"><span>Date nomination</span>
            <input type="date" name="date_nomination">
          </label>
        </div>

        <div class="grid-2">
          <label class="field"><span>N° CNI / Passeport</span>
            <input type="text" name="cni" maxlength="50">
          </label>
          <label class="field"><span>Téléphone</span>
            <input type="text" name="telephone" maxlength="25">
          </label>
        </div>

        <label class="field"><span>Email</span>
          <input type="email" name="email" maxlength="150">
        </label>

        <label class="field"><span>Adresse</span>
          <textarea name="adresse" rows="2" maxlength="500"></textarea>
        </label>

        <label style="display:flex;align-items:center;gap:10px;margin:14px 0;color:var(--text)">
          <input type="checkbox" name="est_signataire" value="1" style="width:20px;height:20px">
          <span>✍️ Habilité à signer pour l'entreprise</span>
        </label>

        <button class="btn-gold full" type="submit">➕ Ajouter</button>
      </form>
    </section>

    <!-- Liste dirigeants -->
    <?php if (!empty($dirigeants)): ?>
      <h3 style="margin:24px 0 14px;color:var(--gold)">📋 Liste des dirigeants (<?= count($dirigeants) ?>)</h3>

      <?php foreach ($dirigeants as $d):
        $initiales = strtoupper(mb_substr($d['nom'], 0, 1) . mb_substr($d['prenom'] ?: $d['postnom'] ?: 'X', 0, 1));
      ?>
        <div class="dir-card">
          <div class="dir-avatar"><?= $initiales ?></div>
          <div class="dir-info">
            <div class="name">
              <?= htmlspecialchars($d['nom'] . ' ' . ($d['postnom'] ?? '') . ' ' . ($d['prenom'] ?? '')) ?>
              <span class="role-badge"><?= htmlspecialchars($d['role']) ?></span>
              <?php if ($d['est_signataire']): ?>
                <span class="role-badge" style="background:rgba(16,185,129,.15);color:#6EE7B7">✍️ Signataire</span>
              <?php endif; ?>
            </div>
            <div class="meta">
              <?php if ($d['cni']): ?>🆔 <?= htmlspecialchars($d['cni']) ?> · <?php endif; ?>
              <?php if ($d['telephone']): ?>📞 <?= htmlspecialchars($d['telephone']) ?> · <?php endif; ?>
              <?php if ($d['email']): ?>✉️ <?= htmlspecialchars($d['email']) ?><?php endif; ?>
            </div>
            <?php if ($d['date_nomination']): ?>
              <div class="meta">📅 Nommé le <?= date('d/m/Y', strtotime($d['date_nomination'])) ?></div>
            <?php endif; ?>
            <form method="POST" style="display:inline;margin-top:10px" onsubmit="return confirm('Supprimer ce dirigeant ?')">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="supprimer">
              <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
              <input type="hidden" name="id_dirigeant" value="<?= (int)$d['id_dirigeant'] ?>">
              <button type="submit" style="padding:6px 12px;border-radius:8px;background:rgba(239,68,68,.15);color:#FCA5A5;border:1px solid rgba(239,68,68,.3);font-weight:700;cursor:pointer;font-size:.72rem">
                🗑 Supprimer
              </button>
            </form>
          </div>
          <div style="text-align:right">
            <div style="font-size:.72rem;color:var(--muted);text-transform:uppercase;font-weight:700">Parts</div>
            <div class="pct"><?= number_format((float)$d['pourcentage_parts'], 2) ?>%</div>
          </div>
        </div>
      <?php endforeach; ?>

    <?php else: ?>
      <div class="card" style="text-align:center;padding:40px">
        <div style="font-size:3rem;opacity:.5;margin-bottom:12px">👥</div>
        <p class="muted">Aucun dirigeant enregistré pour cette entreprise.</p>
      </div>
    <?php endif; ?>

  </main>
</div>

<footer class="atf-footer">© <?= date('Y') ?> AFRICAN TRUST FUND</footer>
<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>