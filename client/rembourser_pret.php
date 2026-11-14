<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/prets.php';
requireRole('client');

$pdo = Database::getConnection();
$id = (int)$_SESSION['client_id'];
$errors = [];
$success = '';

/* Token pour identifier l'échéance */
$token = $_GET['token'] ?? $_POST['token'] ?? '';

/* Traitement du remboursement */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'rembourser') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) throw new RuntimeException("Jeton invalide.");
        
        $id_echeance = (int)($_POST['id_echeance'] ?? 0);
        $montant_paye = (float)str_replace([' ', ','], ['', '.'], $_POST['montant'] ?? '0');
        $devise_paiement = in_array($_POST['devise'] ?? '', ['CDF','USD'], true) ? $_POST['devise'] : 'CDF';
        
        if ($montant_paye <= 0) throw new RuntimeException("Montant invalide.");
        
        $pdo->beginTransaction();
        
        /* Charger l'échéance + le prêt */
        $stmt = $pdo->prepare("
            SELECT e.*, p.id_client, p.reference, p.devise, p.montant_restant_du, p.statut AS pret_statut
            FROM Echeances_Pret e
            JOIN Prets p ON p.id_pret = e.id_pret
            WHERE e.id_echeance = ? AND p.id_client = ?
            FOR UPDATE
        ");
        $stmt->execute([$id_echeance, $id]);
        $e = $stmt->fetch();
        
        if (!$e) throw new RuntimeException("Échéance introuvable.");
        if ($e['statut'] === 'paye') throw new RuntimeException("Cette échéance est déjà payée.");
        if (!in_array($e['pret_statut'], ['decaisse','en_cours','en_retard'], true)) {
            throw new RuntimeException("Ce prêt n'est pas actif.");
        }
        
        /* Vérifier le solde disponible du client */
        $col_solde = $devise_paiement === 'USD' ? 'solde_usd' : 'solde_cdf';
        
        $stmt = $pdo->prepare("SELECT $col_solde AS solde FROM Client WHERE id_client = ? FOR UPDATE");
        $stmt->execute([$id]);
        $client_solde = (float)$stmt->fetchColumn();
        
        if ($devise_paiement !== $e['devise']) {
            throw new RuntimeException("La devise doit être " . $e['devise'] . ".");
        }
        
        if ($montant_paye > $client_solde) {
            throw new RuntimeException("Solde insuffisant. Disponible : " . formaterMontant($client_solde, $devise_paiement));
        }
        
        /* Calculer le reste à payer sur cette échéance */
        $reste_echeance = (float)$e['montant_total'] - (float)$e['montant_paye'];
        
        if ($montant_paye > $reste_echeance) {
            $montant_paye = $reste_echeance; // On plafonne au montant dû
        }
        
        /* Débiter le client */
        $pdo->prepare("UPDATE Client SET $col_solde = $col_solde - ? WHERE id_client = ?")
            ->execute([$montant_paye, $id]);
        
        /* Mettre à jour l'échéance */
        $nouveau_paye = (float)$e['montant_paye'] + $montant_paye;
        $nouveau_statut_e = ($nouveau_paye >= (float)$e['montant_total']) ? 'paye' : 'partiel';
        
        $pdo->prepare("
            UPDATE Echeances_Pret 
            SET montant_paye = ?, statut = ?, date_paiement = NOW()
            WHERE id_echeance = ?
        ")->execute([$nouveau_paye, $nouveau_statut_e, $id_echeance]);
        
        /* Mettre à jour le prêt */
        $nouveau_total_paye = (float)$e['montant_paye'] + $montant_paye; // ancien paye sur cette éch + ce paiement
        
        // Recalculer le total payé du prêt
        $stmt = $pdo->prepare("SELECT COALESCE(SUM(montant_paye), 0) FROM Echeances_Pret WHERE id_pret = ?");
        $stmt->execute([(int)$e['id_pret']]);
        $total_paye_pret = (float)$stmt->fetchColumn();
        
        $stmt = $pdo->prepare("SELECT montant_total_du FROM Prets WHERE id_pret = ?");
        $stmt->execute([(int)$e['id_pret']]);
        $total_du = (float)$stmt->fetchColumn();
        
        $restant = $total_du - $total_paye_pret;
        $nouveau_statut_pret = 'en_cours';
        if ($restant <= 0.01) {
            $nouveau_statut_pret = 'solde';
            $restant = 0;
        }
        
        $pdo->prepare("
            UPDATE Prets 
            SET montant_paye = ?, montant_restant_du = ?, statut = ?
            WHERE id_pret = ?
        ")->execute([$total_paye_pret, $restant, $nouveau_statut_pret, (int)$e['id_pret']]);
        
        /* Enregistrer le remboursement */
        $ref = 'RBT-' . strtoupper(bin2hex(random_bytes(6)));
        $pdo->prepare("
            INSERT INTO Remboursements 
                (id_pret, id_echeance, montant, devise, mode, reference, effectue_par, effectue_par_role, commentaire)
            VALUES (?, ?, ?, ?, 'especes', ?, ?, 'client', ?)
        ")->execute([
            (int)$e['id_pret'], $id_echeance, $montant_paye, $devise_paiement, $ref, $id,
            "Remboursement en ligne — échéance #{$e['numero_echeance']}"
        ]);
        
        /* Transaction générale */
        $ref_tx = 'TRX-' . strtoupper(bin2hex(random_bytes(6)));
        $pdo->prepare("
            INSERT INTO Transactions 
                (id_client, type_transaction, montant, devise, mode, effectue_par,
                 solde_avant, solde_apres, reference, description, ip_address)
            VALUES (?, 'Retrait', ?, ?, 'En ligne', 'Client', ?, ?, ?, ?, ?)
        ")->execute([
            $id, $montant_paye, $devise_paiement,
            $client_solde, $client_solde - $montant_paye,
            $ref_tx,
            "Remboursement prêt {$e['reference']} — échéance #{$e['numero_echeance']}",
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);
        
        /* Log */
        logPret($pdo, (int)$e['id_pret'], 'REMBOURSEMENT_ECHEANCE', $e['statut'], $nouveau_statut_e, 'client', $id, "Montant : $montant_paye $devise_paiement");
        
        $pdo->commit();
        
        $msg = "✅ Remboursement effectué !<br>
            <strong>Référence :</strong> $ref<br>
            <strong>Montant payé :</strong> " . formaterMontant($montant_paye, $devise_paiement) . "<br>
            <strong>Échéance #{$e['numero_echeance']} :</strong> $nouveau_statut_e";
        
        if ($nouveau_statut_pret === 'solde') {
            $msg .= "<br><br>🎉 <strong>Félicitations ! Votre prêt est entièrement remboursé !</strong>";
        }
        
        $_SESSION['flash_success'] = $msg;
        header('Location: ' . url('client/mes_prets.php'));
        exit;
        
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = $ex->getMessage();
    }
}

/* Charger l'échéance à rembourser (via token) */
$echeance = null;
$pret = null;
if ($token) {
    $data = consommerToken('echeance', $token);
    if ($data) {
        $stmt = $pdo->prepare("
            SELECT e.*, p.id_client, p.reference, p.devise, p.statut AS pret_statut, p.id_pret,
                   pr.nom AS produit_nom
            FROM Echeances_Pret e
            JOIN Prets p ON p.id_pret = e.id_pret
            JOIN Produits_Pret pr ON pr.id_produit = p.id_produit
            WHERE e.id_echeance = ? AND p.id_client = ?
        ");
        $stmt->execute([(int)$data['id'], $id]);
        $echeance = $stmt->fetch();
        if ($echeance) $pret = $echeance;
    }
}

/* Si pas de token : charger la prochaine échéance */
if (!$echeance) {
    $stmt = $pdo->prepare("
        SELECT e.*, p.id_client, p.reference, p.devise, p.statut AS pret_statut, p.id_pret,
               pr.nom AS produit_nom
        FROM Echeances_Pret e
        JOIN Prets p ON p.id_pret = e.id_pret
        JOIN Produits_Pret pr ON pr.id_produit = p.id_produit
        WHERE p.id_client = ? AND p.statut IN ('decaisse','en_cours','en_retard')
          AND e.statut IN ('en_attente','partiel','retard')
        ORDER BY e.date_echeance ASC
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $echeance = $stmt->fetch();
    if ($echeance) $pret = $echeance;
}

/* Solde client */
$stmt = $pdo->prepare("SELECT solde_cdf, solde_usd FROM Client WHERE id_client = ?");
$stmt->execute([$id]);
$soldes = $stmt->fetch();

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Rembourser une échéance — ATF</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<style>
:root {
  --nav-height: 76px;
  --nav-safe-area: env(safe-area-inset-bottom, 0px);
}
body.app-mode { padding-bottom: calc(var(--nav-height) + var(--nav-safe-area) + 20px); }

.rmb-container {
  max-width: 720px;
  margin: 0 auto;
}

.rmb-hero {
  padding: 26px 28px;
  border-radius: 20px;
  background: linear-gradient(135deg, rgba(16,185,129,.12), rgba(16,185,129,.02)),
              radial-gradient(circle at 90% 20%, rgba(16,185,129,.15), transparent 60%);
  border: 1px solid rgba(16,185,129,.35);
  margin-bottom: 24px;
  position: relative;
  overflow: hidden;
}
.rmb-hero h1 { color: #6EE7B7; font-size: 1.5rem; margin-bottom: 8px; }
.rmb-hero p { color: var(--muted); font-size: .92rem; }

.rmb-card {
  padding: 24px 26px;
  border-radius: 20px;
  background: linear-gradient(145deg, var(--card), var(--card-2));
  border: 1px solid var(--border);
  margin-bottom: 20px;
}

.rmb-info-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px;
  margin-bottom: 20px;
}
@media (max-width: 600px) {
  .rmb-info-grid { grid-template-columns: 1fr; }
}

.rmb-info-box {
  padding: 14px 16px;
  border-radius: 12px;
  background: rgba(11,14,20,.5);
  border: 1px solid var(--border);
}
.rmb-info-box .lbl {
  font-size: .7rem;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 1px;
  font-weight: 700;
  margin-bottom: 4px;
}
.rmb-info-box .val {
  font-size: 1.15rem;
  font-weight: 800;
  color: #fff;
}
.rmb-info-box .val.gold { color: var(--gold); }
.rmb-info-box .val.green { color: #6EE7B7; }
.rmb-info-box .val.red { color: #FCA5A5; }
.rmb-info-box .val.blue { color: #93B4FF; }

.rmb-alert {
  padding: 14px 16px;
  border-radius: 12px;
  margin-bottom: 20px;
  font-size: .88rem;
  line-height: 1.6;
  display: flex;
  align-items: flex-start;
  gap: 12px;
}
.rmb-alert.info { background: rgba(59,130,246,.08); border: 1px solid rgba(59,130,246,.3); color: #93B4FF; }
.rmb-alert.warn { background: rgba(245,184,0,.08); border: 1px solid rgba(245,184,0,.3); color: #FCD34D; }
.rmb-alert.danger { background: rgba(239,68,68,.08); border: 1px solid rgba(239,68,68,.3); color: #FCA5A5; }
.rmb-alert .ico { font-size: 1.3rem; flex-shrink: 0; }

.form-group { margin-bottom: 18px; }
.form-group label {
  display: block;
  font-size: .82rem;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: .5px;
  font-weight: 700;
  margin-bottom: 8px;
}
.form-group input {
  width: 100%;
  padding: 16px 18px;
  background: var(--bg-1);
  border: 1px solid var(--border);
  border-radius: 12px;
  color: var(--text);
  font-size: 1.2rem;
  font-weight: 800;
  font-family: inherit;
  text-align: center;
}
.form-group input:focus {
  outline: none;
  border-color: var(--gold);
  box-shadow: 0 0 0 3px rgba(245,184,0,.15);
}

.quick-amounts {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(80px, 1fr));
  gap: 8px;
  margin-bottom: 18px;
}
.quick-amounts button {
  padding: 10px 8px;
  border-radius: 10px;
  background: rgba(245,184,0,.1);
  color: var(--gold);
  border: 1px solid rgba(245,184,0,.3);
  font-weight: 700;
  font-size: .85rem;
  cursor: pointer;
  font-family: inherit;
  transition: all .2s;
}
.quick-amounts button:hover {
  background: rgba(245,184,0,.2);
  transform: translateY(-1px);
}
.quick-amounts button.full {
  grid-column: 1 / -1;
  background: linear-gradient(135deg, #3B82F6, #2563EB);
  color: #fff;
  border: none;
}

.btn-gold-full {
  width: 100%;
  padding: 16px;
  border-radius: 999px;
  background: linear-gradient(135deg, #10B981, #059669);
  color: #fff;
  border: none;
  font-weight: 800;
  font-size: 1.05rem;
  cursor: pointer;
  font-family: inherit;
  box-shadow: 0 6px 20px rgba(16,185,129,.4);
  transition: all .25s;
  margin-top: 12px;
}
.btn-gold-full:hover {
  transform: translateY(-2px);
  box-shadow: 0 10px 28px rgba(16,185,129,.55);
}
.btn-gold-full:disabled {
  opacity: .5;
  cursor: not-allowed;
  transform: none;
}

.btn-ghost-full {
  display: block;
  width: 100%;
  padding: 14px;
  border-radius: 999px;
  background: rgba(255,255,255,.08);
  color: var(--text);
  text-decoration: none;
  text-align: center;
  font-weight: 700;
  margin-top: 12px;
  border: 1px solid var(--border);
}
.btn-ghost-full:hover { background: rgba(255,255,255,.14); color: var(--text); }
</style>
</head>
<body class="dark-auth app-mode">

<?php require __DIR__ . '/../includes/header.php'; ?>

<main class="dash">
  <div class="rmb-container">

    <div class="welcome-head">
      <div>
        <h2>💰 Rembourser une échéance</h2>
        <p class="sub">Payez votre mensualité en ligne</p>
      </div>
      <a class="btn-ghost" href="<?= $base ?>/client/mes_prets.php">← Mes prêts</a>
    </div>

    <?php if ($errors): ?>
      <div class="alert error"><ul><?php foreach ($errors as $e) echo "<li>" . htmlspecialchars($e) . "</li>"; ?></ul></div>
    <?php endif; ?>

    <?php if (!$echeance): ?>
      <div class="rmb-card" style="text-align:center;padding:50px">
        <div style="font-size:4rem;opacity:.5;margin-bottom:16px">🎉</div>
        <h3>Aucune échéance à payer</h3>
        <p class="muted" style="margin-top:8px">Vous n'avez aucun remboursement en attente.</p>
        <a href="<?= $base ?>/client/mes_prets.php" class="btn-ghost-full" style="max-width:300px;margin:20px auto 0">← Retour aux prêts</a>
      </div>
    <?php else:
      $reste = (float)$echeance['montant_total'] - (float)$echeance['montant_paye'];
      $jours_retard = (int)floor((time() - strtotime($echeance['date_echeance'])) / 86400);
      $en_retard = $jours_retard > 0;
      $devise = $echeance['devise'];
      $solde_devise = $devise === 'USD' ? (float)$soldes['solde_usd'] : (float)$soldes['solde_cdf'];
      $solde_ok = $solde_devise >= $reste;
    ?>

      <div class="rmb-hero">
        <h1>💳 <?= htmlspecialchars($echeance['produit_nom']) ?></h1>
        <p>Prêt <strong><?= htmlspecialchars($echeance['reference']) ?></strong> · Échéance <strong>#<?= (int)$echeance['numero_echeance'] ?></strong></p>
      </div>

      <?php if ($en_retard): ?>
        <div class="rmb-alert danger">
          <span class="ico">⚠️</span>
          <div>
            <strong>Échéance en retard de <?= $jours_retard ?> jour(s)</strong><br>
            Cette échéance était due le <?= date('d/m/Y', strtotime($echeance['date_echeance'])) ?>. Des pénalités peuvent s'appliquer.
          </div>
        </div>
      <?php else: ?>
        <div class="rmb-alert info">
          <span class="ico">📅</span>
          <div>
            <strong>Date limite : <?= date('d/m/Y', strtotime($echeance['date_echeance'])) ?></strong><br>
            Vous avez encore <?= max(0, -$jours_retard) ?> jour(s) pour payer sans pénalité.
          </div>
        </div>
      <?php endif; ?>

      <!-- Informations échéance -->
      <div class="rmb-card">
        <h3 style="margin-bottom:16px;color:var(--gold)">📋 Détails de l'échéance</h3>
        
        <div class="rmb-info-grid">
          <div class="rmb-info-box">
            <div class="lbl">💰 Montant total dû</div>
            <div class="val gold"><?= htmlspecialchars(formaterMontant((float)$echeance['montant_total'], $devise)) ?></div>
          </div>
          <div class="rmb-info-box">
            <div class="lbl">📊 Déjà payé</div>
            <div class="val green"><?= htmlspecialchars(formaterMontant((float)$echeance['montant_paye'], $devise)) ?></div>
          </div>
          <div class="rmb-info-box">
            <div class="lbl">🎯 Reste à payer</div>
            <div class="val red"><?= htmlspecialchars(formaterMontant($reste, $devise)) ?></div>
          </div>
          <div class="rmb-info-box">
            <div class="lbl">💵 Mon solde <?= $devise ?></div>
            <div class="val <?= $solde_ok ? 'blue' : 'red' ?>"><?= htmlspecialchars(formaterMontant($solde_devise, $devise)) ?></div>
          </div>
        </div>

        <?php if (!$solde_ok): ?>
          <div class="rmb-alert danger" style="margin-bottom:0">
            <span class="ico">❌</span>
            <div>
              <strong>Solde insuffisant</strong><br>
              Il vous manque <?= htmlspecialchars(formaterMontant($reste - $solde_devise, $devise)) ?>. Veuillez d'abord alimenter votre compte.
            </div>
          </div>
        <?php endif; ?>
      </div>

      <!-- Formulaire de paiement -->
      <?php if ($solde_ok): ?>
        <form method="POST" class="rmb-card" id="formRmb">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="rembourser">
          <input type="hidden" name="id_echeance" value="<?= (int)$echeance['id_echeance'] ?>">
          <input type="hidden" name="devise" value="<?= htmlspecialchars($devise) ?>">

          <h3 style="margin-bottom:18px;color:#6EE7B7">💳 Paiement en ligne</h3>

          <div class="form-group">
            <label>Montant à payer (<?= htmlspecialchars($devise) ?>)</label>
            <input type="text" name="montant" id="montantInput" required 
                   value="<?= (float)$reste ?>" inputmode="decimal">
          </div>

          <div class="quick-amounts">
            <button type="button" onclick="setMontant(<?= (float)$reste ?>)" class="full">
              💰 Payer la totalité (<?= htmlspecialchars(formaterMontant($reste, $devise)) ?>)
            </button>
          </div>

          <div class="rmb-alert info">
            <span class="ico">💡</span>
            <div>
              Le montant sera <strong>immédiatement débité</strong> de votre solde disponible <strong><?= htmlspecialchars($devise) ?></strong>.
            </div>
          </div>

          <button class="btn-gold-full" type="submit" id="btnPay" onclick="return confirm('Confirmer le remboursement de ce montant ?')">
            ✅ Confirmer le paiement
          </button>
        </form>
      <?php endif; ?>

      <a href="<?= $base ?>/client/mes_prets.php" class="btn-ghost-full">← Retour aux prêts</a>

    <?php endif; ?>

  </div>
</main>

<footer class="atf-footer" style="padding:20px;text-align:center;color:var(--muted)">
  © <?= date('Y') ?> AFRICAN TRUST FUND
</footer>

<script>
function setMontant(v) {
    document.getElementById('montantInput').value = v;
}

document.getElementById('montantInput')?.addEventListener('input', function() {
    let v = this.value.replace(/[^\d.,]/g, '').replace(',', '.');
    const parts = v.split('.');
    if (parts.length > 2) v = parts[0] + '.' + parts.slice(1).join('');
    this.value = v;
});

document.getElementById('formRmb')?.addEventListener('submit', function() {
    const btn = document.getElementById('btnPay');
    btn.disabled = true;
    btn.textContent = '⏳ Traitement en cours...';
});
</script>
<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>