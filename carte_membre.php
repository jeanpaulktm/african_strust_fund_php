<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth_guard.php';
require_once __DIR__ . '/config/secure_tokens.php';

/* ============================================================
   VÉRIFICATION DU TYPE ET DU TOKEN
   ============================================================ */
$type_carte = $_GET['type'] ?? '';
$token = $_GET['token'] ?? '';
$is_admin_view = isset($_GET['admin']) && $_GET['admin'] === '1';

if (!in_array($type_carte, ['client', 'caissier', 'actionnaire'], true) || empty($token)) {
    refuserAcces("Accès non autorisé. Token manquant.");
}

$pdo = Database::getConnection();

/* ============================================================
   RÉCUPÉRATION SELON LE TYPE (100% via TOKEN)
   ============================================================ */
$entite = null;
$data = consommerToken($type_carte, $token);

if (!$data) refuserAcces("Token invalide ou expiré.");

$id = (int)$data['id'];

if ($type_carte === 'client') {
    $stmt = $pdo->prepare("SELECT * FROM Client WHERE id_client = ?");
    $stmt->execute([$id]);
    $entite = $stmt->fetch();

} elseif ($type_carte === 'caissier') {
    $stmt = $pdo->prepare("SELECT * FROM Caissier WHERE id_caissier = ?");
    $stmt->execute([$id]);
    $entite = $stmt->fetch();

} else {
    $stmt = $pdo->prepare("SELECT * FROM Actionnaires WHERE id_actionnaire = ?");
    $stmt->execute([$id]);
    $entite = $stmt->fetch();
}

if (!$entite) refuserAcces("Compte introuvable.");

/* ============================================================
   PRÉPARER LES DONNÉES DE LA CARTE
   ============================================================ */
if ($type_carte === 'client') {
    $carte_numero = $entite['carte_numero'] ?? ('ATF-CLI-' . str_pad((string)$id, 8, '0', STR_PAD_LEFT));
    $carte_emise  = $entite['carte_emise'] ?? date('Y-m-d');
    $carte_expire = $entite['carte_expire'] ?? date('Y-m-d', strtotime('+5 years'));
    $carte_qr     = $entite['carte_qr_token'] ?? '';
    $nom_complet  = strtoupper($entite['nom'] . ' ' . $entite['postnom'] . ' ' . $entite['prenom']);
    $role_label   = 'CLIENT';
    $carte_class  = 'client';
    $photo        = $entite['photo_profil'] ?? null;
    $initiales    = strtoupper(mb_substr($entite['nom'], 0, 1) . mb_substr($entite['prenom'] ?: $entite['postnom'], 0, 1));
    $numero_compte = $entite['numero_compte'] ?? '';
    $telephone    = $entite['numero_phone'] ?? '';
    $statut_membre = $entite['statut'] ?? 'actif';

} elseif ($type_carte === 'caissier') {
    $carte_numero = $entite['carte_numero'] ?? ('ATF-CAI-' . str_pad((string)$id, 8, '0', STR_PAD_LEFT));
    $carte_emise  = $entite['carte_emise'] ?? date('Y-m-d');
    $carte_expire = $entite['carte_expire'] ?? date('Y-m-d', strtotime('+5 years'));
    $carte_qr     = $entite['carte_qr_token'] ?? '';
    $nom_complet  = strtoupper($entite['nom'] . ' ' . $entite['postnom'] . ' ' . ($entite['post_prenom'] ?? ''));
    $role_label   = 'CAISSIER';
    $carte_class  = 'caissier';
    $photo        = $entite['photo_profil'] ?? null;
    $initiales    = strtoupper(mb_substr($entite['nom'], 0, 1) . mb_substr($entite['post_prenom'] ?? '', 0, 1));
    $numero_compte = $entite['matricule'] ?? '';
    $telephone    = $entite['telephone'] ?? '';
    $statut_membre = $entite['statut'] ?? 'actif';

} else {
    $carte_numero = $entite['carte_numero'] ?? ('ATF-ACT-' . str_pad((string)$id, 8, '0', STR_PAD_LEFT));
    $carte_emise  = $entite['carte_emise'] ?? date('Y-m-d');
    $carte_expire = $entite['carte_expire'] ?? date('Y-m-d', strtotime('+5 years'));
    $carte_qr     = $entite['carte_qr_token'] ?? '';
    $nom_complet  = strtoupper($entite['nom'] . ' ' . $entite['postnom'] . ' ' . $entite['prenom']);
    $role_label   = 'ACTIONNAIRE';
    $carte_class  = 'actionnaire';
    $photo        = $entite['photo_profil'] ?? null;
    $initiales    = strtoupper(mb_substr($entite['nom'], 0, 1) . mb_substr($entite['prenom'] ?: $entite['postnom'], 0, 1));
    $numero_compte = $entite['numero_actionnaire'] ?? '';
    $telephone    = $entite['telephone'] ?? '';
    $statut_membre = $entite['statut'] ?? 'actif';
}

/* Numéro de carte en groupes de 4 */
$numero_clean = preg_replace('/[^A-Z0-9]/', '', $carte_numero);
$numero_groupes = [];
for ($i = 0; $i < 16; $i += 4) {
    $numero_groupes[] = substr($numero_clean . str_repeat('0', 16), $i, 4);
}

/* ============================================================
   🆕 QR CODE ENRICHI — Contient les infos publiques du membre
   ============================================================ */
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$qr_url = 'http://' . $host . '/african-trust-fund/verifier_carte.php?token=' . urlencode($carte_qr);

/* Payload JSON dans le QR (données non sensibles + URL de vérif) */
$qr_payload = json_encode([
    'atf'         => 1,
    'type'        => $type_carte,
    'carte'       => $carte_numero,
    'nom'         => $nom_complet,
    'compte'      => $numero_compte,
    'tel'         => $telephone,
    'statut'      => $statut_membre,
    'emise'       => $carte_emise,
    'expire'      => $carte_expire,
    'verif'       => $qr_url
], JSON_UNESCAPED_UNICODE);

/* Vérification photo */
$photo_path = null;
if ($photo && file_exists(__DIR__ . '/' . $photo)) {
    $photo_path = $photo;
}

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Carte Membre — <?= htmlspecialchars($nom_complet) ?></title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/carte.css">
<script src="<?= $base ?>/assets/js/qrcode.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js"></script>
<style>
/* ============================================================
   STRUCTURE GÉNÉRALE
   ============================================================ */
.carte-page {
  min-height: 100vh;
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding: 30px 16px;
}

.carte-container {
  width: 100%;
  max-width: 620px;
}

.carte-header {
  text-align: center;
  margin-bottom: 24px;
}

.carte-header h2 {
  color: var(--gold);
  font-size: 1.3rem;
  letter-spacing: 2px;
  margin-bottom: 6px;
}

.carte-header p {
  color: var(--muted);
  font-size: .9rem;
}

/* ============================================================
   BOUTONS D'ACTIONS
   ============================================================ */
.carte-actions {
  display: flex;
  gap: 12px;
  margin-top: 24px;
  justify-content: center;
  flex-wrap: wrap;
}

.carte-actions .btn {
  padding: 14px 26px;
  border-radius: 999px;
  font-weight: 700;
  font-size: .9rem;
  border: none;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  transition: all .25s;
  font-family: inherit;
  text-decoration: none;
}

.btn-download {
  background: linear-gradient(135deg, #10B981, #059669);
  color: #fff;
  box-shadow: 0 8px 24px rgba(16,185,129,.35);
}
.btn-download:hover {
  transform: translateY(-2px);
  box-shadow: 0 12px 32px rgba(16,185,129,.5);
}
.btn-download:disabled {
  opacity: .6;
  cursor: not-allowed;
  transform: none;
}

.btn-print {
  background: linear-gradient(135deg, #F5B800, #D4AF37);
  color: #0B0E14;
  box-shadow: 0 8px 24px rgba(245,184,0,.35);
}
.btn-print:hover {
  transform: translateY(-2px);
  box-shadow: 0 12px 32px rgba(245,184,0,.5);
}

.btn-back {
  background: rgba(255,255,255,.08);
  color: var(--text);
  border: 1px solid var(--border);
}
.btn-back:hover {
  background: rgba(255,255,255,.14);
  transform: translateY(-2px);
}

/* Notification toast */
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
  z-index: 9999;
  opacity: 0;
  transition: all .4s cubic-bezier(.4,0,.2,1);
  pointer-events: none;
  display: flex;
  align-items: center;
  gap: 8px;
}
.toast.show {
  opacity: 1;
  transform: translateX(-50%) translateY(0);
}

/* Overlay de chargement */
.download-overlay {
  position: fixed;
  inset: 0;
  background: rgba(11,14,20,.85);
  backdrop-filter: blur(8px);
  z-index: 9998;
  display: none;
  align-items: center;
  justify-content: center;
  flex-direction: column;
  gap: 20px;
}
.download-overlay.active { display: flex; }

.spinner {
  width: 60px;
  height: 60px;
  border: 4px solid rgba(245,184,0,.2);
  border-top-color: #F5B800;
  border-radius: 50%;
  animation: spin 1s linear infinite;
}
@keyframes spin {
  to { transform: rotate(360deg); }
}

.download-overlay p {
  color: #F5B800;
  font-weight: 700;
  font-size: 1rem;
  letter-spacing: 1px;
}

/* Preview zone (off-screen pour la capture) */
#captureZone {
  position: absolute;
  left: -9999px;
  top: 0;
}

/* ============================================================
   MOBILE
   ============================================================ */
@media (max-width: 640px) {
  .carte-page { padding: 16px 10px; }
  .carte-actions { flex-direction: column; }
  .carte-actions .btn { width: 100%; justify-content: center; }
}

/* ============================================================
   IMPRESSION
   ============================================================ */
@media print {
  body { background: #fff !important; }
  .no-print { display: none !important; }
  .carte-page { padding: 0; }
  .carte-container { max-width: 100%; }
}
</style>
</head>
<body class="dark-auth">

<div class="carte-page">
  <div class="carte-container">

    <div class="carte-header no-print">
      <h2>💳 CARTE MEMBRE</h2>
      <p>AFRICAN TRUST FUND · <?= $role_label ?></p>
    </div>

    <!-- ZONE À CAPTURER (recto + verso) -->
    <div class="carte-wrapper" id="carteCapture">

      <!-- ============ RECTO ============ -->
      <div class="carte-atf <?= $carte_class ?>">
        <div class="carte-contenu">

          <div class="carte-top">
            <div class="carte-logo-atf">
              <img src="<?= $base ?>/assets/img/logo.png" alt="ATF"
                   onerror="this.src='data:image/svg+xml;utf8,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><circle cx=%2250%22 cy=%2250%22 r=%2245%22 fill=%22%23F5B800%22/><text x=%2250%22 y=%2262%22 font-size=%2234%22 text-anchor=%22middle%22 fill=%22%230B0E14%22 font-weight=%22bold%22 font-family=%22Arial%22>ATF</text></svg>'">
              <div class="carte-marque">
                <div class="nom">AFRICAN TRUST FUND</div>
                <div class="type">Carte Membre Officielle</div>
              </div>
            </div>
            <div class="carte-badge"><?= $role_label ?></div>
          </div>

          <div class="carte-middle">
            <div class="carte-middle-left">
              <div class="carte-puce"></div>
              <div class="carte-numero">
                <?php foreach ($numero_groupes as $groupe): ?>
                  <span><?= htmlspecialchars($groupe) ?></span>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="carte-photo">
              <?php if ($photo_path): ?>
                <img src="<?= $base ?>/<?= htmlspecialchars($photo_path) ?>"
                     alt="Photo" crossorigin="anonymous"
                     onerror="this.style.display='none'; this.nextElementSibling.style.display='flex'">
                <div class="photo-placeholder" style="display:none">
                  <div class="initiales"><?= $initiales ?></div>
                  <div class="lbl">Photo</div>
                </div>
              <?php else: ?>
                <div class="photo-placeholder">
                  <div class="initiales"><?= $initiales ?></div>
                  <div class="lbl">Photo</div>
                </div>
              <?php endif; ?>
            </div>
          </div>

          <div class="carte-bottom">
            <div class="carte-bottom-left">
              <div class="carte-nom">
                <div class="lbl">Titulaire</div>
                <div class="val"><?= htmlspecialchars(mb_substr($nom_complet, 0, 26)) ?></div>
              </div>
              <div class="carte-expiration">
                <div class="lbl">Valide jusqu'à</div>
                <div class="val"><?= date('m/y', strtotime($carte_expire)) ?></div>
              </div>
            </div>

            <div class="carte-qr" id="qrCode"></div>
          </div>

        </div>

        <div class="carte-hologramme"></div>
        <div class="carte-visa-brand">ATF</div>
      </div>

      <!-- ============ VERSO ============ -->
      <div class="carte-verso">
        <div class="bande-magnetique"></div>
        <div class="zone-signature">
          <?= htmlspecialchars($nom_complet) ?>
        </div>
        <div class="mention">
          Cette carte est la propriété exclusive d'<strong>AFRICAN TRUST FUND</strong>.<br>
          En cas de perte, veuillez la signaler immédiatement au +243 123 456 789.<br>
          RCCM : CD/KIN/RCCM/24-B-01234 · NIF : A0123456Z<br>
          www.africantrustfund.cd<br><br>
          Carte N° <?= htmlspecialchars($carte_numero) ?> · Émise le <?= date('d/m/Y', strtotime($carte_emise)) ?>
        </div>
      </div>

    </div>

    <!-- ============ ACTIONS ============ -->
    <div class="carte-actions no-print">
      <button class="btn btn-download" id="btnDownload" onclick="downloadCarte()">
        📥 Télécharger la carte (PNG)
      </button>
      <button class="btn btn-print" onclick="window.print()">
        🖨️ Imprimer
      </button>
      <button class="btn btn-back" onclick="history.back()">
        ← Retour
      </button>
    </div>

  </div>
</div>

<!-- Toast -->
<div class="toast" id="toast">
  <span>✅</span>
  <span id="toastMsg">Carte téléchargée !</span>
</div>

<!-- Overlay de chargement -->
<div class="download-overlay" id="downloadOverlay">
  <div class="spinner"></div>
  <p id="downloadText">Génération de l'image...</p>
</div>

<script>
/* ============================================================
   GÉNÉRATION DU QR CODE (avec toutes les infos)
   ============================================================ */
window.addEventListener('load', function() {
    const qrPayload = <?= json_encode($qr_payload) ?>;

    try {
        new QRCode(document.getElementById("qrCode"), {
            text: qrPayload,
            width: 70,
            height: 70,
            colorDark: "#0B0E14",
            colorLight: "#FFFFFF",
            correctLevel: QRCode.CorrectLevel.L   // L = plus de capacité
        });
    } catch (e) {
        console.error('QR Code error:', e);
        document.getElementById("qrCode").innerHTML =
            '<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:#0B0E14;font-size:10px;font-weight:800;text-align:center;padding:4px">ATF</div>';
    }
});

/* ============================================================
   TÉLÉCHARGEMENT PNG DE LA CARTE
   ============================================================ */
async function downloadCarte() {
    const btn = document.getElementById('btnDownload');
    const overlay = document.getElementById('downloadOverlay');
    const toast = document.getElementById('toast');

    btn.disabled = true;
    overlay.classList.add('active');

    try {
        /* Étape 1 : capturer le recto */
        document.getElementById('downloadText').textContent = 'Capture du recto...';
        const recto = await html2canvas(document.querySelector('.carte-atf'), {
            scale: 3,
            backgroundColor: '#0B0E14',
            useCORS: true,
            allowTaint: true,
            logging: false,
            width: 620,
            height: 380
        });

        /* Étape 2 : capturer le verso */
        document.getElementById('downloadText').textContent = 'Capture du verso...';
        const verso = await html2canvas(document.querySelector('.carte-verso'), {
            scale: 3,
            backgroundColor: '#0B0E14',
            useCORS: true,
            allowTaint: true,
            logging: false,
            width: 620,
            height: 380
        });

        /* Étape 3 : fusionner dans un canvas unique */
        document.getElementById('downloadText').textContent = 'Fusion des 2 faces...';

        const padding = 40;
        const gap = 40;
        const canvasFinal = document.createElement('canvas');

        const scaleW = 2; // échelle finale
        const w = Math.max(recto.width, verso.width);
        const h = recto.height + verso.height + padding * 2 + gap;

        canvasFinal.width = w;
        canvasFinal.height = h;

        const ctx = canvasFinal.getContext('2d');

        /* Fond */
        ctx.fillStyle = '#0B0E14';
        ctx.fillRect(0, 0, w, h);

        /* Titre */
        ctx.fillStyle = '#F5B800';
        ctx.font = 'bold 32px Arial';
        ctx.textAlign = 'center';
        ctx.fillText('AFRICAN TRUST FUND', w / 2, padding + 20);

        ctx.fillStyle = '#8A93A6';
        ctx.font = '18px Arial';
        ctx.fillText('Carte Membre — <?= addslashes($role_label) ?>', w / 2, padding + 50);

        /* Dessin recto */
        ctx.drawImage(recto, 0, padding + 80, w, recto.height);

        /* Dessin verso */
        ctx.drawImage(verso, 0, padding + 80 + recto.height + gap, w, verso.height);

        /* Footer */
        ctx.fillStyle = '#8A93A6';
        ctx.font = '14px Arial';
        ctx.textAlign = 'center';
        ctx.fillText(
            'Carte N° <?= addslashes($carte_numero) ?> · Générée le ' + new Date().toLocaleDateString('fr-FR'),
            w / 2,
            h - padding / 2
        );

        /* Étape 4 : télécharger */
        document.getElementById('downloadText').textContent = 'Enregistrement...';

        const filename = 'carte_<?= preg_replace('/[^A-Za-z0-9]/', '_', $carte_numero) ?>_' +
                         new Date().toISOString().slice(0,10) + '.png';

        canvasFinal.toBlob(function(blob) {
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = filename;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            setTimeout(() => URL.revokeObjectURL(url), 1000);

            /* Feedback */
            overlay.classList.remove('active');
            btn.disabled = false;

            showToast('📥 Carte téléchargée : ' + filename);
        }, 'image/png', 1.0);

    } catch (err) {
        console.error('Erreur téléchargement :', err);
        overlay.classList.remove('active');
        btn.disabled = false;
        showToast('❌ Erreur lors de la génération. Réessayez.', true);
    }
}

/* ============================================================
   TOAST DE NOTIFICATION
   ============================================================ */
function showToast(message, isError = false) {
    const toast = document.getElementById('toast');
    const toastMsg = document.getElementById('toastMsg');
    const icon = toast.querySelector('span:first-child');

    toastMsg.textContent = message;

    if (isError) {
        toast.style.background = 'linear-gradient(135deg, #DC2626, #EF4444)';
        icon.textContent = '❌';
    } else {
        toast.style.background = 'linear-gradient(135deg, #10B981, #059669)';
        icon.textContent = '✅';
    }

    toast.classList.add('show');
    setTimeout(() => toast.classList.remove('show'), 3500);
}

/* ============================================================
   AUTO-IMPRESSION
   ============================================================ */
if (new URLSearchParams(location.search).has('auto')) {
    window.addEventListener('load', () => setTimeout(() => window.print(), 900));
}

/* ============================================================
   RACCOURCIS CLAVIER
   ============================================================ */
document.addEventListener('keydown', (e) => {
    if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        downloadCarte();
    }
    if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
        e.preventDefault();
        window.print();
    }
});

console.log('%c💳 Carte Membre ATF', 'color:#F5B800;font-weight:bold;font-size:14px');
console.log('%c📥 Téléchargement PNG disponible · QR Code enrichi', 'color:#10B981;font-size:12px');
</script>

</body>
</html>