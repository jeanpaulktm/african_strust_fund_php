<?php
declare(strict_types=1);

/**
 * ============================================================
 * GESTION DES PARAMÈTRES SYSTÈME
 * ============================================================
 */

/**
 * Récupère la valeur d'un paramètre
 */
function getParametre(PDO $pdo, string $cle, mixed $default = null): mixed
{
    static $cache = [];

    if (array_key_exists($cle, $cache)) {
        return $cache[$cle];
    }

    $stmt = $pdo->prepare("SELECT valeur FROM Parametres_Systeme WHERE cle = ? LIMIT 1");
    $stmt->execute([$cle]);
    $valeur = $stmt->fetchColumn();

    if ($valeur === false) {
        $cache[$cle] = $default;
        return $default;
    }

    $cache[$cle] = $valeur;
    return $valeur;
}

/**
 * Récupère tous les paramètres d'une catégorie
 */
function getParametresParCategorie(PDO $pdo, string $categorie): array
{
    $stmt = $pdo->prepare("
        SELECT * FROM Parametres_Systeme 
        WHERE categorie = ? 
        ORDER BY cle ASC
    ");
    $stmt->execute([$categorie]);
    return $stmt->fetchAll();
}

/**
 * Met à jour un paramètre
 */
function setParametre(PDO $pdo, string $cle, string $valeur, ?int $admin_id = null): bool
{
    $stmt = $pdo->prepare("
        UPDATE Parametres_Systeme 
        SET valeur = ?, updated_by = ?
        WHERE cle = ?
    ");
    return $stmt->execute([$valeur, $admin_id, $cle]);
}

/**
 * Taux d'intérêt par défaut (annuel en %)
 */
function getTauxInteretDefaut(PDO $pdo): float
{
    return (float)getParametre($pdo, 'taux_interet_defaut', 5.00);
}

/**
 * Durée de blocage des intérêts en mois
 */
function getDureeBlocageInterets(PDO $pdo): int
{
    return (int)getParametre($pdo, 'duree_blocage_interets', 6);
}

/**
 * Commission de retrait en %
 */
function getCommissionRetrait(PDO $pdo): float
{
    return (float)getParametre($pdo, 'commission_retrait', 1.00);
}