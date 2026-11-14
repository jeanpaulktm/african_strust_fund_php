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
   CRÉATION D'UN ACTIONNAIRE
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) throw new RuntimeException("Jeton invalide.");

        $nom         = cleanString($_POST['nom'] ?? '', 60);
        $postnom     = cleanString($_POST['postnom'] ?? '', 60);
        $prenom      = cleanString($_POST['prenom'] ?? '', 60);
        $email       = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: null;
        $phone       = cleanString($_POST['numero_phone'] ?? '', 25);
        $adresse     = cleanString($_POST['adresse_complete'] ?? '', 500);
        $type        = in_array($_POST['type_actionnaire'] ?? '', ['fondateur','investisseur','employe','partenaire'])
                        ? $_POST['type_actionnaire'] : 'investisseur';
        $password    = (string)($_POST['mot_de_passe'] ?? '');
        $password2   = (string)($_POST['mot_de_passe2'] ?? '');
        $nb_parts    = (int)($_POST['nombre_parts_initial'] ?? 0);
        $devise      = in_array($_POST['devise'] ?? '', ['CDF','USD']) ? $_POST['devise'] : 'CDF';

        if (!$nom || !$postnom || !$prenom)              $errors[] = "Les noms sont obligatoires.";
        if (!preg_match('/^\+?[0-9 ]{9,20}$/', $phone))  $errors[] = "Téléphone invalide.";
        if (!validerMotDePasse($password))                $errors[] = "Mot de passe faible.";
        if ($password !== $password2)                     $errors[] = "Mots de passe non identiques.";

        if (!$errors) {
            $chk = $pdo->prepare("SELECT 1 FROM Actionnaires WHERE numero_phone = ?");
            $chk->execute([$phone]);
            if ($chk->fetchColumn()) $errors[] = "Ce numéro est déjà utilisé.";
        }

        if (!$errors) {
            $pdo->beginTransaction();

            $numero = genererNumeroActionnaire($pdo);
            $hash   = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

            $pdo->prepare("
                INSERT INTO Actionnaires 
                    (numero_actionnaire, nom, postnom, prenom, email, numero_phone,
                     adresse_complete, type_actionnaire, mot_de_passe, date_adhesion, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE(), ?)
            ")->execute([
                $numero, $nom, $postnom, $prenom, $email, $phone,
                $adresse, $type, $hash, $admin_id
            ]);

            $id_actionnaire = (int)$pdo->lastInsertId();

            if ($nb_parts > 0) {
                souscrireParts($pdo, $id_actionnaire, $nb_parts, $devise,
                    "Souscription initiale à la création");
            }

            $pdo->commit();

            logAction($pdo, 'admin', $admin_id, 'ACTIONNAIRE_CREATE',
                "Création actionnaire $numero : $nom $postnom");

            $_SESSION['flash_success'] = "✅ Actionnaire créé !<br>
                <strong>Numéro :</strong> $numero<br>
                <strong>Nom :</strong> $nom $postnom<br>
                <strong>Identifiant de connexion :</strong> $numero<br>
                <strong>Mot de passe :</strong> celui que vous avez défini";

            header('Location: ' . url('admin/actionnaires.php'));
            exit;
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = $e->getMessage();
    }
}

/* ============================================================
   CHARGEMENT
   ============================================================ */
$success = '';
if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (!empty($_SESSION['flash_error'])) {
    $errors[] = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

$actionnaires = $pdo->query("
    SELECT * FROM Actionnaires ORDER BY created_at DESC
")->fetchAll();

$capital = getCapitalCourant($pdo);

/* Statistiques */
$stats = [
    'total'      => (int)$pdo->query("SELECT COUNT(*) FROM Actionnaires")->fetchColumn(),
    'actifs'     => (int)$pdo->query("SELECT COUNT(*) FROM Actionnaires WHERE statut='actif'")->fetchColumn(),
    'total_parts'=> (int)$pdo->query("SELECT COALESCE(SUM(nombre_parts),0) FROM Actionnaires")->fetchColumn(),
    'capital_cdf'=> (float)$pdo->query("SELECT COALESCE(SUM(capital_investi_cdf),0) FROM Actionnaires")->fetchColumn(),
];

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Actionnaires — ATF Admin</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/admin-premium.css">
<style>
/* Modale */
.modal-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(11,14,20,.85);
  backdrop-filter: blur(10px);
  z-index: 99999;
  align-items: center;
  justify-content: center;
  padding: 20px;
  overflow-y: auto;
}
.modal-overlay.active { display: flex; }

.modal-content {
  background: linear-gradient(145deg, #1A2130, #121824);
  border-radius: 20px;
  border: 1px solid rgba(245,184,0,.3);
  box-shadow: 0 30px 80px rgba(0,0,0,.8);
  padding: 32px 28px;
  max-width: 700px;
  width: 100%;
  max-height: 90vh;
  overflow-y: auto;
  animation: slideUp .3s;
}
@keyframes slideUp {
  from { transform: translateY(30px); opacity: 0; }
  to { transform: translateY(0); opacity: 1; }
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 24px;
  padding-bottom: 16px;
  border-bottom: 1px solid var(--border);
}
.modal-header h3 { color: var(--gold); font-size: 1.2rem; font-weight: 800; }
.modal-close {
  width: 36px; height: 36px;
  border-radius: 50%;
  background: rgba(239,68,68,.15);
  color: #FCA5A5;
  border: 1px solid rgba(239,68,68,.3);
  cursor: pointer;
  font-size: 1.2rem;
  font-weight: 800;
}
.modal-actions {
  display: flex;
  gap: 12px;
  justify-content: flex-end;
  margin-top: 24px;
  padding-top: 20px;
  border-top: 1px solid var(--border);
}

/* Boutons actions tableau */
.actions-cell {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}
.btn-action-mini {
  padding: 6px 10px;
  border-radius: 8px;
  font-size: .72rem;
  font-weight: 700;
  border: none;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  transition: all .2s;
  text-decoration: none;
  font-family: inherit;
}
.btn-action-mini.view   { background: rgba(59,130,246,.15); color: #93B4FF; border: 1px solid rgba(59,130,246,.3); }
.btn-action-mini.edit   { background: rgba(245,184,0,.15);  color: #F5B800; border: 1px solid rgba(245,184,0,.3); }
.btn-action-mini.delete { background: rgba(239,68,68,.15);  color: #FCA5A5; border: 1px solid rgba(239,68,68,.3); }
.btn-action-mini:hover { transform: translateY(-1px); }

.danger-box {
  padding: 16px;
  border-radius: 12px;
  background: rgba(239,68,68,.08);
  border: 1px solid rgba(239,68,68,.3);
  margin: 20px 0;
  color: #FCA5A5;
  font-size: .88rem;
  line-height: 1.6;
}

@media (max-width: 900px) {
  .admin-layout { grid-template-columns: 1fr; }
}
</style>
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
    <a href="<?= $base ?>/admin/actionnaires.php" class="active"><span class="ico">🏛️</span> Actionnaires</a>
    <a href="<?= $base ?>/admin/parts_sociales.php"><span class="ico">📜</span> Parts sociales</a>
    <a href="<?= $base ?>/admin/dividendes.php"><span class="ico">💰</span> Dividendes</a>
    <a href="<?= $base ?>/admin/produits.php"><span class="ico">📈</span> Produits</a>
    <a href="<?= $base ?>/admin/placements.php"><span class="ico">💵</span> Placements</a>
    <a href="<?= $base ?>/admin/statistiques.php"><span class="ico">📈</span> Statistiques</a>
    <a href="<?= $base ?>/admin/logs.php"><span class="ico">📜</span> Journal</a>
    <a href="<?= $base ?>/auth/logout.php"><span class="ico">🚪</span> Déconnexion</a>
  </aside>

  <main class="admin-main">

    <div class="welcome-head">
      <div>
        <h2>🏛️ Gestion des Actionnaires</h2>
        <p class="sub"><?= $stats['total'] ?> actionnaire(s) — <?= $stats['actifs'] ?> actif(s)</p>
      </div>
    </div>

    <?php if ($success): ?><div class="alert success"><?= $success ?></div><?php endif; ?>
    <?php if ($errors): ?><div class="alert error"><ul><?php foreach ($errors as $e) echo "<li>" . htmlspecialchars($e) . "</li>"; ?></ul></div><?php endif; ?>

    <!-- KPIs -->
    <section class="kpi-grid">
      <div class="stat-block gold animate-in delay-1">
        <div class="ico">🏛️</div>
        <div class="meta">
          <strong><?= $stats['total'] ?></strong>
          <span>Total actionnaires</span>
        </div>
      </div>
      <div class="stat-block green animate-in delay-2">
        <div class="ico">✅</div>
        <div class="meta">
          <strong><?= $stats['actifs'] ?></strong>
          <span>Actifs</span>
        </div>
      </div>
      <div class="stat-block blue animate-in delay-3">
        <div class="ico">📜</div>
        <div class="meta">
          <strong><?= number_format($stats['total_parts'], 0, ',', ' ') ?></strong>
          <span>Parts détenues</span>
        </div>
      </div>
      <div class="stat-block gold animate-in delay-4">
        <div class="ico">💰</div>
        <div class="meta">
          <strong><?= number_format($stats['capital_cdf'], 0, ',', ' ') ?></strong>
          <span>Capital total (CDF)</span>
        </div>
      </div>
    </section>

    <!-- Capital -->
    <?php if ($capital): ?>
      <section class="card animate-in delay-1" style="background:linear-gradient(145deg,rgba(245,184,0,.08),rgba(245,184,0,.02));border-color:rgba(245,184,0,.3)">
        <h3 style="margin-bottom:14px">💼 Capital social ATF <?= $capital['annee_fiscale'] ?></h3>
        <div class="grid-2">
          <div>
            <div style="color:var(--muted);font-size:.78rem;text-transform:uppercase;letter-spacing:1px">Parts émises</div>
            <div style="font-size:1.4rem;font-weight:800;color:var(--gold);margin-top:4px"><?= number_format((int)$capital['nombre_parts_emises'], 0, ',', ' ') ?></div>
          </div>
          <div>
            <div style="color:var(--muted);font-size:.78rem;text-transform:uppercase;letter-spacing:1px">Parts vendues</div>
            <div style="font-size:1.4rem;font-weight:800;color:var(--green);margin-top:4px"><?= number_format((int)$capital['nombre_parts_vendues'], 0, ',', ' ') ?></div>
          </div>
          <div>
            <div style="color:var(--muted);font-size:.78rem;text-transform:uppercase;letter-spacing:1px">Prix unitaire</div>
            <div style="font-size:1.2rem;font-weight:800;margin-top:4px"><?= number_format((float)$capital['prix_unitaire'], 0, ',', ' ') ?> <?= $capital['devise'] ?></div>
          </div>
          <div>
            <div style="color:var(--muted);font-size:.78rem;text-transform:uppercase;letter-spacing:1px">Disponibles</div>
            <div style="font-size:1.2rem;font-weight:800;color:#93B4FF;margin-top:4px">
              <?= number_format((int)$capital['nombre_parts_emises'] - (int)$capital['nombre_parts_vendues'], 0, ',', ' ') ?>
            </div>
          </div>
        </div>
      </section>
    <?php endif; ?>

    <!-- Formulaire création -->
    <section class="card animate-in delay-2">
      <h3 style="margin-bottom:16px">➕ Créer un nouvel actionnaire</h3>

      <form method="POST" novalidate autocomplete="off">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="create">

        <fieldset>
          <legend>Identité</legend>
          <div class="grid-3">
            <label class="field"><span>Nom *</span><input type="text" name="nom" required maxlength="60"></label>
            <label class="field"><span>Post-nom *</span><input type="text" name="postnom" required maxlength="60"></label>
            <label class="field"><span>Prénom *</span><input type="text" name="prenom" required maxlength="60"></label>
          </div>
          <div class="grid-2">
            <label class="field"><span>Téléphone *</span><input type="text" name="numero_phone" required maxlength="25" placeholder="+243 ..."></label>
            <label class="field"><span>Email</span><input type="email" name="email" maxlength="120"></label>
          </div>
          <label class="field"><span>Adresse complète</span><textarea name="adresse_complete" rows="2" maxlength="500"></textarea></label>
        </fieldset>

        <fieldset>
          <legend>Type d'actionnaire</legend>
          <label class="field"><span>Type *</span>
            <select name="type_actionnaire" required>
              <option value="investisseur" selected>Investisseur</option>
              <option value="fondateur">Fondateur</option>
              <option value="employe">Employé</option>
              <option value="partenaire">Partenaire</option>
            </select>
          </label>
        </fieldset>

        <fieldset>
          <legend>Souscription initiale (optionnel)</legend>
          <div class="grid-2">
            <label class="field"><span>Nombre de parts</span>
              <input type="number" name="nombre_parts_initial" min="0" max="10000" value="0"></label>
            <label class="field"><span>Devise</span>
              <select name="devise">
                <option value="CDF">🇨🇩 CDF</option>
                <option value="USD">🇺🇸 USD</option>
              </select></label>
          </div>
          <p class="hint">
            Prix actuel : <?= $capital ? number_format((float)$capital['prix_unitaire'], 0, ',', ' ') . ' ' . $capital['devise'] : '—' ?> / part
          </p>
        </fieldset>

        <fieldset>
          <legend>Sécurité — Mot de passe</legend>
          <div class="grid-2">
            <label class="field"><span>Mot de passe *</span>
              <input type="password" id="pwd1" name="mot_de_passe" required minlength="8" autocomplete="new-password"></label>
            <label class="field"><span>Confirmer *</span>
              <input type="password" id="pwd2" name="mot_de_passe2" required minlength="8" autocomplete="new-password"></label>
          </div>
          <div class="pwd-meter"><div id="pwdBar"></div></div>
          <p class="hint">8 car. min. · 1 majuscule · 1 minuscule · 1 chiffre · 1 symbole</p>
        </fieldset>

        <button class="btn-gold full" type="submit" style="margin-top:10px">
          🏛️ Créer l'actionnaire
        </button>
      </form>
    </section>

    <!-- Liste -->
    <section class="card animate-in delay-3">
      <h3 style="margin-bottom:16px">📋 Liste des actionnaires (<?= count($actionnaires) ?>)</h3>

      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>N° Actionnaire</th>
              <th>Nom complet</th>
              <th>Type</th>
              <th style="text-align:right">Parts</th>
              <th style="text-align:right">%</th>
              <th style="text-align:right">Capital</th>
              <th>Statut</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$actionnaires): ?>
              <tr><td colspan="8" class="muted center" style="padding:30px">Aucun actionnaire.</td></tr>
            <?php else: foreach ($actionnaires as $a):
              $pct = getPourcentageDetention($pdo, (int)$a['nombre_parts']);
            ?>
              <tr>
                <td><code><?= htmlspecialchars($a['numero_actionnaire']) ?></code></td>
                <td>
                  <strong><?= htmlspecialchars($a['nom'] . ' ' . $a['postnom']) ?></strong><br>
                  <small class="muted"><?= htmlspecialchars($a['prenom']) ?> · <?= htmlspecialchars($a['numero_phone']) ?></small>
                </td>
                <td><span class="badge <?= $a['type_actionnaire'] === 'fondateur' ? 'gold' : 'blue' ?>"><?= htmlspecialchars($a['type_actionnaire']) ?></span></td>
                <td style="text-align:right"><strong><?= number_format((int)$a['nombre_parts'], 0, ',', ' ') ?></strong></td>
                <td style="text-align:right"><strong style="color:var(--gold)"><?= number_format($pct, 2) ?>%</strong></td>
                <td style="text-align:right"><?= number_format((float)$a['capital_investi_cdf'], 0, ',', ' ') ?> CDF</td>
                <td><span class="badge <?= $a['statut'] === 'actif' ? 'green' : 'red' ?>"><?= htmlspecialchars($a['statut']) ?></span></td>
                <td>
                  <div class="actions-cell">
                    <a class="btn-action-mini view"
                       href="<?= $base ?>/admin/actionnaire_details.php?token=<?= htmlspecialchars(genererToken('actionnaire', (int)$a['id_actionnaire'])) ?>">
                      👁 Voir
                    </a>
                    <button class="btn-action-mini edit"
                            data-actionnaire='<?= htmlspecialchars(json_encode([
                              'id' => (int)$a['id_actionnaire'],
                              'numero' => $a['numero_actionnaire'],
                              'nom' => $a['nom'],
                              'postnom' => $a['postnom'],
                              'prenom' => $a['prenom'],
                              'email' => $a['email'],
                              'phone' => $a['numero_phone'],
                              'adresse' => $a['adresse_complete'],
                              'type' => $a['type_actionnaire'],
                              'statut' => $a['statut'],
                              'devise' => $a['devise'] ?? 'CDF',
                            ]), ENT_QUOTES, 'UTF-8') ?>'
                            onclick="ouvrirModif(this)">
                      ✏️ Modifier
                    </button>
                    <button class="btn-action-mini delete"
                            data-actionnaire='<?= htmlspecialchars(json_encode([
                              'id' => (int)$a['id_actionnaire'],
                              'numero' => $a['numero_actionnaire'],
                              'nom' => $a['nom'] . ' ' . $a['postnom'],
                              'parts' => (int)$a['nombre_parts'],
                            ]), ENT_QUOTES, 'UTF-8') ?>'
                            onclick="ouvrirSuppr(this)">
                      🗑 Supprimer
                    </button>
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

<!-- ============================================================
     MODALE : MODIFIER
     ============================================================ -->
<div class="modal-overlay" id="modalModif">
  <div class="modal-content">
    <div class="modal-header">
      <h3>✏️ Modifier l'actionnaire</h3>
      <button class="modal-close" onclick="fermerModal('modalModif')">✕</button>
    </div>

    <form method="POST" action="<?= $base ?>/admin/actionnaires_action.php">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="modifier">
      <input type="hidden" name="id_actionnaire" id="modifId">

      <fieldset>
        <legend>Identité</legend>
        <div class="grid-3">
          <label class="field"><span>Nom</span><input type="text" name="nom" id="modifNom" required maxlength="60"></label>
          <label class="field"><span>Post-nom</span><input type="text" name="postnom" id="modifPostnom" required maxlength="60"></label>
          <label class="field"><span>Prénom</span><input type="text" name="prenom" id="modifPrenom" required maxlength="60"></label>
        </div>
        <div class="grid-2">
          <label class="field"><span>Téléphone</span><input type="text" name="numero_phone" id="modifPhone" required maxlength="25"></label>
          <label class="field"><span>Email</span><input type="email" name="email" id="modifEmail" maxlength="120"></label>
        </div>
        <label class="field"><span>Adresse</span><textarea name="adresse_complete" id="modifAdresse" rows="2" maxlength="500"></textarea></label>
      </fieldset>

      <fieldset>
        <legend>Statut & Type</legend>
        <div class="grid-2">
          <label class="field"><span>Type</span>
            <select name="type_actionnaire" id="modifType">
              <option value="investisseur">Investisseur</option>
              <option value="fondateur">Fondateur</option>
              <option value="employe">Employé</option>
              <option value="partenaire">Partenaire</option>
            </select>
          </label>
          <label class="field"><span>Statut</span>
            <select name="statut" id="modifStatut">
              <option value="actif">Actif</option>
              <option value="inactif">Inactif</option>
              <option value="suspendu">Suspendu</option>
            </select>
          </label>
        </div>
      </fieldset>

      <fieldset>
        <legend>Devise monétaire préférée</legend>
        <label class="field"><span>Devise</span>
          <select name="devise" id="modifDevise">
            <option value="CDF">🇨🇩 CDF - Franc Congolais</option>
            <option value="USD">🇺🇸 USD - Dollar Américain</option>
          </select>
        </label>
      </fieldset>

      <div class="modal-actions">
        <button type="button" class="btn-ghost" onclick="fermerModal('modalModif')">Annuler</button>
        <button type="submit" class="btn-gold">💾 Enregistrer</button>
      </div>
    </form>
  </div>
</div>

<!-- ============================================================
     MODALE : SUPPRIMER
     ============================================================ -->
<div class="modal-overlay" id="modalSuppr">
  <div class="modal-content" style="max-width:500px">
    <div class="modal-header">
      <h3>🗑 Supprimer l'actionnaire</h3>
      <button class="modal-close" onclick="fermerModal('modalSuppr')">✕</button>
    </div>

    <div class="danger-box">
      ⚠️ <strong>Attention !</strong><br>
      Vous êtes sur le point de supprimer :<br>
      <strong id="supprNom" style="font-size:1.1rem;color:#FCA5A5"></strong><br>
      <small id="supprInfo" style="color:#94A3B8"></small>
    </div>

    <form method="POST" action="<?= $base ?>/admin/actionnaires_action.php">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="supprimer">
      <input type="hidden" name="id_actionnaire" id="supprId">

      <label class="field">
        <span>Raison (obligatoire)</span>
        <textarea name="raison" rows="2" required placeholder="Ex: Démission, erreur de saisie..."></textarea>
      </label>

      <p style="color:#FCA5A5;font-size:.8rem;margin-top:12px">
        ⚠️ Cette action est <strong>irréversible</strong> et supprimera toutes les données liées (parts, dividendes...).
      </p>

      <div class="modal-actions">
        <button type="button" class="btn-ghost" onclick="fermerModal('modalSuppr')">Annuler</button>
        <button type="submit" style="background:linear-gradient(135deg,#DC2626,#EF4444);color:#fff;padding:12px 24px;border-radius:12px;border:none;font-weight:800;cursor:pointer;">
          🗑 Confirmer
        </button>
      </div>
    </form>
  </div>
</div>

<footer class="atf-footer">© <?= date('Y') ?> AFRICAN TRUST FUND</footer>

<script>
window.addEventListener('scroll', () => {
  const h = document.documentElement.scrollHeight - window.innerHeight;
  document.getElementById('topProgress').style.width = (window.scrollY / h) * 100 + '%';
});

function ouvrirModal(id) { document.getElementById(id).classList.add('active'); }
function fermerModal(id) { document.getElementById(id).classList.remove('active'); }

document.querySelectorAll('.modal-overlay').forEach(ov => {
  ov.addEventListener('click', e => {
    if (e.target === ov) ov.classList.remove('active');
  });
});

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-overlay.active').forEach(m => m.classList.remove('active'));
  }
});

function ouvrirModif(btn) {
  const a = JSON.parse(btn.dataset.actionnaire);
  document.getElementById('modifId').value       = a.id;
  document.getElementById('modifNom').value      = a.nom;
  document.getElementById('modifPostnom').value  = a.postnom;
  document.getElementById('modifPrenom').value   = a.prenom;
  document.getElementById('modifPhone').value    = a.phone;
  document.getElementById('modifEmail').value    = a.email || '';
  document.getElementById('modifAdresse').value  = a.adresse || '';
  document.getElementById('modifType').value     = a.type;
  document.getElementById('modifStatut').value   = a.statut;
  document.getElementById('modifDevise').value   = a.devise || 'CDF';
  ouvrirModal('modalModif');
}

function ouvrirSuppr(btn) {
  const a = JSON.parse(btn.dataset.actionnaire);
  document.getElementById('supprId').value = a.id;
  document.getElementById('supprNom').textContent = a.nom;
  document.getElementById('supprInfo').textContent = 'N° ' + a.numero + ' · ' + a.parts + ' part(s)';
  ouvrirModal('modalSuppr');
}
</script>
<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>