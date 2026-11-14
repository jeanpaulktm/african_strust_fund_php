<?php
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/parametres.php';
requireRole('admin');

$pdo = Database::getConnection();
$admin_id = (int)$_SESSION['admin_id'];

$errors = [];
$success = '';

/* ============================================================
   TRAITEMENT DE LA MISE À JOUR
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) throw new RuntimeException("Jeton invalide.");

        $parametres = $_POST['parametres'] ?? [];

        if (empty($parametres)) {
            throw new RuntimeException("Aucun paramètre à mettre à jour.");
        }

        foreach ($parametres as $cle => $valeur) {
            /* Validations spécifiques */
            if ($cle === 'taux_interet_defaut') {
                $taux = (float)$valeur;
                if ($taux < 0 || $taux > 50) {
                    throw new RuntimeException("Le taux mensuel doit être entre 0% et 50%.");
                }
            }
            if ($cle === 'duree_blocage_interets') {
                $duree = (int)$valeur;
                if ($duree < 1 || $duree > 60) {
                    throw new RuntimeException("La durée de blocage doit être entre 1 et 60 mois.");
                }
            }
            if ($cle === 'commission_retrait') {
                $comm = (float)$valeur;
                if ($comm < 0 || $comm > 10) {
                    throw new RuntimeException("La commission doit être entre 0% et 10%.");
                }
            }
            setParametre($pdo, $cle, (string)$valeur, $admin_id);
        }

        logAction($pdo, 'admin', $admin_id, 'PARAMETRES_UPDATE',
            "Modification : " . implode(', ', array_keys($parametres)));

        $_SESSION['flash_success'] = "✅ Paramètres mis à jour avec succès !<br>
            <small>Les nouveaux taux s'appliquent aux <strong>nouveaux dépôts</strong>.</small>";

        header('Location: ' . url('admin/parametres.php'));
        exit;

    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$success = '';
if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

/* ============================================================
   CHARGER LES PARAMÈTRES
   ============================================================ */
$parametres_interets    = getParametresParCategorie($pdo, 'interets');
$parametres_commissions = getParametresParCategorie($pdo, 'commissions');
$parametres_epargne     = getParametresParCategorie($pdo, 'epargne');
$parametres_devises     = getParametresParCategorie($pdo, 'devises');
$parametres_general     = getParametresParCategorie($pdo, 'general');

$taux_actuel = getTauxInteretDefaut($pdo);
$duree_blocage = getDureeBlocageInterets($pdo);
$commission = getCommissionRetrait($pdo);

/* ============================================================
   STATISTIQUES POUR APERÇU
   ============================================================ */
$stats = [
    'nb_placements'    => (int)$pdo->query("SELECT COUNT(*) FROM Placements WHERE statut = 'actif'")->fetchColumn(),
    'capital_investi'  => (float)$pdo->query("SELECT COALESCE(SUM(montant_initial),0) FROM Placements WHERE statut = 'actif'")->fetchColumn(),
    'interets_generes' => (float)$pdo->query("SELECT COALESCE(SUM(interets_cumules),0) FROM Placements")->fetchColumn(),
    'nb_transactions'  => (int)$pdo->query("SELECT COUNT(*) FROM Transactions")->fetchColumn(),
    'volume_total'     => (float)$pdo->query("SELECT COALESCE(SUM(montant),0) FROM Transactions")->fetchColumn(),
];

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Paramètres & Rapports — ATF Admin</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<link rel="stylesheet" href="<?= $base ?>/assets/css/admin-premium.css">
<style>
/* ============================================================
   STYLES SPÉCIFIQUES
   ============================================================ */

/* Bandeau impact */
.impact-box {
  padding: 24px 28px;
  border-radius: 20px;
  background: linear-gradient(135deg, rgba(245,184,0,.12), rgba(245,184,0,.02)),
              radial-gradient(circle at 90% 20%, rgba(245,184,0,.15), transparent 60%);
  border: 1px solid rgba(245,184,0,.3);
  margin-bottom: 26px;
  position: relative;
  overflow: hidden;
}

.impact-box::before {
  content: "";
  position: absolute;
  top: -30%; right: -5%;
  width: 280px; height: 280px;
  border-radius: 50%;
  background: radial-gradient(circle, rgba(245,184,0,.2), transparent 70%);
  animation: float 7s ease-in-out infinite;
}

.impact-title {
  color: var(--gold);
  font-size: 1rem;
  font-weight: 800;
  margin-bottom: 16px;
  position: relative;
  z-index: 1;
}

.impact-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 14px;
  position: relative;
  z-index: 1;
}

.impact-item {
  padding: 16px 18px;
  border-radius: 14px;
  background: rgba(11,14,20,.5);
  border: 1px solid rgba(245,184,0,.2);
  text-align: center;
}

.impact-item .lbl {
  font-size: .68rem;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 1px;
  font-weight: 700;
}

.impact-item .val {
  font-size: 1.3rem;
  font-weight: 800;
  color: var(--gold);
  margin-top: 6px;
}

/* Onglets */
.tabs-header {
  display: flex;
  gap: 8px;
  margin-bottom: 24px;
  padding: 6px;
  background: var(--bg-1);
  border-radius: 999px;
  border: 1px solid var(--border);
  overflow-x: auto;
  scrollbar-width: none;
}
.tabs-header::-webkit-scrollbar { display: none; }

.tab-btn {
  padding: 12px 24px;
  border-radius: 999px;
  font-size: .88rem;
  font-weight: 700;
  color: var(--muted);
  cursor: pointer;
  white-space: nowrap;
  transition: .3s;
  background: transparent;
  border: none;
  font-family: inherit;
}

.tab-btn:hover { color: var(--gold); background: var(--gold-soft); }
.tab-btn.active {
  background: linear-gradient(135deg, var(--gold), var(--gold-2));
  color: #0B0E14;
  box-shadow: 0 6px 20px rgba(245,184,0,.35);
}

.tab-content { display: none; animation: fadeInUp .4s; }
.tab-content.active { display: block; }

/* Section paramètre */
.param-section {
  background: linear-gradient(145deg, var(--card), var(--card-2));
  border: 1px solid var(--border);
  border-radius: 20px;
  padding: 26px;
  margin-bottom: 22px;
  position: relative;
  overflow: hidden;
}

.param-section::before {
  content: "";
  position: absolute;
  top: 0; left: 0;
  width: 4px; height: 100%;
}

.param-section.interets::before { background: linear-gradient(180deg, #10B981, transparent); }
.param-section.commissions::before { background: linear-gradient(180deg, #F5B800, transparent); }
.param-section.epargne::before { background: linear-gradient(180deg, #3B82F6, transparent); }
.param-section.devises::before { background: linear-gradient(180deg, #8B5CF6, transparent); }
.param-section.general::before { background: linear-gradient(180deg, #6B7280, transparent); }

.param-header {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 20px;
  padding-bottom: 14px;
  border-bottom: 1px solid var(--border);
}

.param-header .ico {
  width: 42px;
  height: 42px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.3rem;
  background: var(--gold-soft);
  color: var(--gold);
}

.param-section.interets .param-header .ico { background: rgba(16,185,129,.15); color: #6EE7B7; }
.param-section.epargne .param-header .ico { background: rgba(59,130,246,.15); color: #93B4FF; }
.param-section.devises .param-header .ico { background: rgba(139,92,246,.15); color: #A78BFA; }

.param-header h3 { font-size: 1.05rem; margin: 0; }
.param-header .sub { font-size: .82rem; color: var(--muted); margin-top: 2px; }

.param-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
  gap: 18px;
}

.param-field {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.param-field label {
  font-size: .82rem;
  color: var(--muted);
  font-weight: 600;
}

.param-field input,
.param-field select {
  padding: 12px 14px;
  background: var(--bg-1);
  border: 1px solid var(--border);
  border-radius: 10px;
  color: var(--text);
  font-size: .95rem;
  font-family: inherit;
  font-weight: 600;
}

.param-field input:focus,
.param-field select:focus {
  outline: none;
  border-color: var(--gold);
  box-shadow: 0 0 0 3px rgba(245,184,0,.15);
}

.param-field .unite {
  position: relative;
}

.param-field .unite input { padding-right: 60px; }

.param-field .unite::after {
  content: attr(data-unite);
  position: absolute;
  right: 14px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--gold);
  font-weight: 700;
  font-size: .9rem;
  pointer-events: none;
}

.param-field .hint {
  font-size: .72rem;
  color: var(--muted);
  font-style: italic;
}

/* Rapports */
.reports-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
  gap: 20px;
  margin-bottom: 24px;
}

.report-card {
  background: linear-gradient(145deg, var(--card), var(--card-2));
  border: 1px solid var(--border);
  border-radius: 20px;
  padding: 26px;
  position: relative;
  overflow: hidden;
  transition: all .3s;
  text-decoration: none;
  color: var(--text);
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.report-card:hover {
  transform: translateY(-4px);
  border-color: var(--gold);
  box-shadow: 0 20px 50px rgba(245,184,0,.2);
}

.report-card::before {
  content: "";
  position: absolute;
  top: 0; left: 0;
  width: 4px; height: 100%;
}

.report-card.produits::before { background: linear-gradient(180deg, #F5B800, transparent); }
.report-card.journalier::before { background: linear-gradient(180deg, #3B82F6, transparent); }
.report-card.mensuel::before { background: linear-gradient(180deg, #8B5CF6, transparent); }
.report-card.annuel::before { background: linear-gradient(180deg, #10B981, transparent); }

.report-icon {
  width: 60px;
  height: 60px;
  border-radius: 16px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.8rem;
  background: var(--gold-soft);
  color: var(--gold);
}

.report-card.produits .report-icon { background: rgba(245,184,0,.15); color: #F5B800; }
.report-card.journalier .report-icon { background: rgba(59,130,246,.15); color: #93B4FF; }
.report-card.mensuel .report-icon { background: rgba(139,92,246,.15); color: #A78BFA; }
.report-card.annuel .report-icon { background: rgba(16,185,129,.15); color: #6EE7B7; }

.report-card h4 {
  font-size: 1.1rem;
  font-weight: 800;
  color: #fff;
  margin: 0;
}

.report-card p {
  color: var(--muted);
  font-size: .85rem;
  line-height: 1.6;
  flex: 1;
}

.report-actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
}

.report-actions .btn-action {
  padding: 10px 16px;
  border-radius: 999px;
  font-weight: 700;
  font-size: .82rem;
  border: none;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  text-decoration: none;
  transition: all .25s;
  font-family: inherit;
}

.report-actions .btn-gold {
  background: linear-gradient(135deg, #F5B800, #D4AF37);
  color: #0B0E14;
}

.report-actions .btn-gold:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 20px rgba(245,184,0,.4);
}

.report-actions .btn-ghost {
  background: transparent;
  color: var(--text);
  border: 1px solid var(--border);
}

.report-actions .btn-ghost:hover {
  border-color: var(--gold);
  color: var(--gold);
}

/* Save bar */
.save-bar {
  position: sticky;
  bottom: 0;
  padding: 20px;
  background: linear-gradient(180deg, transparent, rgba(11,14,20,.95) 30%);
  margin-top: 20px;
  z-index: 10;
}

@media (max-width: 700px) {
  .reports-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body class="dark-auth">

<?php require __DIR__ . '/../includes/header.php'; ?>

<div class="admin-layout">
  <aside class="admin-sidebar animate-left">
    <h3>📊 Navigation</h3>
    <a href="<?= $base ?>/admin/dashboard.php"><span class="ico">📊</span> Tableau de bord</a>
    <a href="<?= $base ?>/admin/clients.php"><span class="ico">👥</span> Clients</a>
    <a href="<?= $base ?>/admin/caissiers.php"><span class="ico">💼</span> Caissiers</a>
    <a href="<?= $base ?>/admin/produits.php"><span class="ico">📈</span> Produits</a>
    <a href="<?= $base ?>/admin/placements.php"><span class="ico">💰</span> Placements</a>
    <a href="<?= $base ?>/admin/commissions.php"><span class="ico">💵</span> Commissions</a>
    <a href="<?= $base ?>/admin/parametres.php" class="active"><span class="ico">⚙️</span> Paramètres</a>
    <a href="<?= $base ?>/admin/logs.php"><span class="ico">📜</span> Journal</a>
    <a href="<?= $base ?>/auth/logout.php"><span class="ico">🚪</span> Déconnexion</a>
  </aside>

  <main class="admin-main">

    <div class="welcome-head">
      <div>
        <h2>⚙️ Paramètres & Rapports</h2>
        <p class="sub">Configuration du système et génération des rapports</p>
      </div>
    </div>

    <?php if ($success): ?>
      <div class="alert success"><?= $success ?></div>
    <?php endif; ?>

    <?php if ($errors): ?>
      <div class="alert error">
        <ul><?php foreach ($errors as $e) echo "<li>" . htmlspecialchars($e) . "</li>"; ?></ul>
      </div>
    <?php endif; ?>

    <!-- ============================================================
         APERÇU IMPACT
         ============================================================ -->
    <section class="impact-box">
      <div class="impact-title">📊 Impact actuel sur le système</div>
      <div class="impact-grid">
        <div class="impact-item">
          <div class="lbl">Taux mensuel</div>
          <div class="val"><?= number_format($taux_actuel, 2) ?>%</div>
        </div>
        <div class="impact-item">
          <div class="lbl">Équivalent annuel</div>
          <div class="val"><?= number_format($taux_actuel * 12, 2) ?>%</div>
        </div>
        <div class="impact-item">
          <div class="lbl">Blocage intérêts</div>
          <div class="val"><?= $duree_blocage ?> mois</div>
        </div>
        <div class="impact-item">
          <div class="lbl">Commission</div>
          <div class="val"><?= number_format($commission, 2) ?>%</div>
        </div>
        <div class="impact-item">
          <div class="lbl">Placements actifs</div>
          <div class="val"><?= $stats['nb_placements'] ?></div>
        </div>
        <div class="impact-item">
          <div class="lbl">Volume total</div>
          <div class="val"><?= number_format($stats['volume_total'], 0, ',', ' ') ?></div>
        </div>
      </div>
    </section>

    <!-- ============================================================
         ONGLETS
         ============================================================ -->
    <div class="tabs-header">
      <button class="tab-btn active" data-tab="tab-parametres">
        ⚙️ Configuration
      </button>
      <button class="tab-btn" data-tab="tab-rapports">
        📄 Rapports d'impression
      </button>
    </div>

    <!-- ============================================================
         TAB 1 : CONFIGURATION
         ============================================================ -->
    <div class="tab-content active" id="tab-parametres">

      <form method="POST" novalidate>
        <?= csrfField() ?>
        <input type="hidden" name="action" value="update">

        <!-- INTÉRÊTS -->
        <div class="param-section interets">
          <div class="param-header">
            <div class="ico">📈</div>
            <div>
              <h3>Taux d'intérêt</h3>
              <div class="sub">Configuration des taux appliqués aux nouveaux dépôts</div>
            </div>
          </div>

          <div class="param-grid">
            <?php foreach ($parametres_interets as $p): ?>
              <div class="param-field">
                <label>💰 <?= htmlspecialchars($p['description'] ?: $p['cle']) ?></label>

                <?php if ($p['type'] === 'pourcentage'): ?>
                  <div class="unite" data-unite="%">
                    <input type="number"
                           name="parametres[<?= htmlspecialchars($p['cle']) ?>]"
                           value="<?= htmlspecialchars($p['valeur']) ?>"
                           step="0.01" min="0" max="50" required>
                  </div>
                <?php elseif ($p['type'] === 'nombre'): ?>
                  <input type="number"
                         name="parametres[<?= htmlspecialchars($p['cle']) ?>]"
                         value="<?= htmlspecialchars($p['valeur']) ?>"
                         step="1" min="1" required>
                <?php else: ?>
                  <input type="text"
                         name="parametres[<?= htmlspecialchars($p['cle']) ?>]"
                         value="<?= htmlspecialchars($p['valeur']) ?>" required>
                <?php endif; ?>

                <div class="hint">Clé : <?= htmlspecialchars($p['cle']) ?></div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- COMMISSIONS -->
        <div class="param-section commissions">
          <div class="param-header">
            <div class="ico">💵</div>
            <div>
              <h3>Commissions</h3>
              <div class="sub">Frais prélevés sur les opérations</div>
            </div>
          </div>

          <div class="param-grid">
            <?php foreach ($parametres_commissions as $p): ?>
              <div class="param-field">
                <label>💰 <?= htmlspecialchars($p['description'] ?: $p['cle']) ?></label>
                <?php if ($p['type'] === 'pourcentage'): ?>
                  <div class="unite" data-unite="%">
                    <input type="number"
                           name="parametres[<?= htmlspecialchars($p['cle']) ?>]"
                           value="<?= htmlspecialchars($p['valeur']) ?>"
                           step="0.01" min="0" max="10" required>
                  </div>
                <?php else: ?>
                  <input type="text"
                         name="parametres[<?= htmlspecialchars($p['cle']) ?>]"
                         value="<?= htmlspecialchars($p['valeur']) ?>" required>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- ÉPARGNE -->
        <div class="param-section epargne">
          <div class="param-header">
            <div class="ico">💼</div>
            <div>
              <h3>Épargne</h3>
              <div class="sub">Limites et règles des dépôts</div>
            </div>
          </div>

          <div class="param-grid">
            <?php foreach ($parametres_epargne as $p): ?>
              <div class="param-field">
                <label>💰 <?= htmlspecialchars($p['description'] ?: $p['cle']) ?></label>
                <input type="number"
                       name="parametres[<?= htmlspecialchars($p['cle']) ?>]"
                       value="<?= htmlspecialchars($p['valeur']) ?>"
                       step="100" min="0" required>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- DEVISES -->
        <div class="param-section devises">
          <div class="param-header">
            <div class="ico">💱</div>
            <div>
              <h3>Devises</h3>
              <div class="sub">Taux de change et devise principale</div>
            </div>
          </div>

          <div class="param-grid">
            <?php foreach ($parametres_devises as $p): ?>
              <div class="param-field">
                <label>💱 <?= htmlspecialchars($p['description'] ?: $p['cle']) ?></label>
                <?php if ($p['cle'] === 'pays_devise_principale'): ?>
                  <select name="parametres[<?= htmlspecialchars($p['cle']) ?>]" required>
                    <option value="CDF" <?= $p['valeur'] === 'CDF' ? 'selected' : '' ?>>🇨🇩 CDF</option>
                    <option value="USD" <?= $p['valeur'] === 'USD' ? 'selected' : '' ?>>🇺🇸 USD</option>
                  </select>
                <?php else: ?>
                  <input type="number"
                         name="parametres[<?= htmlspecialchars($p['cle']) ?>]"
                         value="<?= htmlspecialchars($p['valeur']) ?>"
                         step="1" min="0" required>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- GÉNÉRAL -->
        <div class="param-section general">
          <div class="param-header">
            <div class="ico">⚙️</div>
            <div>
              <h3>Informations générales</h3>
              <div class="sub">Identité de la banque</div>
            </div>
          </div>

          <div class="param-grid">
            <?php foreach ($parametres_general as $p): ?>
              <div class="param-field">
                <label>📝 <?= htmlspecialchars($p['description'] ?: $p['cle']) ?></label>
                <input type="text"
                       name="parametres[<?= htmlspecialchars($p['cle']) ?>]"
                       value="<?= htmlspecialchars($p['valeur']) ?>"
                       maxlength="255" required>
              </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- SAVE -->
        <div class="save-bar">
          <button class="btn-gold full" type="submit" style="padding:18px;font-size:1rem">
            💾 Enregistrer tous les paramètres
          </button>
        </div>
      </form>
    </div>

    <!-- ============================================================
         TAB 2 : RAPPORTS
         ============================================================ -->
    <div class="tab-content" id="tab-rapports">

      <div class="alert info" style="margin-bottom:24px">
        📊 <strong>4 rapports professionnels</strong> sont disponibles. Chaque rapport s'ouvre dans un nouvel onglet et peut être imprimé ou enregistré en PDF.
      </div>

      <div class="reports-grid">

        <!-- RAPPORT 1 : PRODUITS INVESTIS -->
        <a class="report-card produits" href="<?= url('admin/rapport_produits.php') ?>" target="_blank">
          <div class="report-icon">📈</div>
          <h4>Rapport des produits investis</h4>
          <p>
            Liste complète des produits d'épargne avec les montants investis,
            les taux appliqués et les intérêts générés.
          </p>
          <div class="report-actions">
            <span class="btn-action btn-gold">🖨️ Générer le rapport</span>
          </div>
        </a>

        <!-- RAPPORT 2 : JOURNALIER -->
        <a class="report-card journalier" href="<?= url('admin/rapport_journalier.php') ?>" target="_blank">
          <div class="report-icon">📅</div>
          <h4>Rapport journalier</h4>
          <p>
            Toutes les opérations du jour : dépôts, retraits, virements,
            investissements et intérêts générés.
          </p>
          <div class="report-actions">
            <span class="btn-action btn-gold">🖨️ Générer le rapport</span>
          </div>
        </a>

        <!-- RAPPORT 3 : MENSUEL -->
        <a class="report-card mensuel" href="<?= url('admin/rapport_mensuel.php') ?>" target="_blank">
          <div class="report-icon">📊</div>
          <h4>Rapport mensuel</h4>
          <p>
            Consolidation mensuelle des activités : volumes, commissions,
            intérêts et statistiques clients.
          </p>
          <div class="report-actions">
            <span class="btn-action btn-gold">🖨️ Générer le rapport</span>
          </div>
        </a>

        <!-- RAPPORT 4 : ANNUEL -->
        <a class="report-card annuel" href="<?= url('admin/rapport_annuel.php') ?>" target="_blank">
          <div class="report-icon">📆</div>
          <h4>Rapport annuel</h4>
          <p>
            Bilan annuel complet : évolution des dépôts, croissance du portefeuille,
            performances et indicateurs clés.
          </p>
          <div class="report-actions">
            <span class="btn-action btn-gold">🖨️ Générer le rapport</span>
          </div>
        </a>

      </div>

      <!-- AIDE -->
      <div class="param-section general">
        <div class="param-header">
          <div class="ico">💡</div>
          <div>
            <h3>Comment utiliser les rapports ?</h3>
            <div class="sub">Guide rapide</div>
          </div>
        </div>
        <ul style="color: var(--muted); line-height: 2; padding-left: 20px; font-size: .88rem">
          <li><strong style="color:var(--gold)">Rapport des produits</strong> : pour voir la performance de chaque produit d'épargne</li>
          <li><strong style="color:var(--gold)">Rapport journalier</strong> : pour la clôture quotidienne de caisse</li>
          <li><strong style="color:var(--gold)">Rapport mensuel</strong> : pour les réunions de direction mensuelles</li>
          <li><strong style="color:var(--gold)">Rapport annuel</strong> : pour les assemblées générales et déclarations officielles</li>
          <li>Chaque rapport peut être <strong>imprimé</strong> (Ctrl+P) ou <strong>enregistré en PDF</strong></li>
        </ul>
      </div>

    </div>

  </main>
</div>

<footer class="atf-footer">© <?= date('Y') ?> AFRICAN TRUST FUND</footer>

<script>
/* ============================================================
   ONGLETS
   ============================================================ */
document.querySelectorAll('.tab-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));

    btn.classList.add('active');
    document.getElementById(btn.dataset.tab).classList.add('active');
  });
});

/* Feedback bouton */
document.querySelector('form').addEventListener('submit', function() {
  const btn = this.querySelector('button[type="submit"]');
  if (btn) {
    btn.disabled = true;
    btn.textContent = '⏳ Enregistrement...';
  }
});

console.log('%c⚙️ Page Paramètres & Rapports ATF', 'color:#F5B800;font-weight:bold;font-size:14px');
</script>

<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>