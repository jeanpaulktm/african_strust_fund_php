<?php
declare(strict_types=1);

/**
 * ============================================================
 * COMPTE ENTREPRISE ATF — Collecte automatique des intérêts
 * VERSION CORRIGÉE — Gère les transactions imbriquées
 * ============================================================
 */

define('COMPTE_ENTREPRISE_NUMERO', 'ATF-2026-436337');

/**
 * Récupère l'ID du compte entreprise (avec cache)
 */
function getIdCompteEntreprise(PDO $pdo): int
{
    static $cached_id = null;

    if ($cached_id !== null) return $cached_id;

    $stmt = $pdo->prepare("
        SELECT id_client FROM Client 
        WHERE numero_compte = ? AND type_compte = 'entreprise' 
        LIMIT 1
    ");
    $stmt->execute([COMPTE_ENTREPRISE_NUMERO]);
    $id = $stmt->fetchColumn();

    if (!$id) {
        throw new RuntimeException(
            "❌ Compte entreprise ATF introuvable : " . COMPTE_ENTREPRISE_NUMERO
        );
    }

    $cached_id = (int)$id;
    return $cached_id;
}

/**
 * Crédite un intérêt sur le compte entreprise
 * 
 * ✅ CORRIGÉ : Ne démarre PAS sa propre transaction si déjà dans une
 * ✅ CORRIGÉ : N'appelle pas commit/rollback si transaction parente
 */
function crediterInteretEntreprise(
    PDO $pdo,
    float $montant,
    string $devise,
    string $type_interet,
    int $id_client_source,
    array $refs = [],
    string $description = ''
): int {
    /* ---------- Validations ---------- */
    if ($montant <= 0) {
        throw new InvalidArgumentException("Le montant de l'intérêt doit être positif.");
    }
    if (!in_array($devise, ['CDF', 'USD'], true)) {
        throw new InvalidArgumentException("Devise invalide : $devise");
    }
    if (!in_array($type_interet, ['commission_retrait', 'interet_epargne', 'penalite_anticipe', 'autre'], true)) {
        throw new InvalidArgumentException("Type d'intérêt invalide : $type_interet");
    }

    $id_entreprise = getIdCompteEntreprise($pdo);

    /* ---------- Détection du contexte transactionnel ---------- */
    $deja_en_transaction = $pdo->inTransaction();

    try {
        /* ---------- Créditer le bon solde ---------- */
        if ($devise === 'USD') {
            $pdo->prepare("
                UPDATE Client SET solde_usd = solde_usd + ? 
                WHERE id_client = ?
            ")->execute([$montant, $id_entreprise]);
        } else {
            $pdo->prepare("
                UPDATE Client SET solde_cdf = solde_cdf + ? 
                WHERE id_client = ?
            ")->execute([$montant, $id_entreprise]);
        }

        /* ---------- Enregistrer dans Interets_Entreprise ---------- */
        $pdo->prepare("
            INSERT INTO Interets_Entreprise 
                (id_client_source, id_transaction_source, id_placement_source,
                 type_interet, montant, devise, description)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            $id_client_source,
            $refs['id_transaction'] ?? null,
            $refs['id_placement'] ?? null,
            $type_interet,
            $montant,
            $devise,
            $description ?: "Intérêt collecté par ATF"
        ]);

        $id_interet = (int)$pdo->lastInsertId();

        /* ---------- Créer la transaction côté entreprise ---------- */
        $reference = 'INT-' . strtoupper(bin2hex(random_bytes(5)));

        // Récupérer solde avant
        $stmt = $pdo->prepare("SELECT solde_cdf, solde_usd FROM Client WHERE id_client = ?");
        $stmt->execute([$id_entreprise]);
        $c_ent = $stmt->fetch();

        $solde_avant = $devise === 'USD'
            ? (float)$c_ent['solde_usd'] - $montant
            : (float)$c_ent['solde_cdf'] - $montant;
        $solde_apres = $solde_avant + $montant;

        $pdo->prepare("
            INSERT INTO Transactions 
                (id_client, type_transaction, montant, devise, mode, effectue_par,
                 solde_avant, solde_apres, reference, description, ip_address)
            VALUES (?, 'Depot', ?, ?, 'En ligne', 'Admin', ?, ?, ?, ?, ?)
        ")->execute([
            $id_entreprise,
            $montant,
            $devise,
            $solde_avant,
            $solde_apres,
            $reference,
            "Intérêt collecté (#$id_interet) : " . mb_substr($description, 0, 150),
            $_SERVER['REMOTE_ADDR'] ?? null
        ]);

        return $id_interet;

    } catch (Throwable $e) {
        /* ⚠️ NE PAS rollback si on est dans une transaction parente */
        error_log('[ATF INTERET ENTREPRISE] ' . $e->getMessage());
        throw $e;
    }
}

/**
 * Statistiques du compte entreprise
 */
function getStatsCompteEntreprise(PDO $pdo): array
{
    $id_entreprise = getIdCompteEntreprise($pdo);

    $stmt = $pdo->prepare("SELECT solde_cdf, solde_usd FROM Client WHERE id_client = ?");
    $stmt->execute([$id_entreprise]);
    $soldes = $stmt->fetch();

    $stmt = $pdo->query("
        SELECT type_interet, devise, COUNT(*) AS nb, COALESCE(SUM(montant), 0) AS total
        FROM Interets_Entreprise
        GROUP BY type_interet, devise
    ");
    $par_type = $stmt->fetchAll();

    $stmt = $pdo->query("
        SELECT devise, COUNT(*) AS nb_total, COALESCE(SUM(montant), 0) AS total_general
        FROM Interets_Entreprise
        GROUP BY devise
    ");
    $totaux = $stmt->fetchAll();

    return [
        'id_entreprise' => $id_entreprise,
        'solde_cdf'     => (float)$soldes['solde_cdf'],
        'solde_usd'     => (float)$soldes['solde_usd'],
        'par_type'      => $par_type,
        'totaux'        => $totaux,
    ];
}