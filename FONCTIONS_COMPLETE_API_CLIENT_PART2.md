# 💰 PRÊTS - Gestion Complète

## 7. PRETS - Liste Tous les Prêts du Client
**Endpoint:** `GET /api/v1/prets?page=1&per_page=10&statut=en_cours&sort=date_desc`  
**Authentification:** Requise (Bearer token)

### Paramètres Query:
```
page=int (default 1)
per_page=int (default 10, max 50)
statut=string (filter: soumis|en_analyse|approuve|decaisse|en_cours|en_retard|solde|rejete|annule)
sort=string (date_desc|date_asc|montant_desc|montant_asc)
devise=string (CDF|USD|all)
```

### Réponse Succès (200):
```json
{
  "success": true,
  "data": [
    {
      "id_pret": 1,
      "reference": "PRE-2026-00001",
      "id_produit": 1,
      "produit_nom": "Prêt Classique CDF",
      "montant_demande": 500000,
      "montant_accorde": 500000,
      "devise": "CDF",
      "taux_applique": 0.12,
      "duree_mois": 12,
      "date_demande": "2026-10-14",
      "date_approbation": "2026-10-20",
      "date_decaissement": "2026-10-25",
      "statut": "en_cours",
      "solde_dû": 450000,
      "montant_rembourse": 50000,
      "prochaine_echeance": "2026-11-25",
      "montant_prochain_paiement": 45833,
      "jours_retard": 0
    }
  ],
  "pagination": {
    "page": 1,
    "per_page": 10,
    "total": 5,
    "total_pages": 1,
    "has_next": false,
    "has_prev": false
  },
  "timestamp": "2026-11-14T14:30:00Z"
}
```

### Query SQL:
```sql
SELECT 
  p.id_pret, p.reference, p.id_produit, pr.nom as produit_nom,
  p.montant_demande, p.montant_accorde, p.devise,
  p.taux_applique, p.duree_mois, p.statut,
  p.date_demande, p.date_approbation, p.date_decaissement,
  (p.montant_accorde - COALESCE(SUM(pr_rem.montant_rembourse), 0)) as solde_dû,
  COALESCE(SUM(pr_rem.montant_rembourse), 0) as montant_rembourse,
  (SELECT MIN(date_echeance) FROM Echeances_Pret 
   WHERE id_pret = p.id_pret AND date_echeance >= CURDATE()) as prochaine_echeance,
  (SELECT montant_dû FROM Echeances_Pret 
   WHERE id_pret = p.id_pret AND date_echeance = (
     SELECT MIN(date_echeance) FROM Echeances_Pret 
     WHERE id_pret = p.id_pret AND date_echeance >= CURDATE()
   )) as montant_prochain_paiement,
  DATEDIFF(CURDATE(), (SELECT MAX(date_echeance) FROM Echeances_Pret 
           WHERE id_pret = p.id_pret AND statut='impaye')) as jours_retard
FROM Prets p
LEFT JOIN Produits_Pret pr ON pr.id_produit = p.id_produit
LEFT JOIN Prets_Remboursements pr_rem ON pr_rem.id_pret = p.id_pret
WHERE p.id_client = ?
GROUP BY p.id_pret
ORDER BY FIELD(p.statut, 'en_cours','en_retard','decaisse','approuve','soumis','en_analyse','solde','rejete','annule'), p.created_at DESC
LIMIT ? OFFSET ?
```

### Filtres Disponibles:
- **Par statut**: Affiche seulement les prêts avec ce statut
- **Par devise**: CDF ou USD
- **Tri**: Date DESC/ASC, Montant DESC/ASC
- **Pagination**: Offset automatique calculé

---

## 8. PRETS DETAILS - Détails Complets d'un Prêt
**Endpoint:** `GET /api/v1/prets/{id}`  
**Authentification:** Requise (Bearer token)  
**Autorisation:** Doit être propriétaire du prêt

### Réponse Succès (200):
```json
{
  "success": true,
  "data": {
    "pret": {
      "id_pret": 1,
      "reference": "PRE-2026-00001",
      "statut": "en_cours",
      "montant_demande": 500000,
      "montant_accorde": 500000,
      "devise": "CDF",
      "taux_applique": 12,
      "duree_mois": 12,
      "date_demande": "2026-10-14",
      "date_approbation": "2026-10-20",
      "date_decaissement": "2026-10-25",
      "date_prevue_cloture": "2027-10-25",
      "solde_dû": 450000,
      "montant_rembourse": 50000,
      "pourcentage_rembourse": 10,
      "notes": "Prêt pour achat équipement"
    },
    "echeances": [
      {
        "id_echeance": 1,
        "num_echeance": 1,
        "date_echeance": "2026-11-25",
        "montant_capital": 41666.67,
        "montant_interet": 5000,
        "montant_dû": 46666.67,
        "montant_paye": 46666.67,
        "date_paiement": "2026-11-25",
        "statut": "paye"
      },
      {
        "id_echeance": 2,
        "num_echeance": 2,
        "date_echeance": "2026-12-25",
        "montant_capital": 41666.67,
        "montant_interet": 4583.33,
        "montant_dû": 46250,
        "montant_paye": 0,
        "statut": "impaye"
      }
    ],
    "remboursements": [
      {
        "id_remboursement": 1,
        "date_remboursement": "2026-11-25",
        "montant_paye": 50000,
        "methode": "virement_bancaire",
        "statut": "complete"
      }
    ]
  },
  "timestamp": "2026-11-14T14:30:00Z"
}
```

### Queries SQL:
```sql
-- Infos prêt
SELECT * FROM Prets WHERE id_pret = ? AND id_client = ?

-- Échéancier complet
SELECT * FROM Echeances_Pret 
WHERE id_pret = ?
ORDER BY num_echeance ASC

-- Historique remboursements
SELECT * FROM Prets_Remboursements
WHERE id_pret = ?
ORDER BY date_remboursement DESC
```

---

## 9. PRETS DEMAND - Créer Nouvelle Demande de Prêt
**Endpoint:** `POST /api/v1/prets/demand`  
**Authentification:** Requise (Bearer token)

### Paramètres:
```json
{
  "id_produit": "int (1-4)",
  "montant_demande": "float (min/max selon produit)",
  "devise": "enum(CDF|USD)",
  "duree_mois": "int (3-60 selon produit)",
  "motif": "string (max 500)",
  "documents_fournis": ["string"]
}
```

### Validation Produits:
```
Produit 1 - Prêt Classique CDF
  Min: 100,000 | Max: 5,000,000 | Taux: 12% | Durée: 3-60 mois

Produit 2 - Prêt Classique USD  
  Min: 100 | Max: 50,000 | Taux: 8% | Durée: 3-60 mois

Produit 3 - Prêt PME/TPE
  Min: 500,000 | Max: 10,000,000 | Taux: 10% | Durée: 6-60 mois

Produit 4 - Prêt Personnel
  Min: 50,000 | Max: 3,000,000 | Taux: 15% | Durée: 3-48 mois
```

### Réponse Succès (201):
```json
{
  "success": true,
  "data": {
    "id_pret": 5,
    "reference": "PRE-2026-00005",
    "statut": "soumis",
    "montant_demande": 1000000,
    "devise": "CDF",
    "duree_mois": 24,
    "date_demande": "2026-11-14",
    "estimation_mensualite": 45833.33,
    "cout_total_interet": 100000,
    "montant_total_a_payer": 1100000,
    "message": "Demande de prêt créée avec succès. En attente d'approbation."
  },
  "timestamp": "2026-11-14T14:30:00Z"
}
```

### Logique Backend:
- Valide montant selon produit
- Calcul mensualité: `M = P * (r * (1+r)^n) / ((1+r)^n - 1)` où r = taux/12
- Calcul intérêts totaux
- INSERT dans table `Prets` avec statut='soumis'
- Génère référence unique: `PRE-YYYY-00XXX`
- Crée écheancier dans `Echeances_Pret`
- Enregistre dans `Audit_Logs`
- Notifie admin
- Retourne 201 Created

### Calcul d'Échéancier:
```php
// Mensualité = Capital * (Taux/12 * (1 + Taux/12)^Mois) / ((1 + Taux/12)^Mois - 1)
$taux_mensuel = $taux_applique / 12;
$montant_mensuel = $montant_accorde * 
  ($taux_mensuel * pow(1 + $taux_mensuel, $duree_mois)) / 
  (pow(1 + $taux_mensuel, $duree_mois) - 1);

// Crée n écheances
for ($i = 1; $i <= $duree_mois; $i++) {
  $interet = $solde_restant * $taux_mensuel;
  $capital = $montant_mensuel - $interet;
  $solde_restant -= $capital;
  // INSERT dans Echeances_Pret
}
```

---

## 10. PRETS REPAY - Rembourser un Prêt
**Endpoint:** `POST /api/v1/prets/{id}/repay`  
**Authentification:** Requise (Bearer token)

### Paramètres:
```json
{
  "montant": "float (obligatoire)",
  "devise": "enum(CDF|USD)",
  "methode": "string (virement|mobile_money|cheque|espece)",
  "reference": "string (optionnel)",
  "notes": "string (optionnel)"
}
```

### Réponse Succès (200):
```json
{
  "success": true,
  "data": {
    "id_remboursement": 5,
    "id_pret": 1,
    "montant_paye": 50000,
    "methode": "virement_bancaire",
    "statut": "complete",
    "date_remboursement": "2026-11-14T14:30:00Z",
    "solde_dû_avant": 450000,
    "solde_dû_apres": 400000,
    "echeances_payees": 1,
    "prochaine_echeance": "2026-12-25"
  },
  "timestamp": "2026-11-14T14:30:00Z"
}
```

### Validations:
- Montant > 0 et <= solde_dû
- Prêt en statut 'decaisse', 'en_cours' ou 'en_retard'
- Devise correspond au prêt
- Solde disponible suffisant

### Logique Backend:
- Valide montant
- Débite solde_cdf ou solde_usd du client
- INSERT dans `Prets_Remboursements`
- Applique paiement aux écheances (priorité: ancienne vers récente)
- Met à jour statut écheances à 'paye'
- Si prêt entièrement remboursé: passe à 'solde'
- Enregistre dans `Audit_Logs` + `Transactions`
- Génère reçu PDF
- Retourne 200

---

