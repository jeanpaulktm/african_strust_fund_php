<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/interets.php';
requireRole('caissier');

$pdo = Database::getConnection();
$caissier_id = (int)$_SESSION['caissier_id'];

/* ============================================================
   1. RÉCUPÉRATION SÉCURISÉE DU CLIENT
   (Session one-shot OU Token POST — jamais d'ID dans l'URL)
   ============================================================ */
$id_client = 0;

// Cas A : POST avec token explicite (nouvelle opération depuis un formulaire)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['client_token'])) {
    if (verifyCsrf($_POST['csrf'] ?? null)) {
        $data = consommerToken('client', $_POST['client_token']);
        if ($data) $id_client = (int)$data['id'];
    }
}
// Cas B : session one-shot (après clic "Traiter" depuis dashboard)
elseif (!empty($_SESSION['one_shot_client_id'])) {
    $id_client = (int)$_SESSION['one_shot_client_id'];
    unset($_SESSION['one_shot_client_id']);
}
// Cas C : POST direct avec hidden id_client (fallback sécurisé par CSRF)
elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['id_client_hidden'])) {
    if (verifyCsrf($_POST['csrf'] ?? null)) {
        $id_client = (int)$_POST['id_client_hidden'];
    }
}

if ($id_client <= 0) {
    $_SESSION['flash_error'] = "Aucun client sélectionné. Veuillez recommencer.";
    header('Location: ' . url('caissier/dashboard.php'));
    exit;
}

/* ============================================================
   2. CHARGEMENT DU CLIENT
   ============================================================ */
$stmt = $pdo->prepare("SELECT * FROM Client WHERE id_client = ? LIMIT 1");
$stmt->execute([$id_client]);
$client = $stmt->fetch();

if (!$client) {
    $_SESSION['flash_error'] = "Client introuvable (ID: $id_client).";
    header('Location: ' . url('caissier/dashboard.php'));
    exit;
}

if ($client['statut'] !== 'actif') {
    $_SESSION['flash_error'] = "Ce client est « {$client['statut']} ». Opération impossible.";
    header('Location: ' . url('caissier/dashboard.php'));
    exit;
}

/* ============================================================
   3. TRAITEMENT DE L'OPÉRATION
   ============================================================ */
$errors = [];
$success_data = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'execute') {
    try {
        // ---- CSRF ----
        if (!verifyCsrf($_POST['csrf'] ?? null)) {
            throw new RuntimeException("Jeton de sécurité invalide.");
        }

        // ---- Récupération & validation ----
        $type_op = in_array($_POST['type_op'] ?? '', ['depot','retrait'], true) ? $_POST['type_op'] : '';
        $devise  = in_array($_POST['devise'] ?? '', ['CDF','USD'], true) ? $_POST['devise'] : 'CDF';
        $note    = cleanString($_POST['note'] ?? '', 200);

        // Nettoyage du montant (supprime espaces et convertit virgule → point)
        $montant_str = str_replace([' ', ','], ['', '.'], $_POST['montant'] ?? '0');
        $montant = (float)$montant_str;

        if ($type_op === '')            throw new RuntimeException("Type d'opération invalide.");
        if ($montant <= 0)              throw new RuntimeException("Le montant doit être supérieur à 0.");
        if ($montant > 100_000_000)     throw new RuntimeException("Montant maximum : 100 000 000.");

        // ---- Transaction SQL ----
        $pdo->beginTransaction();

        // Verrou client
        $stmt = $pdo->prepare("SELECT solde, devise FROM Client WHERE id_client = ? FOR UPDATE");
        $stmt->execute([$id_client]);
        $c_fresh = $stmt->fetch();

        if (!$c_fresh) throw new RuntimeException("Client introuvable (verrou).");

        $solde_avant = (float)$c_fresh['solde'];
        $devise_compte = $c_fresh['devise'];

        // ---- Conversion si devise différente ----
        $montant_effectif = $montant;
        if ($devise !== $devise_compte) {
            if ($devise === 'USD' && $devise_compte === 'CDF') {
                $montant_effectif = $montant * 2800;
            } elseif ($devise === 'CDF' && $devise_compte === 'USD') {
                $montant_effectif = $montant / 2800;
            }
        }

        // ---- Vérification solde pour retrait ----
        if ($type_op === 'retrait') {
            if ($montant_effectif > $solde_avant) {
                throw new RuntimeException(
                    "Solde insuffisant. Disponible : " .
                    number_format($solde_avant, 2, ',', ' ') . " $devise_compte. " .
                    "Demandé : " . number_format($montant_effectif, 2, ',', ' ') . " $devise_compte."
                );
            }
            $solde_apres = $solde_avant - $montant_effectif;
            $type_tx = 'Retrait';
        } else {
            $solde_apres = $solde_avant + $montant_effectif;
            $type_tx = 'Depot';
        }

        // ---- Référence unique ----
        $reference = 'AGT-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));

        // ---- Mise à jour du solde ----
        $pdo->prepare("UPDATE Client SET solde = ? WHERE id_client = ?")
            ->execute([$solde_apres, $id_client]);

        // ---- Enregistrement de la transaction ----
        $pdo->prepare("
            INSERT INTO Transactions
                (id_client, type_transaction, montant, devise, mode, effectue_par,
                 id_caissier, solde_avant, solde_apres, reference, description, ip_address)
            VALUES (?, ?, ?, ?, 'Agence', 'Caissier', ?, ?, ?, ?, ?, ?)
        ")->execute([
            $id_client, $type_tx, $montant_effectif, $devise_compte,
            $caissier_id, $solde_avant, $solde_apres, $reference,
            "Opération agence par caissier #$caissier_id" . ($note ? " — $note" : ""),
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);

        $pdo->commit();

        // ---- Audit log ----
        logAction($pdo, 'caissier', $caissier_id, strtoupper($type_op) . '_OK',
            "Client #$id_client | $montant $devise | Ref: $reference");

        // ---- Données de succès pour affichage ----
        $success_data = [
            'reference'      => $reference,
            'type'           => $type_tx,
            'montant_saisi'  => $montant,
            'devise_saisie'  => $devise,
            'montant_effectif' => $montant_effectif,
            'devise_compte'  => $devise_compte,
            'solde_avant'    => $solde_avant,
            'solde_apres'    => $solde_apres,
            'note'           => $note,
        ];

        // ---- Stocker en session pour afficher après PRG ----
        $_SESSION['flash_success_op'] = $success_data;

        // ---- Redirection (POST/Redirect/GET) ----
        $_SESSION['one_shot_client_id'] = $id_client;
        header('Location: ' . url('caissier/transaction.php'));
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = $e->getMessage();

        logAction($pdo, 'caissier', $caissier_id, 'OP_FAILED',
            "Client #$id_client | Erreur: " . $e->getMessage());
    }
}

/* ============================================================
   4. RÉCUPÉRATION DU MESSAGE DE SUCCÈS (flash)
   ============================================================ */
$success_data = null;
if (!empty($_SESSION['flash_success_op'])) {
    $success_data = $_SESSION['flash_success_op'];
    unset($_SESSION['flash_success_op']);
}

/* ============================================================
   5. RECHARGER LE CLIENT (solde à jour après opération)
   ============================================================ */
$stmt = $pdo->prepare("SELECT * FROM Client WHERE id_client = ?");
$stmt->execute([$id_client]);
$client = $stmt->fetch();

/* ============================================================
   6. HISTORIQUE RÉCENT DU CLIENT
   ============================================================ */
$stmt = $pdo->prepare("
    SELECT * FROM Transactions
    WHERE id_client = ?
    ORDER BY date_transaction DESC
    LIMIT 5
");
$stmt->execute([$id_client]);
$historique = $stmt->fetchAll();

/* ============================================================
   7. TOKEN ONE-SHOT POUR PROTÉGER LE PROCHAIN POST
   ============================================================ */
$client_token = genererToken('client', $id_client);

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Opération — <?= htmlspecialchars($client['nom']) ?> — ATF Caissier</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/admin-premium.css">
<style>
/* Notification toast succès/erreur */
.flash-toast {
  position: fixed;
  top: 100px;
  right: 24px;
  max-width: 420px;
  padding: 22px 26px;
  border-radius: 16px;
  z-index: 9999;
  animation: slideInRight .5s ease-out both;
  box-shadow: 0 20px 60px rgba(0,0,0,.6);
  backdrop-filter: blur(12px);
}
.flash-toast.success {
  background: linear-gradient(135deg, rgba(16,185,129,.95), rgba(5,150,105,.95));
  border: 2px solid #10B981;
  color: #fff;
}
.flash-toast.error {
  background: linear-gradient(135deg, rgba(239,68,68,.95), rgba(185,28,28,.95));
  border: 2px solid #EF4444;
  color: #fff;
}
.flash-toast h4 {
  font-size: 1.15rem;
  margin-bottom: 12px;
  display: flex;
  align-items: center;
  gap: 10px;
}
.flash-toast .details {
  background: rgba(0,0,0,.25);
  padding: 14px;
  border-radius: 10px;
  font-size: .88rem;
  line-height: 1.8;
}
.flash-toast .details strong { color: #FFE082; }
.flash-toast .close {
  position: absolute;
  top: 10px;
  right: 14px;
  background: none;
  border: none;
  color: inherit;
  font-size: 1.4rem;
  cursor: pointer;
  opacity: .7;
  padding: 6px;
}
.flash-toast .close:hover { opacity: 1; }
@keyframes slideInRight {
  from { transform: translateX(120%); opacity: 0; }
  to   { transform: translateX(0);    opacity: 1; }
}

/* Bandeau solde en direct */
.solde-live {
  background: linear-gradient(135deg, rgba(245,184,0,.15), rgba(245,184,0,.05));
  border: 2px solid rgba(245,184,0,.4);
  border-radius: 16px;
  padding: 20px 28px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 24px;
  flex-wrap: wrap;
  gap: 12px;
}
.solde-live .label {
  color: var(--muted);
  font-size: .78rem;
  text-transform: uppercase;
  letter-spacing: 1.5px;
}
.solde-live .value {
  font-size: 2rem;
  font-weight: 800;
  color: var(--gold);
  text-shadow: 0 0 25px rgba(245,184,0,.35);
  margin-top: 4px;
}

/* Radio type d'opération */
.op-type-selector {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px;
  margin-bottom: 20px;
}
.op-type-btn {
  padding: 20px;
  border-radius: 16px;
  background: linear-gradient(145deg, var(--card), var(--card-2));
  border: 2px solid var(--border);
  text-align: center;
  cursor: pointer;
  transition: all .3s;
  font-weight: 700;
  color: var(--text);
}
.op-type-btn:hover { border-color: var(--gold); transform: translateY(-3px); }
.op-type-btn.active {
  border-color: var(--gold);
  background: linear-gradient(145deg, rgba(245,184,0,.15), rgba(245,184,0,.05));
  color: var(--gold);
  box-shadow: 0 12px 30px rgba(245,184,0,.25);
}
.op-type-btn .ico { font-size: 1.8rem; display: block; margin-bottom: 6px; }
.op-type-btn.depot.active { border-color: var(--green); background: linear-gradient(145deg, rgba(16,185,129,.15), rgba(16,185,129,.05)); color: var(--green); }
.op-type-btn.retrait.active { border-color: #EF4444; background: linear-gradient(145deg, rgba(239,68,68,.15), rgba(239,68,68,.05)); color: #FCA5A5; }
</style>
</head>
<body class="dark-auth">

<div class="top-progress" id="topProgress"></div>
<?php require __DIR__ . '/../includes/header.php'; ?>

<!-- ============================================================
     MESSAGE FLASH DE SUCCÈS
     ============================================================ -->
<?php if ($success_data): ?>
  <div class="flash-toast success" id="flashToast">
    <button class="close" onclick="document.getElementById('flashToast').remove()">×</button>
    <h4>✅ Opération réussie !</h4>
    <div class="details">
      <div><strong>Référence :</strong> <code><?= htmlspecialchars($success_data['reference']) ?></code></div>
      <div><strong>Type :</strong> <?= $success_data['type'] === 'Depot' ? '➕ Dépôt' : '➖ Retrait' ?></div>
      <div><strong>Montant saisi :</strong> <?= number_format($success_data['montant_saisi'], 2, ',', ' ') ?> <?= htmlspecialchars($success_data['devise_saisie']) ?></div>
      <?php if ($success_data['devise_saisie'] !== $success_data['devise_compte']): ?>
        <div><strong>Crédité/débité :</strong> <?= number_format($success_data['montant_effectif'], 2, ',', ' ') ?> <?= htmlspecialchars($success_data['devise_compte']) ?> <em>(conversion)</em></div>
      <?php endif; ?>
      <div><strong>Nouveau solde :</strong> <span style="color:#FFE082;font-size:1.1rem"><?= number_format($success_data['solde_apres'], 2, ',', ' ') ?> <?= htmlspecialchars($success_data['devise_compte']) ?></span></div>
    </div>
    <p style="margin-top:12px;font-size:.82rem;opacity:.9">Impression du reçu recommandée. Le client doit signer.</p>
  </div>
  <script>
    // Auto-fermeture après 15 secondes
    setTimeout(() => {
      const t = document.getElementById('flashToast');
      if (t) t.style.transform = 'translateX(120%)', t.style.opacity = '0', t.style.transition = 'all .5s';
      if (t) setTimeout(() => t.remove(), 600);
    }, 15000);
  </script>
<?php endif; ?>

<div class="admin-layout">
  <aside class="admin-sidebar animate-left">
    <h3>💼 Guichet</h3>
    <a href="<?= $base ?>/caissier/dashboard.php"><span class="ico">🔍</span> Rechercher client</a>
    <a href="<?= $base ?>/caissier/transaction.php" class="active"><span class="ico">💳</span> Nouvelle opération</a>
    <a href="<?= $base ?>/caissier/historique.php"><span class="ico">📋</span> Mes opérations</a>
    <a href="<?= $base ?>/auth/logout.php"><span class="ico">🚪</span> Déconnexion</a>

    <div style="padding:16px 24px;margin-top:20px;border-top:1px solid var(--border)">
      <div style="color:var(--muted);font-size:.72rem;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px">Caissier</div>
      <div class="user-chip" style="width:100%">
        <span class="dot"></span>
        <?= htmlspecialchars($_SESSION['caissier_nom'] ?? 'Caissier') ?>
      </div>
    </div>
  </aside>

  <main class="admin-main">

    <!-- ============ CARTE CLIENT ============ -->
    <div class="client-header animate-in">
      <?php if (!empty($client['photo_profil']) && file_exists(__DIR__ . '/../' . $client['photo_profil'])): ?>
        <img src="<?= $base ?>/<?= htmlspecialchars($client['photo_profil']) ?>" alt="Photo" class="client-photo-large">
      <?php else: ?>
        <div class="client-photo-placeholder-large">
          <?= strtoupper(mb_substr($client['nom'], 0, 1) . mb_substr($client['prenom'], 0, 1)) ?>
        </div>
      <?php endif; ?>

      <div class="client-info">
        <h1><?= htmlspecialchars($client['nom'] . ' ' . $client['postnom'] . ' ' . $client['prenom']) ?></h1>
        <div class="compte"><?= htmlspecialchars($client['numero_compte']) ?></div>
        <div style="margin-top:10px;display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <div>
            <div style="color:var(--muted);font-size:.72rem;text-transform:uppercase;letter-spacing:1px">Téléphone</div>
            <div style="font-weight:700"><?= htmlspecialchars($client['numero_phone']) ?></div>
          </div>
          <div>
            <div style="color:var(--muted);font-size:.72rem;text-transform:uppercase;letter-spacing:1px">Statut</div>
            <div><span class="badge <?= $client['statut'] === 'actif' ? 'green' : 'red' ?>">● <?= htmlspecialchars($client['statut']) ?></span></div>
          </div>
        </div>
      </div>
    </div>

    <!-- ============ SOLDE EN DIRECT ============ -->
    <div class="solde-live">
      <div>
        <div class="label">💰 Solde disponible</div>
        <div class="value"><?= number_format((float)$client['solde'], 2, ',', ' ') ?> <?= htmlspecialchars($client['devise']) ?></div>
      </div>
      <div style="text-align:right">
        <div class="label">Devise du compte</div>
        <div style="font-size:1.3rem;font-weight:800;color:#93B4FF"><?= $client['devise'] === 'USD' ? '🇺🇸 USD' : '🇨🇩 CDF' ?></div>
        <div style="font-size:.75rem;color:var(--muted);margin-top:4px">Taux : 1 USD = 2 800 CDF</div>
      </div>
    </div>

    <!-- ============ ERREURS ============ -->
    <?php if ($errors): ?>
      <div class="alert error animate-in" style="border-width:2px">
        <strong style="font-size:1.05rem">❌ Opération échouée</strong>
        <ul style="margin-top:10px;margin-left:20px">
          <?php foreach ($errors as $e): ?>
            <li><?= htmlspecialchars($e) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <!-- ============ FORMULAIRE D'OPÉRATION ============ -->
    <div class="op-wrapper animate-in delay-1" style="max-width:640px">

      <h1 style="text-align:center">💼 Nouvelle opération</h1>
      <p class="op-sub">Sélectionnez le type, la devise et le montant</p>

      <form method="POST" novalidate autocomplete="off" id="formOperation">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="execute">
        <input type="hidden" name="id_client_hidden" value="<?= (int)$id_client ?>">
        <input type="hidden" name="client_token" value="<?= htmlspecialchars($client_token) ?>">
        <input type="hidden" name="type_op" id="inputTypeOp" value="depot">

        <!-- ============ TYPE D'OPÉRATION ============ -->
        <div class="op-type-selector">
          <button type="button" class="op-type-btn depot active" id="btnDepot" onclick="selectType('depot')">
            <span class="ico">➕</span>
            DÉPÔT
          </button>
          <button type="button" class="op-type-btn retrait" id="btnRetrait" onclick="selectType('retrait')">
            <span class="ico">➖</span>
            RETRAIT
          </button>
        </div>

        <!-- ============ DEVISES ============ -->
        <label class="field">
          <span>💱 Devise de l'opération *</span>
          <select name="devise" id="deviseSelect" required>
            <option value="CDF">🇨🇩 Franc Congolais (CDF)</option>
            <option value="USD">🇺🇸 Dollar Américain (USD)</option>
          </select>
        </label>

        <!-- ============ MONTANT ============ -->
        <label class="field">
          <span>💵 Montant *</span>
          <div class="amount-input">
            <span class="cur" id="curSymbol">CDF</span>
            <input type="text"
                   name="montant"
                   id="montantInput"
                   placeholder="0.00"
                   required
                   autofocus
                   inputmode="decimal"
                   autocomplete="off">
          </div>
        </label>

        <!-- ============ APERÇU TEMPS RÉEL ============ -->
        <div id="previewBox" class="info-banner" style="display:none">
          <strong>📊 Aperçu de l'opération :</strong>
          <div style="margin-top:10px;line-height:1.9" id="previewContent">—</div>
        </div>

        <!-- ============ MONTANTS RAPIDES ============ -->
        <div class="quick-amounts">
          <button type="button" onclick="setAmount(1000)">1 000</button>
          <button type="button" onclick="setAmount(5000)">5 000</button>
          <button type="button" onclick="setAmount(10000)">10 000</button>
          <button type="button" onclick="setAmount(50000)">50 000</button>
          <button type="button" onclick="setAmount(100000)">100 000</button>
          <button type="button" onclick="setAmount(500000)">500 000</button>
        </div>

        <!-- ============ NOTE ============ -->
        <label class="field">
          <span>📝 Note (optionnel)</span>
          <input type="text" name="note" maxlength="200"
                 placeholder="Ex: Retrait espèces au guichet - Client présent">
        </label>

        <!-- ============ BOUTON ============ -->
        <button class="btn-gold full" type="submit" id="btnSubmit">
          ✅ Valider l'opération
        </button>

        <p class="center muted" style="margin-top:16px">
          <a href="<?= $base ?>/caissier/dashboard.php">← Retour à la recherche</a>
        </p>
      </form>
    </div>

    <!-- ============ HISTORIQUE RÉCENT ============ -->
    <section class="card animate-in delay-3" style="margin-top:30px">
      <h3 style="margin-bottom:16px">📋 5 dernières opérations de ce client</h3>

      <?php if (!$historique): ?>
        <p class="muted center" style="padding:20px">Aucune transaction pour ce client.</p>
      <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Référence</th>
                <th>Type</th>
                <th style="text-align:right">Montant</th>
                <th style="text-align:right">Solde après</th>
                <th>Mode</th>
                <th>Date</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($historique as $h): ?>
                <tr>
                  <td><code><?= htmlspecialchars($h['reference']) ?></code></td>
                  <td>
                    <span class="tag <?= strtolower($h['type_transaction']) ?>">
                      <?= htmlspecialchars($h['type_transaction']) ?>
                    </span>
                  </td>
                  <td style="text-align:right;font-weight:700;color:<?= $h['type_transaction'] === 'Retrait' ? '#FCA5A5' : 'var(--green)' ?>">
                    <?= $h['type_transaction'] === 'Retrait' ? '−' : '+' ?>
                    <?= number_format((float)$h['montant'], 2, ',', ' ') ?>
                    <small class="muted"><?= htmlspecialchars($h['devise'] ?? 'CDF') ?></small>
                  </td>
                  <td style="text-align:right">
                    <?= number_format((float)$h['solde_apres'], 2, ',', ' ') ?>
                  </td>
                  <td><?= htmlspecialchars($h['mode']) ?></td>
                  <td><?= date('d/m/Y H:i', strtotime($h['date_transaction'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

  </main>
</div>

<footer class="atf-footer">© <?= date('Y') ?> AFRICAN TRUST FUND</footer>

<script>
/* ============================================================
   CONFIGURATION
   ============================================================ */
const SOLDE_ACTUEL  = <?= (float)$client['solde'] ?>;
const DEVISE_COMPTE = "<?= htmlspecialchars($client['devise']) ?>";
const TAUX_USD_CDF  = 2800;

let currentType = 'depot';

/* ============================================================
   SÉLECTION DU TYPE D'OPÉRATION
   ============================================================ */
function selectType(type) {
  currentType = type;
  document.getElementById('inputTypeOp').value = type;

  document.getElementById('btnDepot').classList.remove('active');
  document.getElementById('btnRetrait').classList.remove('active');
  document.getElementById(type === 'depot' ? 'btnDepot' : 'btnRetrait').classList.add('active');

  // Changer la couleur du bouton submit
  const btn = document.getElementById('btnSubmit');
  if (type === 'depot') {
    btn.textContent = '✅ Confirmer le DÉPÔT';
  } else {
    btn.textContent = '✅ Confirmer le RETRAIT';
  }

  updatePreview();
}

/* ============================================================
   APERÇU TEMPS RÉEL
   ============================================================ */
function updatePreview() {
  const devise = document.getElementById('deviseSelect').value;
  const montantInput = document.getElementById('montantInput');
  const montant = parseFloat(montantInput.value.replace(',', '.')) || 0;

  document.getElementById('curSymbol').textContent = devise;

  if (montant <= 0) {
    document.getElementById('previewBox').style.display = 'none';
    return;
  }

  // Conversion
  let montant_effectif = montant;
  let hasConversion = false;
  if (devise !== DEVISE_COMPTE) {
    hasConversion = true;
    if (devise === 'USD' && DEVISE_COMPTE === 'CDF') montant_effectif = montant * TAUX_USD_CDF;
    if (devise === 'CDF' && DEVISE_COMPTE === 'USD') montant_effectif = montant / TAUX_USD_CDF;
  }

  const nouveau_solde = currentType === 'depot'
    ? SOLDE_ACTUEL + montant_effectif
    : SOLDE_ACTUEL - montant_effectif;

  const fmt = (v) => v.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  let html = '';

  if (currentType === 'depot') {
    html += `<div><strong>➕ Dépôt :</strong> <span style="color:#6EE7B7;font-weight:700">+ ${fmt(montant)} ${devise}</span></div>`;
  } else {
    html += `<div><strong>➖ Retrait :</strong> <span style="color:#FCA5A5;font-weight:700">− ${fmt(montant)} ${devise}</span></div>`;
  }

  if (hasConversion) {
    html += `<div style="font-size:.85rem;color:var(--muted)">💱 Converti en <strong>${fmt(montant_effectif)} ${DEVISE_COMPTE}</strong></div>`;
  }

  html += `<div style="margin-top:8px;padding-top:8px;border-top:1px solid var(--border)">`;
  html += `<strong>Ancien solde :</strong> ${fmt(SOLDE_ACTUEL)} ${DEVISE_COMPTE}<br>`;
  html += `<strong>Nouveau solde :</strong> `;

  if (currentType === 'retrait' && nouveau_solde < 0) {
    html += `<span style="color:#FCA5A5;font-weight:800;font-size:1.1rem">⛔ SOLDE INSUFFISANT (${fmt(nouveau_solde)} ${DEVISE_COMPTE})</span>`;
  } else {
    html += `<span style="color:#F5B800;font-weight:800;font-size:1.1rem">${fmt(nouveau_solde)} ${DEVISE_COMPTE}</span>`;
  }
  html += `</div>`;

  document.getElementById('previewContent').innerHTML = html;
  document.getElementById('previewBox').style.display = 'block';
}

/* ============================================================
   MONTANTS RAPIDES
   ============================================================ */
function setAmount(v) {
  document.getElementById('montantInput').value = v;
  updatePreview();
}

/* ============================================================
   NETTOYAGE DU MONTANT (chiffres et point uniquement)
   ============================================================ */
document.getElementById('montantInput').addEventListener('input', (e) => {
  let v = e.target.value.replace(/[^\d.,]/g, '');
  v = v.replace(',', '.');
  const parts = v.split('.');
  if (parts.length > 2) v = parts[0] + '.' + parts.slice(1).join('');
  e.target.value = v;
  updatePreview();
});

document.getElementById('deviseSelect').addEventListener('change', updatePreview);

/* ============================================================
   SOUMISSION DU FORMULAIRE
   ============================================================ */
document.getElementById('formOperation').addEventListener('submit', (e) => {
  const montant = parseFloat(document.getElementById('montantInput').value) || 0;

  if (montant <= 0) {
    e.preventDefault();
    alert('Veuillez saisir un montant valide.');
    return false;
  }

  const btn = document.getElementById('btnSubmit');
  btn.disabled = true;
  btn.textContent = '⏳ Traitement en cours...';
});

/* ============================================================
   BARRE DE PROGRESSION SCROLL
   ============================================================ */
window.addEventListener('scroll', () => {
  const h = document.documentElement.scrollHeight - window.innerHeight;
  const el = document.getElementById('topProgress');
  if (el) el.style.width = (window.scrollY / h) * 100 + '%';
});

/* ============================================================
   INITIALISATION
   ============================================================ */
selectType('depot');
updatePreview();
</script>

<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>