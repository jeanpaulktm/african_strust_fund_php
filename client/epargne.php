<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/interets.php';
require_once __DIR__ . '/../config/parametres.php';
requireRole('client');

$pdo = Database::getConnection();
$id  = (int)$_SESSION['client_id'];

$errors = [];
$success = '';

/* ============================================================
   PARAMÈTRES SYSTÈME
   ============================================================ */
$taux_actuel      = getTauxInteretDefaut($pdo);
$duree_blocage    = getDureeBlocageInterets($pdo);
$commission_taux  = getCommissionRetrait($pdo);
$montant_min      = (float)getParametre($pdo, 'montant_min_depot', 5000);
$montant_max      = (float)getParametre($pdo, 'montant_max_depot', 100000000);

/* ============================================================
   INVESTIR DANS L'ÉPARGNE
   Solde → Solde_épargne
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'deposer') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) throw new RuntimeException("Jeton invalide.");

        $montant = (float)str_replace([' ', ','], ['', '.'], $_POST['montant'] ?? '0');
        $devise  = in_array($_POST['devise'] ?? '', ['CDF','USD'], true) ? $_POST['devise'] : 'CDF';
        $duree_mois = (int)($_POST['duree_mois'] ?? 12);
        if ($duree_mois < 1) $duree_mois = 12;
        if ($duree_mois > 60) $duree_mois = 60;

        if ($montant <= 0) throw new RuntimeException("Le montant doit être positif.");
        if ($devise === 'CDF' && $montant < $montant_min) {
            throw new RuntimeException("Minimum : " . number_format($montant_min, 0, ',', ' ') . " CDF");
        }
        if ($montant > $montant_max) {
            throw new RuntimeException("Maximum : " . number_format($montant_max, 0, ',', ' ') . " $devise");
        }

        $pdo->beginTransaction();

        /* Charger le client avec verrou */
        $stmt = $pdo->prepare("SELECT * FROM Client WHERE id_client = ? FOR UPDATE");
        $stmt->execute([$id]);
        $client = $stmt->fetch();

        /* Colonnes dynamiques selon la devise */
        $col_solde  = $devise === 'USD' ? 'solde_usd' : 'solde_cdf';
        $col_epargne = $devise === 'USD' ? 'solde_epargne_usd' : 'solde_epargne_cdf';

        $solde_disponible = (float)$client[$col_solde];

        if ($montant > $solde_disponible) {
            throw new RuntimeException(
                "Solde $devise insuffisant. Disponible : " .
                number_format($solde_disponible, 2, ',', ' ') . " $devise"
            );
        }

        /* ============================================================
           TRANSFERT : Solde → Solde_épargne
           ============================================================ */
        $pdo->prepare("
            UPDATE Client 
            SET $col_solde = $col_solde - ?,
                $col_epargne = $col_epargne + ?
            WHERE id_client = ?
        ")->execute([$montant, $montant, $id]);

        /* Créer le placement (pour calculer les intérêts) */
        $reference = 'DEP-' . strtoupper(bin2hex(random_bytes(6)));
        $date_debut = date('Y-m-d');
        $date_deblocage = date('Y-m-d', strtotime("+$duree_blocage months"));
        $date_fin = date('Y-m-d', strtotime("+$duree_mois months"));

        /* Trouver ou créer un produit standard */
        $stmt = $pdo->prepare("SELECT id_produit FROM Produits_Epargne WHERE actif = 1 AND devise = ? LIMIT 1");
        $stmt->execute([$devise]);
        $id_produit = (int)$stmt->fetchColumn();

        if (!$id_produit) {
            $pdo->prepare("
                INSERT INTO Produits_Epargne 
                    (nom, description, type_interet, taux_annuel, 
                     duree_min_mois, duree_max_mois, montant_min, montant_max,
                     commission_retrait, penalite_anticipe, devise, actif)
                VALUES (?, ?, 'simple', ?, 6, 60, ?, ?, ?, 0.005, ?, 1)
            ")->execute([
                'Épargne ' . $devise,
                'Produit d\'épargne automatique',
                $taux_actuel / 100,
                $montant_min,
                $montant_max,
                $commission_taux / 100,
                $devise
            ]);
            $id_produit = (int)$pdo->lastInsertId();
        }

        $pdo->prepare("
            INSERT INTO Placements 
                (id_client, id_produit, montant_initial, montant_actuel, devise,
                 taux_applique, interets_cumules, interets_retires, capital_retire,
                 duree_mois, date_debut, date_fin, date_dernier_calcul,
                 date_deblocage_interets, statut, reference, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 0, 0, 0, ?, ?, ?, ?, ?, 'actif', ?, NOW())
        ")->execute([
            $id, $id_produit, $montant, $montant, $devise,
            $taux_actuel / 100,
            $duree_mois, $date_debut, $date_fin, $date_debut,
            $date_deblocage, $reference
        ]);

        /* Transaction */
        $pdo->prepare("
            INSERT INTO Transactions 
                (id_client, type_transaction, montant, devise, mode, effectue_par,
                 solde_avant, solde_apres, reference, description, ip_address)
            VALUES (?, 'Depot', ?, ?, 'En ligne', 'Client', ?, ?, ?, ?, ?)
        ")->execute([
            $id, $montant, $devise,
            $solde_disponible, $solde_disponible - $montant,
            $reference,
            "Investissement épargne — Taux {$taux_actuel}% | Déblocage le " . date('d/m/Y', strtotime($date_deblocage)),
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);

        $pdo->commit();

        logAction($pdo, 'client', $id, 'DEPOT_PLACEMENT',
            "Montant: $montant $devise | Taux: $taux_actuel% | Ref: $reference");

        $_SESSION['flash_success'] = "✅ Investissement épargne effectué !<br>
            <strong>Référence :</strong> $reference<br>
            <strong>Montant investi :</strong> " . number_format($montant, 2, ',', ' ') . " $devise<br>
            <strong>Taux appliqué :</strong> {$taux_actuel}% par an<br>
            <strong>⚠️ Intérêts bloqués jusqu'au :</strong> " . date('d/m/Y', strtotime($date_deblocage)) . "<br>
            <small>Solde disponible diminué de " . number_format($montant, 2, ',', ' ') . " $devise<br>
            Solde épargne augmenté de " . number_format($montant, 2, ',', ' ') . " $devise</small>";

        header('Location: ' . url('client/epargne.php'));
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = $e->getMessage();
    }
}

/* ============================================================
   RETRAIT DU CAPITAL
   Solde_épargne → Solde (avec commission)
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'retirer_capital') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) throw new RuntimeException("Jeton invalide.");

        $id_placement = (int)($_POST['id_placement'] ?? 0);
        $montant = (float)str_replace([' ', ','], ['', '.'], $_POST['montant'] ?? '0');

        if ($montant <= 0) throw new RuntimeException("Le montant doit être positif.");

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT * FROM Placements 
            WHERE id_placement = ? AND id_client = ? AND statut = 'actif'
            FOR UPDATE
        ");
        $stmt->execute([$id_placement, $id]);
        $placement = $stmt->fetch();

        if (!$placement) throw new RuntimeException("Placement introuvable.");

        $capital_disponible = (float)$placement['montant_initial'] - (float)$placement['capital_retire'];

        if ($montant > $capital_disponible) {
            throw new RuntimeException(
                "Capital insuffisant. Disponible : " .
                number_format($capital_disponible, 2, ',', ' ') . " {$placement['devise']}"
            );
        }

        /* Commission */
        $commission = round($montant * ($commission_taux / 100), 2);

        $devise = $placement['devise'];
        $col_solde   = $devise === 'USD' ? 'solde_usd' : 'solde_cdf';
        $col_epargne = $devise === 'USD' ? 'solde_epargne_usd' : 'solde_epargne_cdf';

        /* Vérifier que le solde_epargne couvre bien */
        $stmt2 = $pdo->prepare("SELECT $col_epargne AS solde_epargne FROM Client WHERE id_client = ?");
        $stmt2->execute([$id]);
        $client_data = $stmt2->fetch();

        if ($montant > (float)$client_data['solde_epargne']) {
            throw new RuntimeException("Erreur interne : solde épargne insuffisant.");
        }

        /* ============================================================
           TRANSFERT : Solde_épargne → Solde
           Le client reçoit le MONTANT, la commission est retenue
           ============================================================ */
        $pdo->prepare("
            UPDATE Client 
            SET $col_epargne = $col_epargne - ?,
                $col_solde   = $col_solde + ? - ?
            WHERE id_client = ?
        ")->execute([$montant, $montant, $commission, $id]);

        /* Mettre à jour le placement */
        $nouveau_capital_retire = (float)$placement['capital_retire'] + $montant;
        $nouveau_montant_actuel = (float)$placement['montant_actuel'] - $montant;
        $nouveau_statut = $nouveau_capital_retire >= (float)$placement['montant_initial'] ? 'cloture' : 'actif';

        $pdo->prepare("
            UPDATE Placements 
            SET capital_retire = ?, montant_actuel = ?, statut = ?
            WHERE id_placement = ?
        ")->execute([$nouveau_capital_retire, max(0, $nouveau_montant_actuel), $nouveau_statut, $id_placement]);

        /* Transaction */
        $reference = 'RET-' . strtoupper(bin2hex(random_bytes(6)));

        $pdo->prepare("
            INSERT INTO Transactions 
                (id_client, type_transaction, montant, devise, mode, effectue_par,
                 solde_avant, solde_apres, reference, description, ip_address)
            VALUES (?, 'Retrait', ?, ?, 'En ligne', 'Client', ?, ?, ?, ?, ?)
        ")->execute([
            $id, $montant, $devise,
            (float)$placement['montant_actuel'], $nouveau_montant_actuel,
            $reference,
            "Retrait CAPITAL du placement {$placement['reference']} | Commission {$commission_taux}% = $commission $devise",
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);

        $id_transaction = (int)$pdo->lastInsertId();

        $pdo->prepare("
            INSERT INTO Commissions 
                (id_client, id_transaction, montant_brut, taux_commission,
                 montant_commission, montant_net, devise, date_commission)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ")->execute([$id, $id_transaction, $montant, $commission_taux / 100, $commission, $montant, $devise]);

        if (function_exists('crediterInteretEntreprise')) {
            crediterInteretEntreprise(
                $pdo, $commission, $devise, 'commission_retrait', $id,
                ['id_transaction' => $id_transaction],
                "Commission sur retrait capital - {$placement['reference']}"
            );
        }

        $pdo->commit();

        logAction($pdo, 'client', $id, 'RETRAIT_CAPITAL',
            "Placement: {$placement['reference']} | Montant: $montant | Commission: $commission");

        $_SESSION['flash_success'] = "✅ Retrait de capital effectué !<br>
            <strong>Référence :</strong> $reference<br>
            <strong>Montant retiré du placement :</strong> " . number_format($montant, 2, ',', ' ') . " $devise<br>
            <strong>Commission ({$commission_taux}%) :</strong> − " . number_format($commission, 2, ',', ' ') . " $devise<br>
            <strong>💵 Crédité sur solde disponible :</strong> " . number_format($montant - $commission, 2, ',', ' ') . " $devise";

        header('Location: ' . url('client/epargne.php'));
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = $e->getMessage();
    }
}

/* ============================================================
   RETRAIT DES INTÉRÊTS
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'retirer_interets') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) throw new RuntimeException("Jeton invalide.");

        $id_placement = (int)($_POST['id_placement'] ?? 0);
        $montant = (float)str_replace([' ', ','], ['', '.'], $_POST['montant'] ?? '0');

        if ($montant <= 0) throw new RuntimeException("Le montant doit être positif.");

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("
            SELECT * FROM Placements 
            WHERE id_placement = ? AND id_client = ? AND statut = 'actif'
            FOR UPDATE
        ");
        $stmt->execute([$id_placement, $id]);
        $placement = $stmt->fetch();

        if (!$placement) throw new RuntimeException("Placement introuvable.");

        /* Vérifier date de déblocage */
        $date_deblocage = $placement['date_deblocage_interets']
            ?? date('Y-m-d', strtotime($placement['date_debut'] . " +$duree_blocage months"));

        if (strtotime(date('Y-m-d')) < strtotime($date_deblocage)) {
            $jours_restants = (int)ceil((strtotime($date_deblocage) - time()) / 86400);
            throw new RuntimeException(
                "❌ Intérêts bloqués jusqu'au " . date('d/m/Y', strtotime($date_deblocage)) . ".\n" .
                "Encore $jours_restants jour(s) à patienter."
            );
        }

        $interets_disponibles = (float)$placement['interets_cumules'] - (float)$placement['interets_retires'];

        if ($montant > $interets_disponibles) {
            throw new RuntimeException(
                "Intérêts insuffisants. Disponible : " .
                number_format($interets_disponibles, 2, ',', ' ') . " {$placement['devise']}"
            );
        }

        $commission = round($montant * ($commission_taux / 100), 2);

        $devise = $placement['devise'];
        $col_solde = $devise === 'USD' ? 'solde_usd' : 'solde_cdf';

        /* Créditer le solde disponible avec les intérêts (net de commission) */
        $pdo->prepare("UPDATE Client SET $col_solde = $col_solde + ? WHERE id_client = ?")
            ->execute([$montant - $commission, $id]);

        $nouveau_interets_retires = (float)$placement['interets_retires'] + $montant;

        $pdo->prepare("UPDATE Placements SET interets_retires = ? WHERE id_placement = ?")
            ->execute([$nouveau_interets_retires, $id_placement]);

        $reference = 'INT-' . strtoupper(bin2hex(random_bytes(6)));

        $pdo->prepare("
            INSERT INTO Transactions 
                (id_client, type_transaction, montant, devise, mode, effectue_par,
                 solde_avant, solde_apres, reference, description, ip_address)
            VALUES (?, 'Retrait', ?, ?, 'En ligne', 'Client', 0, 0, ?, ?, ?)
        ")->execute([
            $id, $montant, $devise, $reference,
            "Retrait INTÉRÊTS du placement {$placement['reference']} | Commission {$commission_taux}% = $commission $devise",
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);

        $id_transaction = (int)$pdo->lastInsertId();

        $pdo->prepare("
            INSERT INTO Commissions 
                (id_client, id_transaction, montant_brut, taux_commission,
                 montant_commission, montant_net, devise, date_commission)
            VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
        ")->execute([$id, $id_transaction, $montant, $commission_taux / 100, $commission, $montant - $commission, $devise]);

        if (function_exists('crediterInteretEntreprise')) {
            crediterInteretEntreprise(
                $pdo, $commission, $devise, 'commission_retrait', $id,
                ['id_transaction' => $id_transaction],
                "Commission sur retrait intérêts - {$placement['reference']}"
            );
        }

        $pdo->commit();

        logAction($pdo, 'client', $id, 'RETRAIT_INTERETS',
            "Placement: {$placement['reference']} | Montant: $montant | Commission: $commission");

        $_SESSION['flash_success'] = "✅ Retrait des intérêts effectué !<br>
            <strong>Référence :</strong> $reference<br>
            <strong>Intérêts retirés :</strong> " . number_format($montant, 2, ',', ' ') . " $devise<br>
            <strong>Commission ({$commission_taux}%) :</strong> − " . number_format($commission, 2, ',', ' ') . " $devise<br>
            <strong>💵 Crédité sur solde :</strong> " . number_format($montant - $commission, 2, ',', ' ') . " $devise";

        header('Location: ' . url('client/epargne.php'));
        exit;

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $errors[] = $e->getMessage();
    }
}

/* ============================================================
   CHARGER LES PLACEMENTS
   ============================================================ */
$stmt = $pdo->prepare("
    SELECT p.*, pr.nom AS produit_nom, pr.taux_annuel, pr.type_interet
    FROM Placements p
    JOIN Produits_Epargne pr ON pr.id_produit = p.id_produit
    WHERE p.id_client = ?
    ORDER BY FIELD(p.statut, 'actif', 'cloture'), p.created_at DESC
");
$stmt->execute([$id]);
$placements = $stmt->fetchAll();

/* Traitement */
foreach ($placements as &$p) {
    $p['date_deblocage'] = $p['date_deblocage_interets']
        ?? date('Y-m-d', strtotime($p['date_debut'] . " +$duree_blocage months"));
    $p['interets_debloques'] = strtotime(date('Y-m-d')) >= strtotime($p['date_deblocage']);
    $p['jours_restants'] = $p['interets_debloques']
        ? 0
        : max(0, (int)ceil((strtotime($p['date_deblocage']) - time()) / 86400));

    $total_jours = (strtotime($p['date_deblocage']) - strtotime($p['date_debut'])) / 86400;
    $jours_ecoules = (time() - strtotime($p['date_debut'])) / 86400;
    $p['progression'] = $total_jours > 0 ? min(100, max(0, ($jours_ecoules / $total_jours) * 100)) : 100;

    $p['capital_disponible'] = (float)$p['montant_initial'] - (float)$p['capital_retire'];
    $p['interets_disponibles'] = (float)$p['interets_cumules'] - (float)$p['interets_retires'];
}
unset($p);

/* Infos client */
$stmt = $pdo->prepare("
    SELECT solde_cdf, solde_usd, 
           solde_epargne_cdf, solde_epargne_usd,
           numero_compte 
    FROM Client WHERE id_client = ?
");
$stmt->execute([$id]);
$client = $stmt->fetch();

/* Flash */
$success = '';
if (!empty($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0,user-scalable=no">
<meta name="theme-color" content="#0B0E14">
<title>Mon Épargne — AFRICAN TRUST FUND</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<style>
:root {
  --nav-height: 76px;
  --nav-safe-area: env(safe-area-inset-bottom, 0px);
}

body.app-mode {
  padding-bottom: calc(var(--nav-height) + var(--nav-safe-area) + 20px);
}

/* ============================================================
   SOLDES (Nouveau bloc à 2 colonnes)
   ============================================================ */
.soldes-duo {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
  margin-bottom: 24px;
}

.soldes-duo .col {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.solde-card {
  padding: 20px 22px;
  border-radius: 16px;
  border: 1px solid var(--border);
  position: relative;
  overflow: hidden;
  transition: all .3s;
}

.solde-card::before {
  content: "";
  position: absolute;
  top: 0; left: 0;
  width: 4px; height: 100%;
}

.solde-card.disponible {
  background: linear-gradient(135deg, rgba(59,130,246,.12), rgba(59,130,246,.03));
  border-color: rgba(59,130,246,.35);
}
.solde-card.disponible::before { background: #3B82F6; }

.solde-card.epargne {
  background: linear-gradient(135deg, rgba(139,92,246,.12), rgba(139,92,246,.03));
  border-color: rgba(139,92,246,.35);
}
.solde-card.epargne::before { background: #8B5CF6; }

.solde-card .head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 10px;
}

.solde-card .lbl {
  font-size: .72rem;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 1.2px;
  font-weight: 700;
}

.solde-card .flag { font-size: 1.3rem; }

.solde-card .amount {
  font-size: 1.4rem;
  font-weight: 800;
  color: #fff;
  line-height: 1.1;
}

.solde-card.disponible .amount { color: #93B4FF; }
.solde-card.epargne .amount { color: #A78BFA; }

.solde-card .devise-badge {
  display: inline-block;
  padding: 3px 10px;
  border-radius: 999px;
  background: rgba(0,0,0,.25);
  color: #fff;
  font-size: .68rem;
  font-weight: 700;
  margin-left: 6px;
  vertical-align: middle;
}

/* ============================================================
   BANDEAU RÈGLES
   ============================================================ */
.rules-banner {
  padding: 22px 26px;
  border-radius: 20px;
  background: linear-gradient(135deg, rgba(139,92,246,.15), rgba(139,92,246,.03));
  border: 1px solid rgba(139,92,246,.3);
  margin-bottom: 24px;
  position: relative;
  overflow: hidden;
}

.rules-title {
  color: #A78BFA;
  font-size: 1rem;
  font-weight: 800;
  margin-bottom: 16px;
}

.rules-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 14px;
}

.rule-card {
  padding: 16px 18px;
  border-radius: 14px;
  background: rgba(11,14,20,.5);
  border: 1px solid rgba(139,92,246,.2);
  text-align: center;
}

.rule-card .lbl {
  font-size: .68rem;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 1px;
  font-weight: 700;
}

.rule-card .val {
  font-size: 1.3rem;
  font-weight: 800;
  color: #A78BFA;
  margin-top: 6px;
}

/* ============================================================
   FORMULAIRE DE DÉPÔT
   ============================================================ */
.depot-form {
  padding: 26px 28px;
  border-radius: 20px;
  background: linear-gradient(145deg, rgba(16,185,129,.1), rgba(16,185,129,.02));
  border: 1px solid rgba(16,185,129,.3);
  margin-bottom: 26px;
}

.depot-form h3 {
  color: #6EE7B7;
  font-size: 1.15rem;
  margin-bottom: 18px;
}

.depot-preview {
  margin-top: 16px;
  padding: 16px 18px;
  border-radius: 14px;
  background: rgba(11,14,20,.5);
  border: 1px solid rgba(16,185,129,.3);
  display: none;
}

.depot-preview.active { display: block; animation: fadeInUp .3s; }

.depot-preview .line {
  display: flex;
  justify-content: space-between;
  padding: 6px 0;
  font-size: .88rem;
  border-bottom: 1px solid rgba(255,255,255,.05);
}

.depot-preview .line:last-child {
  border-bottom: none;
  margin-top: 8px;
  padding-top: 12px;
  border-top: 2px solid rgba(16,185,129,.3);
  font-size: 1rem;
}

.depot-preview .line .lbl { color: var(--muted); }
.depot-preview .line .val { font-weight: 700; color: #fff; }
.depot-preview .line .val.gold { color: #F5B800; }
.depot-preview .line .val.green { color: #6EE7B7; font-size: 1.15rem; }

/* ============================================================
   CARTE PLACEMENT
   ============================================================ */
.placement-card {
  background: linear-gradient(145deg, var(--card), var(--card-2));
  border: 1px solid var(--border);
  border-radius: 20px;
  padding: 24px;
  margin-bottom: 20px;
  position: relative;
  overflow: hidden;
  transition: all .3s;
}

.placement-card:hover {
  border-color: var(--gold);
  box-shadow: 0 16px 40px rgba(245,184,0,.15);
}

.placement-card.cloture { opacity: .65; }

.placement-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 20px;
  flex-wrap: wrap;
  gap: 12px;
}

.placement-ref {
  font-family: 'Courier New', monospace;
  font-size: .85rem;
  color: var(--gold);
  background: rgba(245,184,0,.1);
  padding: 4px 12px;
  border-radius: 8px;
  border: 1px solid rgba(245,184,0,.3);
  font-weight: 700;
}

.placement-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 14px;
  margin-bottom: 20px;
}

.placement-kpi {
  padding: 14px 16px;
  border-radius: 12px;
  background: rgba(11,14,20,.5);
  border: 1px solid var(--border);
}

.placement-kpi .lbl {
  font-size: .68rem;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 1px;
  font-weight: 700;
  margin-bottom: 4px;
}

.placement-kpi .val {
  font-size: 1.15rem;
  font-weight: 800;
  color: #fff;
}

.placement-kpi .val.gold { color: var(--gold); }
.placement-kpi .val.green { color: #6EE7B7; }
.placement-kpi .val.blue { color: #93B4FF; }
.placement-kpi .val.muted { color: var(--muted); font-size: .95rem; }

/* Barre de progression */
.progress-section {
  margin: 20px 0;
  padding: 18px;
  border-radius: 14px;
  background: linear-gradient(135deg, rgba(139,92,246,.08), rgba(139,92,246,.02));
  border: 1px solid rgba(139,92,246,.25);
}

.progress-section.unlocked {
  background: linear-gradient(135deg, rgba(16,185,129,.12), rgba(16,185,129,.02));
  border-color: rgba(16,185,129,.4);
}

.progress-label {
  display: flex;
  justify-content: space-between;
  margin-bottom: 10px;
  font-size: .85rem;
  font-weight: 700;
  flex-wrap: wrap;
  gap: 8px;
}

.progress-label .title { color: #A78BFA; }
.progress-section.unlocked .progress-label .title { color: #6EE7B7; }
.progress-label .days { color: var(--muted); font-size: .8rem; }

.progress-track {
  height: 10px;
  background: rgba(11,14,20,.5);
  border-radius: 999px;
  overflow: hidden;
}

.progress-fill {
  height: 100%;
  background: linear-gradient(90deg, #8B5CF6, #A78BFA);
  border-radius: 999px;
  transition: width 1s ease;
}

.progress-section.unlocked .progress-fill {
  background: linear-gradient(90deg, #10B981, #059669);
}

.progress-message {
  margin-top: 10px;
  font-size: .82rem;
  color: var(--muted);
  text-align: center;
}

.progress-message.unlocked { color: #6EE7B7; font-weight: 700; }

/* Actions */
.placement-actions {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  margin-top: 20px;
  padding-top: 20px;
  border-top: 1px solid var(--border);
}

.btn-capital, .btn-interets {
  padding: 12px 20px;
  border-radius: 999px;
  font-weight: 700;
  font-size: .88rem;
  border: none;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 8px;
  transition: all .25s;
}

.btn-capital {
  background: linear-gradient(135deg, #F5B800, #D4AF37);
  color: #0B0E14;
}

.btn-interets {
  background: linear-gradient(135deg, #8B5CF6, #7C3AED);
  color: #fff;
}

.btn-locked {
  background: rgba(239,68,68,.15);
  color: #FCA5A5;
  padding: 12px 20px;
  border-radius: 999px;
  font-weight: 700;
  font-size: .88rem;
  border: 1px solid rgba(239,68,68,.3);
  cursor: not-allowed;
}

.badge-locked, .badge-unlocked {
  padding: 4px 12px;
  border-radius: 999px;
  font-size: .72rem;
  font-weight: 700;
  text-transform: uppercase;
}

.badge-locked {
  background: rgba(239,68,68,.15);
  color: #FCA5A5;
  border: 1px solid rgba(239,68,68,.3);
}

.badge-unlocked {
  background: rgba(16,185,129,.15);
  color: #6EE7B7;
  border: 1px solid rgba(16,185,129,.3);
}

/* Form retrait */
.withdraw-form {
  display: none;
  margin-top: 20px;
  padding: 20px;
  border-radius: 14px;
  background: rgba(245,184,0,.05);
  border: 1px solid rgba(245,184,0,.3);
}

.withdraw-form.active { display: block; animation: fadeInUp .3s; }

.withdraw-form h4 {
  margin-bottom: 14px;
  color: var(--gold);
  font-size: 1rem;
}

.withdraw-form .info-amount {
  font-size: .85rem;
  color: var(--muted);
  margin-bottom: 12px;
}

.withdraw-form .info-amount strong { color: var(--gold); font-size: 1.1rem; }

.commission-box {
  margin-top: 14px;
  padding: 14px;
  border-radius: 10px;
  background: rgba(16,185,129,.08);
  border: 1px solid rgba(16,185,129,.25);
  display: none;
}

.commission-box.active { display: block; }

.commission-row {
  display: flex;
  justify-content: space-between;
  padding: 5px 0;
  font-size: .88rem;
}

.commission-row .lbl { color: var(--muted); }
.commission-row .val { font-weight: 700; }
.commission-row .val.neg { color: #FCA5A5; }
.commission-row.total {
  border-top: 2px solid rgba(16,185,129,.3);
  margin-top: 8px;
  padding-top: 10px;
}
.commission-row.total .val { color: #6EE7B7; font-size: 1.15rem; }

/* Help */
.help-box {
  margin-top: 24px;
  padding: 20px;
  border-radius: 14px;
  background: linear-gradient(135deg, rgba(59,130,246,.08), rgba(59,130,246,.02));
  border: 1px solid rgba(59,130,246,.25);
}

.help-box h3 {
  color: #93B4FF;
  margin-bottom: 12px;
  font-size: 1rem;
}

.help-box ul { list-style: none; padding: 0; }

.help-box li {
  padding: 8px 0;
  color: var(--muted);
  font-size: .88rem;
  display: flex;
  gap: 10px;
  line-height: 1.6;
}

.help-box li::before {
  content: "✓";
  color: #10B981;
  font-weight: 800;
  flex-shrink: 0;
}

/* ============================================================
   MENU MOBILE EN BAS
   ============================================================ */
.mobile-nav {
  display: none;
  position: fixed;
  bottom: 0; left: 0; right: 0;
  height: calc(var(--nav-height) + var(--nav-safe-area));
  padding-bottom: var(--nav-safe-area);
  background: rgba(11,14,20,.95);
  backdrop-filter: blur(20px);
  border-top: 1px solid rgba(245,184,0,.15);
  z-index: 9000;
  box-shadow: 0 -8px 30px rgba(0,0,0,.5);
}

.mobile-nav-inner {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  height: var(--nav-height);
  align-items: center;
  padding: 0 8px;
  max-width: 600px;
  margin: 0 auto;
}

.nav-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
  padding: 10px 4px;
  text-decoration: none;
  color: var(--muted);
  transition: all .25s;
  border-radius: 14px;
  position: relative;
  text-transform: uppercase;
}

.nav-item .nav-icon { font-size: 1.5rem; line-height: 1; transition: transform .3s; }
.nav-item .nav-label { font-size: .62rem; font-weight: 700; }
.nav-item.active { color: var(--gold); }
.nav-item.active .nav-icon { transform: scale(1.15) translateY(-2px); filter: drop-shadow(0 0 12px rgba(245,184,0,.6)); }
.nav-item.active::before {
  content: "";
  position: absolute;
  top: 0; left: 50%;
  transform: translateX(-50%);
  width: 32px; height: 3px;
  background: linear-gradient(90deg, #F5B800, #D4AF37);
  border-radius: 0 0 3px 3px;
}

.nav-fab-wrapper {
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
  height: 100%;
}

.nav-fab {
  position: absolute;
  top: -22px;
  width: 60px; height: 60px;
  border-radius: 50%;
  background: linear-gradient(135deg, #F5B800, #D4AF37);
  color: #0B0E14;
  border: 4px solid #0B0E14;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.8rem;
  font-weight: 900;
  box-shadow: 0 10px 30px rgba(245,184,0,.5), 0 0 0 6px rgba(245,184,0,.15);
  transition: all .3s;
  z-index: 2;
}

.nav-fab.active { transform: rotate(45deg); }

.fab-menu {
  display: none;
  position: fixed;
  bottom: calc(var(--nav-height) + var(--nav-safe-area) + 20px);
  left: 50%;
  transform: translateX(-50%) translateY(20px);
  background: linear-gradient(180deg, #1A2130, #121824);
  border-radius: 20px;
  padding: 16px;
  box-shadow: 0 20px 60px rgba(0,0,0,.7);
  border: 1px solid rgba(245,184,0,.25);
  z-index: 8999;
  opacity: 0;
  transition: all .3s;
  min-width: 240px;
}

.fab-menu.open { display: block; opacity: 1; transform: translateX(-50%) translateY(0); }

.fab-menu-item {
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 14px 18px;
  border-radius: 12px;
  color: var(--text);
  text-decoration: none;
  font-size: .9rem;
  font-weight: 700;
  transition: all .2s;
  border: 1px solid transparent;
  width: 100%;
  cursor: pointer;
  background: transparent;
  text-align: left;
}

.fab-menu-item:hover {
  background: rgba(245,184,0,.1);
  border-color: rgba(245,184,0,.3);
  color: var(--gold);
  transform: translateX(4px);
}

.fab-menu-item .ico {
  width: 40px; height: 40px;
  border-radius: 12px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.3rem;
  background: var(--gold-soft);
  color: var(--gold);
}

.fab-menu-item.depot .ico { background: rgba(16,185,129,.15); color: var(--green); }
.fab-menu-item.retrait .ico { background: rgba(239,68,68,.15); color: #FCA5A5; }
.fab-menu-item.virement .ico { background: rgba(59,130,246,.15); color: #93B4FF; }

.fab-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.5);
  backdrop-filter: blur(4px);
  z-index: 8998;
  opacity: 0;
}
.fab-overlay.open { display: block; opacity: 1; }

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 900px) {
  .mobile-nav { display: block; }
}

@media (max-width: 700px) {
  .soldes-duo { grid-template-columns: 1fr; }
}

@media (max-width: 640px) {
  .placement-actions { flex-direction: column; }
  .btn-capital, .btn-interets, .btn-locked { justify-content: center; width: 100%; }
  .rules-grid { grid-template-columns: 1fr 1fr; }
}
</style>
</head>
<body class="dark-auth app-mode">

<?php require __DIR__ . '/../includes/header.php'; ?>

<main class="dash">

  <div class="welcome-head">
    <div>
      <h2>💰 Mon Épargne</h2>
      <p class="sub">Investissez votre capital, laissez-le travailler</p>
    </div>
    <a class="btn-ghost" href="<?= $base ?>/client/dashboard.php">← Tableau de bord</a>
  </div>

  <?php if ($success): ?>
    <div class="alert success"><?= $success ?></div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="alert error">
      <ul style="margin-left:20px">
        <?php foreach ($errors as $e): foreach (explode("\n", $e) as $line): if (trim($line)): ?>
          <li><?= htmlspecialchars(trim($line)) ?></li>
        <?php endif; endforeach; endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <!-- ============ SOLDES DISPONIBLES ET ÉPARGNÉS ============ -->
  <section class="soldes-duo">
    
    <!-- Colonne CDF -->
    <div class="col">
      <div class="solde-card disponible">
        <div class="head">
          <span class="lbl">🇨🇩 Solde disponible</span>
          <span class="flag">💰</span>
        </div>
        <div class="amount">
          <?= number_format((float)$client['solde_cdf'], 2, ',', ' ') ?>
          <span class="devise-badge">CDF</span>
        </div>
      </div>
      
      <div class="solde-card epargne">
        <div class="head">
          <span class="lbl">🇨🇩 Épargne investie</span>
          <span class="flag">📈</span>
        </div>
        <div class="amount">
          <?= number_format((float)$client['solde_epargne_cdf'], 2, ',', ' ') ?>
          <span class="devise-badge">CDF</span>
        </div>
      </div>
    </div>

    <!-- Colonne USD -->
    <div class="col">
      <div class="solde-card disponible">
        <div class="head">
          <span class="lbl">🇺🇸 Solde disponible</span>
          <span class="flag">💰</span>
        </div>
        <div class="amount">
          $ <?= number_format((float)$client['solde_usd'], 2, '.', ',') ?>
          <span class="devise-badge">USD</span>
        </div>
      </div>
      
      <div class="solde-card epargne">
        <div class="head">
          <span class="lbl">🇺🇸 Épargne investie</span>
          <span class="flag">📈</span>
        </div>
        <div class="amount">
          $ <?= number_format((float)$client['solde_epargne_usd'], 2, '.', ',') ?>
          <span class="devise-badge">USD</span>
        </div>
      </div>
    </div>

  </section>

  <!-- ============ RÈGLES ACTUELLES ============ -->
  <div class="rules-banner">
    <div class="rules-title">📋 Conditions d'épargne actuelles</div>
    <div class="rules-grid">
      <div class="rule-card">
        <div class="lbl">💰 Taux d'intérêt</div>
        <div class="val"><?= number_format($taux_actuel, 2) ?>% / an</div>
      </div>
      <div class="rule-card">
        <div class="lbl">🔒 Blocage intérêts</div>
        <div class="val"><?= $duree_blocage ?> mois</div>
      </div>
      <div class="rule-card">
        <div class="lbl">💵 Commission retrait</div>
        <div class="val"><?= number_format($commission_taux, 2) ?>%</div>
      </div>
      <div class="rule-card">
        <div class="lbl">📊 Dépôt minimum</div>
        <div class="val"><?= number_format($montant_min, 0, ',', ' ') ?> CDF</div>
      </div>
    </div>
  </div>

  <!-- ============ FORMULAIRE DE DÉPÔT ============ -->
  <section class="depot-form">
    <h3>➕ Investir dans l'épargne</h3>

    <form method="POST" novalidate autocomplete="off" id="formDepot">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="deposer">

      <div class="grid-2">
        <label class="field">
          <span>💵 Montant à investir *</span>
          <input type="text" name="montant" id="montantInput" required placeholder="Ex: 50000" inputmode="decimal">
        </label>
        <label class="field">
          <span>💱 Devise *</span>
          <select name="devise" id="deviseSelect" required>
            <option value="CDF" selected>🇨🇩 Franc Congolais (CDF)</option>
            <option value="USD">🇺🇸 Dollar Américain (USD)</option>
          </select>
        </label>
      </div>

      <label class="field" style="margin-top:14px">
        <span>📅 Durée du placement *</span>
        <select name="duree_mois" required>
          <option value="6">6 mois</option>
          <option value="12" selected>12 mois (1 an)</option>
          <option value="24">24 mois (2 ans)</option>
          <option value="36">36 mois (3 ans)</option>
          <option value="60">60 mois (5 ans)</option>
        </select>
      </label>

      <div class="depot-preview" id="depotPreview">
        <div class="line">
          <span class="lbl">💰 Montant investi</span>
          <span class="val" id="prevMontant">—</span>
        </div>
        <div class="line">
          <span class="lbl">📊 Taux appliqué</span>
          <span class="val" id="prevTaux"><?= number_format($taux_actuel, 2) ?>% / an</span>
        </div>
        <div class="line">
          <span class="lbl">📅 Durée</span>
          <span class="val" id="prevDuree">—</span>
        </div>
        <div class="line">
          <span class="lbl">💹 Intérêts prévus</span>
          <span class="val green" id="prevInterets">—</span>
        </div>
        <div class="line">
          <span class="lbl">🎯 Valeur à terme</span>
          <span class="val gold" id="prevTotal">—</span>
        </div>
      </div>

      <button class="btn-gold full" type="submit" style="margin-top:16px" id="btnDepot">
        💰 Confirmer l'investissement
      </button>
    </form>
  </section>

  <!-- ============ MES PLACEMENTS ============ -->
  <h2 style="margin:32px 0 20px;font-size:1.3rem">📊 Mes placements (<?= count($placements) ?>)</h2>

  <?php if (empty($placements)): ?>
    <div class="card" style="text-align:center;padding:50px">
      <div style="font-size:4rem;opacity:.4;margin-bottom:16px">💼</div>
      <h3>Aucun placement pour le moment</h3>
      <p class="muted" style="margin-top:8px">Investissez votre premier montant ci-dessus</p>
    </div>
  <?php else: ?>

    <?php foreach ($placements as $p): ?>

      <div class="placement-card <?= $p['statut'] === 'cloture' ? 'cloture' : '' ?>">

        <div class="placement-header">
          <div>
            <span class="placement-ref"><?= htmlspecialchars($p['reference']) ?></span>
            <div style="margin-top:8px;font-size:.85rem;color:var(--muted)">
              <strong><?= htmlspecialchars($p['produit_nom']) ?></strong> · 
              Taux : <strong style="color:var(--gold)"><?= number_format((float)$p['taux_applique'] * 100, 2) ?>%</strong> / an
            </div>
          </div>
          <div style="text-align:right">
            <?php if ($p['statut'] === 'cloture'): ?>
              <span class="badge" style="background:rgba(138,147,166,.2);color:var(--muted)">CLÔTURÉ</span>
            <?php elseif ($p['interets_debloques']): ?>
              <span class="badge-unlocked">✅ Intérêts débloqués</span>
            <?php else: ?>
              <span class="badge-locked">🔒 Intérêts bloqués</span>
            <?php endif; ?>
          </div>
        </div>

        <div class="placement-grid">
          <div class="placement-kpi">
            <div class="lbl">Capital initial</div>
            <div class="val gold"><?= number_format((float)$p['montant_initial'], 2, ',', ' ') ?> <?= $p['devise'] ?></div>
          </div>
          <div class="placement-kpi">
            <div class="lbl">Capital disponible</div>
            <div class="val blue"><?= number_format((float)$p['capital_disponible'], 2, ',', ' ') ?> <?= $p['devise'] ?></div>
          </div>
          <div class="placement-kpi">
            <div class="lbl">Intérêts accumulés</div>
            <div class="val green">+ <?= number_format((float)$p['interets_cumules'], 2, ',', ' ') ?> <?= $p['devise'] ?></div>
          </div>
          <div class="placement-kpi">
            <div class="lbl">Intérêts disponibles</div>
            <div class="val <?= $p['interets_debloques'] ? 'green' : 'muted' ?>">
              <?= $p['interets_debloques']
                  ? number_format((float)$p['interets_disponibles'], 2, ',', ' ') . ' ' . $p['devise']
                  : '🔒 Bloqués' ?>
            </div>
          </div>
        </div>

        <?php if ($p['statut'] === 'actif'): ?>
          <div class="progress-section <?= $p['interets_debloques'] ? 'unlocked' : '' ?>">
            <div class="progress-label">
              <span class="title">
                <?= $p['interets_debloques'] ? '✅ Intérêts débloqués' : '🔒 Déblocage des intérêts' ?>
              </span>
              <span class="days">
                <?php if ($p['interets_debloques']): ?>
                  Depuis le <?= date('d/m/Y', strtotime($p['date_deblocage'])) ?>
                <?php else: ?>
                  Encore <strong><?= $p['jours_restants'] ?> jour(s)</strong>
                <?php endif; ?>
              </span>
            </div>
            <div class="progress-track">
              <div class="progress-fill" style="width: <?= number_format($p['progression'], 1) ?>%"></div>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($p['statut'] === 'actif'): ?>
          <div class="placement-actions">

            <?php if ($p['capital_disponible'] > 0): ?>
              <button class="btn-capital" onclick="toggleWithdraw('capital_<?= (int)$p['id_placement'] ?>')">
                💰 Retirer du capital
              </button>
            <?php endif; ?>

            <?php if ($p['interets_debloques'] && $p['interets_disponibles'] > 0): ?>
              <button class="btn-interets" onclick="toggleWithdraw('interets_<?= (int)$p['id_placement'] ?>')">
                📈 Retirer les intérêts
              </button>
            <?php elseif (!$p['interets_debloques']): ?>
              <button class="btn-locked" disabled>
                🔒 Intérêts bloqués (<?= $p['jours_restants'] ?> j)
              </button>
            <?php endif; ?>

          </div>

          <?php if ($p['capital_disponible'] > 0): ?>
            <div class="withdraw-form" id="capital_<?= (int)$p['id_placement'] ?>">
              <h4>💰 Retrait de capital</h4>
              <div class="info-amount">
                Capital disponible : <strong><?= number_format((float)$p['capital_disponible'], 2, ',', ' ') ?> <?= $p['devise'] ?></strong>
              </div>

              <form method="POST" onsubmit="return confirm('Confirmer le retrait du capital ?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="retirer_capital">
                <input type="hidden" name="id_placement" value="<?= (int)$p['id_placement'] ?>">

                <label class="field">
                  <span>Montant à retirer</span>
                  <input type="text" name="montant" class="withdraw-amount"
                         data-devise="<?= $p['devise'] ?>" data-commission="<?= $commission_taux ?>"
                         required placeholder="0" inputmode="decimal">
                </label>

                <button type="button" class="btn-ghost" style="margin-top:8px;width:100%"
                        onclick="setMax(this, <?= $p['capital_disponible'] ?>)">
                  Utiliser tout le capital (<?= number_format((float)$p['capital_disponible'], 2, ',', ' ') ?> <?= $p['devise'] ?>)
                </button>

                <div class="commission-box">
                  <div class="commission-row">
                    <span class="lbl">Montant demandé</span>
                    <span class="val brut">—</span>
                  </div>
                  <div class="commission-row">
                    <span class="lbl">Commission (<?= number_format($commission_taux, 2) ?>%)</span>
                    <span class="val neg comm">—</span>
                  </div>
                  <div class="commission-row total">
                    <span class="lbl">💵 Crédité sur solde</span>
                    <span class="val net">—</span>
                  </div>
                </div>

                <button class="btn-gold full" type="submit" style="margin-top:14px">
                  ✅ Confirmer le retrait
                </button>
              </form>
            </div>
          <?php endif; ?>

          <?php if ($p['interets_debloques'] && $p['interets_disponibles'] > 0): ?>
            <div class="withdraw-form" id="interets_<?= (int)$p['id_placement'] ?>">
              <h4>📈 Retrait des intérêts</h4>
              <div class="info-amount">
                Intérêts disponibles : <strong><?= number_format((float)$p['interets_disponibles'], 2, ',', ' ') ?> <?= $p['devise'] ?></strong>
              </div>

              <form method="POST" onsubmit="return confirm('Confirmer le retrait des intérêts ?')">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="retirer_interets">
                <input type="hidden" name="id_placement" value="<?= (int)$p['id_placement'] ?>">

                <label class="field">
                  <span>Montant à retirer</span>
                  <input type="text" name="montant" class="withdraw-amount"
                         data-devise="<?= $p['devise'] ?>" data-commission="<?= $commission_taux ?>"
                         required placeholder="0" inputmode="decimal">
                </label>

                <button type="button" class="btn-ghost" style="margin-top:8px;width:100%"
                        onclick="setMax(this, <?= $p['interets_disponibles'] ?>)">
                  Utiliser tous les intérêts (<?= number_format((float)$p['interets_disponibles'], 2, ',', ' ') ?> <?= $p['devise'] ?>)
                </button>

                <div class="commission-box">
                  <div class="commission-row">
                    <span class="lbl">Montant demandé</span>
                    <span class="val brut">—</span>
                  </div>
                  <div class="commission-row">
                    <span class="lbl">Commission (<?= number_format($commission_taux, 2) ?>%)</span>
                    <span class="val neg comm">—</span>
                  </div>
                  <div class="commission-row total">
                    <span class="lbl">💵 Crédité sur solde</span>
                    <span class="val net">—</span>
                  </div>
                </div>

                <button class="btn-gold full" type="submit" style="margin-top:14px">
                  ✅ Confirmer le retrait
                </button>
              </form>
            </div>
          <?php endif; ?>

        <?php endif; ?>

      </div>

    <?php endforeach; ?>

  <?php endif; ?>

  <!-- AIDE -->
  <div class="help-box">
    <h3>💡 Comment ça marche ?</h3>
    <ul>
      <li>Vous investissez depuis votre <strong>solde disponible</strong></li>
      <li>Le montant est <strong>transféré vers votre solde épargne</strong></li>
      <li>Le capital génère des intérêts au taux de <strong><?= number_format($taux_actuel, 2) ?>% par an</strong></li>
      <li><strong>Votre capital reste disponible</strong> — retirable à tout moment</li>
      <li>Les <strong>intérêts sont bloqués <?= $duree_blocage ?> mois</strong></li>
      <li>Chaque retrait entraîne une <strong>commission de <?= number_format($commission_taux, 2) ?>%</strong></li>
    </ul>
  </div>

</main>

<footer class="atf-footer">
  © <?= date('Y') ?> AFRICAN TRUST FUND — L'argent de l'Afrique pour construire l'Afrique
</footer>

<!-- ============================================================
     MENU MOBILE EN BAS
     ============================================================ -->
<nav class="mobile-nav">
  <div class="mobile-nav-inner">

    <a href="<?= $base ?>/client/dashboard.php" class="nav-item">
      <span class="nav-icon">🏠</span>
      <span class="nav-label">Accueil</span>
    </a>

    <a href="<?= $base ?>/client/depot.php" class="nav-item">
      <span class="nav-icon">＋</span>
      <span class="nav-label">Dépôt</span>
    </a>

    <div class="nav-fab-wrapper">
      <button class="nav-fab" id="navFab" type="button" aria-label="Plus d'actions">＋</button>
    </div>

    <a href="<?= $base ?>/client/epargne.php" class="nav-item active">
      <span class="nav-icon">💰</span>
      <span class="nav-label">Épargne</span>
    </a>

    <a href="<?= $base ?>/client/profil.php" class="nav-item">
      <span class="nav-icon">👤</span>
      <span class="nav-label">Profil</span>
    </a>

  </div>
</nav>

<!-- Menu FAB -->
<div class="fab-overlay" id="fabOverlay"></div>

<div class="fab-menu" id="fabMenu">

  <a href="<?= $base ?>/client/retrait.php" class="fab-menu-item retrait">
    <span class="ico">💸</span>
    <span>Retrait</span>
  </a>

  <a href="<?= $base ?>/client/virement.php" class="fab-menu-item virement">
    <span class="ico">⇄</span>
    <span>Virement</span>
  </a>

  <a href="<?= $base ?>/client/transactions.php" class="fab-menu-item">
    <span class="ico">📋</span>
    <span>Historique</span>
  </a>

  <a href="<?= $base ?>/client/releve.php" class="fab-menu-item depot">
    <span class="ico">📄</span>
    <span>Relevé</span>
  </a>

</div>

<script>
const TAUX_ACTUEL = <?= $taux_actuel ?>;
const COMMISSION  = <?= $commission_taux ?>;
const MIN_DEPOT   = <?= $montant_min ?>;
const MAX_DEPOT   = <?= $montant_max ?>;
const SOLDE_CDF   = <?= (float)$client['solde_cdf'] ?>;
const SOLDE_USD   = <?= (float)$client['solde_usd'] ?>;

const formDepot     = document.getElementById('formDepot');
const montantInput  = document.getElementById('montantInput');
const deviseSelect  = document.getElementById('deviseSelect');
const dureeSelect   = formDepot.querySelector('select[name="duree_mois"]');
const preview       = document.getElementById('depotPreview');
const btnDepot      = document.getElementById('btnDepot');

function fmt(val, devise) {
  if (devise === 'USD') return '$ ' + val.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  return val.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' CDF';
}

function updatePreview() {
  const devise = deviseSelect.value;
  const montant = parseFloat(montantInput.value.replace(',', '.')) || 0;
  const duree = parseInt(dureeSelect.value) || 12;

  if (montant <= 0) {
    preview.classList.remove('active');
    return;
  }

  const annees = duree / 12;
  const interets = montant * (TAUX_ACTUEL / 100) * annees;
  const total = montant + interets;

  document.getElementById('prevMontant').textContent = fmt(montant, devise);
  document.getElementById('prevDuree').textContent = duree + ' mois';
  document.getElementById('prevInterets').textContent = '+ ' + fmt(interets, devise);
  document.getElementById('prevTotal').textContent = fmt(total, devise);

  preview.classList.add('active');
}

montantInput.addEventListener('input', (e) => {
  let v = e.target.value.replace(/[^\d.,]/g, '').replace(',', '.');
  const parts = v.split('.');
  if (parts.length > 2) v = parts[0] + '.' + parts.slice(1).join('');
  e.target.value = v;
  updatePreview();
});

deviseSelect.addEventListener('change', updatePreview);
dureeSelect.addEventListener('change', updatePreview);

formDepot.addEventListener('submit', () => {
  btnDepot.disabled = true;
  btnDepot.textContent = '⏳ Traitement...';
});

function toggleWithdraw(id) {
  const form = document.getElementById(id);
  if (!form) return;

  document.querySelectorAll('.withdraw-form.active').forEach(f => {
    if (f.id !== id) f.classList.remove('active');
  });

  form.classList.toggle('active');

  if (form.classList.contains('active')) {
    setTimeout(() => {
      form.scrollIntoView({ behavior: 'smooth', block: 'center' });
      const input = form.querySelector('input[name="montant"]');
      if (input) input.focus();
    }, 100);
  }
}

function setMax(btn, montant) {
  const form = btn.closest('form');
  const input = form.querySelector('input[name="montant"]');
  input.value = montant;
  updateCommission(input);
  input.focus();
}

function updateCommission(input) {
  const form = input.closest('form');
  const box = form.querySelector('.commission-box');
  if (!box) return;

  const devise = input.dataset.devise || 'CDF';
  const tauxComm = parseFloat(input.dataset.commission || 1) / 100;
  const montant = parseFloat(input.value.replace(',', '.')) || 0;

  if (montant <= 0) {
    box.classList.remove('active');
    return;
  }

  const commission = Math.round(montant * tauxComm * 100) / 100;
  const netVerse   = Math.round((montant - commission) * 100) / 100;

  const fmt2 = (v) => {
    if (devise === 'USD') return '$ ' + v.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return v.toLocaleString('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' CDF';
  };

  box.querySelector('.val.brut').textContent = fmt2(montant);
  box.querySelector('.val.comm').textContent = '− ' + fmt2(commission);
  box.querySelector('.val.net').textContent = fmt2(netVerse);
  box.classList.add('active');
}

document.querySelectorAll('.withdraw-amount').forEach(input => {
  input.addEventListener('input', function() {
    let v = this.value.replace(/[^\d.,]/g, '').replace(',', '.');
    const parts = v.split('.');
    if (parts.length > 2) v = parts[0] + '.' + parts.slice(1).join('');
    this.value = v;
    updateCommission(this);
  });
});

/* Menu FAB */
(function() {
  const fab = document.getElementById('navFab');
  const menu = document.getElementById('fabMenu');
  const overlay = document.getElementById('fabOverlay');

  if (!fab || !menu || !overlay) return;

  function toggleMenu() {
    const isOpen = menu.classList.contains('open');
    if (isOpen) {
      menu.classList.remove('open');
      overlay.classList.remove('open');
      fab.classList.remove('active');
    } else {
      menu.classList.add('open');
      overlay.classList.add('open');
      fab.classList.add('active');
    }
  }

  fab.addEventListener('click', toggleMenu);
  overlay.addEventListener('click', toggleMenu);

  menu.querySelectorAll('a').forEach(link => {
    link.addEventListener('click', () => {
      menu.classList.remove('open');
      overlay.classList.remove('open');
      fab.classList.remove('active');
    });
  });
})();

console.log('%c💰 Page épargne ATF chargée', 'color:#F5B800;font-weight:bold;font-size:13px');
console.log('%c📊 Taux actuel : ' + TAUX_ACTUEL + '%', 'color:#6EE7B7;font-size:12px');
</script>

<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>