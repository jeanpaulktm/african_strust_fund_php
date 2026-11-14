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
   CHARGER LE CLIENT
   ============================================================ */
$stmt = $pdo->prepare("SELECT * FROM Client WHERE id_client = ?");
$stmt->execute([$id]);
$client = $stmt->fetch();

if (!$client) refuserAcces("Compte introuvable.");

/* ============================================================
   VÉRIFIER S'IL Y A UNE DEMANDE EN ATTENTE OU ACTIVE
   ============================================================ */
$demande_en_cours = null;
try {
    $stmt = $pdo->prepare("
        SELECT p.*, pr.nom AS produit_nom, pr.taux_annuel
        FROM Prets p
        JOIN Produits_Pret pr ON pr.id_produit = p.id_produit
        WHERE p.id_client = ? 
          AND p.statut IN ('soumis','en_analyse','approuve','signe')
        ORDER BY p.date_demande DESC
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $demande_en_cours = $stmt->fetch();
} catch (Throwable $e) { /* */ }

/* ============================================================
   DERNIÈRE DEMANDE TRAITÉE (rejetée/annulée) — info pédagogique
   ============================================================ */
$derniere_rejetee = null;
try {
    $stmt = $pdo->prepare("
        SELECT p.*, pr.nom AS produit_nom
        FROM Prets p
        JOIN Produits_Pret pr ON pr.id_produit = p.id_produit
        WHERE p.id_client = ? 
          AND p.statut IN ('rejete','annule')
        ORDER BY p.date_demande DESC
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $derniere_rejetee = $stmt->fetch();
} catch (Throwable $e) { /* */ }

/* ============================================================
   INFO CLIENT (pour affichage)
   ============================================================ */
$date_inscription = new DateTime($client['created_at']);
$date_aujourdhui  = new DateTime();
$intervalle       = $date_inscription->diff($date_aujourdhui);
$jours_anciennete = $intervalle->days;
$mois_anciennete_precis = (int)floor($jours_anciennete / 30);

/* Score de crédit */
$score = calculerScoreCredit($pdo, $id);

/* ============================================================
   VÉRIFIER S'IL Y A UN PRÊT ACTIF (décaissé)
   ============================================================ */
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM Prets 
    WHERE id_client = ? AND statut IN ('decaisse','en_cours','en_retard')
");
$stmt->execute([$id]);
$a_pret_actif = ((int)$stmt->fetchColumn() > 0);

/* ============================================================
   TRAITEMENT POST — ANNULATION DEMANDE
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'annuler') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) {
            throw new RuntimeException("Jeton de sécurité invalide.");
        }

        $id_pret = (int)($_POST['id_pret'] ?? 0);
        $motif = cleanString($_POST['motif_annulation'] ?? '', 255);

        if ($id_pret <= 0) throw new RuntimeException("Demande invalide.");
        if ($motif === '') throw new RuntimeException("Veuillez indiquer un motif d'annulation.");

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM Prets WHERE id_pret = ? AND id_client = ? FOR UPDATE");
        $stmt->execute([$id_pret, $id]);
        $pret = $stmt->fetch();

        if (!$pret) throw new RuntimeException("Demande introuvable.");

        if (!in_array($pret['statut'], ['soumis','en_analyse'], true)) {
            throw new RuntimeException(
                "Cette demande ne peut plus être annulée (statut : " . 
                str_replace('_', ' ', $pret['statut']) . ")."
            );
        }

        $pdo->prepare("
            UPDATE Prets SET statut = 'annule', motif_rejet = ? WHERE id_pret = ?
        ")->execute(["Annulée par le client : $motif", $id_pret]);

        logPret($pdo, $id_pret, 'DEMANDE_ANNULEE', $pret['statut'], 'annule', 'client', $id, "Motif : $motif");

        $pdo->commit();

        $_SESSION['flash_success'] = "✅ <strong>Demande annulée</strong><br>Référence : {$pret['reference']}";

        header('Location: ' . url('client/demande_pret.php'));
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = $e->getMessage();
    }
}

/* ============================================================
   TRAITEMENT POST — NOUVELLE DEMANDE
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'soumettre') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) {
            throw new RuntimeException("Jeton de sécurité invalide.");
        }

        /* Vérifier statut client */
        if ($client['statut'] !== 'actif') {
            throw new RuntimeException("Votre compte doit être actif pour demander un prêt.");
        }

        /* Vérifier pas de demande en cours */
        if ($demande_en_cours) {
            throw new RuntimeException(
                "Vous avez déjà une demande en cours (référence : {$demande_en_cours['reference']})."
            );
        }

        /* Vérifier pas de prêt actif */
        if ($a_pret_actif) {
            throw new RuntimeException("Vous avez déjà un prêt actif en cours.");
        }

        $id_produit = (int)($_POST['id_produit'] ?? 0);
        $montant = (float)str_replace([' ', ','], ['', '.'], $_POST['montant'] ?? '0');
        $duree = (int)($_POST['duree_mois'] ?? 0);
        $motif = cleanString($_POST['motif_pret'] ?? '', 500);
        $destination = cleanString($_POST['destination_fonds'] ?? '', 200);
        $devise_choisie = in_array($_POST['devise'] ?? '', ['CDF','USD'], true) ? $_POST['devise'] : 'CDF';

        /* Charger produit */
        $stmt = $pdo->prepare("SELECT * FROM Produits_Pret WHERE id_produit = ? AND actif = 1");
        $stmt->execute([$id_produit]);
        $produit = $stmt->fetch();

        if (!$produit) throw new RuntimeException("Veuillez sélectionner un produit valide.");

        if ($montant < (float)$produit['montant_min'] || $montant > (float)$produit['montant_max']) {
            throw new RuntimeException("Montant hors limites pour ce produit.");
        }

        if ($duree < (int)$produit['duree_min_mois'] || $duree > (int)$produit['duree_max_mois']) {
            throw new RuntimeException("Durée hors limites pour ce produit.");
        }

        if ($motif === '') throw new RuntimeException("Le motif du prêt est obligatoire.");

        /* Créer la demande */
        $pdo->beginTransaction();

        $reference = genererReferencePret($pdo);

        $pdo->prepare("
            INSERT INTO Prets 
                (reference, id_client, id_produit, type_client, montant_demande, devise, duree_mois,
                 motif_pret, destination_fonds, statut, score_credit, niveau_score, date_demande)
            VALUES (?, ?, ?, 'physique', ?, ?, ?, ?, ?, 'soumis', ?, ?, NOW())
        ")->execute([
            $reference, $id, $id_produit, $montant, $produit['devise'], $duree,
            $motif, $destination ?: null, $score['score'], $score['niveau']
        ]);

        $id_pret = (int)$pdo->lastInsertId();

        logPret(
            $pdo, $id_pret, 'DEMANDE_SOUMISE', null, 'soumis', 'client', $id,
            "Montant : " . formaterMontant($montant, $produit['devise'])
        );

        $pdo->commit();

        $_SESSION['flash_success'] = "✅ <strong>Demande de prêt soumise avec succès !</strong><br>
            Référence : <strong>$reference</strong><br>
            Montant : " . formaterMontant($montant, $produit['devise']) . "<br><br>
            <small>⏳ Votre demande est en attente d'approbation par un agent de crédit.</small>";

        header('Location: ' . url('client/demande_pret.php'));
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = $e->getMessage();
    }
}

/* FLASH */
if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

/* ============================================================
   CHARGER PRODUITS
   ============================================================ */
$produits = [];
try {
    $stmt = $pdo->prepare("
        SELECT * FROM Produits_Pret 
        WHERE actif = 1 
          AND type_client IN ('physique','tous')
        ORDER BY devise, montant_min ASC
    ");
    $stmt->execute();
    $produits = $stmt->fetchAll();
} catch (Throwable $e) { /* */ }

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0,user-scalable=no">
<meta name="theme-color" content="#0B0E14">
<title>Demande de prêt — ATF</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<style>
:root {
  --nav-height: 76px;
  --nav-safe-area: env(safe-area-inset-bottom, 0px);
}
body.app-mode {
  padding-bottom: calc(var(--nav-height) + var(--nav-safe-area) + 20px);
}

/* HERO */
.hero-demande {
  padding: 28px 30px;
  border-radius: 22px;
  background: linear-gradient(135deg, rgba(139,92,246,.15), rgba(76,29,149,.05)),
              radial-gradient(circle at 80% 20%, rgba(167,139,250,.18), transparent 60%);
  border: 1px solid rgba(139,92,246,.35);
  margin-bottom: 24px;
  position: relative;
  overflow: hidden;
}
.hero-demande::before {
  content: "";
  position: absolute;
  top: -40%; right: -10%;
  width: 320px; height: 320px;
  border-radius: 50%;
  background: radial-gradient(circle, rgba(139,92,246,.22), transparent 70%);
  animation: float 8s ease-in-out infinite;
}
.hero-demande h1 {
  color: #C4B5FD;
  font-size: 1.5rem;
  margin-bottom: 8px;
  position: relative;
  z-index: 1;
}
.hero-demande p { color: var(--muted); font-size: .92rem; position: relative; z-index: 1; }

/* DEMANDE EN COURS */
.demande-en-cours {
  padding: 26px 28px;
  border-radius: 20px;
  background: linear-gradient(135deg, rgba(245,184,0,.12), rgba(245,184,0,.03));
  border: 1px solid rgba(245,184,0,.4);
  margin-bottom: 24px;
  position: relative;
  overflow: hidden;
}
.demande-en-cours::before {
  content: "";
  position: absolute;
  top: -30%; right: -5%;
  width: 240px; height: 240px;
  border-radius: 50%;
  background: radial-gradient(circle, rgba(245,184,0,.15), transparent 70%);
  animation: float 7s ease-in-out infinite;
}
.demande-en-cours .head {
  display: flex;
  align-items: center;
  gap: 16px;
  margin-bottom: 18px;
  position: relative;
  z-index: 1;
  flex-wrap: wrap;
}
.demande-en-cours .icon {
  width: 56px; height: 56px;
  border-radius: 16px;
  background: rgba(245,184,0,.2);
  color: #FCD34D;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.8rem;
  flex-shrink: 0;
}
.demande-en-cours .text h3 { color: #FCD34D; font-size: 1.15rem; margin-bottom: 4px; }
.demande-en-cours .text p { color: var(--muted); font-size: .88rem; }

.demande-info-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 12px;
  margin-top: 18px;
  position: relative;
  z-index: 1;
}
.demande-info-box {
  padding: 14px 16px;
  border-radius: 12px;
  background: rgba(11,14,20,.55);
  border: 1px solid rgba(245,184,0,.2);
}
.demande-info-box .lbl {
  font-size: .68rem;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 1px;
  font-weight: 700;
  margin-bottom: 4px;
}
.demande-info-box .val { font-size: 1rem; font-weight: 800; color: #fff; }
.demande-info-box .val.gold { color: var(--gold); }
.demande-info-box .val.blue { color: #93B4FF; }
.demande-info-box .val.green { color: #6EE7B7; }

/* TIMELINE */
.timeline {
  margin-top: 20px;
  padding: 18px;
  border-radius: 14px;
  background: rgba(11,14,20,.5);
  border: 1px solid var(--border);
  position: relative;
  z-index: 1;
}
.timeline-steps {
  display: flex;
  justify-content: space-between;
  position: relative;
  gap: 8px;
}
.timeline-steps::before {
  content: "";
  position: absolute;
  top: 16px; left: 24px; right: 24px;
  height: 2px;
  background: rgba(255,255,255,.1);
}
.timeline-step {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
  position: relative;
  z-index: 1;
  flex: 1;
  min-width: 60px;
}
.timeline-step .dot {
  width: 32px; height: 32px;
  border-radius: 50%;
  background: rgba(11,14,20,.9);
  border: 2px solid var(--border);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: .85rem;
  font-weight: 800;
  color: var(--muted);
}
.timeline-step.done .dot {
  background: rgba(16,185,129,.15);
  border-color: #10B981;
  color: #6EE7B7;
}
.timeline-step.active .dot {
  background: rgba(245,184,0,.2);
  border-color: #F5B800;
  color: #FCD34D;
  box-shadow: 0 0 0 4px rgba(245,184,0,.15);
  animation: pulse-active 2s infinite;
}
.timeline-step .lbl {
  font-size: .7rem;
  color: var(--muted);
  text-align: center;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .5px;
}
.timeline-step.active .lbl { color: #FCD34D; }
.timeline-step.done .lbl { color: #6EE7B7; }
@keyframes pulse-active {
  0%, 100% { box-shadow: 0 0 0 4px rgba(245,184,0,.15); }
  50%      { box-shadow: 0 0 0 10px rgba(245,184,0,0); }
}

/* BOUTONS ACTIONS */
.demande-actions {
  display: flex;
  gap: 10px;
  margin-top: 18px;
  position: relative;
  z-index: 1;
  flex-wrap: wrap;
}
.btn-annuler {
  padding: 12px 22px;
  border-radius: 999px;
  background: rgba(239,68,68,.15);
  color: #FCA5A5;
  border: 1px solid rgba(239,68,68,.35);
  font-weight: 800;
  font-size: .88rem;
  cursor: pointer;
  font-family: inherit;
  transition: all .25s;
  display: inline-flex;
  align-items: center;
  gap: 8px;
}
.btn-annuler:hover { background: rgba(239,68,68,.25); transform: translateY(-2px); }
.btn-voir {
  padding: 12px 22px;
  border-radius: 999px;
  background: linear-gradient(135deg, #3B82F6, #2563EB);
  color: #fff;
  text-decoration: none;
  font-weight: 800;
  font-size: .88rem;
  transition: all .25s;
  display: inline-flex;
  align-items: center;
  gap: 8px;
}
.btn-voir:hover { transform: translateY(-2px); color: #fff; }

/* FORMULAIRE */
.form-card {
  padding: 26px 28px;
  border-radius: 20px;
  background: linear-gradient(145deg, var(--card), var(--card-2));
  border: 1px solid var(--border);
  margin-bottom: 24px;
}
.form-card h3 {
  color: var(--gold);
  font-size: 1.1rem;
  margin-bottom: 18px;
  display: flex;
  align-items: center;
  gap: 10px;
}

.produit-picker {
  display: grid;
  grid-template-columns: 1fr;
  gap: 10px;
}
.produit-card {
  padding: 16px 18px;
  border-radius: 14px;
  background: rgba(11,14,20,.4);
  border: 2px solid var(--border);
  cursor: pointer;
  transition: all .25s;
  position: relative;
  display: block;
}
.produit-card:hover { border-color: rgba(245,184,0,.5); }
.produit-card.selected {
  border-color: var(--gold);
  background: rgba(245,184,0,.08);
  box-shadow: 0 0 0 4px rgba(245,184,0,.1);
}
.produit-card input[type="radio"] {
  position: absolute;
  opacity: 0;
  pointer-events: none;
}
.produit-card .name {
  font-weight: 800;
  color: var(--text);
  font-size: .95rem;
  margin-bottom: 6px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}
.produit-card .devise-badge {
  padding: 2px 10px;
  border-radius: 999px;
  font-size: .68rem;
  font-weight: 800;
}
.produit-card .devise-badge.cdf { background: rgba(245,184,0,.15); color: var(--gold); }
.produit-card .devise-badge.usd { background: rgba(59,130,246,.15); color: #93B4FF; }
.produit-card .info {
  font-size: .78rem;
  color: var(--muted);
  display: flex;
  gap: 14px;
  flex-wrap: wrap;
}
.produit-card .info .rate { color: #6EE7B7; font-weight: 800; font-family: monospace; }
.produit-card .limits {
  margin-top: 8px;
  padding-top: 8px;
  border-top: 1px solid rgba(255,255,255,.05);
  font-size: .72rem;
  color: var(--muted);
  display: flex;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 6px;
}

.form-group { margin-bottom: 16px; }
.form-group label {
  display: block;
  color: var(--muted);
  font-size: .78rem;
  text-transform: uppercase;
  letter-spacing: 1px;
  font-weight: 700;
  margin-bottom: 8px;
}
.form-group input[type="text"],
.form-group input[type="number"],
.form-group textarea,
.form-group select {
  width: 100%;
  padding: 14px 16px;
  background: var(--bg-1);
  border: 1px solid var(--border);
  border-radius: 12px;
  color: var(--text);
  font-size: .95rem;
  font-family: inherit;
}
.form-group input:focus,
.form-group textarea:focus,
.form-group select:focus {
  outline: none;
  border-color: var(--gold);
  box-shadow: 0 0 0 3px rgba(245,184,0,.12);
}
.form-group textarea { resize: vertical; min-height: 80px; }
.form-group .hint { color: var(--muted); font-size: .72rem; margin-top: 6px; }

/* Select devise */
.devise-picker {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}
.devise-option {
  padding: 16px;
  border-radius: 12px;
  background: rgba(11,14,20,.4);
  border: 2px solid var(--border);
  cursor: pointer;
  text-align: center;
  transition: all .2s;
}
.devise-option:hover { border-color: rgba(245,184,0,.5); }
.devise-option.selected {
  border-color: var(--gold);
  background: rgba(245,184,0,.08);
}
.devise-option input[type="radio"] {
  position: absolute;
  opacity: 0;
}
.devise-option .flag { font-size: 1.8rem; display: block; margin-bottom: 6px; }
.devise-option .code { font-weight: 800; color: var(--text); font-size: .9rem; }
.devise-option .label { color: var(--muted); font-size: .72rem; margin-top: 2px; }

/* Preview */
.preview-calc {
  margin-top: 16px;
  padding: 18px;
  border-radius: 14px;
  background: linear-gradient(135deg, rgba(16,185,129,.08), rgba(16,185,129,.02));
  border: 1px solid rgba(16,185,129,.3);
  display: none;
}
.preview-calc.active { display: block; animation: fadeInUp .3s; }
@keyframes fadeInUp {
  from { opacity: 0; transform: translateY(10px); }
  to { opacity: 1; transform: translateY(0); }
}
.preview-calc .row {
  display: flex;
  justify-content: space-between;
  padding: 8px 0;
  font-size: .85rem;
  border-bottom: 1px solid rgba(255,255,255,.05);
}
.preview-calc .row:last-child {
  border-bottom: none;
  padding-top: 12px;
  margin-top: 4px;
  border-top: 2px solid rgba(16,185,129,.3);
  font-size: 1rem;
}
.preview-calc .row .lbl { color: var(--muted); }
.preview-calc .row .val { font-weight: 800; color: var(--text); }
.preview-calc .row .val.gold { color: var(--gold); }
.preview-calc .row .val.green { color: #6EE7B7; font-size: 1.15rem; }

.btn-submit-demande {
  width: 100%;
  padding: 18px;
  border-radius: 999px;
  background: linear-gradient(135deg, #10B981, #059669);
  color: #fff;
  border: none;
  font-weight: 800;
  font-size: 1.05rem;
  cursor: pointer;
  font-family: inherit;
  box-shadow: 0 8px 24px rgba(16,185,129,.4);
  transition: all .25s;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  margin-top: 10px;
}
.btn-submit-demande:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 12px 32px rgba(16,185,129,.55); }
.btn-submit-demande:disabled { opacity: .5; cursor: not-allowed; }

/* Info rejet */
.info-rejet {
  padding: 18px 22px;
  border-radius: 16px;
  background: linear-gradient(135deg, rgba(107,114,128,.1), rgba(107,114,128,.02));
  border: 1px solid rgba(107,114,128,.3);
  margin-bottom: 20px;
}
.info-rejet h4 { color: #9CA3AF; font-size: .95rem; margin-bottom: 10px; display: flex; align-items: center; gap: 8px; }
.info-rejet p { color: var(--muted); font-size: .85rem; line-height: 1.6; }
.info-rejet p strong { color: var(--text); }
.info-rejet.rejete { background: linear-gradient(135deg, rgba(239,68,68,.08), rgba(239,68,68,.02)); border-color: rgba(239,68,68,.3); }
.info-rejet.rejete h4 { color: #FCA5A5; }

/* Info box score */
.score-info {
  padding: 16px 20px;
  border-radius: 14px;
  background: linear-gradient(135deg, rgba(245,184,0,.08), rgba(245,184,0,.02));
  border: 1px solid rgba(245,184,0,.3);
  margin-bottom: 20px;
  display: flex;
  align-items: center;
  gap: 16px;
  flex-wrap: wrap;
}
.score-info .circle {
  width: 60px; height: 60px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.3rem;
  font-weight: 900;
  border: 3px solid;
  flex-shrink: 0;
}
.score-info .circle.excellent { background: rgba(16,185,129,.15); border-color: #10B981; color: #6EE7B7; }
.score-info .circle.bon { background: rgba(59,130,246,.15); border-color: #3B82F6; color: #93B4FF; }
.score-info .circle.moyen { background: rgba(245,184,0,.15); border-color: #F5B800; color: #FCD34D; }
.score-info .circle.faible, .score-info .circle.mauvais { background: rgba(239,68,68,.15); border-color: #EF4444; color: #FCA5A5; }
.score-info .txt .t1 { font-weight: 800; color: var(--text); font-size: .95rem; }
.score-info .txt .t2 { color: var(--muted); font-size: .78rem; margin-top: 4px; }

/* Modal */
.modal-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(11,14,20,.85);
  backdrop-filter: blur(10px);
  z-index: 99999;
  align-items: center;
  justify-content: center;
  padding: 20px;
}
.modal-overlay.active { display: flex; }
.modal-content {
  background: linear-gradient(145deg, #1A2130, #121824);
  border-radius: 20px;
  border: 1px solid rgba(239,68,68,.35);
  box-shadow: 0 30px 80px rgba(0,0,0,.8);
  padding: 30px 28px;
  max-width: 480px;
  width: 100%;
  max-height: 90vh;
  overflow-y: auto;
}
.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
  padding-bottom: 14px;
  border-bottom: 1px solid var(--border);
}
.modal-header h3 { color: #FCA5A5; font-size: 1.15rem; margin: 0; }
.modal-close {
  width: 36px; height: 36px;
  border-radius: 50%;
  background: rgba(239,68,68,.15);
  color: #FCA5A5;
  border: 1px solid rgba(239,68,68,.3);
  cursor: pointer;
  font-size: 1.2rem;
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 800;
}
.modal-actions {
  display: flex;
  gap: 12px;
  justify-content: flex-end;
  margin-top: 22px;
  padding-top: 18px;
  border-top: 1px solid var(--border);
}
.btn-modal-cancel {
  padding: 12px 22px;
  border-radius: 12px;
  background: transparent;
  color: var(--text);
  border: 1px solid var(--border);
  font-weight: 700;
  cursor: pointer;
  font-family: inherit;
}
.btn-modal-confirm {
  padding: 12px 22px;
  border-radius: 12px;
  background: linear-gradient(135deg, #DC2626, #EF4444);
  color: #fff;
  border: none;
  font-weight: 800;
  cursor: pointer;
  font-family: inherit;
}

@media (max-width: 640px) {
  .hero-demande, .demande-en-cours { padding: 20px; }
  .hero-demande h1 { font-size: 1.25rem; }
  .form-card { padding: 20px 18px; }
  .timeline-steps { flex-wrap: wrap; }
  .timeline-steps::before { display: none; }
  .timeline-step { flex: 0 0 33%; margin-bottom: 10px; }
}
@keyframes float {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-15px); }
}
@keyframes spin { to { transform: rotate(360deg); } }
</style>
</head>
<body class="dark-auth app-mode">

<?php require __DIR__ . '/../includes/header.php'; ?>

<main class="dash">

  <div class="welcome-head">
    <div>
      <h2>💳 Demande de prêt</h2>
      <p class="sub">
        <?php if ($demande_en_cours): ?>
          Vous avez une demande en cours
        <?php else: ?>
          Remplissez le formulaire ci-dessous
        <?php endif; ?>
      </p>
    </div>
    <a class="btn-ghost" href="<?= $base ?>/client/mes_prets.php">← Mes prêts</a>
  </div>

  <?php if ($errors): ?>
    <div class="alert error">
      <strong>❌ Erreur :</strong>
      <ul style="margin-top:8px;margin-left:20px">
        <?php foreach ($errors as $e) echo "<li>" . htmlspecialchars($e) . "</li>"; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($success): ?>
    <div class="alert success"><?= $success ?></div>
  <?php endif; ?>

  <!-- ============================================================
       CAS 1 : DEMANDE EN COURS (blocage)
       ============================================================ -->
  <?php if ($demande_en_cours): 
    $statuts_timeline = ['soumis','en_analyse','approuve','signe','decaisse'];
    $current_idx = array_search($demande_en_cours['statut'], $statuts_timeline);
    if ($current_idx === false) $current_idx = 0;
  ?>
    <div class="demande-en-cours">
      <div class="head">
        <div class="icon">⏳</div>
        <div class="text">
          <h3>Demande en cours de traitement</h3>
          <p>Votre demande est en attente d'approbation par un agent de crédit.</p>
        </div>
      </div>

      <div class="demande-info-grid">
        <div class="demande-info-box">
          <div class="lbl">Référence</div>
          <div class="val gold"><?= htmlspecialchars($demande_en_cours['reference']) ?></div>
        </div>
        <div class="demande-info-box">
          <div class="lbl">Produit</div>
          <div class="val" style="font-size:.88rem"><?= htmlspecialchars($demande_en_cours['produit_nom']) ?></div>
        </div>
        <div class="demande-info-box">
          <div class="lbl">Montant demandé</div>
          <div class="val gold"><?= htmlspecialchars(formaterMontant((float)$demande_en_cours['montant_demande'], $demande_en_cours['devise'])) ?></div>
        </div>
        <div class="demande-info-box">
          <div class="lbl">Durée</div>
          <div class="val"><?= (int)$demande_en_cours['duree_mois'] ?> mois</div>
        </div>
        <div class="demande-info-box">
          <div class="lbl">Date demande</div>
          <div class="val" style="font-size:.88rem"><?= date('d/m/Y', strtotime($demande_en_cours['date_demande'])) ?></div>
        </div>
        <div class="demande-info-box">
          <div class="lbl">Statut</div>
          <div class="val blue"><?= strtoupper(str_replace('_', ' ', $demande_en_cours['statut'])) ?></div>
        </div>
      </div>

      <div class="timeline">
        <div class="timeline-steps">
          <?php 
            $etapes = [
              ['soumis', '📤', 'Soumis'],
              ['en_analyse', '🔍', 'Analyse'],
              ['approuve', '✅', 'Approuvé'],
              ['signe', '✍️', 'Signé'],
              ['decaisse', '💰', 'Décaissé'],
            ];
            foreach ($etapes as $i => $et):
              $classe = '';
              if ($i < $current_idx) $classe = 'done';
              elseif ($i === $current_idx) $classe = 'active';
          ?>
            <div class="timeline-step <?= $classe ?>">
              <div class="dot"><?= $et[1] ?></div>
              <div class="lbl"><?= $et[2] ?></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="demande-actions">
        <a class="btn-voir" href="<?= $base ?>/client/mes_prets.php">
          👁 Voir les détails
        </a>
        <?php if (in_array($demande_en_cours['statut'], ['soumis','en_analyse'], true)): ?>
          <button class="btn-annuler" onclick="ouvrirAnnulation(<?= (int)$demande_en_cours['id_pret'] ?>, '<?= htmlspecialchars($demande_en_cours['reference'], ENT_QUOTES) ?>')">
            🚫 Annuler ma demande
          </button>
        <?php endif; ?>
      </div>
    </div>

  <?php else: ?>

    <!-- ============================================================
         CAS 2 : FORMULAIRE VISIBLE (le client peut toujours postuler)
         ============================================================ -->

    <!-- Info dernière rejetée -->
    <?php if ($derniere_rejetee): ?>
      <div class="info-rejet <?= $derniere_rejetee['statut'] === 'rejete' ? 'rejete' : '' ?>">
        <h4>📋 Votre dernière demande</h4>
        <p>
          La demande <strong><?= htmlspecialchars($derniere_rejetee['reference']) ?></strong> 
          du <strong><?= date('d/m/Y', strtotime($derniere_rejetee['date_demande'])) ?></strong> 
          a été <strong><?= $derniere_rejetee['statut'] === 'rejete' ? 'rejetée' : 'annulée' ?></strong>.
          <?php if ($derniere_rejetee['motif_rejet']): ?>
            <br><br>
            <strong>Motif :</strong> <?= htmlspecialchars($derniere_rejetee['motif_rejet']) ?>
          <?php endif; ?>
          <br><br>
          Vous pouvez soumettre une <strong>nouvelle demande</strong> ci-dessous.
        </p>
      </div>
    <?php endif; ?>

    <!-- Info score -->
    <div class="score-info">
      <div class="circle <?= htmlspecialchars($score['niveau']) ?>">
        <?= (int)$score['score'] ?>
      </div>
      <div class="txt" style="flex:1;min-width:180px">
        <div class="t1">Score de crédit : <?= strtoupper($score['niveau']) ?></div>
        <div class="t2">Fiabilité : <?= $score['fiabilite'] ?>/33 · Compétence : <?= $score['competence'] ?>/33 · Bonne volonté : <?= $score['bonne_volonte'] ?>/34</div>
      </div>
      <div style="text-align:right">
        <div class="t2">Ancienneté</div>
        <div class="t1"><?= $mois_anciennete_precis ?> mois</div>
      </div>
    </div>

    <!-- FORMULAIRE -->
    <form method="POST" id="formDemande" novalidate>
      <?= csrfField() ?>
      <input type="hidden" name="action" value="soumettre">

      <!-- Produit -->
      <div class="form-card">
        <h3>🎯 Choisissez votre produit de prêt</h3>

        <?php if (empty($produits)): ?>
          <div class="alert error">
            Aucun produit de prêt disponible pour le moment.
          </div>
        <?php else: ?>
          <div class="produit-picker">
            <?php foreach ($produits as $i => $pr): ?>
              <label class="produit-card <?= $i === 0 ? 'selected' : '' ?>"
                     data-id="<?= (int)$pr['id_produit'] ?>"
                     data-devise="<?= htmlspecialchars($pr['devise']) ?>"
                     data-taux="<?= (float)$pr['taux_annuel'] ?>"
                     data-mmin="<?= (float)$pr['montant_min'] ?>"
                     data-mmax="<?= (float)$pr['montant_max'] ?>"
                     data-dmin="<?= (int)$pr['duree_min_mois'] ?>"
                     data-dmax="<?= (int)$pr['duree_max_mois'] ?>">
                <input type="radio" name="id_produit" value="<?= (int)$pr['id_produit'] ?>" 
                       <?= $i === 0 ? 'checked' : '' ?> required>

                <div class="name">
                  <?= htmlspecialchars($pr['nom']) ?>
                  <span class="devise-badge <?= strtolower($pr['devise']) ?>">
                    <?= htmlspecialchars($pr['devise']) ?>
                  </span>
                </div>
                <div class="info">
                  <span>Taux : <span class="rate"><?= number_format((float)$pr['taux_annuel'] * 100, 2) ?>%/an</span></span>
                  <span>Durée : <?= (int)$pr['duree_min_mois'] ?>-<?= (int)$pr['duree_max_mois'] ?> mois</span>
                </div>
                <div class="limits">
                  <span>
                    💰 <?= htmlspecialchars(formaterMontant((float)$pr['montant_min'], $pr['devise'])) ?>
                    → <?= htmlspecialchars(formaterMontant((float)$pr['montant_max'], $pr['devise'])) ?>
                  </span>
                </div>
              </label>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <!-- Détails -->
      <div class="form-card">
        <h3>💰 Détails du prêt</h3>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
          <div class="form-group">
            <label>Montant *</label>
            <input type="text" name="montant" id="montantInput" required placeholder="0" inputmode="decimal">
            <div class="hint" id="montantHint">—</div>
          </div>
          <div class="form-group">
            <label>Durée (mois) *</label>
            <input type="number" name="duree_mois" id="dureeInput" required min="1" max="60" value="12">
            <div class="hint" id="dureeHint">—</div>
          </div>
        </div>

        <div class="preview-calc" id="previewCalc">
          <div class="row">
            <span class="lbl">💰 Mensualité estimée</span>
            <span class="val green" id="prevMensualite">—</span>
          </div>
          <div class="row">
            <span class="lbl">📈 Total intérêts</span>
            <span class="val" id="prevInterets">—</span>
          </div>
          <div class="row">
            <span class="lbl">🎯 Total à rembourser</span>
            <span class="val gold" id="prevTotal">—</span>
          </div>
        </div>

        <div class="form-group" style="margin-top:16px">
          <label>Motif du prêt *</label>
          <textarea name="motif_pret" required maxlength="500"
                    placeholder="Ex: Achat de marchandises pour mon commerce au marché central..."></textarea>
          <div class="hint">Soyez précis : cela aidera l'analyse de votre dossier</div>
        </div>

        <div class="form-group">
          <label>Destination des fonds (optionnel)</label>
          <input type="text" name="destination_fonds" maxlength="200"
                 placeholder="Ex: Boutique au marché central, Kinshasa">
        </div>
      </div>

      <button class="btn-submit-demande" type="submit" id="btnSubmit">
        📤 Soumettre ma demande
      </button>

      <p style="text-align:center;color:var(--muted);font-size:.78rem;margin-top:14px">
        ⏳ Votre demande sera examinée par un agent de crédit. Vous serez notifié de la décision.
      </p>
    </form>

  <?php endif; ?>

</main>

<!-- Modal Annulation -->
<div class="modal-overlay" id="modalAnnulation">
  <div class="modal-content">
    <div class="modal-header">
      <h3>🚫 Annuler la demande</h3>
      <button class="modal-close" onclick="fermerModal()">✕</button>
    </div>

    <div style="padding:14px;border-radius:12px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.3);margin-bottom:18px">
      <p style="color:#FCA5A5;font-size:.88rem;line-height:1.6">
        ⚠️ <strong>Action irréversible.</strong><br>
        Cette demande sera définitivement annulée. Vous pourrez en soumettre une nouvelle.
      </p>
    </div>

    <p style="color:var(--muted);font-size:.85rem;margin-bottom:14px">
      Référence : <strong id="modalRef" style="color:var(--gold)">—</strong>
    </p>

    <form method="POST" id="formAnnulation">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="annuler">
      <input type="hidden" name="id_pret" id="modalIdPret">

      <div class="form-group">
        <label>Motif de l'annulation *</label>
        <textarea name="motif_annulation" required rows="3" maxlength="255"
                  placeholder="Ex: J'ai changé d'avis, j'ai trouvé un autre financement..."></textarea>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-modal-cancel" onclick="fermerModal()">Retour</button>
        <button type="submit" class="btn-modal-confirm">🚫 Confirmer</button>
      </div>
    </form>
  </div>
</div>

<footer class="atf-footer" style="padding:20px;text-align:center;color:var(--muted)">
  © <?= date('Y') ?> AFRICAN TRUST FUND
</footer>

<script>
/* MODAL */
function ouvrirAnnulation(idPret, reference) {
    document.getElementById('modalIdPret').value = idPret;
    document.getElementById('modalRef').textContent = reference;
    document.getElementById('modalAnnulation').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function fermerModal() {
    document.getElementById('modalAnnulation').classList.remove('active');
    document.body.style.overflow = '';
}
document.getElementById('modalAnnulation').addEventListener('click', e => {
    if (e.target === e.currentTarget) fermerModal();
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') fermerModal();
});

/* SÉLECTION PRODUIT */
let produitActuel = null;

document.querySelectorAll('.produit-card').forEach(card => {
    card.addEventListener('click', () => {
        document.querySelectorAll('.produit-card').forEach(c => c.classList.remove('selected'));
        card.classList.add('selected');
        card.querySelector('input[type=radio]').checked = true;
        chargerProduit(card);
    });
});

function chargerProduit(card) {
    produitActuel = {
        id: card.dataset.id,
        devise: card.dataset.devise,
        taux: parseFloat(card.dataset.taux),
        mmin: parseFloat(card.dataset.mmin),
        mmax: parseFloat(card.dataset.mmax),
        dmin: parseInt(card.dataset.dmin),
        dmax: parseInt(card.dataset.dmax),
    };

    const mInput = document.getElementById('montantInput');
    const dInput = document.getElementById('dureeInput');

    mInput.min = produitActuel.mmin;
    mInput.max = produitActuel.mmax;
    dInput.min = produitActuel.dmin;
    dInput.max = produitActuel.dmax;
    dInput.value = Math.min(12, produitActuel.dmax);

    document.getElementById('montantHint').textContent = 
        `Entre ${formatMontant(produitActuel.mmin)} et ${formatMontant(produitActuel.mmax)}`;
    document.getElementById('dureeHint').textContent = 
        `Entre ${produitActuel.dmin} et ${produitActuel.dmax} mois`;

    mInput.value = produitActuel.mmin;
    updatePreview();
}

function formatMontant(v) {
    if (!produitActuel) return v;
    if (produitActuel.devise === 'USD') return '$ ' + Number(v).toLocaleString('fr-FR', {minimumFractionDigits:2, maximumFractionDigits:2});
    return Number(v).toLocaleString('fr-FR', {maximumFractionDigits:0}) + ' CDF';
}

function updatePreview() {
    if (!produitActuel) return;
    const montant = parseFloat(document.getElementById('montantInput').value.replace(/[^\d.]/g, '')) || 0;
    const duree = parseInt(document.getElementById('dureeInput').value) || 0;

    if (montant <= 0 || duree <= 0) {
        document.getElementById('previewCalc').classList.remove('active');
        return;
    }

    const tm = produitActuel.taux / 12;
    let mensualite;
    if (tm <= 0) {
        mensualite = montant / duree;
    } else {
        const f = Math.pow(1 + tm, -duree);
        mensualite = montant * tm / (1 - f);
    }
    const total = mensualite * duree;
    const interets = total - montant;

    document.getElementById('prevMensualite').textContent = formatMontant(mensualite);
    document.getElementById('prevInterets').textContent = '+' + formatMontant(interets);
    document.getElementById('prevTotal').textContent = formatMontant(total);
    document.getElementById('previewCalc').classList.add('active');
}

document.getElementById('montantInput')?.addEventListener('input', updatePreview);
document.getElementById('dureeInput')?.addEventListener('input', updatePreview);

const firstCard = document.querySelector('.produit-card.selected');
if (firstCard) chargerProduit(firstCard);

/* SUBMIT */
document.getElementById('formDemande')?.addEventListener('submit', function(e) {
    if (!produitActuel) {
        e.preventDefault();
        alert('❌ Sélectionnez un produit');
        return;
    }

    const montant = parseFloat(document.getElementById('montantInput').value.replace(/[^\d.]/g, '')) || 0;
    if (montant < produitActuel.mmin || montant > produitActuel.mmax) {
        e.preventDefault();
        alert(`❌ Montant hors limites (${formatMontant(produitActuel.mmin)} - ${formatMontant(produitActuel.mmax)})`);
        return;
    }

    const btn = document.getElementById('btnSubmit');
    btn.disabled = true;
    btn.innerHTML = '<span style="display:inline-block;width:18px;height:18px;border:2px solid #fff;border-top-color:transparent;border-radius:50%;animation:spin 1s linear infinite"></span> Envoi en cours...';
});
</script>

<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>