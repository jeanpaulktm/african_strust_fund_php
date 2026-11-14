<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/prets.php';
requireRole('admin');

$pdo = Database::getConnection();
$admin_id = (int)$_SESSION['admin_id'];

$errors = [];
$success = '';

/* ============================================================
   FONCTION : Générer un numéro de compte entreprise unique
   ============================================================ */
function genererNumeroCompteEntreprise(PDO $pdo): string
{
    $prefix = 'ATF-PM-';

    // Chercher le dernier numéro
    $stmt = $pdo->prepare("
        SELECT numero_compte 
        FROM Client 
        WHERE numero_compte LIKE ?
        ORDER BY numero_compte DESC 
        LIMIT 1
    ");
    $stmt->execute([$prefix . '%']);
    $last = $stmt->fetchColumn();

    $num = 1;
    if ($last) {
        $num = (int)substr($last, -6) + 1;
    }

    // Boucle pour éviter les collisions
    $tentatives = 0;
    do {
        $candidat = $prefix . str_pad((string)$num, 6, '0', STR_PAD_LEFT);
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM Client WHERE numero_compte = ?");
        $stmt->execute([$candidat]);
        $existe = (int)$stmt->fetchColumn() > 0;
        
        if (!$existe) return $candidat;
        
        $num++;
        $tentatives++;
        
        if ($tentatives > 100) {
            throw new RuntimeException("Impossible de générer un numéro de compte unique.");
        }
    } while (true);
}

/* ============================================================
   FONCTION : Valider un mot de passe fort
   ============================================================ */
function validerMotDePasseFort(string $pwd): array
{
    $erreurs = [];
    
    if (strlen($pwd) < 8) {
        $erreurs[] = "Le mot de passe doit contenir au moins 8 caractères.";
    }
    if (!preg_match('/[A-Z]/', $pwd)) {
        $erreurs[] = "Le mot de passe doit contenir au moins 1 majuscule.";
    }
    if (!preg_match('/[a-z]/', $pwd)) {
        $erreurs[] = "Le mot de passe doit contenir au moins 1 minuscule.";
    }
    if (!preg_match('/[0-9]/', $pwd)) {
        $erreurs[] = "Le mot de passe doit contenir au moins 1 chiffre.";
    }
    if (!preg_match('/[^A-Za-z0-9]/', $pwd)) {
        $erreurs[] = "Le mot de passe doit contenir au moins 1 caractère spécial.";
    }
    
    return $erreurs;
}

/* ============================================================
   FONCTION : Générer un mot de passe aléatoire fort
   ============================================================ */
function genererMotDePasseAleatoire(int $longueur = 12): string
{
    $majuscules = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $minuscules = 'abcdefghijkmnpqrstuvwxyz';
    $chiffres   = '23456789';
    $speciaux   = '!@#$%&*+-=?';

    $tous = $majuscules . $minuscules . $chiffres . $speciaux;

    // Garantir au moins 1 de chaque
    $pwd = $majuscules[random_int(0, strlen($majuscules) - 1)]
         . $minuscules[random_int(0, strlen($minuscules) - 1)]
         . $chiffres[random_int(0, strlen($chiffres) - 1)]
         . $speciaux[random_int(0, strlen($speciaux) - 1)];

    // Compléter
    for ($i = strlen($pwd); $i < $longueur; $i++) {
        $pwd .= $tous[random_int(0, strlen($tous) - 1)];
    }

    // Mélanger
    return str_shuffle($pwd);
}

/* ============================================================
   TRAITEMENT POST — CRÉATION ENTREPRISE
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) {
            throw new RuntimeException("Jeton de sécurité invalide.");
        }

        /* ============================================================
           DONNÉES CLIENT (compte entreprise)
           ============================================================ */
        $nom_client     = cleanString($_POST['nom_client'] ?? '', 60);
        $postnom_client = cleanString($_POST['postnom_client'] ?? '', 60);
        $prenom_client  = cleanString($_POST['prenom_client'] ?? '', 60);
        $phone_client   = cleanString($_POST['phone_client'] ?? '', 25);
        $email_client   = filter_var($_POST['email_client'] ?? '', FILTER_VALIDATE_EMAIL) ?: null;
        $adresse_client = cleanString($_POST['adresse_client'] ?? '', 500);

        /* ============================================================
           DONNÉES ENTREPRISE
           ============================================================ */
        $raison_sociale  = cleanString($_POST['raison_sociale'] ?? '', 150);
        $forme_juridique = in_array($_POST['forme_juridique'] ?? '', ['SARL','SA','SAS','SNC','coop','autre'], true)
                            ? $_POST['forme_juridique'] : 'SARL';
        $rccm            = cleanString($_POST['rccm'] ?? '', 50);
        $nif             = cleanString($_POST['nif'] ?? '', 50);
        $capital_social  = (float)str_replace([' ', ','], ['', '.'], $_POST['capital_social'] ?? '0');
        $devise_capital  = in_array($_POST['devise_capital'] ?? '', ['CDF','USD'], true) ? $_POST['devise_capital'] : 'CDF';
        $date_creation   = $_POST['date_creation'] ?? null;
        $secteur         = cleanString($_POST['secteur_activite'] ?? '', 100);
        $adresse_siege   = cleanString($_POST['adresse_siege'] ?? '', 500);
        $tel_fixe        = cleanString($_POST['telephone_fixe'] ?? '', 25);
        $email_ent       = filter_var($_POST['email_entreprise'] ?? '', FILTER_VALIDATE_EMAIL) ?: null;
        $nb_employes     = (int)($_POST['nombre_employes'] ?? 0);

        /* ============================================================
           DIRIGEANT
           ============================================================ */
        $dir_nom    = cleanString($_POST['dirigeant_nom'] ?? '', 100);
        $dir_cni    = cleanString($_POST['dirigeant_cni'] ?? '', 50);
        $dir_phone  = cleanString($_POST['dirigeant_phone'] ?? '', 25);

        /* ============================================================
           SÉCURITÉ — Mot de passe
           ============================================================ */
        $mot_de_passe  = (string)($_POST['mot_de_passe'] ?? '');
        $mot_de_passe2 = (string)($_POST['mot_de_passe2'] ?? '');

        /* ============================================================
           VALIDATIONS
           ============================================================ */
        if (!$nom_client || !$postnom_client || !$prenom_client) {
            $errors[] = "Le nom, post-nom et prénom du responsable sont obligatoires.";
        }
        if (!preg_match('/^\+?[0-9 ]{9,20}$/', $phone_client)) {
            $errors[] = "Téléphone du responsable invalide.";
        }
        if (!$raison_sociale) {
            $errors[] = "La raison sociale est obligatoire.";
        }
        if ($rccm === '') {
            $errors[] = "Le numéro RCCM est obligatoire pour une personne morale.";
        }
        if ($nif === '') {
            $errors[] = "Le numéro NIF est obligatoire pour une personne morale.";
        }
        if ($capital_social <= 0) {
            $errors[] = "Le capital social doit être supérieur à 0.";
        }

        /* Vérifier unicité RCCM et NIF */
        if (!$errors) {
            $chk = $pdo->prepare("SELECT raison_sociale FROM Entreprises WHERE rccm = ? OR nif = ?");
            $chk->execute([$rccm, $nif]);
            $existante = $chk->fetchColumn();
            if ($existante) {
                $errors[] = "Une entreprise avec ce RCCM ou NIF existe déjà : $existante";
            }
        }

        /* Vérifier téléphone client */
        if (!$errors) {
            $chk = $pdo->prepare("SELECT 1 FROM Client WHERE numero_phone = ?");
            $chk->execute([$phone_client]);
            if ($chk->fetchColumn()) {
                $errors[] = "Ce numéro de téléphone est déjà utilisé.";
            }
        }

        /* ============================================================
           VALIDATION MOT DE PASSE
           ============================================================ */
        if ($mot_de_passe === '' || $mot_de_passe2 === '') {
            $errors[] = "Le mot de passe est obligatoire.";
        } elseif ($mot_de_passe !== $mot_de_passe2) {
            $errors[] = "Les mots de passe ne correspondent pas.";
        } else {
            $pwd_erreurs = validerMotDePasseFort($mot_de_passe);
            if (!empty($pwd_erreurs)) {
                foreach ($pwd_erreurs as $pe) {
                    $errors[] = "Mot de passe : $pe";
                }
            }
        }

        /* ============================================================
           ENREGISTREMENT
           ============================================================ */
        if (!$errors) {
            $pdo->beginTransaction();

            /* Générer numéro de compte */
            $numero_compte = genererNumeroCompteEntreprise($pdo);

            /* Hash du mot de passe */
            $hash = password_hash($mot_de_passe, PASSWORD_BCRYPT, ['cost' => 12]);

            /* Créer le Client (compte entreprise) */
            $pdo->prepare("
                INSERT INTO Client 
                    (nom, postnom, prenom, numero_phone, email, adresse_complete,
                     numero_compte, statut, devise, type_client, mot_de_passe, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'actif', ?, 'morale', ?, NOW())
            ")->execute([
                $nom_client,
                $postnom_client,
                $prenom_client,
                $phone_client,
                $email_client,
                $adresse_client,
                $numero_compte,
                $devise_capital,
                $hash
            ]);

            $id_client = (int)$pdo->lastInsertId();

            /* Créer l'entreprise */
            $pdo->prepare("
                INSERT INTO Entreprises 
                    (id_client, raison_sociale, forme_juridique, rccm, nif,
                     capital_social, devise_capital, date_creation, secteur_activite,
                     adresse_siege, telephone_fixe, email_entreprise, nombre_employes,
                     dirigeant_nom, dirigeant_cni, dirigeant_phone,
                     statut_verification, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'en_attente', NOW())
            ")->execute([
                $id_client,
                $raison_sociale,
                $forme_juridique,
                $rccm,
                $nif,
                $capital_social,
                $devise_capital,
                $date_creation ?: null,
                $secteur ?: null,
                $adresse_siege ?: null,
                $tel_fixe ?: null,
                $email_ent,
                $nb_employes,
                $dir_nom ?: null,
                $dir_cni ?: null,
                $dir_phone ?: null
            ]);

            $id_entreprise = (int)$pdo->lastInsertId();

            /* Créer le dirigeant principal */
            if ($dir_nom) {
                $pdo->prepare("
                    INSERT INTO Entreprise_Dirigeants 
                        (id_entreprise, nom, role, cni, telephone, est_signataire)
                    VALUES (?, ?, 'gerant', ?, ?, TRUE)
                ")->execute([
                    $id_entreprise,
                    $dir_nom,
                    $dir_cni ?: null,
                    $dir_phone ?: null
                ]);
            }

            $pdo->commit();

            /* Log */
            logAction($pdo, 'admin', $admin_id, 'ENTREPRISE_CREATE',
                "ID: $id_entreprise | Raison: $raison_sociale | Compte: $numero_compte");

            /* Message de succès avec toutes les infos */
            $_SESSION['flash_success'] = "
                <div style='line-height:1.7'>
                    <strong style='font-size:1.05rem'>✅ Entreprise créée avec succès !</strong><br><br>
                    <strong>🏢 Raison sociale :</strong> " . htmlspecialchars($raison_sociale) . "<br>
                    <strong>🆔 Numéro de compte :</strong> <code style='background:rgba(245,184,0,.15);color:var(--gold);padding:2px 8px;border-radius:4px'>$numero_compte</code><br>
                    <strong>🔑 Mot de passe :</strong> celui que vous avez défini<br>
                    <strong>👤 Responsable :</strong> " . htmlspecialchars("$prenom_client $nom_client") . "<br><br>
                    <div style='padding:12px;border-radius:8px;background:rgba(59,130,246,.1);border-left:3px solid #3B82F6;color:#93B4FF'>
                        💡 <strong>Important :</strong> Communiquez ces identifiants à l'entreprise.<br>
                        Elle pourra se connecter sur : <strong>/entreprise/login.php</strong>
                    </div>
                </div>
            ";

            header('Location: ' . url('admin/entreprises.php'));
            exit;
        }

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = $e->getMessage();
    }
}

/* Générer un mot de passe suggéré au chargement */
$mot_de_passe_suggere = genererMotDePasseAleatoire(12);

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Nouvelle Entreprise — ATF Admin</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/admin-premium.css">
<style>
/* ============================================================
   HERO
   ============================================================ */
.page-hero {
    padding: 24px 28px;
    border-radius: 20px;
    background: linear-gradient(135deg, rgba(139,92,246,.15), rgba(76,29,149,.05)),
                radial-gradient(circle at 80% 20%, rgba(167,139,250,.18), transparent 60%);
    border: 1px solid rgba(139,92,246,.35);
    margin-bottom: 24px;
    position: relative;
    overflow: hidden;
}
.page-hero::before {
    content: "";
    position: absolute;
    top: -40%; right: -10%;
    width: 300px; height: 300px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(139,92,246,.2), transparent 70%);
    animation: float 8s ease-in-out infinite;
}
.page-hero h2 {
    color: #C4B5FD;
    font-size: 1.5rem;
    margin: 0 0 6px 0;
    position: relative;
    z-index: 1;
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
}
.page-hero p {
    color: var(--muted);
    font-size: .92rem;
    position: relative;
    z-index: 1;
    margin: 0;
}

/* ============================================================
   FORM SECTIONS
   ============================================================ */
.form-card {
    padding: 26px 28px;
    border-radius: 20px;
    background: linear-gradient(145deg, var(--card), var(--card-2));
    border: 1px solid var(--border);
    margin-bottom: 20px;
}
.form-card h3 {
    color: var(--gold);
    font-size: 1.05rem;
    margin-bottom: 18px;
    display: flex;
    align-items: center;
    gap: 10px;
    padding-bottom: 12px;
    border-bottom: 2px solid rgba(245,184,0,.2);
}

/* ============================================================
   PASSWORD STRENGTH METER
   ============================================================ */
.pwd-wrapper {
    position: relative;
}
.pwd-toggle {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    background: rgba(255,255,255,.08);
    border: 1px solid var(--border);
    border-radius: 6px;
    color: var(--muted);
    cursor: pointer;
    padding: 4px 8px;
    font-size: .9rem;
}
.pwd-toggle:hover { background: rgba(255,255,255,.15); }

.pwd-meter {
    height: 6px;
    background: rgba(11,14,20,.6);
    border-radius: 3px;
    overflow: hidden;
    margin-top: 8px;
    position: relative;
}
.pwd-meter > div {
    height: 100%;
    border-radius: 3px;
    transition: all .3s;
    width: 0%;
}
.pwd-meter .weak { background: #EF4444; width: 25%; }
.pwd-meter .medium { background: #F5B800; width: 50%; }
.pwd-meter .good { background: #3B82F6; width: 75%; }
.pwd-meter .strong { background: #10B981; width: 100%; }

.pwd-feedback {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-top: 10px;
}
.pwd-critere {
    padding: 4px 10px;
    border-radius: 6px;
    font-size: .7rem;
    font-weight: 700;
    background: rgba(107,114,128,.15);
    color: #9CA3AF;
    border: 1px solid rgba(107,114,128,.3);
    transition: all .2s;
}
.pwd-critere.ok {
    background: rgba(16,185,129,.15);
    color: #6EE7B7;
    border-color: rgba(16,185,129,.35);
}

/* Suggestions de mot de passe */
.pwd-suggestion {
    margin-top: 12px;
    padding: 12px 14px;
    border-radius: 10px;
    background: rgba(59,130,246,.08);
    border: 1px solid rgba(59,130,246,.3);
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.pwd-suggestion .val {
    font-family: monospace;
    color: #93B4FF;
    font-weight: 800;
    font-size: .95rem;
    letter-spacing: 1px;
}
.btn-copy-suggest {
    padding: 6px 12px;
    border-radius: 6px;
    background: rgba(59,130,246,.2);
    color: #93B4FF;
    border: 1px solid rgba(59,130,246,.4);
    font-weight: 700;
    font-size: .75rem;
    cursor: pointer;
    font-family: inherit;
    transition: all .2s;
}
.btn-copy-suggest:hover {
    background: rgba(59,130,246,.35);
    transform: translateY(-1px);
}

/* ============================================================
   INFO BOX
   ============================================================ */
.info-box {
    padding: 14px 16px;
    border-radius: 12px;
    background: rgba(59,130,246,.08);
    border: 1px solid rgba(59,130,246,.3);
    color: #93B4FF;
    font-size: .82rem;
    line-height: 1.6;
    margin-bottom: 16px;
}
.info-box strong { color: #fff; }

/* ============================================================
   BOUTON SUBMIT
   ============================================================ */
.btn-submit {
    width: 100%;
    padding: 18px;
    border-radius: 999px;
    background: linear-gradient(135deg, #8B5CF6, #7C3AED);
    color: #fff;
    border: none;
    font-weight: 800;
    font-size: 1.05rem;
    cursor: pointer;
    font-family: inherit;
    box-shadow: 0 8px 24px rgba(139,92,246,.4);
    transition: all .25s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 10px;
    margin-top: 20px;
}
.btn-submit:hover:not(:disabled) {
    transform: translateY(-2px);
    box-shadow: 0 12px 32px rgba(139,92,246,.6);
}
.btn-submit:disabled {
    opacity: .5;
    cursor: not-allowed;
}

/* ============================================================
   TOAST
   ============================================================ */
.toast {
    position: fixed;
    bottom: 30px;
    left: 50%;
    transform: translateX(-50%) translateY(100px);
    background: linear-gradient(135deg, #10B981, #059669);
    color: #fff;
    padding: 14px 24px;
    border-radius: 999px;
    font-weight: 700;
    box-shadow: 0 12px 40px rgba(0,0,0,.5);
    z-index: 99999;
    opacity: 0;
    transition: all .4s cubic-bezier(.4,0,.2,1);
    pointer-events: none;
}
.toast.show {
    opacity: 1;
    transform: translateX(-50%) translateY(0);
}

@keyframes float {
    0%, 100% { transform: translateY(0); }
    50% { transform: translateY(-15px); }
}

@media (max-width: 640px) {
    .page-hero { padding: 20px; }
    .page-hero h2 { font-size: 1.25rem; }
    .form-card { padding: 20px 18px; }
}
</style>
</head>
<body class="dark-auth">

<?php require __DIR__ . '/../includes/header.php'; ?>

<div class="admin-layout">
  <aside class="admin-sidebar animate-left">
    <h3>📊 Navigation</h3>
    <a href="<?= $base ?>/admin/dashboard.php"><span class="ico">📊</span> Tableau de bord</a>
    <a href="<?= $base ?>/admin/clients.php"><span class="ico">👥</span> Clients</a>
    <a href="<?= $base ?>/admin/entreprises.php" class="active"><span class="ico">🏢</span> Entreprises</a>
    <a href="<?= $base ?>/admin/actionnaires.php"><span class="ico">🏛️</span> Actionnaires</a>
    <a href="<?= $base ?>/admin/prets.php"><span class="ico">💳</span> Prêts</a>
    <a href="<?= $base ?>/admin/logs.php"><span class="ico">📜</span> Journal</a>
    <a href="<?= $base ?>/auth/logout.php"><span class="ico">🚪</span> Déconnexion</a>
  </aside>

  <main class="admin-main">

    <!-- HERO -->
    <div class="page-hero">
      <h2>🏢 Nouvelle entreprise</h2>
      <p>Créer un compte pour une personne morale (entreprise)</p>
    </div>

    <!-- MESSAGES -->
    <?php if ($errors): ?>
      <div class="alert error">
        <strong>❌ Erreur(s) :</strong>
        <ul style="margin-top:8px;margin-left:20px">
          <?php foreach ($errors as $e) echo "<li>" . htmlspecialchars($e) . "</li>"; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="POST" novalidate autocomplete="off" id="formEntreprise">
      <?= csrfField() ?>

      <!-- ============================================================
           SECTION 1 : RESPONSABLE LÉGAL (Compte client)
           ============================================================ -->
      <div class="form-card">
        <h3>👤 Responsable légal (compte client)</h3>
        
        <div class="info-box">
          💡 Le responsable légal sera le titulaire du compte. Il pourra se connecter 
          avec le <strong>numéro de compte entreprise</strong> et le <strong>mot de passe</strong>.
        </div>

        <div class="grid-3">
          <label class="field">
            <span>Nom *</span>
            <input type="text" name="nom_client" required maxlength="60" 
                   value="<?= htmlspecialchars($_POST['nom_client'] ?? '') ?>">
          </label>
          <label class="field">
            <span>Post-nom *</span>
            <input type="text" name="postnom_client" required maxlength="60"
                   value="<?= htmlspecialchars($_POST['postnom_client'] ?? '') ?>">
          </label>
          <label class="field">
            <span>Prénom *</span>
            <input type="text" name="prenom_client" required maxlength="60"
                   value="<?= htmlspecialchars($_POST['prenom_client'] ?? '') ?>">
          </label>
        </div>

        <div class="grid-2">
          <label class="field">
            <span>Téléphone *</span>
            <input type="text" name="phone_client" required maxlength="25" 
                   placeholder="+243 ..."
                   value="<?= htmlspecialchars($_POST['phone_client'] ?? '') ?>">
          </label>
          <label class="field">
            <span>Email</span>
            <input type="email" name="email_client" maxlength="150"
                   value="<?= htmlspecialchars($_POST['email_client'] ?? '') ?>">
          </label>
        </div>

        <label class="field">
          <span>Adresse du responsable</span>
          <textarea name="adresse_client" rows="2" maxlength="500"><?= htmlspecialchars($_POST['adresse_client'] ?? '') ?></textarea>
        </label>
      </div>

      <!-- ============================================================
           SECTION 2 : INFORMATIONS ENTREPRISE
           ============================================================ -->
      <div class="form-card">
        <h3>🏢 Informations de l'entreprise</h3>

        <label class="field">
          <span>Raison sociale *</span>
          <input type="text" name="raison_sociale" required maxlength="150" 
                 placeholder="Ex: AFRICAN TRADING SARL"
                 value="<?= htmlspecialchars($_POST['raison_sociale'] ?? '') ?>">
        </label>

        <div class="grid-2">
          <label class="field">
            <span>Forme juridique *</span>
            <select name="forme_juridique" required>
              <option value="SARL" <?= ($_POST['forme_juridique'] ?? '') === 'SARL' ? 'selected' : '' ?>>SARL</option>
              <option value="SA" <?= ($_POST['forme_juridique'] ?? '') === 'SA' ? 'selected' : '' ?>>SA</option>
              <option value="SAS" <?= ($_POST['forme_juridique'] ?? '') === 'SAS' ? 'selected' : '' ?>>SAS</option>
              <option value="SNC" <?= ($_POST['forme_juridique'] ?? '') === 'SNC' ? 'selected' : '' ?>>SNC</option>
              <option value="coop" <?= ($_POST['forme_juridique'] ?? '') === 'coop' ? 'selected' : '' ?>>Coopérative</option>
              <option value="autre" <?= ($_POST['forme_juridique'] ?? '') === 'autre' ? 'selected' : '' ?>>Autre</option>
            </select>
          </label>
          <label class="field">
            <span>Date de création</span>
            <input type="date" name="date_creation"
                   value="<?= htmlspecialchars($_POST['date_creation'] ?? '') ?>">
          </label>
        </div>

        <div class="grid-2">
          <label class="field">
            <span>RCCM *</span>
            <input type="text" name="rccm" required maxlength="50" 
                   placeholder="CD/KIN/RCCM/..."
                   value="<?= htmlspecialchars($_POST['rccm'] ?? '') ?>">
            <span class="hint" style="color:var(--muted);font-size:.72rem;margin-top:4px;display:block">Obligatoire pour une personne morale</span>
          </label>
          <label class="field">
            <span>NIF *</span>
            <input type="text" name="nif" required maxlength="50" 
                   placeholder="A0123456Z"
                   value="<?= htmlspecialchars($_POST['nif'] ?? '') ?>">
            <span class="hint" style="color:var(--muted);font-size:.72rem;margin-top:4px;display:block">Obligatoire pour une personne morale</span>
          </label>
        </div>

        <div class="grid-3">
          <label class="field">
            <span>Capital social *</span>
            <input type="text" name="capital_social" required 
                   placeholder="1000000" inputmode="decimal"
                   value="<?= htmlspecialchars($_POST['capital_social'] ?? '') ?>">
          </label>
          <label class="field">
            <span>Devise du capital</span>
            <select name="devise_capital">
              <option value="CDF" <?= ($_POST['devise_capital'] ?? 'CDF') === 'CDF' ? 'selected' : '' ?>>🇨🇩 CDF</option>
              <option value="USD" <?= ($_POST['devise_capital'] ?? '') === 'USD' ? 'selected' : '' ?>>🇺🇸 USD</option>
            </select>
          </label>
          <label class="field">
            <span>Nombre d'employés</span>
            <input type="number" name="nombre_employes" min="0" 
                   value="<?= (int)($_POST['nombre_employes'] ?? 0) ?>">
          </label>
        </div>

        <label class="field">
          <span>Secteur d'activité</span>
          <input type="text" name="secteur_activite" maxlength="100" 
                 placeholder="Ex: Commerce général, Transport, Agriculture..."
                 value="<?= htmlspecialchars($_POST['secteur_activite'] ?? '') ?>">
        </label>

        <label class="field">
          <span>Adresse du siège social</span>
          <textarea name="adresse_siege" rows="2" maxlength="500"><?= htmlspecialchars($_POST['adresse_siege'] ?? '') ?></textarea>
        </label>

        <div class="grid-2">
          <label class="field">
            <span>Téléphone fixe</span>
            <input type="text" name="telephone_fixe" maxlength="25"
                   value="<?= htmlspecialchars($_POST['telephone_fixe'] ?? '') ?>">
          </label>
          <label class="field">
            <span>Email entreprise</span>
            <input type="email" name="email_entreprise" maxlength="150"
                   value="<?= htmlspecialchars($_POST['email_entreprise'] ?? '') ?>">
          </label>
        </div>
      </div>

      <!-- ============================================================
           SECTION 3 : DIRIGEANT PRINCIPAL
           ============================================================ -->
      <div class="form-card">
        <h3>👔 Dirigeant principal</h3>

        <div class="grid-3">
          <label class="field">
            <span>Nom complet</span>
            <input type="text" name="dirigeant_nom" maxlength="100"
                   placeholder="Ex: Jean Dupont"
                   value="<?= htmlspecialchars($_POST['dirigeant_nom'] ?? '') ?>">
          </label>
          <label class="field">
            <span>N° CNI</span>
            <input type="text" name="dirigeant_cni" maxlength="50"
                   value="<?= htmlspecialchars($_POST['dirigeant_cni'] ?? '') ?>">
          </label>
          <label class="field">
            <span>Téléphone</span>
            <input type="text" name="dirigeant_phone" maxlength="25"
                   value="<?= htmlspecialchars($_POST['dirigeant_phone'] ?? '') ?>">
          </label>
        </div>

        <div class="info-box">
          💡 Le dirigeant sera automatiquement enregistré comme <strong>gérant signataire</strong>.
        </div>
      </div>

      <!-- ============================================================
           SECTION 4 : SÉCURITÉ — MOT DE PASSE
           ============================================================ -->
      <div class="form-card">
        <h3>🔐 Sécurité — Mot de passe d'accès</h3>

        <div class="info-box">
          🔑 Ce mot de passe permettra à l'entreprise de se connecter à son espace. 
          <strong>Communiquez-le lui de manière sécurisée.</strong>
        </div>

        <!-- Suggestion automatique -->
        <div class="pwd-suggestion">
          <div>
            <div style="color:var(--muted);font-size:.72rem;text-transform:uppercase;font-weight:700;margin-bottom:4px">
              💡 Mot de passe suggéré (fort)
            </div>
            <div class="val" id="pwdSuggestion"><?= htmlspecialchars($mot_de_passe_suggere) ?></div>
          </div>
          <button type="button" class="btn-copy-suggest" onclick="utiliserSuggestion()">
            📋 Utiliser
          </button>
        </div>

        <div class="grid-2" style="margin-top:16px">
          <div class="field">
            <span>Mot de passe *</span>
            <div class="pwd-wrapper">
              <input type="password" name="mot_de_passe" id="pwd1" required minlength="8" 
                     autocomplete="new-password" maxlength="100">
              <button type="button" class="pwd-toggle" onclick="togglePwd('pwd1')">👁️</button>
            </div>
            <div class="pwd-meter" id="pwdMeter">
              <div></div>
            </div>
            <div class="pwd-feedback" id="pwdFeedback">
              <span class="pwd-critere" data-critere="length">8+ caractères</span>
              <span class="pwd-critere" data-critere="upper">Majuscule</span>
              <span class="pwd-critere" data-critere="lower">Minuscule</span>
              <span class="pwd-critere" data-critere="digit">Chiffre</span>
              <span class="pwd-critere" data-critere="special">Spécial</span>
            </div>
          </div>

          <div class="field">
            <span>Confirmer le mot de passe *</span>
            <div class="pwd-wrapper">
              <input type="password" name="mot_de_passe2" id="pwd2" required minlength="8" 
                     autocomplete="new-password" maxlength="100">
              <button type="button" class="pwd-toggle" onclick="togglePwd('pwd2')">👁️</button>
            </div>
            <div id="pwdMatch" style="margin-top:10px;font-size:.78rem;font-weight:700"></div>
          </div>
        </div>
      </div>

      <!-- SUBMIT -->
      <button class="btn-submit" type="submit" id="btnSubmit">
        🏢 Créer l'entreprise
      </button>
    </form>

  </main>
</div>

<!-- TOAST -->
<div class="toast" id="toast">
    <span id="toastMsg">—</span>
</div>

<footer class="atf-footer">© <?= date('Y') ?> AFRICAN TRUST FUND</footer>

<script>
/* ============================================================
   TOGGLE PASSWORD VISIBILITY
   ============================================================ */
function togglePwd(id) {
    const input = document.getElementById(id);
    input.type = input.type === 'password' ? 'text' : 'password';
}

/* ============================================================
   UTILISER LE MOT DE PASSE SUGGÉRÉ
   ============================================================ */
function utiliserSuggestion() {
    const suggestion = document.getElementById('pwdSuggestion').textContent.trim();
    document.getElementById('pwd1').value = suggestion;
    document.getElementById('pwd2').value = suggestion;
    evaluerMotDePasse();
    afficherToast('✅ Mot de passe suggéré appliqué');
}

/* ============================================================
   ÉVALUATION DU MOT DE PASSE
   ============================================================ */
document.getElementById('pwd1').addEventListener('input', evaluerMotDePasse);

function evaluerMotDePasse() {
    const pwd = document.getElementById('pwd1').value;
    const meter = document.querySelector('#pwdMeter > div');
    const feedback = document.getElementById('pwdFeedback');

    // Critères
    const criteres = {
        length: pwd.length >= 8,
        upper: /[A-Z]/.test(pwd),
        lower: /[a-z]/.test(pwd),
        digit: /[0-9]/.test(pwd),
        special: /[^A-Za-z0-9]/.test(pwd),
    };

    // Mettre à jour les badges
    feedback.querySelectorAll('.pwd-critere').forEach(badge => {
        const c = badge.dataset.critere;
        if (criteres[c]) {
            badge.classList.add('ok');
        } else {
            badge.classList.remove('ok');
        }
    });

    // Calculer le score
    const score = Object.values(criteres).filter(Boolean).length;

    // Mettre à jour la barre
    meter.className = '';
    if (pwd.length === 0) {
        meter.style.width = '0%';
    } else if (score <= 2) {
        meter.classList.add('weak');
    } else if (score === 3) {
        meter.classList.add('medium');
    } else if (score === 4) {
        meter.classList.add('good');
    } else {
        meter.classList.add('strong');
    }

    verifierCorrespondance();
}

/* ============================================================
   VÉRIFIER QUE LES 2 MOTS DE PASSE CORRESPONDENT
   ============================================================ */
document.getElementById('pwd2').addEventListener('input', verifierCorrespondance);

function verifierCorrespondance() {
    const p1 = document.getElementById('pwd1').value;
    const p2 = document.getElementById('pwd2').value;
    const match = document.getElementById('pwdMatch');

    if (!p2) {
        match.textContent = '';
        return;
    }

    if (p1 === p2) {
        match.textContent = '✅ Les mots de passe correspondent';
        match.style.color = '#6EE7B7';
    } else {
        match.textContent = '❌ Les mots de passe ne correspondent pas';
        match.style.color = '#FCA5A5';
    }
}

/* ============================================================
   TOAST
   ============================================================ */
function afficherToast(msg) {
    const toast = document.getElementById('toast');
    document.getElementById('toastMsg').textContent = msg;
    toast.classList.add('show');
    setTimeout(() => toast.classList.remove('show'), 3000);
}

/* ============================================================
   SUBMIT FEEDBACK
   ============================================================ */
document.getElementById('formEntreprise').addEventListener('submit', function(e) {
    const p1 = document.getElementById('pwd1').value;
    const p2 = document.getElementById('pwd2').value;

    if (p1 !== p2) {
        e.preventDefault();
        afficherToast('❌ Les mots de passe ne correspondent pas');
        return;
    }

    if (p1.length < 8) {
        e.preventDefault();
        afficherToast('❌ Le mot de passe doit contenir au moins 8 caractères');
        return;
    }

    const btn = document.getElementById('btnSubmit');
    btn.disabled = true;
    btn.innerHTML = '<span style="display:inline-block;width:18px;height:18px;border:2px solid #fff;border-top-color:transparent;border-radius:50%;animation:spin 1s linear infinite"></span> Création en cours...';
});

console.log('%c🏢 Nouvelle Entreprise ATF', 'color:#A78BFA;font-weight:bold;font-size:14px');
</script>

<style>
@keyframes spin { to { transform: rotate(360deg); } }
</style>

<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>