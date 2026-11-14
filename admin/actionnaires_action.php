<?php
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/actionnaires.php';
requireRole('admin');

$pdo = Database::getConnection();
$admin_id = (int)$_SESSION['admin_id'];
$action = $_POST['action'] ?? '';

/* ============================================================
   MODIFIER
   ============================================================ */
if ($action === 'modifier' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) throw new RuntimeException("Jeton invalide.");

        $id       = (int)($_POST['id_actionnaire'] ?? 0);
        $nom      = cleanString($_POST['nom'] ?? '', 60);
        $postnom  = cleanString($_POST['postnom'] ?? '', 60);
        $prenom   = cleanString($_POST['prenom'] ?? '', 60);
        $email    = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: null;
        $phone    = cleanString($_POST['numero_phone'] ?? '', 25);
        $adresse  = cleanString($_POST['adresse_complete'] ?? '', 500);
        $type     = in_array($_POST['type_actionnaire'] ?? '', ['fondateur','investisseur','employe','partenaire'])
                    ? $_POST['type_actionnaire'] : 'investisseur';
        $statut   = in_array($_POST['statut'] ?? '', ['actif','inactif','suspendu'])
                    ? $_POST['statut'] : 'actif';
        $devise   = in_array($_POST['devise'] ?? '', ['CDF','USD']) ? $_POST['devise'] : 'CDF';

        if ($id <= 0) throw new RuntimeException("Actionnaire invalide.");
        if (!$nom || !$postnom || !$prenom) throw new RuntimeException("Noms obligatoires.");
        if (!preg_match('/^\+?[0-9 ]{9,20}$/', $phone)) throw new RuntimeException("Téléphone invalide.");

        $chk = $pdo->prepare("SELECT 1 FROM Actionnaires WHERE numero_phone = ? AND id_actionnaire != ?");
        $chk->execute([$phone, $id]);
        if ($chk->fetchColumn()) throw new RuntimeException("Ce numéro est déjà utilisé.");

        $pdo->prepare("
            UPDATE Actionnaires SET
                nom = ?, postnom = ?, prenom = ?, email = ?,
                numero_phone = ?, adresse_complete = ?,
                type_actionnaire = ?, statut = ?, devise = ?
            WHERE id_actionnaire = ?
        ")->execute([$nom, $postnom, $prenom, $email, $phone, $adresse, $type, $statut, $devise, $id]);

        logAction($pdo, 'admin', $admin_id, 'ACTIONNAIRE_UPDATE', "ID: $id");
        $_SESSION['flash_success'] = "✅ Actionnaire modifié avec succès !";

    } catch (Throwable $e) {
        $_SESSION['flash_error'] = $e->getMessage();
    }
    header('Location: ' . url('admin/actionnaires.php'));
    exit;
}

/* ============================================================
   SUPPRIMER
   ============================================================ */
if ($action === 'supprimer' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) throw new RuntimeException("Jeton invalide.");

        $id     = (int)($_POST['id_actionnaire'] ?? 0);
        $raison = cleanString($_POST['raison'] ?? '', 255);

        if ($id <= 0) throw new RuntimeException("Actionnaire invalide.");

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM Actionnaires WHERE id_actionnaire = ? FOR UPDATE");
        $stmt->execute([$id]);
        $a = $stmt->fetch();

        if (!$a) throw new RuntimeException("Actionnaire introuvable.");

        // Supprimer les données liées (si tables existent)
        try { $pdo->prepare("DELETE FROM Parts_Sociales WHERE id_actionnaire = ?")->execute([$id]); } catch (Throwable $e) {}
        try { $pdo->prepare("DELETE FROM Dividendes WHERE id_actionnaire = ?")->execute([$id]); } catch (Throwable $e) {}

        // Supprimer l'actionnaire
        $pdo->prepare("DELETE FROM Actionnaires WHERE id_actionnaire = ?")->execute([$id]);

        $pdo->commit();

        logAction($pdo, 'admin', $admin_id, 'ACTIONNAIRE_DELETE',
            "ID: $id | N°: {$a['numero_actionnaire']} | Nom: {$a['nom']} | Raison: $raison");

        $_SESSION['flash_success'] = "✅ Actionnaire supprimé : {$a['numero_actionnaire']}";

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['flash_error'] = $e->getMessage();
    }
    header('Location: ' . url('admin/actionnaires.php'));
    exit;
}

header('Location: ' . url('admin/actionnaires.php'));
exit;
?>