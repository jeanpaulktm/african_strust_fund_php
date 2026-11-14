<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/auth_guard.php';
require_once __DIR__ . '/../config/secure_tokens.php';
require_once __DIR__ . '/../config/interets.php';
requireRole('admin');

$pdo = Database::getConnection();
$admin_id = (int)$_SESSION['admin_id'];

echo "<pre style='background:#111;color:#0f0;padding:20px;font-family:monospace;font-size:13px;line-height:1.6'>";
echo "🌱 GÉNÉRATION DE DONNÉES DE DÉMONSTRATION\n\n";

/* ============================================================
   1. CRÉER DES PRODUITS D'ÉPARGNE (si absents)
   ============================================================ */
$nb_produits = (int)$pdo->query("SELECT COUNT(*) FROM Produits_Epargne")->fetchColumn();

if ($nb_produits === 0) {
    echo "📦 Création de 3 produits d'épargne...\n";

    $produits = [
        ['Épargne Classique', 'simple',  0.0500, 1, 12, 1000,  1000000, 'CDF'],
        ['Épargne Premium',   'compose', 0.0800, 6, 24, 50000, 5000000, 'CDF'],
        ['Épargne Flash',     'simple',  0.0300, 1, 6,  5000,  500000, 'CDF'],
    ];

    $stmt = $pdo->prepare("
        INSERT INTO Produits_Epargne 
        (nom, description, type_interet, taux_annuel, duree_min_mois, duree_max_mois,
         montant_min, montant_max, commission_retrait, penalite_anticipe, devise, actif)
        VALUES (?,?,?,?,?,?,?,?,0.0100,0.0050,?,1)
    ");

    foreach ($produits as $p) {
        $stmt->execute([$p[0], "Produit de démonstration", $p[1], $p[2], $p[3], $p[4], $p[5], $p[6], $p[7]]);
        echo "   ✅ {$p[0]} créé\n";
    }
} else {
    echo "📦 $nb_produits produit(s) déjà présent(s)\n";
}

/* ============================================================
   2. CRÉER DES CLIENTS DE TEST (si absents)
   ============================================================ */
$nb_clients = (int)$pdo->query("SELECT COUNT(*) FROM Client")->fetchColumn();

if ($nb_clients < 5) {
    echo "\n👥 Création de 5 clients de test...\n";

    $clients = [
        ['KAMBA',   'MULUMBA', 'Jean',   'Avenue Lumumba 45, Kinshasa',    '+243850000001'],
        ['NGOY',    'KABUYA',  'Alice',  'Rue du Commerce 12, Lubumbashi', '+243850000002'],
        ['MUKENDI', 'KASONGO', 'Pierre', 'Boulevard Kanyamuhanga, Goma',   '+243850000003'],
        ['ILUNGA',  'TSHIBANGU','Sarah', 'Avenue Mobutu 88, Kinshasa',     '+243850000004'],
        ['BUKASA',  'LUKOKI',  'David',  'Rue Kasavubu 5, Matadi',         '+243850000005'],
    ];

    $hash = password_hash('Client@2025', PASSWORD_BCRYPT, ['cost' => 12]);

    $stmt = $pdo->prepare("
        INSERT INTO Client 
        (nom, postnom, prenom, adresse_complete, numero_phone, numero_compte, 
         mot_de_passe, solde, devise, statut)
        VALUES (?,?,?,?,?,?,?,?, 'CDF', 'actif')
    ");

    foreach ($clients as $i => $c) {
        $numero = 'ATF-2025-' . str_pad((string)($i + 1), 6, '0', STR_PAD_LEFT);
        try {
            $stmt->execute([$c[0], $c[1], $c[2], $c[3], $c[4], $numero, $hash, rand(10000, 500000)]);
            echo "   ✅ {$c[0]} {$c[1]} — $numero\n";
        } catch (Throwable $e) {
            echo "   ⚠️  {$c[0]} : " . $e->getMessage() . "\n";
        }
    }
} else {
    echo "\n👥 $nb_clients client(s) déjà présent(s)\n";
}

/* ============================================================
   3. CRÉER DES PLACEMENTS DE DÉMONSTRATION
   ============================================================ */
$nb_placements = (int)$pdo->query("SELECT COUNT(*) FROM Placements")->fetchColumn();

if ($nb_placements === 0) {
    echo "\n💰 Création de placements de démonstration...\n";

    // Récupérer les IDs
    $clients_ids = $pdo->query("SELECT id_client, devise FROM Client LIMIT 5")->fetchAll();
    $produits_ids = $pdo->query("SELECT id_produit, taux_annuel, type_interet, devise FROM Produits_Epargne WHERE actif=1")->fetchAll();

    if ($clients_ids && $produits_ids) {
        $stmt = $pdo->prepare("
            INSERT INTO Placements 
            (id_client, id_produit, montant_initial, montant_actuel, devise, taux_applique,
             interets_cumules, duree_mois, date_debut, date_fin, date_dernier_calcul, statut, reference)
            VALUES (?,?,?,?,?,?,?,?,?,?,?, 'actif', ?)
        ");

        $montants = [25000, 50000, 100000, 75000, 150000];

        foreach ($clients_ids as $i => $cl) {
            $prod = $produits_ids[$i % count($produits_ids)];
            $montant = $montants[$i % count($montants)];

            // Simuler que le placement a démarré il y a quelques mois
            $mois_ecoules = rand(1, 6);
            $date_debut = date('Y-m-d', strtotime("-$mois_ecoules months"));
            $duree = 12;
            $date_fin = date('Y-m-d', strtotime("$date_debut + $duree months"));

            // Calcul des intérêts déjà acquis
            $calcul = calculerInterets($montant, (float)$prod['taux_annuel'], $mois_ecoules, $prod['type_interet']);
            $interets_acquis = $calcul['interets'];
            $montant_actuel = $montant + $interets_acquis;

            $ref = 'PLC-DEMO' . str_pad((string)($i + 1), 4, '0', STR_PAD_LEFT);

            try {
                $stmt->execute([
                    (int)$cl['id_client'], (int)$prod['id_produit'],
                    $montant, $montant_actuel, $cl['devise'], (float)$prod['taux_annuel'],
                    $interets_acquis, $duree, $date_debut, $date_fin, date('Y-m-d'), $ref
                ]);
                echo "   ✅ $ref — Client #{$cl['id_client']} — " . number_format($montant, 0, ',', ' ') . " CDF (+" . number_format($interets_acquis, 0, ',', ' ') . " intérêts)\n";
            } catch (Throwable $e) {
                echo "   ⚠️  $ref : " . $e->getMessage() . "\n";
            }
        }
    }
} else {
    echo "\n💰 $nb_placements placement(s) déjà présent(s)\n";
}

/* ============================================================
   4. CRÉER DES TRANSACTIONS DE DÉMONSTRATION
   ============================================================ */
$nb_transactions = (int)$pdo->query("SELECT COUNT(*) FROM Transactions")->fetchColumn();

if ($nb_transactions < 10) {
    echo "\n💳 Création de transactions de démonstration...\n";

    $clients = $pdo->query("SELECT id_client, solde, devise FROM Client LIMIT 5")->fetchAll();

    $stmt = $pdo->prepare("
        INSERT INTO Transactions 
        (id_client, type_transaction, montant, devise, mode, effectue_par,
         solde_avant, solde_apres, reference, description, date_transaction)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
    ");

    $types = ['Depot', 'Retrait', 'Depot', 'Depot', 'Retrait', 'Depot', 'Virement', 'Depot', 'Retrait', 'Depot'];
    $modes = ['En ligne', 'Agence', 'En ligne', 'Agence', 'En ligne'];

    for ($i = 0; $i < 10; $i++) {
        $cl = $clients[$i % count($clients)];
        $type = $types[$i];
        $montant = rand(5000, 100000);

        $solde_avant = (float)$cl['solde'];
        $solde_apres = $type === 'Retrait' ? $solde_avant - $montant : $solde_avant + $montant;

        // Répartir sur plusieurs mois
        $jours_en_arriere = rand(1, 330);
        $date = date('Y-m-d H:i:s', strtotime("-$jours_en_arriere days"));

        $ref = 'TRX-DEMO' . str_pad((string)($i + 1), 4, '0', STR_PAD_LEFT);

        try {
            $stmt->execute([
                (int)$cl['id_client'], $type, $montant, $cl['devise'],
                $modes[$i % count($modes)], 'Client',
                $solde_avant, $solde_apres, $ref,
                "Transaction de démonstration", $date
            ]);
            echo "   ✅ $ref — $type — " . number_format($montant, 0, ',', ' ') . " CDF\n";
        } catch (Throwable $e) {
            echo "   ⚠️  $ref : " . $e->getMessage() . "\n";
        }
    }
} else {
    echo "\n💳 $nb_transactions transaction(s) déjà présente(s)\n";
}

/* ============================================================
   5. CRÉER DES INTÉRÊTS HISTORIQUES
   ============================================================ */
$nb_interets = (int)$pdo->query("SELECT COUNT(*) FROM Interets_Historique")->fetchColumn();

if ($nb_interets === 0) {
    echo "\n📈 Création d'historique d'intérêts...\n";

    $placements = $pdo->query("SELECT id_placement, montant_actuel, interets_cumules, devise FROM Placements WHERE statut='actif'")->fetchAll();

    $stmt = $pdo->prepare("
        INSERT INTO Interets_Historique 
        (id_placement, montant_interet, solde_avant, solde_apres, periode_debut, periode_fin, date_calcul)
        VALUES (?,?,?,?,?,?,?)
    ");

    foreach ($placements as $p) {
        // Créer 3 entrées d'intérêts réparties sur 3 mois
        for ($m = 3; $m >= 1; $m--) {
            $interet = (float)$p['interets_cumules'] / 3;
            $solde_avant = (float)$p['montant_actuel'] - ($interet * $m);
            $solde_apres = $solde_avant + $interet;

            $date_calcul = date('Y-m-d', strtotime("-$m months"));
            $periode_debut = date('Y-m-d', strtotime("-$m months -30 days"));
            $periode_fin = $date_calcul;

            try {
                $stmt->execute([
                    (int)$p['id_placement'], $interet, $solde_avant, $solde_apres,
                    $periode_debut, $periode_fin, $date_calcul
                ]);
            } catch (Throwable $e) {
                // Ignorer les doublons
            }
        }
        echo "   ✅ Placement #{$p['id_placement']} : 3 entrées d'intérêts\n";
    }
} else {
    echo "\n📈 $nb_interets entrée(s) d'intérêts déjà présente(s)\n";
}

/* ============================================================
   6. CRÉER DES COMMISSIONS DE DÉMONSTRATION
   ============================================================ */
$nb_commissions = (int)$pdo->query("SELECT COUNT(*) FROM Commissions")->fetchColumn();

if ($nb_commissions === 0) {
    echo "\n💵 Création de commissions de démonstration...\n";

    $clients = $pdo->query("SELECT id_client, devise FROM Client LIMIT 5")->fetchAll();

    $stmt = $pdo->prepare("
        INSERT INTO Commissions 
        (id_client, montant_brut, taux_commission, montant_commission, montant_net, devise, date_commission)
        VALUES (?,?,0.0100,?,?,?,?)
    ");

    foreach ($clients as $i => $cl) {
        $brut = rand(20000, 200000);
        $commission = $brut * 0.01;
        $net = $brut - $commission;

        $jours = rand(1, 180);
        $date = date('Y-m-d H:i:s', strtotime("-$jours days"));

        try {
            $stmt->execute([
                (int)$cl['id_client'], $brut, $commission, $net, $cl['devise'], $date
            ]);
            echo "   ✅ Client #{$cl['id_client']} : " . number_format($commission, 0, ',', ' ') . " CDF de commission\n";
        } catch (Throwable $e) {
            echo "   ⚠️  " . $e->getMessage() . "\n";
        }
    }
} else {
    echo "\n💵 $nb_commissions commission(s) déjà présente(s)\n";
}

/* ============================================================
   RÉCAPITULATIF
   ============================================================ */
echo "\n" . str_repeat('=', 50) . "\n";
echo "📊 RÉCAPITULATIF FINAL\n";
echo str_repeat('=', 50) . "\n\n";

$final = [
    'Clients'       => (int)$pdo->query("SELECT COUNT(*) FROM Client")->fetchColumn(),
    'Produits'      => (int)$pdo->query("SELECT COUNT(*) FROM Produits_Epargne")->fetchColumn(),
    'Placements'    => (int)$pdo->query("SELECT COUNT(*) FROM Placements")->fetchColumn(),
    'Transactions'  => (int)$pdo->query("SELECT COUNT(*) FROM Transactions")->fetchColumn(),
    'Intérêts'      => (int)$pdo->query("SELECT COUNT(*) FROM Interets_Historique")->fetchColumn(),
    'Commissions'   => (int)$pdo->query("SELECT COUNT(*) FROM Commissions")->fetchColumn(),
];

foreach ($final as $k => $v) {
    echo sprintf("   %-15s : %d\n", $k, $v);
}

logAction($pdo, 'admin', $admin_id, 'SEED_DEMO', 'Données de démonstration créées');

echo "\n✅ TERMINÉ ! Ouvrez maintenant : /admin/statistiques.php\n\n";
echo "⚠️  SUPPRIMEZ CE FICHIER (seed_demo.php) APRÈS USAGE.\n";
echo "</pre>";