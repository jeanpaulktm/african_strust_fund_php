<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/prets.php';
requireRole('client');

$pdo = Database::getConnection();
$id = (int)$_SESSION['client_id'];

/* Simuler le flux complet côté client */
$etapes = [];

// 1. Vérifier éligibilité
$verif = peutDemanderPret($pdo, $id);
$etapes[] = [
    'nom' => 'Vérification éligibilité',
    'statut' => $verif['ok'] ? 'ok' : 'warning',
    'message' => $verif['ok'] ? '✅ Éligible' : '❌ ' . $verif['raison'],
];

// 2. Calculer score
$score = calculerScoreCredit($pdo, $id);
$etapes[] = [
    'nom' => 'Calcul score crédit',
    'statut' => 'ok',
    'message' => "Score : {$score['score']}/100 ({$score['niveau']})",
    'donnees' => $score,
];

// 3. Vérifier produits disponibles
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM Produits_Pret 
    WHERE actif = 1 AND type_client IN ('physique','tous') 
      AND score_min_requis <= ?
");
$stmt->execute([$score['score']]);
$produits_ok = (int)$stmt->fetchColumn();
$etapes[] = [
    'nom' => 'Produits accessibles',
    'statut' => $produits_ok > 0 ? 'ok' : 'warning',
    'message' => "$produits_ok produit(s) accessible(s) selon votre score",
];

// 4. Vérifier solde
$stmt = $pdo->prepare("SELECT solde_cdf, solde_usd FROM Client WHERE id_client = ?");
$stmt->execute([$id]);
$solde = $stmt->fetch();
$etapes[] = [
    'nom' => 'Soldes disponibles',
    'statut' => 'ok',
    'message' => "CDF : " . number_format((float)$solde['solde_cdf'], 0, ',', ' ') . " · USD : $ " . number_format((float)$solde['solde_usd'], 2, '.', ','),
];

// 5. Vérifier documents KYC
$stmt = $pdo->prepare("SELECT COUNT(*) FROM Client_Documents WHERE id_client = ? AND verifie = TRUE");
$stmt->execute([$id]);
$docs_ok = (int)$stmt->fetchColumn();
$etapes[] = [
    'nom' => 'Documents KYC vérifiés',
    'statut' => $docs_ok >= 2 ? 'ok' : 'warning',
    'message' => "$docs_ok document(s) vérifié(s)",
];

// 6. Prêts actifs
$stmt = $pdo->prepare("SELECT COUNT(*) FROM Prets WHERE id_client = ? AND statut IN ('en_cours','decaisse','en_retard')");
$stmt->execute([$id]);
$prets_actifs = (int)$stmt->fetchColumn();
$etapes[] = [
    'nom' => 'Prêts en cours',
    'statut' => $prets_actifs === 0 ? 'ok' : 'warning',
    'message' => $prets_actifs > 0 ? "$prets_actifs prêt(s) en cours" : 'Aucun prêt en cours',
];

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Test éligibilité prêt — ATF</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<style>
:root { --nav-height: 76px; --nav-safe-area: env(safe-area-inset-bottom, 0px); }
body.app-mode { padding-bottom: calc(var(--nav-height) + var(--nav-safe-area) + 20px); }

.test-hero {
  padding: 26px 28px;
  border-radius: 20px;
  background: linear-gradient(135deg, rgba(59,130,246,.12), rgba(30,64,175,.04));
  border: 1px solid rgba(59,130,246,.35);
  margin-bottom: 24px;
}
.test-hero h1 { color: #93B4FF; font-size: 1.4rem; margin-bottom: 8px; }
.test-hero p { color: var(--muted); font-size: .9rem; }

.etape-card {
  padding: 16px 18px;
  border-radius: 12px;
  background: var(--card);
  border: 1px solid var(--border);
  margin-bottom: 10px;
  display: flex;
  gap: 14px;
  align-items: flex-start;
}
.etape-card.ok { border-left: 4px solid #10B981; }
.etape-card.warning { border-left: 4px solid #F5B800; }
.etape-card.erreur { border-left: 4px solid #EF4444; }

.etape-ico {
  width: 40px; height: 40px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.2rem;
  flex-shrink: 0;
}
.etape-ico.ok { background: rgba(16,185,129,.15); color: #6EE7B7; }
.etape-ico.warning { background: rgba(245,184,0,.15); color: #FCD34D; }
.etape-ico.erreur { background: rgba(239,68,68,.15); color: #FCA5A5; }

.etape-info { flex: 1; }
.etape-info .nom { font-weight: 800; color: var(--text); font-size: .95rem; }
.etape-info .msg { color: var(--muted); font-size: .85rem; margin-top: 4px; }

.actions-row { display: flex; gap: 12px; margin-top: 20px; flex-wrap: wrap; }
.btn-cta {
  flex: 1;
  padding: 14px 20px;
  border-radius: 999px;
  text-align: center;
  text-decoration: none;
  font-weight: 800;
  transition: all .25s;
}
.btn-cta.primary { background: linear-gradient(135deg, #10B981, #059669); color: #fff; }
.btn-cta.ghost { background: rgba(255,255,255,.08); color: var(--text); border: 1px solid var(--border); }
.btn-cta:hover { transform: translateY(-2px); }
</style>
</head>
<body class="dark-auth app-mode">

<?php require __DIR__ . '/../includes/header.php'; ?>

<main class="dash">

  <div class="welcome-head">
    <div>
      <h2>🧪 Test d'éligibilité</h2>
      <p class="sub">Vérifiez votre éligibilité avant de faire une demande</p>
    </div>
    <a class="btn-ghost" href="<?= $base ?>/client/mes_prets.php">← Mes prêts</a>
  </div>

  <div class="test-hero">
    <h1>🎯 Résultat de votre pré-analyse</h1>
    <p>Voici les vérifications effectuées sur votre profil pour un prêt</p>
  </div>

  <?php foreach ($etapes as $e): ?>
    <div class="etape-card <?= htmlspecialchars($e['statut']) ?>">
      <div class="etape-ico <?= htmlspecialchars($e['statut']) ?>">
        <?= $e['statut'] === 'ok' ? '✅' : ($e['statut'] === 'warning' ? '⚠️' : '❌') ?>
      </div>
      <div class="etape-info">
        <div class="nom"><?= htmlspecialchars($e['nom']) ?></div>
        <div class="msg"><?= $e['message'] ?></div>
      </div>
    </div>
  <?php endforeach; ?>

  <div class="actions-row">
    <?php if ($verif['ok']): ?>
      <a class="btn-cta primary" href="<?= $base ?>/client/demande_pret.php">📤 Faire la demande</a>
    <?php endif; ?>
    <a class="btn-cta ghost" href="<?= $base ?>/client/simulateur_pret.php">🧮 Simulateur</a>
    <a class="btn-cta ghost" href="<?= $base ?>/client/documents.php">📎 Documents</a>
  </div>

</main>

<footer class="atf-footer" style="padding:20px;text-align:center;color:var(--muted)">
  © <?= date('Y') ?> AFRICAN TRUST FUND
</footer>

<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>