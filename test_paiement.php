<?php
declare(strict_types=1);
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/secure_tokens.php';
require_once __DIR__ . '/config/payment.php';
require_once __DIR__ . '/config/MaishaPayService.php';

startSecureSession();

$pdo = Database::getConnection();
$maishaPayConfig = (require __DIR__ . '/config/payment.php')['maishapay'];
$maishaPay = new MaishaPayService($maishaPayConfig);

$result  = null;
$error   = null;
$success = null;
$payment_id = null;

/* ============================================================
   TRAITEMENT DU FORMULAIRE
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        /* Vérification CSRF */
        if (!verifyCsrf($_POST['csrf'] ?? null)) {
            throw new RuntimeException("Jeton de sécurité invalide. Rechargez la page.");
        }

        /* Récupération des données */
        $amount        = (float)str_replace([' ', ','], ['', '.'], $_POST['amount'] ?? '0');
        $currency      = in_array($_POST['currency'] ?? '', ['CDF','USD'], true) ? $_POST['currency'] : 'CDF';
        $provider      = in_array($_POST['provider'] ?? '', ['AIRTEL','ORANGE','MPESA','MTN'], true) ? $_POST['provider'] : 'AIRTEL';
        $wallet_id     = trim($_POST['wallet_id'] ?? '');
        $customer_name = trim($_POST['customer_name'] ?? 'Client Test');

        /* Validations */
        if ($amount <= 0) {
            throw new RuntimeException("Le montant doit être supérieur à 0.");
        }
        if ($amount > 1000000) {
            throw new RuntimeException("Montant maximum : 1 000 000.");
        }
        if ($wallet_id === '') {
            throw new RuntimeException("Le numéro de téléphone est obligatoire.");
        }
        if (!preg_match('/^\+?[0-9 ]{9,20}$/', $wallet_id)) {
            throw new RuntimeException("Numéro de téléphone invalide (ex: +243990001234).");
        }

        /* Générer une référence unique */
        $reference = 'ATF-' . date('YmdHis') . '-' . random_int(1000, 9999);

        /* ============================================================
           ÉTAPE 1 : Enregistrer la transaction EN ATTENTE en base
           ============================================================ */
        $pdo->beginTransaction();

        /* Chercher un client par téléphone (optionnel) */
        $stmt = $pdo->prepare("SELECT id_client, nom, postnom FROM Client WHERE numero_phone = ? LIMIT 1");
        $stmt->execute([$wallet_id]);
        $client = $stmt->fetch();
        $id_client = $client ? (int)$client['id_client'] : null;

        /* Insérer dans la table Paiements_MaishaPay (à créer) */
        $stmt = $pdo->prepare("
            INSERT INTO Paiements_MaishaPay 
                (reference, transaction_id_maishapay, id_client, customer_name, customer_phone,
                 provider, montant, devise, statut, response_json, date_creation)
            VALUES (?, NULL, ?, ?, ?, ?, ?, ?, 'pending', NULL, NOW())
        ");
        $stmt->execute([
            $reference,
            $id_client,
            $customer_name,
            $wallet_id,
            $provider,
            $amount,
            $currency
        ]);

        $payment_id = (int)$pdo->lastInsertId();

        $pdo->commit();

        /* ============================================================
           ÉTAPE 2 : Appeler l'API MaishaPay
           ============================================================ */
        try {
            $paymentParams = [
                'amount'         => $amount,
                'currency'       => $currency,
                'provider'       => $provider,
                'wallet_id'      => $wallet_id,
                'customer_name'  => $customer_name,
                'customer_phone' => $wallet_id,
                'reference'      => $reference,
            ];

            $result = $maishaPay->initiatePayment($paymentParams);

            /* ============================================================
               ÉTAPE 3 : Analyser la réponse
               ============================================================ */
            $status_code = (int)($result['data']['statusCode'] ?? 0);
            $status_desc = (string)($result['data']['statusDescription'] ?? '');
            $maisha_id   = (string)($result['data']['transactionId'] ?? '');

            if ($status_code === 200 && in_array($status_desc, ['Accepted', 'Success'], true)) {

                /* Mettre à jour la ligne avec les infos MaishaPay */
                $stmt = $pdo->prepare("
                    UPDATE Paiements_MaishaPay 
                    SET transaction_id_maishapay = ?,
                        response_json = ?,
                        statut = 'accepted',
                        date_initiation = NOW()
                    WHERE id_paiement = ?
                ");
                $stmt->execute([
                    $maisha_id,
                    json_encode($result, JSON_UNESCAPED_UNICODE),
                    $payment_id
                ]);

                $success = [
                    'reference'      => $reference,
                    'transaction_id' => $maisha_id,
                    'status'         => $status_desc,
                    'message'        => "Paiement initié avec succès. Le client doit confirmer sur son téléphone."
                ];

            } else {
                /* API a répondu mais sans succès */
                $stmt = $pdo->prepare("
                    UPDATE Paiements_MaishaPay 
                    SET statut = 'failed',
                        response_json = ?,
                        error_message = ?
                    WHERE id_paiement = ?
                ");
                $stmt->execute([
                    json_encode($result, JSON_UNESCAPED_UNICODE),
                    "API : $status_desc",
                    $payment_id
                ]);

                throw new RuntimeException("MaishaPay a refusé : " . $status_desc);
            }

        } catch (Throwable $api_error) {
            /* Marquer comme échouée */
            $stmt = $pdo->prepare("
                UPDATE Paiements_MaishaPay 
                SET statut = 'failed',
                    error_message = ?
                WHERE id_paiement = ?
            ");
            $stmt->execute([$api_error->getMessage(), $payment_id]);
            throw $api_error;
        }

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

/* ============================================================
   HISTORIQUE DES DERNIERS PAIEMENTS
   ============================================================ */
$stmt = $pdo->query("
    SELECT * FROM Paiements_MaishaPay 
    ORDER BY date_creation DESC 
    LIMIT 10
");
$historique = $stmt->fetchAll();

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Test Paiement MaishaPay — AFRICAN TRUST FUND</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<style>
.test-wrapper {
  max-width: 900px;
  margin: 40px auto;
  padding: 0 20px;
}

.test-card {
  background: linear-gradient(160deg, #1A2130, #121824);
  border: 1px solid rgba(245,184,0,.3);
  border-radius: 24px;
  padding: 36px;
  box-shadow: 0 20px 60px rgba(0,0,0,.5);
  margin-bottom: 30px;
}

.test-header {
  display: flex;
  align-items: center;
  gap: 16px;
  margin-bottom: 30px;
  padding-bottom: 20px;
  border-bottom: 2px solid rgba(245,184,0,.2);
}

.test-header .icon {
  width: 60px;
  height: 60px;
  border-radius: 16px;
  background: linear-gradient(135deg, #F5B800, #D4AF37);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.8rem;
  box-shadow: 0 8px 24px rgba(245,184,0,.4);
}

.test-header h1 {
  font-size: 1.5rem;
  color: #fff;
  margin: 0;
  font-weight: 800;
}

.test-header .sub {
  color: #8A93A6;
  font-size: .9rem;
  margin-top: 4px;
}

.mode-badge {
  margin-left: auto;
  padding: 8px 18px;
  border-radius: 999px;
  font-size: .82rem;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 1px;
}

.mode-badge.sandbox {
  background: rgba(245,184,0,.15);
  color: #F5B800;
  border: 1px solid rgba(245,184,0,.4);
}

.mode-badge.live {
  background: rgba(16,185,129,.15);
  color: #6EE7B7;
  border: 1px solid rgba(16,185,129,.4);
}

/* Formulaire */
.form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
}

.form-grid.full {
  grid-template-columns: 1fr;
}

.field-large {
  grid-column: span 2;
}

.field {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.field label {
  color: #8A93A6;
  font-size: .82rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 1px;
}

.field input,
.field select {
  padding: 14px 16px;
  background: #0B0E14;
  border: 1px solid rgba(245,184,0,.25);
  border-radius: 12px;
  color: #E8ECF3;
  font-size: 1rem;
  font-family: inherit;
  transition: all .2s;
}

.field input:focus,
.field select:focus {
  outline: none;
  border-color: #F5B800;
  box-shadow: 0 0 0 3px rgba(245,184,0,.15);
}

.field .hint {
  color: #5A6275;
  font-size: .75rem;
  font-style: italic;
}

/* Montants rapides */
.quick-amounts {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  margin-top: 10px;
}

.quick-amounts button {
  padding: 8px 16px;
  border-radius: 8px;
  background: rgba(245,184,0,.08);
  border: 1px solid rgba(245,184,0,.3);
  color: #F5B800;
  font-weight: 700;
  font-size: .82rem;
  cursor: pointer;
  font-family: inherit;
  transition: all .2s;
}

.quick-amounts button:hover {
  background: rgba(245,184,0,.15);
  border-color: rgba(245,184,0,.5);
  transform: translateY(-2px);
}

/* Bouton envoyer */
.btn-submit {
  width: 100%;
  padding: 18px;
  border-radius: 999px;
  background: linear-gradient(135deg, #F5B800, #D4AF37);
  color: #0B0E14;
  border: none;
  font-weight: 800;
  font-size: 1rem;
  cursor: pointer;
  font-family: inherit;
  transition: all .25s;
  margin-top: 20px;
}

.btn-submit:hover:not(:disabled) {
  transform: translateY(-3px);
  box-shadow: 0 14px 40px rgba(245,184,0,.5);
}

.btn-submit:disabled {
  opacity: .6;
  cursor: not-allowed;
  transform: none;
}

/* Alertes */
.alert-box {
  padding: 20px 24px;
  border-radius: 16px;
  margin-bottom: 24px;
  animation: slideIn .3s ease-out;
}

@keyframes slideIn {
  from { opacity: 0; transform: translateY(-10px); }
  to   { opacity: 1; transform: translateY(0); }
}

.alert-box.success {
  background: linear-gradient(135deg, rgba(16,185,129,.15), rgba(16,185,129,.05));
  border: 2px solid rgba(16,185,129,.4);
  color: #6EE7B7;
}

.alert-box.error {
  background: linear-gradient(135deg, rgba(239,68,68,.15), rgba(239,68,68,.05));
  border: 2px solid rgba(239,68,68,.4);
  color: #FCA5A5;
}

.alert-box h3 {
  margin: 0 0 12px;
  font-size: 1.1rem;
  display: flex;
  align-items: center;
  gap: 10px;
}

.alert-box .details {
  background: rgba(0,0,0,.25);
  padding: 14px 18px;
  border-radius: 10px;
  margin-top: 12px;
  font-family: 'Courier New', monospace;
  font-size: .88rem;
  line-height: 1.7;
}

.alert-box .details strong {
  color: #fff;
}

/* Instructions client */
.client-instructions {
  margin-top: 20px;
  padding: 20px;
  border-radius: 14px;
  background: linear-gradient(135deg, rgba(59,130,246,.1), rgba(59,130,246,.02));
  border: 1px solid rgba(59,130,246,.3);
  color: #93B4FF;
}

.client-instructions h4 {
  margin: 0 0 12px;
  font-size: 1rem;
  display: flex;
  align-items: center;
  gap: 8px;
}

.client-instructions ol {
  margin: 0;
  padding-left: 24px;
  line-height: 2;
  font-size: .92rem;
}

.client-instructions li strong {
  color: #fff;
}

/* Historique */
.historique-table {
  width: 100%;
  border-collapse: collapse;
  font-size: .88rem;
}

.historique-table th {
  padding: 12px;
  text-align: left;
  color: #8A93A6;
  font-size: .72rem;
  text-transform: uppercase;
  letter-spacing: 1px;
  font-weight: 700;
  border-bottom: 1px solid rgba(255,255,255,.1);
  background: rgba(11,14,20,.5);
}

.historique-table td {
  padding: 14px 12px;
  border-bottom: 1px solid rgba(255,255,255,.05);
  vertical-align: middle;
}

.historique-table .ref {
  font-family: 'Courier New', monospace;
  font-size: .78rem;
  color: #F5B800;
}

.statut-badge {
  display: inline-block;
  padding: 4px 12px;
  border-radius: 999px;
  font-size: .72rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .5px;
}

.statut-badge.pending {
  background: rgba(245,184,0,.15);
  color: #F5B800;
  border: 1px solid rgba(245,184,0,.3);
}

.statut-badge.accepted {
  background: rgba(59,130,246,.15);
  color: #93B4FF;
  border: 1px solid rgba(59,130,246,.3);
}

.statut-badge.success {
  background: rgba(16,185,129,.15);
  color: #6EE7B7;
  border: 1px solid rgba(16,185,129,.3);
}

.statut-badge.failed {
  background: rgba(239,68,68,.15);
  color: #FCA5A5;
  border: 1px solid rgba(239,68,68,.3);
}

/* Spinner */
.spinner {
  display: inline-block;
  width: 16px;
  height: 16px;
  border: 2px solid rgba(11,14,20,.3);
  border-top-color: #0B0E14;
  border-radius: 50%;
  animation: spin .6s linear infinite;
  margin-right: 8px;
  vertical-align: middle;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

/* Responsive */
@media (max-width: 640px) {
  .test-card { padding: 24px 18px; }
  .form-grid { grid-template-columns: 1fr; }
  .field-large { grid-column: span 1; }
  .test-header { flex-wrap: wrap; }
  .mode-badge { margin-left: 0; }
}
</style>
</head>
<body class="dark-auth">

<?php require __DIR__ . '/includes/header.php'; ?>

<div class="test-wrapper">

  <!-- EN-TÊTE -->
  <div class="test-card">
    <div class="test-header">
      <div class="icon">💳</div>
      <div>
        <h1>Test Paiement En ligne</h1>
        <div class="sub">Intégration Mobile Money · ATF</div>
      </div>
      <div class="mode-badge <?= (int)$maishaPayConfig['gateway_mode'] === 0 ? 'sandbox' : 'live' ?>">
        <?= (int)$maishaPayConfig['gateway_mode'] === 0 ? '🧪 Sandbox' : '🚀 Live' ?>
      </div>
    </div>

    <!-- ALERTE SUCCÈS -->
    <?php if ($success): ?>
      <div class="alert-box success">
        <h3>✅ Paiement initié avec succès !</h3>
        <div class="details">
          <div><strong>Référence ATF :</strong> <?= htmlspecialchars($success['reference']) ?></div>
          <div><strong>Transaction MaishaPay :</strong> #<?= htmlspecialchars($success['transaction_id']) ?></div>
          <div><strong>Statut :</strong> <?= htmlspecialchars($success['status']) ?></div>
        </div>
      </div>

      <div class="client-instructions">
        <h4>📱 Instructions pour le client</h4>
        <ol>
          <li>Le client va recevoir <strong>un SMS ou une notification</strong> sur son téléphone.</li>
          <li>Il doit <strong>ouvrir le message</strong> et suivre les instructions.</li>
          <li>Il doit <strong>taper son code PIN</strong> pour confirmer le paiement.</li>
          <li>Une fois validé, le compte sera <strong>automatiquement crédité</strong>.</li>
          <li>Délai maximum : <strong>5 minutes</strong>. Passé ce délai, la transaction sera annulée.</li>
        </ol>
      </div>
    <?php endif; ?>

    <!-- ALERTE ERREUR -->
    <?php if ($error): ?>
      <div class="alert-box error">
        <h3>❌ Erreur</h3>
        <div class="details"><?= htmlspecialchars($error) ?></div>
        <?php if ($payment_id): ?>
          <p style="margin-top:12px;font-size:.85rem">
            Référence interne : <strong>#<?= $payment_id ?></strong>
          </p>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <!-- FORMULAIRE -->
    <form method="POST" novalidate autocomplete="off">
      <?= csrfField() ?>

      <div class="form-grid">
        <div class="field">
          <label for="amount">💰 Montant *</label>
          <input type="number" name="amount" id="amount" 
                 value="<?= htmlspecialchars($_POST['amount'] ?? '100') ?>"
                 step="0.01" min="1" max="1000000" required>
          <div class="quick-amounts">
            <button type="button" onclick="setAmount(100)">100</button>
            <button type="button" onclick="setAmount(1000)">1 000</button>
            <button type="button" onclick="setAmount(5000)">5 000</button>
            <button type="button" onclick="setAmount(10000)">10 000</button>
            <button type="button" onclick="setAmount(50000)">50 000</button>
          </div>
        </div>

        <div class="field">
          <label for="currency">💱 Devise *</label>
          <select name="currency" id="currency" required>
            <option value="CDF" <?= ($_POST['currency'] ?? 'CDF') === 'CDF' ? 'selected' : '' ?>>🇨🇩 CDF (Franc Congolais)</option>
            <option value="USD" <?= ($_POST['currency'] ?? '') === 'USD' ? 'selected' : '' ?>>🇺🇸 USD (Dollar Américain)</option>
          </select>
        </div>

        <div class="field">
          <label for="provider">📡 Opérateur *</label>
          <select name="provider" id="provider" required>
            <option value="AIRTEL" <?= ($_POST['provider'] ?? 'AIRTEL') === 'AIRTEL' ? 'selected' : '' ?>>📱 Airtel Money</option>
            <option value="ORANGE" <?= ($_POST['provider'] ?? '') === 'ORANGE' ? 'selected' : '' ?>>🟠 Orange Money</option>
            <option value="MPESA" <?= ($_POST['provider'] ?? '') === 'MPESA' ? 'selected' : '' ?>>🟢 M-Pesa</option>
            <option value="MTN" <?= ($_POST['provider'] ?? '') === 'MTN' ? 'selected' : '' ?>>🟡 MTN Mobile Money</option>
          </select>
        </div>

        <div class="field">
          <label for="wallet_id">📱 Numéro de téléphone *</label>
          <input type="tel" name="wallet_id" id="wallet_id"
                 value="<?= htmlspecialchars($_POST['wallet_id'] ?? '+243990001234') ?>"
                 placeholder="+243 99 000 1234" required>
          <div class="hint">Le numéro qui recevra la demande de paiement</div>
        </div>

        <div class="field field-large">
          <label for="customer_name">👤 Nom du client (optionnel)</label>
          <input type="text" name="customer_name" id="customer_name"
                 value="<?= htmlspecialchars($_POST['customer_name'] ?? '') ?>"
                 placeholder="Ex: KAMBA MULUMBA Jean"
                 maxlength="100">
        </div>
      </div>

      <button type="submit" class="btn-submit" id="btnSubmit">
        🚀 Lancer le paiement test
      </button>
    </form>
  </div>

  <!-- HISTORIQUE -->
  <?php if (!empty($historique)): ?>
    <div class="test-card">
      <h2 style="margin:0 0 20px;font-size:1.2rem;color:#F5B800">
        📋 Historique des 10 derniers paiements
      </h2>
      <div style="overflow-x:auto">
        <table class="historique-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Référence</th>
              <th>Client</th>
              <th>Opérateur</th>
              <th style="text-align:right">Montant</th>
              <th>Statut</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($historique as $h): ?>
              <tr>
                <td><?= date('d/m H:i', strtotime($h['date_creation'])) ?></td>
                <td><span class="ref"><?= htmlspecialchars($h['reference']) ?></span></td>
                <td>
                  <?= htmlspecialchars($h['customer_name'] ?: '—') ?><br>
                  <small style="color:#8A93A6"><?= htmlspecialchars($h['customer_phone']) ?></small>
                </td>
                <td>
                  <span class="badge" style="background:rgba(59,130,246,.15);color:#93B4FF;font-size:.7rem">
                    <?= htmlspecialchars($h['provider']) ?>
                  </span>
                </td>
                <td style="text-align:right;font-weight:700;color:#F5B800">
                  <?= number_format((float)$h['montant'], 2, ',', ' ') ?> <?= htmlspecialchars($h['devise']) ?>
                </td>
                <td>
                  <span class="statut-badge <?= htmlspecialchars($h['statut']) ?>">
                    <?= htmlspecialchars($h['statut']) ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  <?php endif; ?>

  <!-- INFO -->
  <div class="test-card" style="background:linear-gradient(135deg,rgba(139,92,246,.1),rgba(139,92,246,.02));border-color:rgba(139,92,246,.3)">
    <h3 style="color:#A78BFA;margin:0 0 12px">💡 Comment ça fonctionne ?</h3>
    <ol style="color:#8A93A6;line-height:2;margin:0;padding-left:24px">
      <li>Vous remplissez le formulaire et cliquez sur <strong style="color:#fff">« Lancer le paiement »</strong></li>
      <li>Le système enregistre la transaction <strong style="color:#fff">en attente</strong> dans la base</li>
      <li>Il envoie la requête à <strong style="color:#fff">MaishaPay</strong></li>
      <li>MaishaPay <strong style="color:#fff">notifie le client</strong> sur son téléphone</li>
      <li>Le client <strong style="color:#fff">valide avec son code PIN</strong></li>
      <li>MaishaPay envoie un <strong style="color:#fff">callback</strong> à votre serveur</li>
      <li>Le compte du client est <strong style="color:#fff">automatiquement crédité</strong></li>
    </ol>
  </div>

</div>

<script>
/* Montants rapides */
function setAmount(v) {
  document.getElementById('amount').value = v;
}

/* Spinner au submit */
document.querySelector('form').addEventListener('submit', function() {
  const btn = document.getElementById('btnSubmit');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span> Traitement en cours...';
});

/* Format du téléphone */
const phoneInput = document.getElementById('wallet_id');
phoneInput.addEventListener('input', function() {
  let v = this.value.replace(/[^\d+ ]/g, '');
  this.value = v;
});

console.log('%c💳 Page de test MaishaPay chargée', 'color:#F5B800;font-weight:bold;font-size:14px');
</script>

<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>