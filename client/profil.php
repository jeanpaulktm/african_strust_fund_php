<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/prets.php';
requireRole('client');

$pdo = Database::getConnection();
$id  = (int)$_SESSION['client_id'];

$errors  = [];
$success = '';

/* ============================================================
   DÉTECTION AUTOMATIQUE DES COLONNES DISPONIBLES
   ============================================================ */
function getTableColumns(PDO $pdo, string $table): array
{
    $cols = [];
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
        foreach ($stmt->fetchAll() as $row) {
            $cols[] = $row['Field'];
        }
    } catch (Throwable $e) { /* */ }
    return $cols;
}

$colonnes_client = getTableColumns($pdo, 'Client');

/* Fonction helper : est-ce que la colonne existe ? */
$has = fn(string $col): bool => in_array($col, $colonnes_client, true);

/* Colonnes disponibles */
$has_date_naissance  = $has('date_naissance');
$has_lieu_naissance  = $has('lieu_naissance');
$has_profession      = $has('profession');
$has_etat_civil      = $has('etat_civil');
$has_email           = $has('email');
$has_mandataire      = $has('nom_mandataire');

/* ============================================================
   TRAITEMENT MODIFICATION
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!verifyCsrf($_POST['csrf'] ?? null)) {
            throw new RuntimeException("Jeton de sécurité invalide.");
        }

        $adresse = cleanString($_POST['adresse_complete'] ?? '', 500);
        $phone   = cleanString($_POST['numero_phone'] ?? '', 25);

        /* Validations de base */
        if ($adresse === '') $errors[] = "L'adresse est obligatoire.";
        if (!preg_match('/^\+?[0-9 ]{9,20}$/', $phone)) $errors[] = "Numéro de téléphone invalide.";

        /* Construire la requête dynamiquement */
        $updates = ['adresse_complete = ?', 'numero_phone = ?'];
        $params  = [$adresse, $phone];

        /* Email (si colonne existe) */
        if ($has_email) {
            $email = cleanString($_POST['email'] ?? '', 150);
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = "Adresse email invalide.";
            }
            $updates[] = 'email = ?';
            $params[] = $email ?: null;
        }

        /* Date naissance */
        if ($has_date_naissance) {
            $dn = cleanString($_POST['date_naissance'] ?? '', 10);
            if ($dn !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dn)) {
                $errors[] = "Date de naissance invalide.";
            }
            $updates[] = 'date_naissance = ?';
            $params[] = $dn ?: null;
        }

        /* Lieu naissance */
        if ($has_lieu_naissance) {
            $updates[] = 'lieu_naissance = ?';
            $params[] = cleanString($_POST['lieu_naissance'] ?? '', 100) ?: null;
        }

        /* Profession */
        if ($has_profession) {
            $updates[] = 'profession = ?';
            $params[] = cleanString($_POST['profession'] ?? '', 100) ?: null;
        }

        /* État civil */
        if ($has_etat_civil) {
            $etat = in_array($_POST['etat_civil'] ?? '', ['celibataire','marie','divorce','veuf'], true)
                ? $_POST['etat_civil'] : 'celibataire';
            $updates[] = 'etat_civil = ?';
            $params[] = $etat;
        }

        /* Mandataire */
        if ($has_mandataire) {
            $updates[] = 'nom_mandataire = ?';
            $params[] = cleanString($_POST['nom_mandataire'] ?? '', 60) ?: null;
            $updates[] = 'postnom_mandataire = ?';
            $params[] = cleanString($_POST['postnom_mandataire'] ?? '', 60) ?: null;
            $updates[] = 'numero_mandataire = ?';
            $params[] = cleanString($_POST['numero_mandataire'] ?? '', 25) ?: null;
            $updates[] = 'adresse_compte_mandataire = ?';
            $params[] = cleanString($_POST['adresse_mandataire'] ?? '', 500) ?: null;
        }

        /* Photo */
        $photo = null;
        if (!empty($_FILES['photo_profil']['name'])) {
            try {
                $photo = handleSecureUpload('photo_profil', 'pp_', __DIR__ . '/../uploads');
                $updates[] = 'photo_profil = ?';
                $params[] = $photo;
            } catch (Throwable $e) {
                $errors[] = "Erreur photo : " . $e->getMessage();
            }
        }

        if (!$errors) {
            $sql = "UPDATE Client SET " . implode(', ', $updates) . " WHERE id_client = ?";
            $params[] = $id;
            $pdo->prepare($sql)->execute($params);

            logAction($pdo, 'client', $id, 'PROFIL_UPDATE', 'Mise à jour du profil');
            $success = "✅ Profil mis à jour avec succès !";
        }

    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

/* ============================================================
   RECHARGEMENT CLIENT
   ============================================================ */
$stmt = $pdo->prepare("SELECT * FROM Client WHERE id_client = ?");
$stmt->execute([$id]);
$client = $stmt->fetch();

if (!$client) refuserAcces("Compte introuvable.");

$initiales = strtoupper(mb_substr($client['nom'], 0, 1) . mb_substr($client['prenom'] ?: $client['postnom'], 0, 1));

/* ============================================================
   SOLDES DISPONIBLES
   ============================================================ */
$solde_cdf = (float)($client['solde_cdf'] ?? 0);
$solde_usd = (float)($client['solde_usd'] ?? 0);
$taux_usd_cdf = 2800;
$solde_total_cdf = $solde_cdf + ($solde_usd * $taux_usd_cdf);

/* ============================================================
   CAPITAL INVESTI EN ÉPARGNE
   ============================================================ */
$stmt = $pdo->prepare("
    SELECT 
        COUNT(CASE WHEN statut = 'actif' THEN 1 END) AS nb_actifs,
        COALESCE(SUM(CASE WHEN devise = 'CDF' AND statut = 'actif' THEN montant_initial ELSE 0 END), 0) AS capital_cdf,
        COALESCE(SUM(CASE WHEN devise = 'USD' AND statut = 'actif' THEN montant_initial ELSE 0 END), 0) AS capital_usd,
        COALESCE(SUM(interets_cumules), 0) AS interets_cumules,
        COALESCE(SUM(interets_retires), 0) AS interets_retires
    FROM Placements WHERE id_client = ?
");
$stmt->execute([$id]);
$stats_epargne = $stmt->fetch();

$capital_cdf = (float)$stats_epargne['capital_cdf'];
$capital_usd = (float)$stats_epargne['capital_usd'];
$interets_cumules = (float)$stats_epargne['interets_cumules'];
$interets_retires = (float)$stats_epargne['interets_retires'];
$interets_restants = $interets_cumules - $interets_retires;

/* Intérêts disponibles */
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(interets_cumules - interets_retires), 0)
    FROM Placements
    WHERE id_client = ? AND statut = 'actif' AND date_deblocage_interets <= CURDATE()
");
$stmt->execute([$id]);
$interets_disponibles = (float)$stmt->fetchColumn();

/* Intérêts bloqués */
$stmt = $pdo->prepare("
    SELECT 
        COALESCE(SUM(interets_cumules - interets_retires), 0) AS bloque,
        MIN(DATEDIFF(date_deblocage_interets, CURDATE())) AS jours_min
    FROM Placements
    WHERE id_client = ? AND statut = 'actif' AND date_deblocage_interets > CURDATE()
");
$stmt->execute([$id]);
$int_info = $stmt->fetch();
$interets_bloques = (float)($int_info['bloque'] ?? 0);
$jours_prochain_deblocage = (int)($int_info['jours_min'] ?? 0);

$capital_total_equiv_cdf = $capital_cdf + ($capital_usd * $taux_usd_cdf);

/* ============================================================
   🆕 STATISTIQUES DES PRÊTS
   ============================================================ */
$stats_prets = [
    'nb_total'      => 0,
    'nb_actifs'     => 0,
    'nb_en_attente' => 0,
    'nb_retard'     => 0,
    'nb_soldes'     => 0,
    'encours_cdf'   => 0,
    'encours_usd'   => 0,
    'total_emprunte'=> 0,
    'total_paye'    => 0,
];

try {
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) AS nb_total,
            COUNT(CASE WHEN statut IN ('decaisse','en_cours') THEN 1 END) AS nb_actifs,
            COUNT(CASE WHEN statut IN ('soumis','en_analyse','approuve') THEN 1 END) AS nb_en_attente,
            COUNT(CASE WHEN statut = 'en_retard' THEN 1 END) AS nb_retard,
            COUNT(CASE WHEN statut = 'solde' THEN 1 END) AS nb_soldes,
            COALESCE(SUM(CASE WHEN statut IN ('decaisse','en_cours','en_retard') AND devise='CDF' THEN montant_restant_du ELSE 0 END), 0) AS encours_cdf,
            COALESCE(SUM(CASE WHEN statut IN ('decaisse','en_cours','en_retard') AND devise='USD' THEN montant_restant_du ELSE 0 END), 0) AS encours_usd,
            COALESCE(SUM(CASE WHEN statut NOT IN ('rejete','annule') THEN montant_accorde ELSE 0 END), 0) AS total_emprunte,
            COALESCE(SUM(montant_paye), 0) AS total_paye
        FROM Prets WHERE id_client = ?
    ");
    $stmt->execute([$id]);
    $stats_prets = $stmt->fetch();
} catch (Throwable $e) { /* table absente */ }

/* Prêt actif principal */
$pret_actif = null;
$prochaine_echeance = null;
try {
    $stmt = $pdo->prepare("
        SELECT p.*, pr.nom AS produit_nom
        FROM Prets p
        JOIN Produits_Pret pr ON pr.id_produit = p.id_produit
        WHERE p.id_client = ? AND p.statut IN ('decaisse','en_cours','en_retard')
        ORDER BY p.date_decaissement DESC LIMIT 1
    ");
    $stmt->execute([$id]);
    $pret_actif = $stmt->fetch();

    if ($pret_actif) {
        $stmt = $pdo->prepare("
            SELECT * FROM Echeances_Pret
            WHERE id_pret = ? AND statut IN ('en_attente','partiel','retard')
            ORDER BY date_echeance ASC LIMIT 1
        ");
        $stmt->execute([(int)$pret_actif['id_pret']]);
        $prochaine_echeance = $stmt->fetch();

        $pret_actif['progression'] = (float)($pret_actif['montant_total_du'] ?? 0) > 0
            ? min(100, ((float)$pret_actif['montant_paye'] / (float)$pret_actif['montant_total_du']) * 100)
            : 0;
    }
} catch (Throwable $e) { /* */ }

/* Demande en cours */
$demande_en_cours = null;
try {
    $stmt = $pdo->prepare("
        SELECT p.*, pr.nom AS produit_nom
        FROM Prets p
        JOIN Produits_Pret pr ON pr.id_produit = p.id_produit
        WHERE p.id_client = ? AND p.statut IN ('soumis','en_analyse','approuve')
        ORDER BY p.date_demande DESC LIMIT 1
    ");
    $stmt->execute([$id]);
    $demande_en_cours = $stmt->fetch();
} catch (Throwable $e) { /* */ }

/* Patrimoine total */
$patrimoine_total = $solde_total_cdf + $capital_total_equiv_cdf + $interets_restants;

$base = '/african-trust-fund';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0,maximum-scale=1.0,user-scalable=no">
<meta name="theme-color" content="#0B0E14">
<title>Mon Profil — AFRICAN TRUST FUND</title>
<link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
<style>
/* ============================================================
   VARIABLES NAV MOBILE
   ============================================================ */
:root {
  --nav-height: 76px;
  --nav-safe-area: env(safe-area-inset-bottom, 0px);
}
body.app-mode { padding-bottom: calc(var(--nav-height) + var(--nav-safe-area) + 20px); }
@media (max-width: 900px) { .atf-header .atf-nav { display: none; } }

/* ============================================================
   SOLDES DISPONIBLES
   ============================================================ */
.soldes-profil { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-top: 16px; }
.solde-profil-card {
  padding: 18px 20px;
  border-radius: 14px;
  position: relative;
  overflow: hidden;
  border: 1px solid var(--border);
}
.solde-profil-card::before {
  content: "";
  position: absolute;
  top: 0; left: 0;
  width: 4px; height: 100%;
}
.solde-profil-card.usd {
  background: linear-gradient(145deg, rgba(59,130,246,.10), rgba(59,130,246,.02));
  border-color: rgba(59,130,246,.3);
}
.solde-profil-card.usd::before { background: #3B82F6; }
.solde-profil-card.cdf {
  background: linear-gradient(145deg, rgba(245,184,0,.10), rgba(245,184,0,.02));
  border-color: rgba(245,184,0,.3);
}
.solde-profil-card.cdf::before { background: #F5B800; }
.solde-profil-card .head { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
.solde-profil-card .flag { font-size: 1.3rem; }
.solde-profil-card .lbl {
  font-size: .72rem;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 1.2px;
  font-weight: 700;
}
.solde-profil-card .val { font-size: 1.5rem; font-weight: 800; line-height: 1.1; }
.solde-profil-card.usd .val { color: #93B4FF; }
.solde-profil-card.cdf .val { color: var(--gold); }
.solde-profil-card .devise {
  font-size: .82rem;
  color: var(--muted);
  font-weight: 600;
  margin-left: 4px;
}

.total-consolide {
  margin-top: 18px;
  padding: 18px 22px;
  border-radius: 14px;
  background: linear-gradient(135deg, rgba(16,185,129,.12), rgba(16,185,129,.02));
  border: 1px solid rgba(16,185,129,.3);
  text-align: center;
}
.total-consolide .lbl {
  color: #6EE7B7;
  font-size: .75rem;
  text-transform: uppercase;
  letter-spacing: 1.5px;
  font-weight: 700;
}
.total-consolide .val { font-size: 1.9rem; font-weight: 800; color: #6EE7B7; margin-top: 6px; }
.total-consolide .sub { color: var(--muted); font-size: .78rem; margin-top: 4px; }

/* ============================================================
   BLOC ÉPARGNE
   ============================================================ */
.epargne-bloc {
  padding: 22px 24px;
  border-radius: 16px;
  background: linear-gradient(135deg, rgba(139,92,246,.12), rgba(76,29,149,.04)),
              radial-gradient(circle at 90% 20%, rgba(167,139,250,.15), transparent 60%);
  border: 1px solid rgba(139,92,246,.35);
  margin-bottom: 20px;
  position: relative;
  overflow: hidden;
}
.epargne-bloc::before {
  content: "";
  position: absolute;
  top: -30%; right: -5%;
  width: 240px; height: 240px;
  border-radius: 50%;
  background: radial-gradient(circle, rgba(139,92,246,.2), transparent 70%);
}
.epargne-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 16px;
  position: relative;
  z-index: 1;
}
.epargne-header h3 {
  color: #C4B5FD;
  font-size: 1.05rem;
  margin: 0;
  display: flex;
  align-items: center;
  gap: 10px;
}
.epargne-badge {
  padding: 4px 12px;
  border-radius: 999px;
  background: rgba(139,92,246,.2);
  color: #C4B5FD;
  border: 1px solid rgba(139,92,246,.4);
  font-size: .72rem;
  font-weight: 700;
}
.epargne-total {
  display: flex;
  align-items: baseline;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 18px;
  position: relative;
  z-index: 1;
}
.epargne-total .val { font-size: 2rem; font-weight: 900; color: #A78BFA; letter-spacing: -1px; line-height: 1; }
.epargne-total .dev { font-size: .95rem; color: #C4B5FD; font-weight: 700; }
.epargne-total .equiv { font-size: .82rem; color: var(--muted); margin-left: 8px; }

.epargne-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 12px;
  position: relative;
  z-index: 1;
}
.epargne-kpi {
  padding: 14px 16px;
  border-radius: 12px;
  background: rgba(11,14,20,.5);
  border: 1px solid rgba(139,92,246,.2);
}
.epargne-kpi .lbl {
  font-size: .68rem;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 1px;
  font-weight: 700;
}
.epargne-kpi .val { font-size: 1.15rem; font-weight: 800; color: #C4B5FD; margin-top: 6px; line-height: 1.1; }
.epargne-kpi .sub { font-size: .7rem; color: var(--muted); margin-top: 4px; }
.epargne-kpi.gold .val { color: var(--gold); }
.epargne-kpi.blue .val { color: #93B4FF; }
.epargne-kpi.green .val { color: #6EE7B7; }
.epargne-kpi.yellow .val { color: #FCD34D; }

/* ============================================================
   🆕 BLOC PRÊTS
   ============================================================ */
.prets-bloc {
  padding: 22px 24px;
  border-radius: 16px;
  background: linear-gradient(135deg, rgba(59,130,246,.12), rgba(30,64,175,.04)),
              radial-gradient(circle at 90% 20%, rgba(59,130,246,.15), transparent 60%);
  border: 1px solid rgba(59,130,246,.35);
  margin-bottom: 20px;
  position: relative;
  overflow: hidden;
}
.prets-bloc::before {
  content: "";
  position: absolute;
  top: -30%; right: -5%;
  width: 240px; height: 240px;
  border-radius: 50%;
  background: radial-gradient(circle, rgba(59,130,246,.2), transparent 70%);
}
.prets-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 16px;
  position: relative;
  z-index: 1;
}
.prets-header h3 {
  color: #93B4FF;
  font-size: 1.05rem;
  margin: 0;
  display: flex;
  align-items: center;
  gap: 10px;
}
.pret-badge {
  padding: 4px 12px;
  border-radius: 999px;
  background: rgba(59,130,246,.2);
  color: #93B4FF;
  border: 1px solid rgba(59,130,246,.4);
  font-size: .72rem;
  font-weight: 700;
}
.prets-kpis {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
  gap: 12px;
  position: relative;
  z-index: 1;
}
.pret-kpi {
  padding: 14px 16px;
  border-radius: 12px;
  background: rgba(11,14,20,.5);
  border: 1px solid rgba(59,130,246,.2);
  text-align: center;
}
.pret-kpi .lbl {
  font-size: .68rem;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 1px;
  font-weight: 700;
}
.pret-kpi .val { font-size: 1.15rem; font-weight: 800; color: #93B4FF; margin-top: 6px; line-height: 1.1; }
.pret-kpi.gold .val { color: var(--gold); }
.pret-kpi.green .val { color: #6EE7B7; }
.pret-kpi.red .val { color: #FCA5A5; }
.pret-kpi.purple .val { color: #A78BFA; }

/* Prêt actif */
.pret-actif-card {
  margin-top: 16px;
  padding: 16px;
  border-radius: 12px;
  background: rgba(11,14,20,.55);
  border: 1px solid rgba(59,130,246,.25);
  position: relative;
  z-index: 1;
}
.pret-actif-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 12px;
}
.pret-actif-card .title { font-weight: 800; color: #fff; font-size: .95rem; }
.pret-actif-card .ref { font-family: monospace; color: #93B4FF; font-size: .75rem; margin-top: 2px; }
.pret-progress-bar {
  height: 8px;
  background: rgba(0,0,0,.4);
  border-radius: 999px;
  overflow: hidden;
  margin: 10px 0 6px;
}
.pret-progress-bar > div {
  height: 100%;
  background: linear-gradient(90deg, #3B82F6, #60A5FA);
  border-radius: 999px;
  transition: width .6s;
}
.pret-info-row {
  display: flex;
  justify-content: space-between;
  font-size: .78rem;
  color: var(--muted);
  flex-wrap: wrap;
  gap: 8px;
}
.pret-info-row strong { color: #93B4FF; }
.next-ech-badge {
  margin-top: 12px;
  padding: 10px 14px;
  border-radius: 10px;
  background: rgba(245,184,0,.08);
  border: 1px solid rgba(245,184,0,.3);
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}
.next-ech-badge.urgent { background: rgba(239,68,68,.08); border-color: rgba(239,68,68,.4); }
.next-ech-badge .lbl {
  color: var(--muted);
  font-size: .7rem;
  text-transform: uppercase;
  font-weight: 700;
}
.next-ech-badge .val { font-size: 1rem; font-weight: 800; color: #FCD34D; }
.next-ech-badge.urgent .val { color: #FCA5A5; }

.pret-actions {
  margin-top: 14px;
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  position: relative;
  z-index: 1;
}
.btn-pret {
  flex: 1;
  min-width: 130px;
  padding: 10px 16px;
  border-radius: 999px;
  font-weight: 700;
  font-size: .8rem;
  text-decoration: none;
  text-align: center;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  transition: all .25s;
}
.btn-pret-primary {
  background: linear-gradient(135deg, #3B82F6, #2563EB);
  color: #fff;
}
.btn-pret-primary:hover { transform: translateY(-2px); color: #fff; }
.btn-pret-gold {
  background: linear-gradient(135deg, #F5B800, #D4AF37);
  color: #0B0E14;
}
.btn-pret-gold:hover { transform: translateY(-2px); color: #0B0E14; }
.btn-pret-ghost {
  background: rgba(255,255,255,.08);
  color: var(--text);
  border: 1px solid var(--border);
}
.btn-pret-ghost:hover { background: rgba(255,255,255,.14); }

/* ============================================================
   NAV MOBILE
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
  grid-template-columns: 1fr 1fr 1fr 1fr 1fr;
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
  padding: 10px 6px;
  text-decoration: none;
  color: var(--muted);
  font-weight: 700;
  text-transform: uppercase;
  border-radius: 14px;
  position: relative;
}
.nav-item .nav-icon { font-size: 1.5rem; line-height: 1; }
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

.nav-fab-wrapper { position: relative; display: flex; align-items: center; justify-content: center; height: 100%; }
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

.fab-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.5);
  backdrop-filter: blur(4px);
  z-index: 8998;
}
.fab-overlay.open { display: block; }

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 900px) {
  .mobile-nav { display: block; }
  .dash { padding: 16px 14px !important; }
}
@media (max-width: 640px) {
  .soldes-profil { grid-template-columns: 1fr; }
  .epargne-grid { grid-template-columns: 1fr 1fr; }
  .prets-kpis { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 480px) {
  .nav-item .nav-icon { font-size: 1.3rem; }
  .nav-item .nav-label { font-size: .58rem; }
  .nav-fab { width: 54px; height: 54px; font-size: 1.6rem; }
  .epargne-total .val { font-size: 1.6rem; }
  .epargne-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body class="dark-auth app-mode">

<?php require __DIR__ . '/../includes/header.php'; ?>

<main class="dash">

  <!-- EN-TÊTE PROFIL -->
  <div class="profil-header">
    <?php if (!empty($client['photo_profil']) && file_exists(__DIR__ . '/../' . $client['photo_profil'])): ?>
      <img class="profil-photo" src="<?= $base ?>/<?= htmlspecialchars($client['photo_profil']) ?>" alt="Photo profil">
    <?php else: ?>
      <div class="profil-photo-placeholder"><?= $initiales ?></div>
    <?php endif; ?>

    <div class="profil-info">
      <h2><?= htmlspecialchars($client['nom'] . ' ' . $client['postnom'] . ' ' . $client['prenom']) ?></h2>
      <p class="compte"><?= htmlspecialchars($client['numero_compte']) ?></p>
      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px">
        <span class="badge <?= $client['statut'] === 'actif' ? 'green' : 'red' ?>">
          ● <?= htmlspecialchars($client['statut']) ?>
        </span>
        <span class="badge gold">💼 Multi-devises</span>
      </div>
    </div>
  </div>

  <!-- MESSAGES -->
  <?php if ($success): ?>
    <div class="alert success"><?= $success ?></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="alert error">
      <ul><?php foreach ($errors as $e) echo "<li>" . htmlspecialchars($e) . "</li>"; ?></ul>
    </div>
  <?php endif; ?>

  <!-- SOLDES -->
  <section class="card" style="border-color:var(--border-strong);box-shadow:0 0 30px rgba(245,184,0,.10)">
    <h3 style="margin-bottom:8px">💰 Mes soldes disponibles</h3>
    <p class="muted" style="font-size:.85rem;margin-bottom:14px">
      Vos soldes en <strong style="color:#93B4FF">Dollars</strong> et en 
      <strong style="color:var(--gold)">Francs Congolais</strong> sont gérés séparément.
    </p>

    <div class="soldes-profil">
      <div class="solde-profil-card usd">
        <div class="head">
          <span class="flag">🇺🇸</span>
          <span class="lbl">Solde disponible USD</span>
        </div>
        <div class="val">
          $ <?= number_format($solde_usd, 2, '.', ',') ?>
          <span class="devise">USD</span>
        </div>
      </div>
      <div class="solde-profil-card cdf">
        <div class="head">
          <span class="flag">🇨🇩</span>
          <span class="lbl">Solde disponible CDF</span>
        </div>
        <div class="val">
          <?= number_format($solde_cdf, 2, ',', ' ') ?>
          <span class="devise">CDF</span>
        </div>
      </div>
    </div>

    <div class="total-consolide">
      <div class="lbl">💰 Total consolidé (équivalent CDF)</div>
      <div class="val"><?= number_format($solde_total_cdf, 2, ',', ' ') ?> CDF</div>
      <div class="sub">≈ $ <?= number_format($solde_total_cdf / $taux_usd_cdf, 2, '.', ',') ?> USD</div>
    </div>
  </section>

  <!-- CAPITAL INVESTI -->
  <section class="epargne-bloc">
    <div class="epargne-header">
      <h3>💎 Mon capital investi en épargne</h3>
      <span class="epargne-badge">
        <?= (int)$stats_epargne['nb_actifs'] ?> placement(s) actif(s)
      </span>
    </div>

    <div class="epargne-total">
      <span class="val"><?= number_format($capital_total_equiv_cdf, 0, ',', ' ') ?></span>
      <span class="dev">CDF</span>
      <?php if ($capital_usd > 0): ?>
        <span class="equiv">≈ $ <?= number_format($capital_total_equiv_cdf / $taux_usd_cdf, 0, '.', ',') ?> USD</span>
      <?php endif; ?>
    </div>

    <div class="epargne-grid">
      <div class="epargne-kpi gold">
        <div class="lbl">💰 Capital CDF</div>
        <div class="val"><?= number_format($capital_cdf, 0, ',', ' ') ?></div>
        <div class="sub">Investi en francs</div>
      </div>
      <div class="epargne-kpi blue">
        <div class="lbl">💵 Capital USD</div>
        <div class="val">$ <?= number_format($capital_usd, 2, '.', ',') ?></div>
        <div class="sub">Investi en dollars</div>
      </div>
      <div class="epargne-kpi green">
        <div class="lbl">📈 Intérêts cumulés</div>
        <div class="val">+<?= number_format($interets_cumules, 0, ',', ' ') ?></div>
        <div class="sub">Depuis le début</div>
      </div>
      <div class="epargne-kpi yellow">
        <div class="lbl">🔒 Intérêts bloqués</div>
        <div class="val"><?= number_format($interets_bloques, 0, ',', ' ') ?></div>
        <div class="sub">
          <?php if ($jours_prochain_deblocage > 0): ?>
            dans <?= $jours_prochain_deblocage ?> j
          <?php else: ?>
            aucun
          <?php endif; ?>
        </div>
      </div>
      <div class="epargne-kpi green">
        <div class="lbl">✅ Intérêts dispo</div>
        <div class="val">+<?= number_format($interets_disponibles, 0, ',', ' ') ?></div>
        <div class="sub">Retirables</div>
      </div>
      <div class="epargne-kpi">
        <div class="lbl">💸 Intérêts retirés</div>
        <div class="val"><?= number_format($interets_retires, 0, ',', ' ') ?></div>
        <div class="sub">Déjà perçus</div>
      </div>
    </div>

    <div class="total-consolide" style="margin-top:16px;background:linear-gradient(135deg, rgba(139,92,246,.15), rgba(139,92,246,.03));border-color:rgba(139,92,246,.4)">
      <div class="lbl" style="color:#C4B5FD">🏆 Patrimoine total (disponible + épargne)</div>
      <div class="val" style="color:#C4B5FD"><?= number_format($patrimoine_total, 2, ',', ' ') ?> CDF</div>
      <div class="sub">≈ $ <?= number_format($patrimoine_total / $taux_usd_cdf, 2, '.', ',') ?> USD</div>
    </div>
  </section>

  <!-- ============================================================
       🆕 BLOC PRÊTS
       ============================================================ -->
  <section class="prets-bloc">
    <div class="prets-header">
      <h3>💳 Mes prêts</h3>
      <span class="pret-badge">
        <?= (int)$stats_prets['nb_total'] ?> dossier(s)
      </span>
    </div>

    <div class="prets-kpis">
      <div class="pret-kpi">
        <div class="lbl">💰 Encours CDF</div>
        <div class="val"><?= number_format((float)$stats_prets['encours_cdf'], 0, ',', ' ') ?></div>
      </div>
      <div class="pret-kpi">
        <div class="lbl">💵 Encours USD</div>
        <div class="val">$ <?= number_format((float)$stats_prets['encours_usd'], 2, '.', ',') ?></div>
      </div>
      <div class="pret-kpi <?= (int)$stats_prets['nb_retard'] > 0 ? 'red' : 'green' ?>">
        <div class="lbl">⚠️ En retard</div>
        <div class="val"><?= (int)$stats_prets['nb_retard'] ?></div>
      </div>
      <div class="pret-kpi gold">
        <div class="lbl">🎉 Soldés</div>
        <div class="val"><?= (int)$stats_prets['nb_soldes'] ?></div>
      </div>
      <?php if ((int)$stats_prets['nb_en_attente'] > 0): ?>
        <div class="pret-kpi purple">
          <div class="lbl">⏳ En attente</div>
          <div class="val"><?= (int)$stats_prets['nb_en_attente'] ?></div>
        </div>
      <?php endif; ?>
    </div>

    <!-- Prêt actif -->
    <?php if ($pret_actif): ?>
      <div class="pret-actif-card">
        <div class="pret-actif-head">
          <div>
            <div class="title"><?= htmlspecialchars($pret_actif['produit_nom']) ?></div>
            <div class="ref"><?= htmlspecialchars($pret_actif['reference']) ?></div>
          </div>
          <div style="text-align:right">
            <div style="font-size:.7rem;color:var(--muted);text-transform:uppercase;font-weight:700">Montant emprunté</div>
            <div style="font-size:1.1rem;font-weight:800;color:var(--gold)">
              <?= htmlspecialchars(formaterMontant((float)$pret_actif['montant_accorde'], $pret_actif['devise'])) ?>
            </div>
          </div>
        </div>

        <div class="pret-info-row">
          <span>💰 Remboursé : <strong><?= htmlspecialchars(formaterMontant((float)$pret_actif['montant_paye'], $pret_actif['devise'])) ?></strong></span>
          <span>📊 Restant : <strong style="color:#FCA5A5"><?= htmlspecialchars(formaterMontant((float)$pret_actif['montant_restant_du'], $pret_actif['devise'])) ?></strong></span>
        </div>

        <div class="pret-progress-bar">
          <div style="width:<?= number_format((float)$pret_actif['progression'], 1) ?>%"></div>
        </div>

        <div class="pret-info-row">
          <span>Progression : <strong><?= number_format((float)$pret_actif['progression'], 1) ?>%</strong></span>
          <span>Durée : <strong><?= (int)$pret_actif['duree_mois'] ?> mois</strong></span>
        </div>

        <?php if ($prochaine_echeance): 
          $jours = (int)floor((strtotime($prochaine_echeance['date_echeance']) - time()) / 86400);
          $urgent = $jours <= 3;
        ?>
          <div class="next-ech-badge <?= $urgent ? 'urgent' : '' ?>">
            <div>
              <div class="lbl">📅 Prochaine échéance</div>
              <div style="color:var(--muted);font-size:.78rem;margin-top:2px">
                <?= date('d/m/Y', strtotime($prochaine_echeance['date_echeance'])) ?>
                <?php if ($jours > 0): ?> · dans <strong><?= $jours ?>j</strong>
                <?php elseif ($jours === 0): ?> · <strong>AUJOURD'HUI</strong>
                <?php else: ?> · <strong>RETARD <?= abs($jours) ?>j</strong><?php endif; ?>
              </div>
            </div>
            <div style="text-align:right">
              <div class="lbl">Montant dû</div>
              <div class="val"><?= htmlspecialchars(formaterMontant((float)$prochaine_echeance['montant_total'], $pret_actif['devise'])) ?></div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <!-- Demande en attente -->
    <?php if ($demande_en_cours): ?>
      <div class="pret-actif-card" style="border-color:rgba(245,184,0,.4);background:rgba(245,184,0,.05)">
        <div class="pret-actif-head">
          <div>
            <div class="title" style="color:#FCD34D">⏳ Demande en attente</div>
            <div class="ref"><?= htmlspecialchars($demande_en_cours['reference']) ?></div>
          </div>
          <div style="text-align:right">
            <div style="font-size:.7rem;color:var(--muted);text-transform:uppercase;font-weight:700">Montant demandé</div>
            <div style="font-size:1.1rem;font-weight:800;color:#FCD34D">
              <?= htmlspecialchars(formaterMontant((float)$demande_en_cours['montant_demande'], $demande_en_cours['devise'])) ?>
            </div>
          </div>
        </div>
        <div class="pret-info-row" style="margin-top:8px">
          <span>Statut : <strong style="color:#FCD34D"><?= strtoupper(str_replace('_', ' ', $demande_en_cours['statut'])) ?></strong></span>
          <span>Date : <strong><?= date('d/m/Y', strtotime($demande_en_cours['date_demande'])) ?></strong></span>
        </div>
      </div>
    <?php endif; ?>

    <div class="pret-actions">
      <a class="btn-pret btn-pret-primary" href="<?= $base ?>/client/mes_prets.php">
        💳 Voir tous mes prêts
      </a>
      <?php if (!$demande_en_cours && !$pret_actif): ?>
        <a class="btn-pret btn-pret-gold" href="<?= $base ?>/client/demande_pret.php">
          ➕ Demander un prêt
        </a>
      <?php endif; ?>
    </div>
  </section>

  <!-- INFO COMPTE -->
  <section class="card">
    <h3 style="margin-bottom:18px">🔒 Informations verrouillées</h3>
    <div class="grid-2">
      <div class="field">
        <span>Numéro de compte</span>
        <input type="text" value="<?= htmlspecialchars($client['numero_compte']) ?>" disabled>
      </div>
      <div class="field">
        <span>Nom</span>
        <input type="text" value="<?= htmlspecialchars($client['nom']) ?>" disabled>
      </div>
      <div class="field">
        <span>Post-nom</span>
        <input type="text" value="<?= htmlspecialchars($client['postnom']) ?>" disabled>
      </div>
      <div class="field">
        <span>Prénom</span>
        <input type="text" value="<?= htmlspecialchars($client['prenom']) ?>" disabled>
      </div>
      <div class="field">
        <span>Solde USD</span>
        <input type="text" value="$ <?= number_format($solde_usd, 2, '.', ',') ?>" disabled>
      </div>
      <div class="field">
        <span>Solde CDF</span>
        <input type="text" value="<?= number_format($solde_cdf, 2, ',', ' ') ?> CDF" disabled>
      </div>
    </div>
  </section>

  <!-- FORMULAIRE MODIFICATION -->
  <section class="card">
    <h3 style="margin-bottom:18px">✏️ Modifier mes informations</h3>

    <form method="POST" enctype="multipart/form-data" novalidate>
      <?= csrfField() ?>

      <fieldset>
        <legend>📞 Coordonnées</legend>
        <label class="field">
          <span>Adresse complète *</span>
          <textarea name="adresse_complete" rows="2" required maxlength="500"><?= htmlspecialchars($client['adresse_complete'] ?? '') ?></textarea>
        </label>
        <div class="grid-2">
          <label class="field">
            <span>Téléphone *</span>
            <input type="text" name="numero_phone" required maxlength="25" value="<?= htmlspecialchars($client['numero_phone'] ?? '') ?>">
          </label>
          <?php if ($has_email): ?>
            <label class="field">
              <span>Email</span>
              <input type="email" name="email" maxlength="150" value="<?= htmlspecialchars($client['email'] ?? '') ?>" placeholder="exemple@mail.com">
            </label>
          <?php endif; ?>
        </div>
        <label class="field">
          <span>Photo de profil (JPG/PNG, max 3 Mo)</span>
          <input type="file" name="photo_profil" accept="image/*">
        </label>
      </fieldset>

      <?php if ($has_date_naissance || $has_lieu_naissance || $has_profession || $has_etat_civil): ?>
        <fieldset>
          <legend>👤 Informations personnelles</legend>
          <div class="grid-2">
            <?php if ($has_date_naissance): ?>
              <label class="field">
                <span>Date de naissance</span>
                <input type="date" name="date_naissance" value="<?= htmlspecialchars($client['date_naissance'] ?? '') ?>">
              </label>
            <?php endif; ?>
            <?php if ($has_lieu_naissance): ?>
              <label class="field">
                <span>Lieu de naissance</span>
                <input type="text" name="lieu_naissance" maxlength="100" value="<?= htmlspecialchars($client['lieu_naissance'] ?? '') ?>" placeholder="Ville, Province">
              </label>
            <?php endif; ?>
            <?php if ($has_profession): ?>
              <label class="field">
                <span>Profession</span>
                <input type="text" name="profession" maxlength="100" value="<?= htmlspecialchars($client['profession'] ?? '') ?>" placeholder="Ex: Commerçant">
              </label>
            <?php endif; ?>
            <?php if ($has_etat_civil): ?>
              <label class="field">
                <span>État civil</span>
                <select name="etat_civil">
                  <?php
                    $etats = [
                      'celibataire' => 'Célibataire',
                      'marie'       => 'Marié(e)',
                      'divorce'     => 'Divorcé(e)',
                      'veuf'        => 'Veuf(ve)'
                    ];
                    $current = $client['etat_civil'] ?? 'celibataire';
                    foreach ($etats as $val => $lbl):
                  ?>
                    <option value="<?= $val ?>" <?= $current === $val ? 'selected' : '' ?>><?= $lbl ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
            <?php endif; ?>
          </div>
        </fieldset>
      <?php endif; ?>

      <?php if ($has_mandataire): ?>
        <fieldset>
          <legend>👥 Mandataire (optionnel)</legend>
          <div class="grid-2">
            <label class="field"><span>Nom</span>
              <input type="text" name="nom_mandataire" maxlength="60" value="<?= htmlspecialchars($client['nom_mandataire'] ?? '') ?>"></label>
            <label class="field"><span>Post-nom</span>
              <input type="text" name="postnom_mandataire" maxlength="60" value="<?= htmlspecialchars($client['postnom_mandataire'] ?? '') ?>"></label>
            <label class="field"><span>Téléphone</span>
              <input type="text" name="numero_mandataire" maxlength="25" value="<?= htmlspecialchars($client['numero_mandataire'] ?? '') ?>"></label>
          </div>
          <label class="field"><span>Adresse</span>
            <textarea name="adresse_mandataire" rows="2" maxlength="500"><?= htmlspecialchars($client['adresse_compte_mandataire'] ?? '') ?></textarea></label>
        </fieldset>
      <?php endif; ?>

      <button class="btn-gold full" type="submit">💾 Enregistrer les modifications</button>
    </form>
  </section>

</main>

<footer class="atf-footer" style="padding-bottom: 20px">
  © <?= date('Y') ?> AFRICAN TRUST FUND
</footer>

<!-- NAV MOBILE -->
<nav class="mobile-nav" id="mobileNav">
  <div class="mobile-nav-inner">
    <a href="<?= $base ?>/client/dashboard.php" class="nav-item">
      <span class="nav-icon">🏠</span>
      <span class="nav-label">Accueil</span>
    </a>
    <a href="<?= $base ?>/client/epargne.php" class="nav-item">
      <span class="nav-icon">💰</span>
      <span class="nav-label">Épargne</span>
    </a>
    <div class="nav-fab-wrapper">
      <button class="nav-fab" id="navFab" type="button">＋</button>
    </div>
    <a href="<?= $base ?>/client/mes_prets.php" class="nav-item">
      <span class="nav-icon">💳</span>
      <span class="nav-label">Prêts</span>
    </a>
    <a href="<?= $base ?>/client/profil.php" class="nav-item active">
      <span class="nav-icon">👤</span>
      <span class="nav-label">Profil</span>
    </a>
  </div>
</nav>

<div class="fab-overlay" id="fabOverlay"></div>

<div class="fab-menu" id="fabMenu">
  <a href="<?= $base ?>/client/depot.php" class="fab-menu-item depot">
    <span class="ico">＋</span><span>Dépôt</span>
  </a>
  <a href="<?= $base ?>/client/retrait.php" class="fab-menu-item retrait">
    <span class="ico">－</span><span>Retrait</span>
  </a>
  <a href="<?= $base ?>/client/virement.php" class="fab-menu-item virement">
    <span class="ico">⇄</span><span>Virement</span>
  </a>
  <a href="<?= $base ?>/client/epargne.php" class="fab-menu-item epargne">
    <span class="ico">💰</span><span>Épargne</span>
  </a>
</div>

<script>
/* FAB MENU */
(function() {
  const fab = document.getElementById('navFab');
  const menu = document.getElementById('fabMenu');
  const overlay = document.getElementById('fabOverlay');
  if (!fab || !menu || !overlay) return;

  function toggle() {
    const open = menu.classList.contains('open');
    if (open) {
      menu.classList.remove('open');
      overlay.classList.remove('open');
      fab.classList.remove('active');
    } else {
      menu.classList.add('open');
      overlay.classList.add('open');
      fab.classList.add('active');
    }
  }
  fab.addEventListener('click', toggle);
  overlay.addEventListener('click', toggle);
  menu.querySelectorAll('a').forEach(a => a.addEventListener('click', toggle));
})();
</script>
<script src="<?= $base ?>/assets/js/main.js"></script>
</body>
</html>