<?php
require_once __DIR__ . '/database.php';

/**
 * ============================================================
 * MODULE ACTIONNAIRES — Fonctions métier
 * ============================================================
 */

/**
 * Génère un numéro d'actionnaire unique
 */
function genererNumeroActionnaire(PDO $pdo): string
{
    for ($i = 0; $i < 10; $i++) {
        $numero = 'ACT-' . date('Y') . '-' . str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare("SELECT 1 FROM Actionnaires WHERE numero_actionnaire = ? LIMIT 1");
        $stmt->execute([$numero]);
        if (!$stmt->fetchColumn()) return $numero;
    }
    throw new RuntimeException("Impossible de générer un numéro d'actionnaire.");
}

/**
 * Récupère la configuration du capital pour l'année en cours
 */
function getCapitalCourant(PDO $pdo, ?int $annee = null): ?array
{
    $annee = $annee ?? (int)date('Y');
    $stmt = $pdo->prepare("SELECT * FROM Capital_ATF WHERE annee_fiscale = ? LIMIT 1");
    $stmt->execute([$annee]);
    return $stmt->fetch() ?: null;
}

/**
 * Calcule le pourcentage de détention d'un actionnaire
 */
function getPourcentageDetention(PDO $pdo, int $nombre_parts_actionnaire): float
{
    $capital = getCapitalCourant($pdo);
    if (!$capital || (int)$capital['nombre_parts_emises'] === 0) return 0;
    return round(($nombre_parts_actionnaire / (int)$capital['nombre_parts_emises']) * 100, 4);
}

/**
 * Enregistre une souscription de parts
 */
function souscrireParts(
    PDO $pdo,
    int $id_actionnaire,
    int $nombre_parts,
    string $devise = 'CDF',
    string $description = ''
): array {
    if ($nombre_parts <= 0) {
        throw new RuntimeException("Le nombre de parts doit être positif.");
    }

    $capital = getCapitalCourant($pdo);
    if (!$capital) {
        throw new RuntimeException("Aucune configuration de capital pour cette année.");
    }

    /* Vérifier qu'il reste des parts disponibles */
    $parts_disponibles = (int)$capital['nombre_parts_emises'] - (int)$capital['nombre_parts_vendues'];
    if ($nombre_parts > $parts_disponibles) {
        throw new RuntimeException(
            "Parts insuffisantes. Disponibles : $parts_disponibles | Demandées : $nombre_parts"
        );
    }

    $prix_unitaire = (float)$capital['prix_unitaire'];
    $montant_total = $prix_unitaire * $nombre_parts;
    $reference     = 'PRT-' . strtoupper(bin2hex(random_bytes(6)));

    $pdo->prepare("
        INSERT INTO Parts_Sociales 
            (id_actionnaire, nombre_parts, prix_unitaire, montant_total, devise,
             type_mouvement, statut, reference, description)
        VALUES (?, ?, ?, ?, ?, 'souscription', 'valide', ?, ?)
    ")->execute([
        $id_actionnaire, $nombre_parts, $prix_unitaire, $montant_total, $devise,
        $reference, $description ?: "Souscription de $nombre_parts parts"
    ]);

    $id_part = (int)$pdo->lastInsertId();

    /* Mettre à jour l'actionnaire */
    if ($devise === 'USD') {
        $pdo->prepare("
            UPDATE Actionnaires 
            SET nombre_parts = nombre_parts + ?,
                capital_investi_usd = capital_investi_usd + ?
            WHERE id_actionnaire = ?
        ")->execute([$nombre_parts, $montant_total, $id_actionnaire]);
    } else {
        $pdo->prepare("
            UPDATE Actionnaires 
            SET nombre_parts = nombre_parts + ?,
                capital_investi_cdf = capital_investi_cdf + ?
            WHERE id_actionnaire = ?
        ")->execute([$nombre_parts, $montant_total, $id_actionnaire]);
    }

    /* Mettre à jour le capital global */
    $pdo->prepare("
        UPDATE Capital_ATF 
        SET nombre_parts_vendues = nombre_parts_vendues + ?,
            capital_social = capital_social + ?
        WHERE id_capital = ?
    ")->execute([$nombre_parts, $montant_total, (int)$capital['id_capital']]);

    return [
        'id_part'       => $id_part,
        'reference'     => $reference,
        'nombre_parts'  => $nombre_parts,
        'montant_total' => $montant_total,
    ];
}

/**
 * Calcule les dividendes pour tous les actionnaires
 */
function calculerDividendes(PDO $pdo, int $annee, float $benefice_total): array
{
    $capital = getCapitalCourant($pdo, $annee);
    if (!$capital) {
        throw new RuntimeException("Capital non configuré pour $annee.");
    }

    $taux_distribution = (float)$capital['taux_distribution'] / 100;
    $montant_a_distribuer = round($benefice_total * $taux_distribution, 2);
    $total_parts = (int)$capital['nombre_parts_emises'];

    if ($total_parts === 0) {
        throw new RuntimeException("Aucune part émise.");
    }

    /* Récupérer tous les actionnaires actifs */
    $actionnaires = $pdo->query("
        SELECT id_actionnaire, nombre_parts, devise_preferee
        FROM Actionnaires 
        WHERE statut = 'actif' AND nombre_parts > 0
    ")->fetchAll();

    $resultats = [
        'annee'               => $annee,
        'benefice_total'      => $benefice_total,
        'montant_a_distribuer'=> $montant_a_distribuer,
        'actionnaires'        => [],
    ];

    foreach ($actionnaires as $a) {
        $parts = (int)$a['nombre_parts'];
        $dividende = round(($montant_a_distribuer * $parts) / $total_parts, 2);

        /* Vérifier si déjà calculé */
        $stmt = $pdo->prepare("
            SELECT id_dividende FROM Dividendes 
            WHERE id_actionnaire = ? AND annee_fiscale = ?
        ");
        $stmt->execute([(int)$a['id_actionnaire'], $annee]);

        if ($stmt->fetchColumn()) {
            continue; // Déjà calculé
        }

        $reference = 'DIV-' . $annee . '-' . strtoupper(bin2hex(random_bytes(4)));

        $pdo->prepare("
            INSERT INTO Dividendes 
                (id_actionnaire, annee_fiscale, nombre_parts, benefice_total,
                 montant_dividende, devise, statut, reference)
            VALUES (?, ?, ?, ?, ?, 'CDF', 'calcule', ?)
        ")->execute([
            (int)$a['id_actionnaire'], $annee, $parts, $benefice_total,
            $dividende, $reference
        ]);

        $resultats['actionnaires'][] = [
            'id_actionnaire' => (int)$a['id_actionnaire'],
            'parts'          => $parts,
            'dividende'      => $dividende,
            'reference'      => $reference,
        ];
    }

    return $resultats;
}

/**
 * Paie les dividendes à un actionnaire
 */
function payerDividende(
    PDO $pdo,
    int $id_dividende,
    string $mode_paiement,
    int $admin_id,
    string $commentaire = ''
): array {
    $stmt = $pdo->prepare("
        SELECT d.*, a.id_client, a.nom, a.postnom
        FROM Dividendes d
        JOIN Actionnaires a ON a.id_actionnaire = d.id_actionnaire
        WHERE d.id_dividende = ? AND d.statut IN ('calcule', 'valide')
    ");
    $stmt->execute([$id_dividende]);
    $dividende = $stmt->fetch();

    if (!$dividende) {
        throw new RuntimeException("Dividende introuvable ou déjà payé.");
    }

    $reference = 'PAY-' . strtoupper(bin2hex(random_bytes(6)));

    $pdo->beginTransaction();

    try {
        /* Créer le paiement */
        $pdo->prepare("
            INSERT INTO Paiements_Dividendes 
                (id_dividende, id_actionnaire, montant, devise, mode_paiement,
                 reference, commentaire, paye_par)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $id_dividende, (int)$dividende['id_actionnaire'],
            (float)$dividende['montant_dividende'], $dividende['devise'],
            $mode_paiement, $reference, $commentaire, $admin_id
        ]);

        /* Mettre à jour le dividende */
        $pdo->prepare("
            UPDATE Dividendes 
            SET statut = 'paye', date_paiement = NOW(), paiement_valide_par = ?
            WHERE id_dividende = ?
        ")->execute([$admin_id, $id_dividende]);

        /* Mettre à jour l'actionnaire */
        $colonne = $dividende['devise'] === 'USD' ? 'dividendes_recus_usd' : 'dividendes_recus_cdf';
        $pdo->prepare("
            UPDATE Actionnaires 
            SET $colonne = $colonne + ?
            WHERE id_actionnaire = ?
        ")->execute([(float)$dividende['montant_dividende'], (int)$dividende['id_actionnaire']]);

        /* Créditer le compte client si lié */
        if (!empty($dividende['id_client']) && $mode_paiement === 'credit_compte') {
            $colonne_solde = $dividende['devise'] === 'USD' ? 'solde_usd' : 'solde_cdf';
            $pdo->prepare("
                UPDATE Client 
                SET $colonne_solde = $colonne_solde + ?
                WHERE id_client = ?
            ")->execute([(float)$dividende['montant_dividende'], (int)$dividende['id_client']]);
        }

        $pdo->commit();

        return [
            'reference' => $reference,
            'montant'   => (float)$dividende['montant_dividende'],
            'devise'    => $dividende['devise'],
        ];

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
?>