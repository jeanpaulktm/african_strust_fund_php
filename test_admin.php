<?php
/* ============================================================
   DIAGNOSTIC CONNEXION ADMIN — ATF
   À SUPPRIMER APRÈS USAGE !
   ============================================================ */

ini_set('display_errors', '1');
error_reporting(E_ALL);

/* Charger la base */
require_once __DIR__ . '/config/database.php';

$pdo = Database::getConnection();

echo "<!DOCTYPE html>
<html lang='fr'>
<head>
<meta charset='UTF-8'>
<title>Test Connexion Admin — ATF</title>
<style>
body { font-family: 'Courier New', monospace; background: #0B0E14; color: #E8ECF3; padding: 30px; line-height: 1.6; }
h1 { color: #F5B800; border-bottom: 2px solid #F5B800; padding-bottom: 10px; }
h2 { color: #93B4FF; margin-top: 30px; border-left: 4px solid #93B4FF; padding-left: 12px; }
.box { background: #1A2130; border: 1px solid #333; padding: 16px; border-radius: 8px; margin: 12px 0; }
.ok { color: #6EE7B7; }
.ko { color: #FCA5A5; }
.warn { color: #FCD34D; }
pre { background: #0B0E14; padding: 12px; border-radius: 6px; overflow-x: auto; font-size: 13px; }
table { width: 100%; border-collapse: collapse; margin: 10px 0; }
th, td { padding: 8px 12px; border: 1px solid #333; text-align: left; }
th { background: #121824; color: #F5B800; }
code { background: #121824; color: #F5B800; padding: 2px 8px; border-radius: 4px; }
form { background: #1A2130; padding: 20px; border-radius: 12px; margin: 20px 0; border: 1px solid #333; }
input { padding: 12px; border-radius: 8px; border: 1px solid #333; background: #121824; color: #E8ECF3; font-size: 14px; width: 100%; margin-bottom: 10px; box-sizing: border-box; }
button { padding: 14px 24px; border-radius: 999px; background: linear-gradient(135deg, #F5B800, #D4AF37); color: #0B0E14; border: none; font-weight: 800; font-size: 15px; cursor: pointer; }
button:hover { transform: translateY(-2px); }
</style>
</head>
<body>

<h1>🔍 Diagnostic Connexion Admin — AFRICAN TRUST FUND</h1>";

/* ============================================================
   1. VÉRIFICATION DE LA CONNEXION BDD
   ============================================================ */
echo "<h2>1. Connexion base de données</h2>";
try {
    $version = $pdo->query("SELECT VERSION()")->fetchColumn();
    echo "<div class='box ok'>✅ Connexion OK — MySQL/MariaDB version : <code>$version</code></div>";
} catch (Throwable $e) {
    echo "<div class='box ko'>❌ Erreur BDD : " . htmlspecialchars($e->getMessage()) . "</div>";
    exit;
}

/* ============================================================
   2. VÉRIFICATION DE LA TABLE Admin_Principal
   ============================================================ */
echo "<h2>2. Table <code>Admin_Principal</code></h2>";
try {
    $cols = $pdo->query("SHOW COLUMNS FROM Admin_Principal")->fetchAll(PDO::FETCH_ASSOC);
    echo "<div class='box'>✅ Table existe — " . count($cols) . " colonne(s)</div>";
    echo "<table><tr><th>Colonne</th><th>Type</th><th>Null</th><th>Défaut</th></tr>";
    foreach ($cols as $c) {
        echo "<tr><td>{$c['Field']}</td><td>{$c['Type']}</td><td>{$c['Null']}</td><td>" . ($c['Default'] ?? '—') . "</td></tr>";
    }
    echo "</table>";
} catch (Throwable $e) {
    echo "<div class='box ko'>❌ Table absente : " . htmlspecialchars($e->getMessage()) . "</div>";
}

/* ============================================================
   3. LISTE DES ADMINS EXISTANTS
   ============================================================ */
echo "<h2>3. Admins enregistrés</h2>";
try {
    $admins = $pdo->query("
        SELECT id_admin, nom, postnom, prenom, role, statut, numero_phone, 
               LENGTH(mot_de_passe) AS hash_len, 
               LEFT(mot_de_passe, 20) AS hash_debut,
               failed_attempts, locked_until, last_login
        FROM Admin_Principal
        ORDER BY id_admin ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (empty($admins)) {
        echo "<div class='box ko'>❌ AUCUN ADMIN ENREGISTRÉ ! Il faut en créer un.</div>";
    } else {
        echo "<div class='box ok'>✅ " . count($admins) . " admin(s) trouvé(s)</div>";
        echo "<table>";
        echo "<tr><th>ID</th><th>Nom</th><th>Rôle</th><th>Statut</th><th>Téléphone</th><th>Hash longueur</th><th>Échecs</th><th>Verrouillé</th><th>Dernière conn.</th></tr>";
        foreach ($admins as $a) {
            $hash_ok = $a['hash_len'] >= 60 ? 'ok' : 'ko';
            $statut_ok = $a['statut'] === 'actif' ? 'ok' : 'ko';
            $locked = $a['locked_until'] && strtotime($a['locked_until']) > time() ? 'ko' : 'ok';

            echo "<tr>";
            echo "<td>#{$a['id_admin']}</td>";
            echo "<td><strong>{$a['nom']}</strong> {$a['postnom']}</td>";
            echo "<td>{$a['role']}</td>";
            echo "<td class='$statut_ok'>{$a['statut']}</td>";
            echo "<td>{$a['numero_phone']}</td>";
            echo "<td class='$hash_ok'>{$a['hash_len']} chars ({$a['hash_debut']}...)</td>";
            echo "<td>" . (int)$a['failed_attempts'] . "</td>";
            echo "<td class='$locked'>" . ($a['locked_until'] ?? '—') . "</td>";
            echo "<td>" . ($a['last_login'] ?? 'Jamais') . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
} catch (Throwable $e) {
    echo "<div class='box ko'>❌ Erreur : " . htmlspecialchars($e->getMessage()) . "</div>";
}

/* ============================================================
   4. CRÉATION D'UN ADMIN DE TEST
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['creer_admin'])) {
    echo "<h2>4. Création admin</h2>";

    $nom      = trim($_POST['nom'] ?? '');
    $postnom  = trim($_POST['postnom'] ?? '');
    $prenom   = trim($_POST['prenom'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';

    $erreurs = [];
    if ($nom === '')      $erreurs[] = "Nom obligatoire.";
    if ($postnom === '')  $erreurs[] = "Post-nom obligatoire.";
    if ($prenom === '')   $erreurs[] = "Prénom obligatoire.";
    if ($phone === '')    $erreurs[] = "Téléphone obligatoire.";
    if (strlen($password) < 8) $erreurs[] = "Mot de passe : 8 caractères minimum.";
    if (!preg_match('/[A-Z]/', $password)) $erreurs[] = "1 majuscule requise.";
    if (!preg_match('/[a-z]/', $password)) $erreurs[] = "1 minuscule requise.";
    if (!preg_match('/\d/', $password))    $erreurs[] = "1 chiffre requis.";
    if (!preg_match('/[\W_]/', $password)) $erreurs[] = "1 symbole requis.";

    if ($erreurs) {
        echo "<div class='box ko'>❌ Erreurs :<ul>";
        foreach ($erreurs as $e) echo "<li>" . htmlspecialchars($e) . "</li>";
        echo "</ul></div>";
    } else {
        try {
            /* Supprimer l'admin avec le même nom s'il existe */
            $pdo->prepare("DELETE FROM Admin_Principal WHERE nom = ?")->execute([$nom]);

            /* Créer le hash */
            $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

            /* Insérer */
            $stmt = $pdo->prepare("
                INSERT INTO Admin_Principal 
                    (nom, postnom, prenom, role, statut, numero_phone, mot_de_passe)
                VALUES (?, ?, ?, 'super_admin', 'actif', ?, ?)
            ");
            $stmt->execute([$nom, $postnom, $prenom, $phone, $hash]);

            $id = (int)$pdo->lastInsertId();

            /* Vérifier immédiatement */
            $stmt = $pdo->prepare("SELECT mot_de_passe FROM Admin_Principal WHERE id_admin = ?");
            $stmt->execute([$id]);
            $hash_stocke = $stmt->fetchColumn();

            $verify = password_verify($password, $hash_stoque) ? 'ok' : 'ko';

            echo "<div class='box ok'>
                ✅ <strong>Admin créé avec succès !</strong><br><br>
                <strong>ID :</strong> #$id<br>
                <strong>Nom (identifiant) :</strong> <code>$nom</code><br>
                <strong>Mot de passe :</strong> <code>$password</code><br>
                <strong>Vérification hash :</strong> <span class='$verify'>" . ($verify === 'ok' ? '✓ OK' : '❌ ÉCHEC') . "</span><br><br>
                🔗 <a href='auth/connexion_admin.php' style='color:#F5B800'>→ Tester la connexion</a>
            </div>";
        } catch (Throwable $e) {
            echo "<div class='box ko'>❌ Erreur : " . htmlspecialchars($e->getMessage()) . "</div>";
        }
    }
}

/* ============================================================
   5. VÉRIFICATION DU FICHIER config/paths.php
   ============================================================ */
echo "<h2>5. Fichiers de configuration</h2>";
$fichiers = [
    'config/database.php',
    'config/security.php',
    'config/paths.php',
    'config/auth_guard.php',
    'config/secure_tokens.php',
    'config/actionnaires.php',
    'auth/connexion_admin.php',
    'includes/header.php',
];

echo "<table>";
foreach ($fichiers as $f) {
    $path = __DIR__ . '/' . $f;
    $existe = file_exists($path);
    $size = $existe ? filesize($path) : 0;
    $icon = $existe ? '✅' : '❌';
    $class = $existe ? 'ok' : 'ko';
    echo "<tr><td class='$class'>$icon</td><td>$f</td><td>$size octets</td></tr>";
}
echo "</table>";

/* ============================================================
   6. VÉRIFICATION SESSION
   ============================================================ */
echo "<h2>6. Session PHP</h2>";
session_start();
$_SESSION['test_atf'] = date('H:i:s');
echo "<div class='box ok'>✅ Session active — ID : <code>" . session_id() . "</code><br>";
echo "Test enregistré à : <code>{$_SESSION['test_atf']}</code></div>";

/* ============================================================
   7. CRÉATION D'UN ADMIN DE TEST
   ============================================================ */
echo "<h2>7. 🛠️ Créer / Réinitialiser un admin</h2>";
echo "<p>Utilisez ce formulaire pour créer un nouvel admin ou réinitialiser un admin existant.</p>";

echo "<form method='POST'>
    <h3 style='color:#F5B800'>Créer un super admin</h3>
    <input type='text' name='nom' placeholder='Nom (ex: ATF)' required value='ATF'>
    <input type='text' name='postnom' placeholder='Post-nom (ex: Root)' required value='Root'>
    <input type='text' name='prenom' placeholder='Prénom (ex: Super)' required value='Super'>
    <input type='text' name='phone' placeholder='Téléphone' required value='+243000000000'>
    <input type='text' name='password' placeholder='Mot de passe (ex: Admin@2026)' required value='Admin@2026'>
    <button type='submit' name='creer_admin' value='1'>🚀 Créer / Réinitialiser cet admin</button>
</form>";

/* ============================================================
   8. NOTES
   ============================================================ */
echo "<h2>8. 📋 Instructions</h2>";
echo "<div class='box' style='background:rgba(245,184,0,.08);border-color:#F5B800'>
    <strong>Après avoir créé l'admin :</strong><br><br>
    1. Notez le <strong>nom</strong> et le <strong>mot de passe</strong><br>
    2. Testez la connexion sur <a href='auth/connexion_admin.php' style='color:#F5B800'>auth/connexion_admin.php</a><br>
    3. Si ça marche → <strong>SUPPRIMEZ ce fichier test_admin.php immédiatement</strong><br>
    4. Si ça ne marche pas → faites une capture d'écran de la page qui s'affiche
</div>";

echo "<div class='box ko' style='border-color:#EF4444'>
    <strong>⚠️ ATTENTION :</strong> Ce fichier <code>test_admin.php</code> permet de créer/réinitialiser des admins. 
    <strong>Supprimez-le IMMÉDIATEMENT après utilisation</strong> pour la sécurité du système.
</div>";

echo "</body></html>";