<?php
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/interets.php';
requireRole('client');

$pdo = Database::getConnection();
$id  = (int)$_SESSION['client_id'];

$errors = [];
$placement_info = null;
$simulation = null;

/* ============================================================
   RÉCUPÉRATION SÉCURISÉE DU PLACEMENT
   ============================================================ */
$id_placement = recupererIdSecurise('placement');

if (!$id_placement) {
    refuserAcces("Session expirée. Retournez à votre épargne.");
}

/* Charger le placement */
$stmt = $pdo->prepare("
    SELECT p.*, pr.nom AS produit_nom, pr.type_interet,
           pr.penalite_anticipe, pr.commission_retrait
    FROM Placements p
    JOIN Produits_Epargne pr ON pr.id_produit = p.id_produit
    WHERE p.id_placement = ? AND p.id_client = ?
");
$stmt->execute([$id_placement, $id]);
$placement_info = $stmt->fetch();

if (!$placement_info) {
    logAction($pdo, 'client', $id, 'PLACEMENT_404', "Placement #$id_placement introuvable");
    refuserAcces("Placement introuvable ou non autorisé.");
}

if ($placement_info['statut'] !== 'actif') {
    $_SESSION['flash_error'] = "Ce placement n'est plus actif.";
    header('Location: ' . url('client/epargne.php')); exit;
}

/* ============================================================
   CONFIRMATION (POST avec token de confirmation)
   ============================================================ */
$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'confirm_retrait') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) throw new RuntimeException("Jeton CSRF invalide.");

        $confirm_token = $_POST['confirm_token'] ?? '';
        $data_confirm = consommerToken('placement', $confirm_token);
        if (!$data_confirm || (int)$data_confirm['id'] !== (int)$placement_info['id_placement']) {
            throw new RuntimeException("Confirmation expirée ou invalide.");
        }

        if ($placement_info['statut'] !== 'actif') {
            throw new RuntimeException("Placement déjà clôturé.");
        }

        $pdo->beginTransaction();

        // Recharger avec verrou
        $stmt = $pdo->prepare("
            SELECT p.*, pr.penalite_anticipe, pr.commission_retrait
            FROM Placements p
            JOIN Produits_Epargne pr ON pr.id_produit = p.id_produit
            WHERE p.id_placement = ? AND p.id_client = ? AND p.statut='actif'
            FOR UPDATE
        ");
        $stmt->execute([(int)$placement_info['id_placement'], $id]);
        $p = $stmt->fetch();

        if (!$p) throw new RuntimeException("Placement déjà clôturé.");

        $aujourdhui = date('Y-m-d');
        $date_dernier = $p['date_dernier_calcul'] ?? $p['date_debut'];
        $jours = max(0, (strtotime($aujourdhui) - strtotime($date_dernier)) / 86400);

        $interets = $jours > 0
            ? calculerInteretsJournaliers((float)$p['montant_actuel'], (float)$p['taux_applique'], (int)$jours)
            : 0;

        $montant_actuel = (float)$p['montant_actuel'] + $interets;

        $est_anticipe = strtotime($aujourdhui) < strtotime($p['date_fin']);
        $penalite = 0;
        if ($est_anticipe) {
            $jours_restants = max(0, (strtotime($p['date_fin']) - strtotime($aujourdhui)) / 86400);
            $penalite = calculerPenaliteAnticipe($montant_actuel, (float)$p['penalite_anticipe'], (int)$jours_restants);
        }

        $montant_apres_penalite = $montant_actuel - $penalite;
        $commission = round($montant_apres_penalite * (float)$p['commission_retrait'], 2);
        $net_verse  = round($montant_apres_penalite - $commission, 2);

        // Client
        $stmt = $pdo->prepare("SELECT solde, devise FROM Client WHERE id_client = ? FOR UPDATE");
        $stmt->execute([$id]);
        $client = $stmt->fetch();

        $solde_avant = (float)$client['solde'];
        $solde_apres = $solde_avant + $net_verse;

        $pdo->prepare("UPDATE Client SET solde = ? WHERE id_client = ?")->execute([$solde_apres, $id]);

        $pdo->prepare("UPDATE Placements SET statut = ?, montant_actuel = 0,
                       interets_cumules = interets_cumules + ?, date_dernier_calcul = ?
                       WHERE id_placement = ?")
            ->execute([$est_anticipe ? 'anticipe' : 'cloture', $interets, $aujourdhui, (int)$p['id_placement']]);

        if ($interets > 0) {
            $pdo->prepare("INSERT INTO Interets_Historique (id_placement, montant_interet, solde_avant, solde_apres, periode_debut, periode_fin)
                           VALUES (?,?,?,?,?,?)")
                ->execute([(int)$p['id_placement'], $interets, (float)$p['montant_actuel'], $montant_actuel, $date_dernier, $aujourdhui]);
        }

        $reference = genererReference();
        $pdo->prepare("INSERT INTO Transactions (id_client, type_transaction, montant, mode, effectue_par, solde_avant, solde_apres, reference, description, ip_address)
                       VALUES (?, 'Retrait', ?, 'En ligne', 'Client', ?, ?, ?, ?, ?)")
            ->execute([$id, $net_verse, $solde_avant, $solde_apres, $reference,
                sprintf("Clôture placement %s | Brut: %.2f | Pénalité: %.2f | Commission: %.2f | Net: %.2f",
                    $p['reference'], $montant_actuel, $penalite, $commission, $net_verse),
                $_SERVER['REMOTE_ADDR'] ?? null]);

        $id_transaction = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO Commissions (id_client, id_transaction, montant_brut, taux_commission, montant_commission, montant_net, devise)
                       VALUES (?,?,?,?,?,?,?)")
            ->execute([$id, $id_transaction, $montant_apres_penalite, (float)$p['commission_retrait'],
                $commission, $net_verse, $client['devise']]);

        $pdo->commit();
        logAction($pdo, 'client', $id, 'PLACEMENT_RETRAIT', "Ref: {$p['reference']} | Net: $net_verse");

        $_SESSION['flash_success'] = "✅ Placement clôturé !<br>
            <strong>Référence :</strong> $reference<br>
            <strong>Capital + intérêts :</strong> " . number_format($montant_actuel, 2, ',', ' ') . " {$client['devise']}<br>" .
            ($penalite > 0 ? "<strong>Pénalité anticipée :</strong> <span style='color:#FCA5A5'>− " . number_format($penalite, 2, ',', ' ') . "</span><br>" : "") .
            "<strong>Commission :</strong> <span style='color:#FCA5A5'>− " . number_format($commission, 2, ',', ' ') . "</span><br>
            <strong style='font-size:1.1em;color:#6EE7B7'>Net versé : " . number_format($net_verse, 2, ',', ' ') . " {$client['devise']}</strong>";

        header('Location: ' . url('client/epargne.php')); exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = $e->getMessage();
    }
}

/* ============================================================
   SIMULATION POUR AFFICHAGE
   ============================================================ */
$stmt = $pdo->prepare("SELECT solde, devise FROM Client WHERE id_client = ?");
$stmt->execute([$id]);
$client = $stmt->fetch();

$aujourdhui = date('Y-m-d');
$date_dernier = $placement_info['date_dernier_calcul'] ?? $placement_info['date_debut'];
$jours = max(0, (strtotime($aujourdhui) - strtotime($date_dernier)) / 86400);

$interets_du_jour = $jours > 0
    ? calculerInteretsJournaliers((float)$placement_info['montant_actuel'], (float)$placement_info['taux_applique'], (int)$jours)
    : 0;

$montant_actuel = (float)$placement_info['montant_actuel'] + $interets_du_jour;
$est_anticipe = strtotime($aujourdhui) < strtotime($placement_info['date_fin']);
$jours_restants = $est_anticipe ? max(0, (strtotime($placement_info['date_fin']) - strtotime($aujourdhui)) / 86400) : 0;

$penalite = 0;
if ($est_anticipe) {
    $penalite = calculerPenaliteAnticipe($montant_actuel, (float)$placement_info['penalite_anticipe'], (int)$jours_restants);
}

$montant_apres_penalite = $montant_actuel - $penalite;
$commission = round($montant_apres_penalite * (float)$placement_info['commission_retrait'], 2);
$net_verse  = round($montant_apres_penalite - $commission, 2);

$simulation = [
    'montant_initial'        => (float)$placement_info['montant_initial'],
    'montant_actuel'         => $montant_actuel,
    'est_anticipe'           => $est_anticipe,
    'jours_restants'         => (int)$jours_restants,
    'penalite'               => $penalite,
    'commission'             => $commission,
    'net_verse'              => $net_verse,
    'devise'                 => $client['devise'],
];

/* ✅ Token de confirmation (one-shot) */
$token_confirm = genererToken('placement', (int)$placement_info['id_placement'], ['action' => 'confirm_retrait']);

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Retirer un placement — ATF</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css"></head>
<body class="dark-auth">
<?php require __DIR__ . '/../includes/header.php'; ?>

<div class="op-wrapper" style="max-width:640px">
  <h1>💸 Retirer un placement</h1>
  <p class="op-sub">Consultez les conditions avant de confirmer</p>

  <?php if ($errors): ?><div class="alert error"><ul><?php foreach ($errors as $e) echo "<li>".htmlspecialchars($e)."</li>"; ?></ul></div><?php endif; ?>

  <section class="card" style="margin-bottom:20px">
    <h3 style="margin-bottom:14px">📋 Détails</h3>
    <div class="grid-2">
      <div><span class="muted">Produit</span><br><strong><?= htmlspecialchars($placement_info['produit_nom']) ?></strong></div>
      <div><span class="muted">Référence</span><br><code><?= htmlspecialchars($placement_info['reference']) ?></code></div>
      <div><span class="muted">Date début</span><br><strong><?= date('d/m/Y', strtotime($placement_info['date_debut'])) ?></strong></div>
      <div><span class="muted">Date fin</span><br><strong><?= date('d/m/Y', strtotime($placement_info['date_fin'])) ?></strong></div>
    </div>
  </section>

  <section class="card" style="background:linear-gradient(145deg,rgba(245,184,0,.08),rgba(245,184,0,.02));border-color:var(--border-strong)">
    <h3 style="margin-bottom:14px">💡 Simulation du retrait</h3>
    <table style="width:100%;font-size:.9rem">
      <tr><td style="padding:8px 0;color:var(--muted)">Capital initial</td>
          <td style="text-align:right;font-weight:700"><?= number_format($simulation['montant_initial'], 2, ',', ' ') ?> <?= $simulation['devise'] ?></td></tr>
      <tr><td style="padding:8px 0;color:var(--muted)">Intérêts cumulés</td>
          <td style="text-align:right;font-weight:700;color:var(--green)">+ <?= number_format($simulation['montant_actuel'] - $simulation['montant_initial'], 2, ',', ' ') ?> <?= $simulation['devise'] ?></td></tr>
      <tr style="border-top:1px solid var(--border)">
        <td style="padding:12px 0;font-weight:700">Valeur actuelle</td>
        <td style="text-align:right;font-weight:800;color:var(--gold);font-size:1.1rem"><?= number_format($simulation['montant_actuel'], 2, ',', ' ') ?> <?= $simulation['devise'] ?></td></tr>

      <?php if ($simulation['est_anticipe']): ?>
        <tr><td style="padding:8px 0;color:#FCA5A5">⚠️ Pénalité anticipée (<?= $simulation['jours_restants'] ?> jours restants)</td>
            <td style="text-align:right;font-weight:700;color:#FCA5A5">− <?= number_format($simulation['penalite'], 2, ',', ' ') ?></td></tr>
      <?php endif; ?>

      <tr><td style="padding:8px 0;color:var(--muted)">Commission (1%)</td>
          <td style="text-align:right;font-weight:700;color:#FCA5A5">− <?= number_format($simulation['commission'], 2, ',', ' ') ?></td></tr>

      <tr style="border-top:2px solid var(--gold);background:rgba(245,184,0,.05)">
        <td style="padding:14px 0;font-weight:800;font-size:1.05rem">💰 Net versé</td>
        <td style="text-align:right;font-weight:800;color:var(--green);font-size:1.3rem"><?= number_format($simulation['net_verse'], 2, ',', ' ') ?> <?= $simulation['devise'] ?></td></tr>
    </table>

    <?php if ($simulation['est_anticipe']): ?>
      <div class="alert warning" style="margin-top:14px">
        ⚠️ Retrait anticipé : pénalité de <strong><?= number_format((float)$placement_info['penalite_anticipe'] * 100, 2) ?>%</strong>
      </div>
    <?php endif; ?>
  </section>

  <form method="POST" novalidate>
    <?= csrfField() ?>
    <input type="hidden" name="action" value="confirm_retrait">
    <input type="hidden" name="confirm_token" value="<?= htmlspecialchars($token_confirm) ?>">

    <button class="btn-gold full" type="submit"
            onclick="return confirm('Confirmez-vous le retrait de ce placement ?')">
      ✅ Confirmer le retrait
    </button>

    <p class="center muted" style="margin-top:16px">
      <a href="<?= $base ?>/client/epargne.php">← Annuler</a>
    </p>
  </form>
</div>

<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>