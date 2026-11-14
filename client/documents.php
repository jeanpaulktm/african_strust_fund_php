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

/* ============================================================
   TYPES DE DOCUMENTS ACCEPTÉS
   ============================================================ */
$types_doc = [
    'carte_electeur'      => ['label' => '🗳️ Carte d\'électeur', 'obligatoire' => true],
    'passeport'           => ['label' => '📘 Passeport', 'obligatoire' => false],
    'photo_identite'      => ['label' => '🖼️ Photo d\'identité', 'obligatoire' => true],
    'certificat_residence'=> ['label' => '🏠 Certificat de résidence', 'obligatoire' => true],
    'attestation_travail' => ['label' => '💼 Attestation de travail', 'obligatoire' => false],
    'fiche_paie'          => ['label' => '💰 Fiche de paie', 'obligatoire' => false],
    'rccm'                => ['label' => '📋 RCCM (entreprise)', 'obligatoire' => false],
    'nif'                 => ['label' => '🎫 NIF (entreprise)', 'obligatoire' => false],
    'releve_bancaire'     => ['label' => '🏦 Relevé bancaire', 'obligatoire' => false],
    'titre_propriete'     => ['label' => '🏘️ Titre de propriété', 'obligatoire' => false],
    'autre'               => ['label' => '📎 Autre document', 'obligatoire' => false],
];

/* ============================================================
   TRAITEMENT UPLOAD
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) throw new RuntimeException("Jeton invalide.");
        
        $type_doc = $_POST['type_document'] ?? '';
        if (!isset($types_doc[$type_doc])) throw new RuntimeException("Type de document invalide.");
        
        $numero_doc = cleanString($_POST['numero_document'] ?? '', 50);
        $date_emission = $_POST['date_emission'] ?? null;
        $date_expiration = $_POST['date_expiration'] ?? null;
        
        if (empty($_FILES['fichier']['name'])) {
            throw new RuntimeException("Aucun fichier sélectionné.");
        }
        
        /* Upload sécurisé */
        $upload = uploadDocumentSecurise($_FILES['fichier'], 'doc_' . $type_doc . '_');
        
        /* Enregistrer en base */
        $pdo->prepare("
            INSERT INTO Client_Documents 
                (id_client, type_document, numero_document, date_emission, date_expiration,
                 fichier_path, fichier_hash, mime_type, taille_octets, verifie)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, FALSE)
        ")->execute([
            $id, $type_doc, $numero_doc ?: null,
            $date_emission ?: null, $date_expiration ?: null,
            $upload['chemin'], $upload['hash'], $upload['mime'], $upload['taille']
        ]);
        
        logAction($pdo, 'client', $id, 'DOCUMENT_UPLOAD', "Type: $type_doc | Fichier: {$upload['nom_fichier']}");
        
        $_SESSION['flash_success'] = "✅ Document <strong>{$types_doc[$type_doc]['label']}</strong> téléversé avec succès. Il sera vérifié par nos services.";
        header('Location: ' . url('client/documents.php'));
        exit;
        
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

/* ============================================================
   SUPPRESSION DOCUMENT
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'supprimer') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) throw new RuntimeException("Jeton invalide.");
        
        $id_doc = (int)($_POST['id_document'] ?? 0);
        
        $stmt = $pdo->prepare("SELECT * FROM Client_Documents WHERE id_document = ? AND id_client = ?");
        $stmt->execute([$id_doc, $id]);
        $doc = $stmt->fetch();
        
        if (!$doc) throw new RuntimeException("Document introuvable.");
        
        /* Supprimer le fichier physique */
        $chemin = __DIR__ . '/../' . $doc['fichier_path'];
        if (file_exists($chemin)) @unlink($chemin);
        
        /* Supprimer en base */
        $pdo->prepare("DELETE FROM Client_Documents WHERE id_document = ?")->execute([$id_doc]);
        
        logAction($pdo, 'client', $id, 'DOCUMENT_DELETE', "ID: $id_doc | Type: {$doc['type_document']}");
        
        $_SESSION['flash_success'] = "✅ Document supprimé.";
        header('Location: ' . url('client/documents.php'));
        exit;
        
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

/* ============================================================
   CHARGER LES DOCUMENTS DU CLIENT
   ============================================================ */
$stmt = $pdo->prepare("SELECT * FROM Client_Documents WHERE id_client = ? ORDER BY created_at DESC");
$stmt->execute([$id]);
$documents = $stmt->fetchAll();

/* Regrouper par type */
$docs_par_type = [];
foreach ($documents as $doc) {
    $docs_par_type[$doc['type_document']][] = $doc;
}

/* Stats */
$stats_docs = [
    'total' => count($documents),
    'verifies' => count(array_filter($documents, fn($d) => (bool)$d['verifie'])),
    'en_attente' => count(array_filter($documents, fn($d) => !$d['verifie'])),
];
$stats_docs['obligatoires_ok'] = 0;
$stats_docs['obligatoires_total'] = 0;
foreach ($types_doc as $key => $info) {
    if ($info['obligatoire']) {
        $stats_docs['obligatoires_total']++;
        if (!empty($docs_par_type[$key])) {
            $stats_docs['obligatoires_ok']++;
        }
    }
}
$stats_docs['progression'] = $stats_docs['obligatoires_total'] > 0
    ? ($stats_docs['obligatoires_ok'] / $stats_docs['obligatoires_total']) * 100
    : 100;

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mes documents — ATF</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<style>
:root {
  --nav-height: 76px;
  --nav-safe-area: env(safe-area-inset-bottom, 0px);
}
body.app-mode { padding-bottom: calc(var(--nav-height) + var(--nav-safe-area) + 20px); }

.doc-hero {
  padding: 26px 28px;
  border-radius: 20px;
  background: linear-gradient(135deg, rgba(139,92,246,.15), rgba(76,29,149,.05)),
              radial-gradient(circle at 90% 20%, rgba(167,139,250,.18), transparent 60%);
  border: 1px solid rgba(139,92,246,.35);
  margin-bottom: 24px;
  position: relative;
  overflow: hidden;
}
.doc-hero::before {
  content: "";
  position: absolute;
  top: -40%; right: -5%;
  width: 260px; height: 260px;
  border-radius: 50%;
  background: radial-gradient(circle, rgba(139,92,246,.25), transparent 70%);
  animation: float 7s ease-in-out infinite;
}
.doc-hero h1 { color: #C4B5FD; font-size: 1.5rem; margin-bottom: 8px; position: relative; z-index: 1; }
.doc-hero p { color: var(--muted); font-size: .92rem; position: relative; z-index: 1; }

/* Progression */
.progress-doc {
  margin-top: 20px;
  position: relative;
  z-index: 1;
}
.progress-doc .lbl-row {
  display: flex;
  justify-content: space-between;
  font-size: .82rem;
  color: var(--muted);
  margin-bottom: 8px;
  flex-wrap: wrap;
  gap: 8px;
}
.progress-doc .lbl-row strong { color: #C4B5FD; }
.progress-doc-bar {
  height: 10px;
  background: rgba(0,0,0,.4);
  border-radius: 999px;
  overflow: hidden;
}
.progress-doc-bar > div {
  height: 100%;
  background: linear-gradient(90deg, #8B5CF6, #A78BFA);
  border-radius: 999px;
  transition: width .6s;
}

/* Stats grid */
.stats-doc {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
  gap: 12px;
  margin-bottom: 24px;
}
.stat-doc {
  padding: 14px 16px;
  border-radius: 12px;
  background: linear-gradient(145deg, var(--card), var(--card-2));
  border: 1px solid var(--border);
  text-align: center;
}
.stat-doc .lbl {
  font-size: .68rem;
  color: var(--muted);
  text-transform: uppercase;
  font-weight: 700;
  letter-spacing: 1px;
}
.stat-doc .val {
  font-size: 1.4rem;
  font-weight: 800;
  color: var(--gold);
  margin-top: 6px;
}
.stat-doc .val.green { color: #6EE7B7; }
.stat-doc .val.blue { color: #93B4FF; }
.stat-doc .val.red { color: #FCA5A5; }

/* Upload form */
.upload-card {
  padding: 24px 26px;
  border-radius: 20px;
  background: linear-gradient(145deg, var(--card), var(--card-2));
  border: 1px solid var(--border);
  margin-bottom: 24px;
}
.upload-card h3 {
  font-size: 1.05rem;
  color: var(--gold);
  margin-bottom: 18px;
  display: flex;
  align-items: center;
  gap: 10px;
}

.form-group { margin-bottom: 16px; }
.form-group label {
  display: block;
  font-size: .82rem;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: .5px;
  font-weight: 700;
  margin-bottom: 8px;
}
.form-group select,
.form-group input[type="text"],
.form-group input[type="date"] {
  width: 100%;
  padding: 14px 16px;
  background: var(--bg-1);
  border: 1px solid var(--border);
  border-radius: 12px;
  color: var(--text);
  font-size: .95rem;
  font-family: inherit;
}
.form-group select:focus,
.form-group input:focus {
  outline: none;
  border-color: var(--gold);
}

/* File drop */
.file-drop {
  padding: 32px 20px;
  border-radius: 16px;
  background: rgba(139,92,246,.04);
  border: 2px dashed rgba(139,92,246,.4);
  text-align: center;
  cursor: pointer;
  transition: all .25s;
  position: relative;
}
.file-drop:hover {
  border-color: var(--gold);
  background: rgba(245,184,0,.05);
}
.file-drop.has-file {
  border-color: #10B981;
  background: rgba(16,185,129,.05);
}
.file-drop input[type="file"] {
  position: absolute;
  inset: 0;
  opacity: 0;
  cursor: pointer;
}
.file-drop .ico {
  font-size: 2.5rem;
  margin-bottom: 12px;
  opacity: .7;
}
.file-drop .lbl {
  color: var(--text);
  font-weight: 700;
  font-size: .95rem;
  margin-bottom: 6px;
}
.file-drop .hint {
  color: var(--muted);
  font-size: .78rem;
}
.file-drop .filename {
  margin-top: 12px;
  padding: 8px 12px;
  border-radius: 8px;
  background: rgba(16,185,129,.15);
  color: #6EE7B7;
  font-weight: 700;
  font-size: .85rem;
  display: inline-block;
  word-break: break-all;
}

.btn-primary-full {
  width: 100%;
  padding: 16px;
  border-radius: 999px;
  background: linear-gradient(135deg, #8B5CF6, #7C3AED);
  color: #fff;
  border: none;
  font-weight: 800;
  font-size: 1rem;
  cursor: pointer;
  font-family: inherit;
  box-shadow: 0 6px 20px rgba(139,92,246,.4);
  transition: all .25s;
  margin-top: 8px;
}
.btn-primary-full:hover {
  transform: translateY(-2px);
  box-shadow: 0 10px 28px rgba(139,92,246,.55);
}
.btn-primary-full:disabled {
  opacity: .5;
  cursor: not-allowed;
  transform: none;
}

/* Document cards */
.docs-section h2 {
  font-size: 1.15rem;
  color: var(--gold);
  margin-bottom: 16px;
  display: flex;
  align-items: center;
  gap: 10px;
}

.doc-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 14px;
  margin-bottom: 24px;
}
@media (max-width: 600px) {
  .doc-grid { grid-template-columns: 1fr; }
}

.doc-card {
  padding: 16px;
  border-radius: 14px;
  background: linear-gradient(145deg, var(--card), var(--card-2));
  border: 1px solid var(--border);
  display: flex;
  gap: 14px;
  align-items: flex-start;
  transition: all .25s;
}
.doc-card:hover {
  transform: translateY(-2px);
  border-color: var(--gold);
}
.doc-card.missing {
  border-color: rgba(245,184,0,.4);
  background: linear-gradient(145deg, rgba(245,184,0,.05), rgba(245,184,0,.02));
}
.doc-card.verified {
  border-color: rgba(16,185,129,.4);
}
.doc-card.pending {
  border-color: rgba(245,184,0,.4);
}

.doc-icon {
  width: 52px;
  height: 52px;
  border-radius: 12px;
  background: rgba(139,92,246,.15);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.6rem;
  flex-shrink: 0;
}

.doc-info { flex: 1; min-width: 0; }
.doc-info .type {
  font-weight: 800;
  color: var(--text);
  font-size: .92rem;
  margin-bottom: 4px;
  word-break: break-word;
}
.doc-info .meta {
  color: var(--muted);
  font-size: .78rem;
  line-height: 1.5;
  word-break: break-word;
}
.doc-info .badge-status {
  display: inline-block;
  margin-top: 8px;
  padding: 3px 10px;
  border-radius: 999px;
  font-size: .7rem;
  font-weight: 800;
  text-transform: uppercase;
}
.badge-status.verified { background: rgba(16,185,129,.15); color: #6EE7B7; }
.badge-status.pending { background: rgba(245,184,0,.15); color: #FCD34D; }
.badge-status.missing { background: rgba(107,114,128,.15); color: #9CA3AF; }

.doc-actions {
  display: flex;
  gap: 6px;
  margin-top: 10px;
  flex-wrap: wrap;
}
.doc-actions a,
.doc-actions button {
  padding: 6px 12px;
  border-radius: 8px;
  font-size: .75rem;
  font-weight: 700;
  text-decoration: none;
  border: none;
  cursor: pointer;
  font-family: inherit;
  transition: all .2s;
}
.doc-actions .view {
  background: rgba(59,130,246,.15);
  color: #93B4FF;
  border: 1px solid rgba(59,130,246,.3);
}
.doc-actions .delete {
  background: rgba(239,68,68,.15);
  color: #FCA5A5;
  border: 1px solid rgba(239,68,68,.3);
}
.doc-actions a:hover,
.doc-actions button:hover {
  transform: translateY(-1px);
}

.empty-state {
  text-align: center;
  padding: 40px 20px;
  color: var(--muted);
}
.empty-state .ico { font-size: 3rem; opacity: .5; margin-bottom: 12px; }
</style>
</head>
<body class="dark-auth app-mode">

<?php require __DIR__ . '/../includes/header.php'; ?>

<main class="dash">

  <div class="welcome-head">
    <div>
      <h2>📎 Mes documents</h2>
      <p class="sub">Pièces justificatives pour vos demandes de prêt</p>
    </div>
    <a class="btn-ghost" href="<?= $base ?>/client/mes_prets.php">← Mes prêts</a>
  </div>

  <?php if (!empty($_SESSION['flash_success'])): ?>
    <div class="alert success"><?= $_SESSION['flash_success'] ?></div>
    <?php unset($_SESSION['flash_success']); ?>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="alert error"><ul><?php foreach ($errors as $e) echo "<li>" . htmlspecialchars($e) . "</li>"; ?></ul></div>
  <?php endif; ?>

  <!-- Hero -->
  <div class="doc-hero">
    <h1>📋 Complétez votre dossier KYC</h1>
    <p>Pour faciliter vos demandes de prêt, téléversez vos pièces justificatives. Elles seront vérifiées par nos services.</p>

    <div class="progress-doc">
      <div class="lbl-row">
        <span>Documents obligatoires fournis</span>
        <span><strong><?= (int)$stats_docs['obligatoires_ok'] ?>/<?= (int)$stats_docs['obligatoires_total'] ?></strong></span>
      </div>
      <div class="progress-doc-bar">
        <div style="width:<?= number_format($stats_docs['progression'], 1) ?>%"></div>
      </div>
    </div>
  </div>

  <!-- Stats -->
  <div class="stats-doc">
    <div class="stat-doc">
      <div class="lbl">📎 Total</div>
      <div class="val"><?= (int)$stats_docs['total'] ?></div>
    </div>
    <div class="stat-doc">
      <div class="lbl">✅ Vérifiés</div>
      <div class="val green"><?= (int)$stats_docs['verifies'] ?></div>
    </div>
    <div class="stat-doc">
      <div class="lbl">⏳ En attente</div>
      <div class="val blue"><?= (int)$stats_docs['en_attente'] ?></div>
    </div>
    <div class="stat-doc">
      <div class="lbl">❗ Manquants</div>
      <div class="val red"><?= max(0, (int)$stats_docs['obligatoires_total'] - (int)$stats_docs['obligatoires_ok']) ?></div>
    </div>
  </div>

  <!-- Upload form -->
  <section class="upload-card">
    <h3>📤 Ajouter un document</h3>

    <form method="POST" enctype="multipart/form-data" novalidate id="formUpload">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="upload">

      <div class="form-group">
        <label>📋 Type de document *</label>
        <select name="type_document" required>
          <option value="">— Sélectionnez —</option>
          <?php foreach ($types_doc as $key => $info): ?>
            <option value="<?= htmlspecialchars($key) ?>">
              <?= htmlspecialchars($info['label']) ?><?= $info['obligatoire'] ? ' *' : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label>🔢 Numéro du document (optionnel)</label>
        <input type="text" name="numero_document" maxlength="50" placeholder="Ex: 123456789">
      </div>

      <div class="form-group" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div>
          <label>📅 Date d'émission</label>
          <input type="date" name="date_emission">
        </div>
        <div>
          <label>📅 Date d'expiration</label>
          <input type="date" name="date_expiration">
        </div>
      </div>

      <div class="form-group">
        <label>📎 Fichier *</label>
        <div class="file-drop" id="fileDrop">
          <input type="file" name="fichier" id="fileInput" required accept="image/jpeg,image/png,image/webp,application/pdf">
          <div class="ico">📤</div>
          <div class="lbl">Cliquez ou glissez votre fichier ici</div>
          <div class="hint">JPG, PNG, WEBP ou PDF · Maximum 5 Mo</div>
          <div class="filename" id="fileName" style="display:none"></div>
        </div>
      </div>

      <button class="btn-primary-full" type="submit" id="btnUpload">
        📤 Téléverser le document
      </button>
    </form>
  </section>

  <!-- Liste des documents -->
  <section class="docs-section">
    <h2>📁 Mes documents (<?= count($documents) ?>)</h2>

    <?php if (empty($documents) && $stats_docs['obligatoires_ok'] === 0): ?>
      <div class="empty-state">
        <div class="ico">📂</div>
        <h3>Aucun document</h3>
        <p>Téléversez vos premiers documents ci-dessus.</p>
      </div>
    <?php else: ?>

      <!-- Documents manquants obligatoires -->
      <?php foreach ($types_doc as $key => $info):
        if ($info['obligatoire'] && empty($docs_par_type[$key])):
      ?>
        <div class="doc-grid" style="margin-bottom:12px">
          <div class="doc-card missing">
            <div class="doc-icon">❓</div>
            <div class="doc-info">
              <div class="type"><?= htmlspecialchars($info['label']) ?></div>
              <div class="meta">Document obligatoire manquant</div>
              <span class="badge-status missing">À FOURNIR</span>
            </div>
          </div>
        </div>
      <?php endif; endforeach; ?>

      <!-- Documents téléversés -->
      <div class="doc-grid">
        <?php foreach ($documents as $doc):
          $info = $types_doc[$doc['type_document']] ?? ['label' => $doc['type_document']];
          $taille = $doc['taille_octets'] ? round($doc['taille_octets'] / 1024, 1) . ' Ko' : '';
          $is_pdf = str_contains($doc['mime_type'] ?? '', 'pdf');
        ?>
          <div class="doc-card <?= $doc['verifie'] ? 'verified' : 'pending' ?>">
            <div class="doc-icon"><?= $is_pdf ? '📄' : '🖼️' ?></div>
            <div class="doc-info">
              <div class="type"><?= htmlspecialchars($info['label']) ?></div>
              <div class="meta">
                <?php if ($doc['numero_document']): ?>N° <?= htmlspecialchars($doc['numero_document']) ?><br><?php endif; ?>
                Téléversé le <?= date('d/m/Y', strtotime($doc['created_at'])) ?>
                <?php if ($taille): ?> · <?= $taille ?><?php endif; ?>
              </div>
              <span class="badge-status <?= $doc['verifie'] ? 'verified' : 'pending' ?>">
                <?= $doc['verifie'] ? '✅ VÉRIFIÉ' : '⏳ EN ATTENTE' ?>
              </span>
              <div class="doc-actions">
                <a class="view" href="<?= $base ?>/<?= htmlspecialchars($doc['fichier_path']) ?>" target="_blank">
                  👁 Voir
                </a>
                <form method="POST" style="display:inline" onsubmit="return confirm('Supprimer ce document ?')">
                  <?= csrfField() ?>
                  <input type="hidden" name="action" value="supprimer">
                  <input type="hidden" name="id_document" value="<?= (int)$doc['id_document'] ?>">
                  <button type="submit" class="delete">🗑 Supprimer</button>
                </form>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

    <?php endif; ?>
  </section>

</main>

<footer class="atf-footer" style="padding:20px;text-align:center;color:var(--muted)">
  © <?= date('Y') ?> AFRICAN TRUST FUND
</footer>

<script>
const fileInput = document.getElementById('fileInput');
const fileDrop = document.getElementById('fileDrop');
const fileName = document.getElementById('fileName');

fileInput.addEventListener('change', function() {
    if (this.files && this.files[0]) {
        const f = this.files[0];
        const maxSize = 5 * 1024 * 1024;
        
        if (f.size > maxSize) {
            alert('❌ Fichier trop volumineux (max 5 Mo)');
            this.value = '';
            return;
        }
        
        fileName.textContent = '✅ ' + f.name + ' (' + (f.size / 1024).toFixed(1) + ' Ko)';
        fileName.style.display = 'inline-block';
        fileDrop.classList.add('has-file');
    }
});

/* Drag & drop visuel */
['dragenter', 'dragover'].forEach(evt => {
    fileDrop.addEventListener(evt, e => {
        e.preventDefault();
        fileDrop.style.borderColor = '#F5B800';
    });
});

['dragleave', 'drop'].forEach(evt => {
    fileDrop.addEventListener(evt, e => {
        e.preventDefault();
        fileDrop.style.borderColor = '';
    });
});

/* Submit */
document.getElementById('formUpload').addEventListener('submit', function() {
    const btn = document.getElementById('btnUpload');
    btn.disabled = true;
    btn.textContent = '⏳ Téléversement en cours...';
});
</script>
<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>