<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/prets.php';
requireRole('admin');

$pdo = Database::getConnection();
$admin_id = (int)$_SESSION['admin_id'];

$token = $_GET['token'] ?? $_POST['token'] ?? '';
if (!$token) { header('Location: ' . url('admin/entreprises.php')); exit; }

$data = consommerToken('entreprise', $token, ['keep' => true]);
if (!$data) { $_SESSION['flash_error'] = "Token invalide."; header('Location: ' . url('admin/entreprises.php')); exit; }

$id_entreprise = (int)$data['id'];

/* Charger l'entreprise */
$stmt = $pdo->prepare("
    SELECT e.*, c.numero_compte
    FROM Entreprises e
    JOIN Client c ON c.id_client = e.id_client
    WHERE e.id_entreprise = ?
");
$stmt->execute([$id_entreprise]);
$e = $stmt->fetch();

if (!$e) { $_SESSION['flash_error'] = "Entreprise introuvable."; header('Location: ' . url('admin/entreprises.php')); exit; }

$errors = [];
$success = '';

/* Traitement saisie bilan */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'saisir_bilan') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) throw new RuntimeException("Jeton invalide.");
        
        $annee_fiscale    = (int)($_POST['annee_fiscale'] ?? date('Y'));
        $chiffre_affaires = (float)str_replace([' ', ','], ['', '.'], $_POST['chiffre_affaires'] ?? '0');
        $benefice_net     = (float)str_replace([' ', ','], ['', '.'], $_POST['benefice_net'] ?? '0');
        $total_actifs     = (float)str_replace([' ', ','], ['', '.'], $_POST['total_actifs'] ?? '0');
        $actifs_courants  = (float)str_replace([' ', ','], ['', '.'], $_POST['actifs_courants'] ?? '0');
        $stocks           = (float)str_replace([' ', ','], ['', '.'], $_POST['stocks'] ?? '0');
        $creances         = (float)str_replace([' ', ','], ['', '.'], $_POST['creances_clients'] ?? '0');
        $tresorerie       = (float)str_replace([' ', ','], ['', '.'], $_POST['tresorerie'] ?? '0');
        $total_passifs    = (float)str_replace([' ', ','], ['', '.'], $_POST['total_passifs'] ?? '0');
        $passifs_courants = (float)str_replace([' ', ','], ['', '.'], $_POST['passifs_courants'] ?? '0');
        $dettes_fourn     = (float)str_replace([' ', ','], ['', '.'], $_POST['dettes_fournisseurs'] ?? '0');
        $capitaux_propres = (float)str_replace([' ', ','], ['', '.'], $_POST['capitaux_propres'] ?? '0');
        
        if ($annee_fiscale < 2000 || $annee_fiscale > 2100) {
            throw new RuntimeException("Année fiscale invalide.");
        }
        
        $pdo->prepare("
            INSERT INTO Entreprise_Bilans 
                (id_entreprise, annee_fiscale, chiffre_affaires, benefice_net,
                 total_actifs, actifs_courants, total_passifs, passifs_courants,
                 capitaux_propres, stocks, creances_clients, dettes_fournisseurs, tresorerie, saisi_par)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                chiffre_affaires = VALUES(chiffre_affaires),
                benefice_net = VALUES(benefice_net),
                total_actifs = VALUES(total_actifs),
                actifs_courants = VALUES(actifs_courants),
                total_passifs = VALUES(total_passifs),
                passifs_courants = VALUES(passifs_courants),
                capitaux_propres = VALUES(capitaux_propres),
                stocks = VALUES(stocks),
                creances_clients = VALUES(creances_clients),
                dettes_fournisseurs = VALUES(dettes_fournisseurs),
                tresorerie = VALUES(tresorerie),
                saisi_par = VALUES(saisi_par)
        ")->execute([
            $id_entreprise, $annee_fiscale, $chiffre_affaires, $benefice_net,
            $total_actifs, $actifs_courants, $total_passifs, $passifs_courants,
            $capitaux_propres, $stocks, $creances, $dettes_fourn, $tresorerie, $admin_id
        ]);
        
        /* Mettre à jour entreprise */
        $pdo->prepare("
            UPDATE Entreprises SET
                chiffre_affaires_annuel = ?, benefice_net = ?,
                total_actifs = ?, total_passifs = ?
            WHERE id_entreprise = ?
        ")->execute([$chiffre_affaires, $benefice_net, $total_actifs, $total_passifs, $id_entreprise]);
        
        logAction($pdo, 'admin', $admin_id, 'ENTREPRISE_BILAN', 
            "ID: $id_entreprise | Année: $annee_fiscale | CA: $chiffre_affaires");
        
        $_SESSION['flash_success'] = "✅ Bilan $annee_fiscale enregistré !";
        header('Location: ' . url('admin/entreprise_bilan.php?token=' . urlencode($token)));
        exit;
        
    } catch (Throwable $ex) {
        $errors[] = $ex->getMessage();
    }
}

/* Charger les bilans */
$stmt = $pdo->prepare("SELECT * FROM Entreprise_Bilans WHERE id_entreprise = ? ORDER BY annee_fiscale DESC");
$stmt->execute([$id_entreprise]);
$bilans = $stmt->fetchAll();

/* Calcul ratios pour chaque bilan */
$bilans_ratios = [];
foreach ($bilans as $b) {
    $ac = (float)$b['actifs_courants'];
    $pc = (float)$b['passifs_courants'];
    $tp = (float)$b['total_passifs'];
    $cp = (float)$b['capitaux_propres'];
    $bn = (float)$b['benefice_net'];
    $ca = (float)$b['chiffre_affaires'];
    
    $bilans_ratios[] = array_merge($b, [
        'liquidite' => $pc > 0 ? round($ac / $pc, 2) : 0,
        'endettement' => $cp > 0 ? round($tp / $cp, 2) : 0,
        'rentabilite' => $ca > 0 ? round(($bn / $ca) * 100, 2) : 0,
    ]);
}

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Bilan — <?= htmlspecialchars($e['raison_sociale']) ?> | ATF</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/admin-premium.css">
<style>
.ent-banner {
  padding: 18px 22px;
  border-radius: 14px;
  background: linear-gradient(135deg, rgba(139,92,246,.12), rgba(76,29,149,.04));
  border: 1px solid rgba(139,92,246,.3);
  margin-bottom: 20px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
}
.ent-banner h3 { color: #C4B5FD; margin: 0; font-size: 1.05rem; }
.ent-banner .sub { color: var(--muted); font-size: .82rem; margin-top: 4px; }

.bilan-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
  gap: 12px;
}
.bilan-box { padding: 12px 14px; border-radius: 10px; background: rgba(11,14,20,.5); border: 1px solid var(--border); }
.bilan-box .lbl { font-size: .7rem; color: var(--muted); text-transform: uppercase; font-weight: 700; margin-bottom: 4px; }
.bilan-box .val { font-size: 1rem; font-weight: 800; color: var(--text); }

.bilan-card {
  padding: 18px;
  border-radius: 14px;
  background: linear-gradient(145deg, var(--card), var(--card-2));
  border: 1px solid var(--border);
  margin-bottom: 14px;
}
.bilan-card:hover { border-color: rgba(245,184,0,.3); }
.bilan-card .head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 14px;
  flex-wrap: wrap;
  gap: 12px;
}
.bilan-card .year {
  font-size: 1.3rem;
  font-weight: 900;
  color: var(--gold);
}
.bilan-card .ca {
  font-size: .82rem;
  color: var(--muted);
}

.ratios-row {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
  gap: 10px;
  margin-top: 12px;
}
.ratio-pill {
  padding: 10px 12px;
  border-radius: 10px;
  text-align: center;
  border: 1px solid var(--border);
}
.ratio-pill .lbl { font-size: .65rem; color: var(--muted); text-transform: uppercase; font-weight: 700; }
.ratio-pill .val { font-size: 1.1rem; font-weight: 800; margin-top: 3px; }
.ratio-pill.ok { background: rgba(16,185,129,.08); border-color: rgba(16,185,129,.3); }
.ratio-pill.ok .val { color: #6EE7B7; }
.ratio-pill.warn { background: rgba(245,184,0,.08); border-color: rgba(245,184,0,.3); }
.ratio-pill.warn .val { color: #FCD34D; }
.ratio-pill.bad { background: rgba(239,68,68,.08); border-color: rgba(239,68,68,.3); }
.ratio-pill.bad .val { color: #FCA5A5; }
</style>
</head>
<body class="dark-auth">

<?php require __DIR__ . '/../includes/header.php'; ?>

<div class="admin-layout">
  <aside class="admin-sidebar">
    <h3>📊 Navigation</h3>
    <a href="<?= $base ?>/admin/entreprises.php" class="active"><span class="ico">🏢</span> Entreprises</a>
    <a href="<?= $base ?>/auth/logout.php"><span class="ico">🚪</span> Déconnexion</a>
  </aside>

  <main class="admin-main">

    <div class="welcome-head">
      <div>
        <h2>📊 Bilans financiers</h2>
        <p class="sub">Saisie et analyse des bilans annuels</p>
      </div>
      <a class="btn-ghost" href="<?= $base ?>/admin/entreprises.php">← Retour</a>
    </div>

    <?php if (!empty($_SESSION['flash_success'])): ?>
      <div class="alert success"><?= $_SESSION['flash_success'] ?></div>
      <?php unset($_SESSION['flash_success']); ?>
    <?php endif; ?>
    <?php if ($errors): ?>
      <div class="alert error"><ul><?php foreach ($errors as $e) echo "<li>" . htmlspecialchars($e) . "</li>"; ?></ul></div>
    <?php endif; ?>

    <div class="ent-banner">
      <div>
        <h3>🏢 <?= htmlspecialchars($e['raison_sociale']) ?></h3>
        <div class="sub"><?= htmlspecialchars($e['forme_juridique']) ?> · Compte <?= htmlspecialchars($e['numero_compte']) ?></div>
      </div>
      <div style="font-size:.82rem;color:var(--muted)">
        <?= count($bilans) ?> bilan(s) enregistré(s)
      </div>
    </div>

    <!-- Formulaire saisie -->
    <section class="card">
      <h3 style="margin-bottom:16px;color:var(--gold)">➕ Saisir / mettre à jour un bilan</h3>

      <form method="POST" novalidate>
        <?= csrfField() ?>
        <input type="hidden" name="action" value="saisir_bilan">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

        <div class="grid-3">
          <label class="field"><span>Année fiscale *</span>
            <input type="number" name="annee_fiscale" required min="2000" max="2100" value="<?= date('Y') - 1 ?>">
          </label>
          <label class="field"><span>Chiffre d'affaires *</span>
            <input type="text" name="chiffre_affaires" required placeholder="0" inputmode="decimal">
          </label>
          <label class="field"><span>Bénéfice net *</span>
            <input type="text" name="benefice_net" required placeholder="0" inputmode="decimal">
          </label>
        </div>

        <fieldset style="margin-top:14px">
          <legend>Actifs</legend>
          <div class="grid-3">
            <label class="field"><span>Total actifs *</span>
              <input type="text" name="total_actifs" required placeholder="0" inputmode="decimal">
            </label>
            <label class="field"><span>Actifs courants</span>
              <input type="text" name="actifs_courants" placeholder="0" inputmode="decimal">
            </label>
            <label class="field"><span>Stocks</span>
              <input type="text" name="stocks" placeholder="0" inputmode="decimal">
            </label>
          </div>
          <div class="grid-2">
            <label class="field"><span>Créances clients</span>
              <input type="text" name="creances_clients" placeholder="0" inputmode="decimal">
            </label>
            <label class="field"><span>Trésorerie</span>
              <input type="text" name="tresorerie" placeholder="0" inputmode="decimal">
            </label>
          </div>
        </fieldset>

        <fieldset style="margin-top:14px">
          <legend>Passifs</legend>
          <div class="grid-3">
            <label class="field"><span>Total passifs *</span>
              <input type="text" name="total_passifs" required placeholder="0" inputmode="decimal">
            </label>
            <label class="field"><span>Passifs courants</span>
              <input type="text" name="passifs_courants" placeholder="0" inputmode="decimal">
            </label>
            <label class="field"><span>Dettes fournisseurs</span>
              <input type="text" name="dettes_fournisseurs" placeholder="0" inputmode="decimal">
            </label>
          </div>
          <label class="field"><span>Capitaux propres *</span>
            <input type="text" name="capitaux_propres" required placeholder="0" inputmode="decimal">
          </label>
        </fieldset>

        <button class="btn-gold full" type="submit" style="margin-top:14px">
          💾 Enregistrer le bilan
        </button>
      </form>
    </section>

    <!-- Liste bilans -->
    <?php if (!empty($bilans_ratios)): ?>
      <h3 style="margin:24px 0 14px;color:var(--gold)">📋 Historique des bilans</h3>

      <?php foreach ($bilans_ratios as $b): ?>
        <div class="bilan-card">
          <div class="head">
            <div>
              <div class="year">📅 <?= (int)$b['annee_fiscale'] ?></div>
              <div class="ca">
                Saisi le <?= date('d/m/Y', strtotime($b['date_saisie'])) ?>
              </div>
            </div>
            <div style="text-align:right">
              <div style="font-size:.72rem;color:var(--muted);text-transform:uppercase;font-weight:700">Chiffre d'affaires</div>
              <div style="font-size:1.3rem;font-weight:900;color:var(--gold)">
                <?= number_format((float)$b['chiffre_affaires'], 0, ',', ' ') ?>
              </div>
            </div>
          </div>

          <div class="bilan-grid">
            <div class="bilan-box">
              <div class="lbl">💰 Bénéfice net</div>
              <div class="val" style="color:#6EE7B7"><?= number_format((float)$b['benefice_net'], 0, ',', ' ') ?></div>
            </div>
            <div class="bilan-box">
              <div class="lbl">📦 Total actifs</div>
              <div class="val"><?= number_format((float)$b['total_actifs'], 0, ',', ' ') ?></div>
            </div>
            <div class="bilan-box">
              <div class="lbl">💸 Total passifs</div>
              <div class="val"><?= number_format((float)$b['total_passifs'], 0, ',', ' ') ?></div>
            </div>
            <div class="bilan-box">
              <div class="lbl">🏦 Capitaux propres</div>
              <div class="val" style="color:#93B4FF"><?= number_format((float)$b['capitaux_propres'], 0, ',', ' ') ?></div>
            </div>
          </div>

          <div class="ratios-row">
            <div class="ratio-pill <?= $b['liquidite'] >= 1.5 ? 'ok' : ($b['liquidite'] >= 1 ? 'warn' : 'bad') ?>">
              <div class="lbl">💧 Liquidité</div>
              <div class="val"><?= number_format($b['liquidite'], 2) ?></div>
            </div>
            <div class="ratio-pill <?= $b['endettement'] <= 1 ? 'ok' : ($b['endettement'] <= 2 ? 'warn' : 'bad') ?>">
              <div class="lbl">⚖️ Endettement</div>
              <div class="val"><?= number_format($b['endettement'], 2) ?></div>
            </div>
            <div class="ratio-pill <?= $b['rentabilite'] >= 10 ? 'ok' : ($b['rentabilite'] >= 5 ? 'warn' : 'bad') ?>">
              <div class="lbl">📈 Rentabilité</div>
              <div class="val"><?= number_format($b['rentabilite'], 1) ?>%</div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    <?php else: ?>
      <div class="card" style="text-align:center;padding:40px">
        <div style="font-size:3rem;opacity:.5;margin-bottom:12px">📭</div>
        <p class="muted">Aucun bilan enregistré pour cette entreprise.</p>
      </div>
    <?php endif; ?>

  </main>
</div>

<footer class="atf-footer">© <?= date('Y') ?> AFRICAN TRUST FUND</footer>
<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>