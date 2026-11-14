<?php
/* ============================================================
   DEBUG CONNEXION ADMIN — À SUPPRIMER APRÈS USAGE
   ============================================================ */

ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/config/database.php';

$pdo = Database::getConnection();

echo "<!DOCTYPE html>
<html lang='fr'>
<head>
<meta charset='UTF-8'>
<title>Debug Login — ATF</title>
<style>
body { font-family: 'Courier New', monospace; background: #0B0E14; color: #E8ECF3; padding: 30px; line-height: 1.7; }
h1 { color: #F5B800; border-bottom: 2px solid #F5B800; padding-bottom: 10px; }
h2 { color: #93B4FF; margin-top: 30px; }
.box { background: #1A2130; border: 1px solid #333; padding: 16px; border-radius: 8px; margin: 12px 0; }
.ok { color: #6EE7B7; font-weight: 700; }
.ko { color: #FCA5A5; font-weight: 700; }
.warn { color: #FCD34D; font-weight: 700; }
table { width: 100%; border-collapse: collapse; margin: 10px 0; }
th, td { padding: 10px; border: 1px solid #333; text-align: left; font-size: 13px; }
th { background: #121824; color: #F5B800; }
code { background: #121824; color: #F5B800; padding: 3px 8px; border-radius: 4px; font-size: 13px; }
pre { background: #0B0E14; padding: 12px; border-radius: 6px; overflow-x: auto; font-size: 12px; color: #6EE7B7; }
.test-form { background: #1A2130; padding: 20px; border-radius: 12px; border: 1px solid #333; margin: 20px 0; }
input { padding: 12px; border-radius: 8px; border: 1px solid #333; background: #121824; color: #E8ECF3; font-size: 15px; width: 100%; box-sizing: border-box; margin-bottom: 12px; font-family: monospace; }
button { padding: 14px 24px; border-radius: 999px; background: linear-gradient(135deg, #F5B800, #D4AF37); color: #0B0E14; border: none; font-weight: 800; font-size: 15px; cursor: pointer; }
</style>
</head>
<body>

<h1>🔍 Debug Connexion Admin</h1>";

/* ============================================================
   1. VÉRIFIER QUE LA TABLE EXISTE
   ============================================================ */
echo "<h2>1. Table Admin_Principal</h2>";
try {
    $count = $pdo->query("SELECT COUNT(*) FROM Admin_Principal")->fetchColumn();
    echo "<div class='box ok'>✅ Table existe — $count enregistrement(s)</div>";
} catch (Throwable $e) {
    echo "<div class='box ko'>❌ Erreur : " . htmlspecialchars($e->getMessage()) . "</div>";
    exit;
}

/* ============================================================
   2. LISTER TOUS LES ADMINS
   ============================================================ */
echo "<h2>2. Tous les admins en base</h2>";

$admins = $pdo->query("
    SELECT id_admin, nom, postnom, prenom, role, statut, numero_phone,
           mot_de_passe, LENGTH(mot_de_passe) AS hash_len,
           failed_attempts, locked_until, last_login
    FROM Admin_Principal
    ORDER BY id_admin
")->fetchAll(PDO::FETCH_ASSOC);

if (empty($admins)) {
    echo "<div class='box ko'>❌ AUCUN admin en base ! Créez-en un.</div>";
} else {
    echo "<table>";
    echo "<tr><th>ID</th><th>Nom</th><th>Post-nom</th><th>Prénom</th><th>Rôle</th><th>Statut</th><th>Hash</th></tr>";
    foreach ($admins as $a) {
        $hash_status = $a['hash_len'] >= 55 && $a['hash_len'] <= 65 ? 'ok' : 'ko';
        $statut_status = $a['statut'] === 'actif' ? 'ok' : 'ko';

        echo "<tr>";
        echo "<td>#{$a['id_admin']}</td>";
        echo "<td><strong>" . htmlspecialchars($a['nom']) . "</strong></td>";
        echo "<td>" . htmlspecialchars($a['postnom']) . "</td>";
        echo "<td>" . htmlspecialchars($a['prenom']) . "</td>";
        echo "<td>{$a['role']}</td>";
        echo "<td class='$statut_status'>" . htmlspecialchars($a['statut']) . "</td>";
        echo "<td class='$hash_status'>{$a['hash_len']} car.</td>";
        echo "</tr>";
    }
    echo "</table>";
}

/* ============================================================
   3. DÉTAILS DU HASH DE JEANPAUL
   ============================================================ */
echo "<h2>3. Détails du hash stocké</h2>";

$stmt = $pdo->prepare("SELECT * FROM Admin_Principal WHERE nom = 'Jeanpaul' LIMIT 1");
$stmt->execute();
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$admin) {
    echo "<div class='box ko'>❌ Aucun admin avec <code>nom = 'Jeanpaul'</code></div>";
    echo "<p>Vérifiez la casse du nom dans la liste ci-dessus.</p>";
} else {
    echo "<div class='box'>";
    echo "<p><strong>Nom en base :</strong> <code>" . htmlspecialchars($admin['nom']) . "</code></p>";
    echo "<p><strong>Longueur du nom :</strong> " . strlen($admin['nom']) . " caractères</p>";
    echo "<p><strong>Hash complet :</strong></p>";
    echo "<pre>" . htmlspecialchars($admin['mot_de_passe']) . "</pre>";
    echo "<p><strong>Longueur du hash :</strong> " . strlen($admin['mot_de_passe']) . " caractères</p>";
    echo "<p><strong>Commence par :</strong> " . htmlspecialchars(substr($admin['mot_de_passe'], 0, 7)) . "</p>";
    echo "<p><strong>Statut :</strong> " . htmlspecialchars($admin['statut']) . "</p>";
    echo "<p><strong>Tentatives échouées :</strong> " . (int)$admin['failed_attempts'] . "</p>";
    echo "<p><strong>Verrouillé jusqu'à :</strong> " . ($admin['locked_until'] ?? 'Non verrouillé') . "</p>";
    echo "</div>";
}

/* ============================================================
   4. TEST DE VÉRIFICATION DU MOT DE PASSE
   ============================================================ */
echo "<h2>4. Test password_verify()</h2>";

if ($admin) {
    $test_passwords = [
        'Jeanpaul@2026',
        'jeanpaul@2026',
        'Jeanpaul2026',
        'JEANPAUL@2026',
        'JeanPaul@2026',
    ];

    echo "<table>";
    echo "<tr><th>Mot de passe testé</th><th>Résultat</th></tr>";
    foreach ($test_passwords as $test) {
        $result = password_verify($test, $admin['mot_de_passe']);
        $class = $result ? 'ok' : 'ko';
        $icon = $result ? '✅ OK' : '❌ NON';
        echo "<tr><td><code>" . htmlspecialchars($test) . "</code></td><td class='$class'>$icon</td></tr>";
    }
    echo "</table>";
}

/* ============================================================
   5. TEST MANUEL DE CONNEXION
   ============================================================ */
echo "<h2>5. Test manuel de connexion</h2>";

$test_nom = $_POST['test_nom'] ?? '';
$test_pwd = $_POST['test_pwd'] ?? '';

if ($test_nom && $test_pwd) {
    echo "<div class='box'>";
    echo "<p><strong>Test avec :</strong> nom=<code>" . htmlspecialchars($test_nom) . "</code></p>";

    $stmt = $pdo->prepare("SELECT * FROM Admin_Principal WHERE nom = ? LIMIT 1");
    $stmt->execute([$test_nom]);
    $test_admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$test_admin) {
        echo "<p class='ko'>❌ Aucun admin avec ce nom exact.</p>";
        echo "<p>Essayez ces noms (copiez-collez) : ";
        foreach ($admins as $a) {
            echo "<code>" . htmlspecialchars($a['nom']) . "</code> ";
        }
        echo "</p>";
    } elseif ($test_admin['statut'] !== 'actif') {
        echo "<p class='ko'>❌ Compte statut : " . htmlspecialchars($test_admin['statut']) . "</p>";
    } elseif ($test_admin['locked_until'] && strtotime($test_admin['locked_until']) > time()) {
        echo "<p class='ko'>❌ Compte verrouillé jusqu'à : " . htmlspecialchars($test_admin['locked_until']) . "</p>";
    } elseif (password_verify($test_pwd, $test_admin['mot_de_passe'])) {
        echo "<p class='ok'>✅ CONNEXION RÉUSSIE !</p>";
        echo "<p>Le couple <code>$test_nom</code> / <code>" . str_repeat('•', strlen($test_pwd)) . "</code> fonctionne.</p>";
    } else {
        echo "<p class='ko'>❌ Mot de passe incorrect pour ce nom.</p>";
        echo "<p>Longueur de votre mot de passe : " . strlen($test_pwd) . " caractères</p>";
        echo "<p>Essayez ces variantes : <code>Jeanpaul@2026</code>, <code>jeanpaul@2026</code>, <code>JeanPaul@2026</code></p>";
    }
    echo "</div>";
}

echo "<form method='POST' class='test-form'>
    <h3 style='color:#F5B800;margin-top:0'>🧪 Tester un couple nom/mot de passe</h3>
    <input type='text' name='test_nom' placeholder='Nom (ex: Jeanpaul)' value='" . htmlspecialchars($test_nom) . "' required>
    <input type='text' name='test_pwd' placeholder='Mot de passe (ex: Jeanpaul@2026)' value='" . htmlspecialchars($test_pwd) . "' required>
    <button type='submit'>🔍 Tester</button>
</form>";

/* ============================================================
   6. RÉINITIALISER LE MOT DE PASSE
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reset_pwd'])) {
    $new_pwd = $_POST['new_pwd'] ?? '';
    $target_nom = $_POST['target_nom'] ?? '';

    if (strlen($new_pwd) < 8) {
        echo "<div class='box ko'>❌ Mot de passe trop court.</div>";
    } else {
        $new_hash = password_hash($new_pwd, PASSWORD_BCRYPT, ['cost' => 12]);

        $pdo->prepare("
            UPDATE Admin_Principal 
            SET mot_de_passe = ?, failed_attempts = 0, locked_until = NULL, statut = 'actif'
            WHERE nom = ?
        ")->execute([$new_hash, $target_nom]);

        echo "<div class='box ok'>
            ✅ Mot de passe réinitialisé pour <code>" . htmlspecialchars($target_nom) . "</code><br>
            <strong>Nouveau mot de passe :</strong> <code>" . htmlspecialchars($new_pwd) . "</code><br>
            <strong>Vérifiez maintenant avec le formulaire ci-dessus.</strong>
        </div>";
    }
}

echo "<form method='POST' class='test-form'>
    <h3 style='color:#F5B800;margin-top:0'>🔑 Réinitialiser un mot de passe</h3>
    <input type='text' name='target_nom' placeholder='Nom de l admin (ex: Jeanpaul)' value='Jeanpaul' required>
    <input type='text' name='new_pwd' placeholder='Nouveau mot de passe' value='Jeanpaul@2026' required>
    <button type='submit' name='reset_pwd' value='1'>🔄 Réinitialiser</button>
</form>";

/* ============================================================
   7. SUPPRIMER ET RECRÉER
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['recreate'])) {
    $nom = trim($_POST['r_nom'] ?? '');
    $postnom = trim($_POST['r_postnom'] ?? '');
    $prenom = trim($_POST['r_prenom'] ?? '');
    $phone = trim($_POST['r_phone'] ?? '');
    $pwd = $_POST['r_pwd'] ?? '';

    if (!$nom || !$pwd) {
        echo "<div class='box ko'>❌ Nom et mot de passe obligatoires.</div>";
    } else {
        /* Suppression */
        $pdo->prepare("DELETE FROM Admin_Principal WHERE nom = ? OR numero_phone = ?")
            ->execute([$nom, $phone]);

        /* Création */
        $hash = password_hash($pwd, PASSWORD_BCRYPT, ['cost' => 12]);
        $pdo->prepare("
            INSERT INTO Admin_Principal 
                (nom, postnom, prenom, role, statut, numero_phone, mot_de_passe)
            VALUES (?, ?, ?, 'super_admin', 'actif', ?, ?)
        ")->execute([$nom, $postnom, $prenom, $phone, $hash]);

        $id = $pdo->lastInsertId();

        /* Vérification */
        $stmt = $pdo->prepare("SELECT mot_de_passe FROM Admin_Principal WHERE id_admin = ?");
        $stmt->execute([$id]);
        $hash_stocke = $stmt->fetchColumn();
        $verify = password_verify($pwd, $hash_stocke);

        echo "<div class='box " . ($verify ? 'ok' : 'ko') . "'>
            " . ($verify ? '✅ Compte recréé et vérifié !' : '❌ Erreur de vérification') . "<br>
            <strong>ID :</strong> #$id<br>
            <strong>Nom :</strong> <code>" . htmlspecialchars($nom) . "</code><br>
            <strong>Mot de passe :</strong> <code>" . htmlspecialchars($pwd) . "</code><br>
            <strong>Hash vérifié :</strong> " . ($verify ? '✓ OUI' : '❌ NON') . "
        </div>";
    }
}

echo "<form method='POST' class='test-form'>
    <h3 style='color:#F5B800;margin-top:0'>🚀 Supprimer et recréer un admin</h3>
    <input type='text' name='r_nom' placeholder='Nom' value='Jeanpaul' required>
    <input type='text' name='r_postnom' placeholder='Post-nom' value='Katumo'>
    <input type='text' name='r_prenom' placeholder='Prénom' value='Jean-Paul'>
    <input type='text' name='r_phone' placeholder='Téléphone' value='+243975272623'>
    <input type='text' name='r_pwd' placeholder='Mot de passe' value='Jeanpaul@2026' required>
    <button type='submit' name='recreate' value='1'>💥 Supprimer et recréer</button>
</form>";

echo "<div class='box ko' style='border-color:#EF4444'>
    <strong>⚠️ ATTENTION :</strong> Supprimez ce fichier <code>debug_login.php</code> après utilisation.
</div>";

echo "</body></html>";