<?php
declare(strict_types=1);

/**
 * MOTEUR DE CALCUL DES INTÉRÊTS
 * Expert-comptable : calculs précis, arrondis bancaires, traçabilité
 */

/**
 * Calcule les intérêts d'un placement
 *
 * @param float  $capital      Montant initial
 * @param float  $taux         Taux annuel (ex: 0.05 pour 5%)
 * @param int    $duree_mois   Durée en mois
 * @param string $type         'simple' ou 'compose'
 * @return array               Détail du calcul
 */
function calculerInterets(float $capital, float $taux, int $duree_mois, string $type = 'simple'): array
{
    $duree_annees = $duree_mois / 12;

    if ($type === 'compose') {
        // Intérêts composés : C × (1 + t)^n - C
        $montant_final = $capital * pow(1 + $taux, $duree_annees);
        $interets = $montant_final - $capital;
    } else {
        // Intérêts simples : C × t × n
        $interets = $capital * $taux * $duree_annees;
        $montant_final = $capital + $interets;
    }

    // Arrondi bancaire (2 décimales)
    $interets = round($interets, 2);
    $montant_final = round($montant_final, 2);

    return [
        'capital'        => $capital,
        'taux'           => $taux,
        'duree_mois'     => $duree_mois,
        'type'           => $type,
        'interets'       => $interets,
        'montant_final'  => $montant_final,
        'taux_effectif'  => $montant_final > 0 ? round(($interets / $capital) * 100, 4) : 0,
    ];
}

/**
 * Calcule les intérêts journaliers (prorata temporis)
 * Utilisé pour les calculs mensuels
 */
function calculerInteretsJournaliers(float $capital, float $taux_annuel, int $jours): float
{
    $taux_journalier = $taux_annuel / 365;
    return round($capital * $taux_journalier * $jours, 2);
}

/**
 * Calcule la commission de retrait
 *
 * @param float $montant_brut    Montant demandé
 * @param float $taux_commission Taux (ex: 0.01 pour 1%)
 * @return array                 Détail
 */
function calculerCommission(float $montant_brut, float $taux_commission): array
{
    $commission = round($montant_brut * $taux_commission, 2);
    $montant_net = round($montant_brut - $commission, 2);

    return [
        'montant_brut'   => $montant_brut,
        'taux'           => $taux_commission,
        'commission'     => $commission,
        'montant_net'    => $montant_net,
    ];
}

/**
 * Calcule la pénalité de retrait anticipé
 */
function calculerPenaliteAnticipe(float $capital, float $taux_penalite, int $jours_restants): float
{
    // Pénalité = capital × taux × (jours restants / 365)
    $penalite = $capital * $taux_penalite * ($jours_restants / 365);
    return round($penalite, 2);
}

/**
 * Crédite les intérêts sur un placement
 * À appeler par un CRON mensuel ou lors de la consultation
 */
function crediterInterets(PDO $pdo, int $id_placement): array
{
    $pdo->beginTransaction();

    try {
        // Charger le placement avec verrou
        $stmt = $pdo->prepare("
            SELECT p.*, pr.type_interet, pr.taux_annuel
            FROM Placements p
            JOIN Produits_Epargne pr ON pr.id_produit = p.id_produit
            WHERE p.id_placement = ? AND p.statut = 'actif'
            FOR UPDATE
        ");
        $stmt->execute([$id_placement]);
        $placement = $stmt->fetch();

        if (!$placement) {
            throw new RuntimeException("Placement introuvable ou inactif.");
        }

        // Période à calculer
        $date_dernier = $placement['date_dernier_calcul'] ?? $placement['date_debut'];
        $aujourdhui = date('Y-m-d');

        $jours = (strtotime($aujourdhui) - strtotime($date_dernier)) / 86400;

        if ($jours < 1) {
            $pdo->rollBack();
            return ['interets' => 0, 'message' => 'Aucun jour à calculer'];
        }

        // Calcul des intérêts journaliers
        $interets = calculerInteretsJournaliers(
            (float)$placement['montant_actuel'],
            (float)$placement['taux_applique'],
            (int)$jours
        );

        if ($interets <= 0) {
            $pdo->rollBack();
            return ['interets' => 0, 'message' => 'Intérêts nuls'];
        }

        $solde_avant = (float)$placement['montant_actuel'];
        $solde_apres = $solde_avant + $interets;

        // Mise à jour du placement
        $pdo->prepare("
            UPDATE Placements 
            SET montant_actuel = ?,
                interets_cumules = interets_cumules + ?,
                date_dernier_calcul = ?
            WHERE id_placement = ?
        ")->execute([$solde_apres, $interets, $aujourdhui, $id_placement]);

        // Historique
        $pdo->prepare("
            INSERT INTO Interets_Historique 
                (id_placement, montant_interet, solde_avant, solde_apres, periode_debut, periode_fin)
            VALUES (?,?,?,?,?,?)
        ")->execute([$id_placement, $interets, $solde_avant, $solde_apres, $date_dernier, $aujourdhui]);

        $pdo->commit();

        return [
            'interets'    => $interets,
            'solde_avant' => $solde_avant,
            'solde_apres' => $solde_apres,
            'jours'       => (int)$jours,
            'message'     => "Intérêts crédités : $interets",
        ];

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Crédite les intérêts sur TOUS les placements actifs
 * (à appeler par CRON une fois par jour)
 */
function crediterTousInterets(PDO $pdo): array
{
    $placements = $pdo->query("SELECT id_placement FROM Placements WHERE statut='actif'")->fetchAll();
    $resultats = ['succes' => 0, 'erreurs' => 0, 'total_interets' => 0];

    foreach ($placements as $p) {
        try {
            $r = crediterInterets($pdo, (int)$p['id_placement']);
            if ($r['interets'] > 0) {
                $resultats['succes']++;
                $resultats['total_interets'] += $r['interets'];
            }
        } catch (Throwable $e) {
            error_log("[ATF INTERETS] Placement {$p['id_placement']} : " . $e->getMessage());
            $resultats['erreurs']++;
        }
    }
    return $resultats;
}