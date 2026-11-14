# 📈 PLACEMENTS & ÉPARGNE

## 16. PLACEMENTS - Liste Tous les Placements
**Endpoint:** `GET /api/v1/placements?page=1&per_page=10&statut=actif&sort=date_desc`  
**Authentification:** Requise (Bearer token)

### Paramètres Query:
```
page=int (default 1)
per_page=int (default 10, max 50)
statut=string (all|actif|cloture|anticipe)
devise=string (CDF|USD|all)
sort=string (date_desc|date_asc|montant_desc|montant_asc|interet_desc)
```

### Réponse Succès (200):
```json
{
  "success": true,
  "data": [
    {
      "id_placement": 1,
      "reference": "PLC-2026-00001",
      "montant_initial": 500000,
      "devise": "CDF",
      "taux_interet": 5,
      "duree_mois": 12,
      "date_debut": "2026-10-14",
      "date_echeance": "2027-10-14",
      "jours_restants": 334,
      "pourcentage_completion": 8.33,
      "statut": "actif",
      "capital_retire": 0,
      "interets_cumules": 20833.33,
      "interets_retires": 0,
      "interets_restants": 20833.33,
      "montant_final_estime": 520833.33,
      "commission_preleve": 5000
    }
  ],
  "pagination": {
    "page": 1,
    "per_page": 10,
    "total": 3,
    "total_pages": 1,
    "has_next": false,
    "has_prev": false
  },
  "resume": {
    "total_capital_investi": 1500000,
    "total_interets_gagnes": 45000,
    "total_interets_retires": 5000,
    "capital_total_en_ligne": 1500000,
    "estimation_total_a_echeance": 1545000
  },
  "timestamp": "2026-11-14T14:30:00Z"
}
```

### Query SQL:
```sql
SELECT 
  id_placement, reference, montant_initial, devise,
  taux_interet, duree_mois, date_debut, date_echeance,
  DATEDIFF(date_echeance, CURDATE()) as jours_restants,
  ROUND((DATEDIFF(CURDATE(), date_debut) / DATEDIFF(date_echeance, date_debut)) * 100, 2) as pourcentage_completion,
  statut, capital_retire,
  interets_cumules,
  interets_retires,
  (interets_cumules - interets_retires) as interets_restants,
  (montant_initial - capital_retire + (interets_cumules - interets_retires)) as montant_final_estime,
  commission_preleve
FROM Placements
WHERE id_client = ?
  AND (? IS NULL OR statut = ?)
  AND (? IS NULL OR devise = ?)
ORDER BY date_debut DESC
LIMIT ? OFFSET ?
```

---

## 17. PLACEMENTS DETAILS - Détails Complet d'un Placement
**Endpoint:** `GET /api/v1/placements/{id}`  
**Authentification:** Requise (Bearer token)

### Réponse Succès (200):
```json
{
  "success": true,
  "data": {
    "placement": {
      "id_placement": 1,
      "reference": "PLC-2026-00001",
      "montant_initial": 500000,
      "devise": "CDF",
      "taux_interet": 5,
      "duree_mois": 12,
      "date_debut": "2026-10-14",
      "date_echeance": "2027-10-14",
      "statut": "actif",
      "capital_retire": 0,
      "interets_cumules": 20833.33,
      "commission_preleve": 5000,
      "taux_effectif_net": 4.5
    },
    "historique_interets_mensuels": [
      {
        "mois": "Octobre 2026",
        "interets": 2083.33,
        "commission": 500,
        "net": 1583.33,
        "date_calcul": "2026-11-14"
      },
      {
        "mois": "Novembre 2026",
        "interets": 2083.33,
        "commission": 500,
        "net": 1583.33,
        "date_calcul": "2026-12-14"
      }
    ],
    "projection_finale": {
      "capital_investis": 500000,
      "interets_totaux": 30000,
      "commission_totale": 6000,
      "net_a_recevoir": 524000,
      "rendement_net": 4.8
    }
  },
  "timestamp": "2026-11-14T14:30:00Z"
}
```

### Logique Calcul Intérêts:
```php
// Intérêts simples (appliqué mensuellement)
$interet_mensuel = $montant_initial * ($taux_interet / 100) / 12;

// Commission (1% des intérêts par défaut)
$commission_mois = $interet_mensuel * 0.01;

// Net = Intérêt - Commission
$net_mois = $interet_mensuel - $commission_mois;

// Cumul: s'ajoute chaque mois jusqu'à échéance
```

---

## 18. PLACEMENTS CREATE - Créer Nouveau Placement
**Endpoint:** `POST /api/v1/placements`  
**Authentification:** Requise (Bearer token)

### Paramètres:
```json
{
  "montant": "float (min 50,000 CDF)",
  "devise": "enum(CDF|USD)",
  "duree_mois": "int (6|12|24|36)",
  "type_interet": "enum(simple|compose)",
  "accepte_conditions": "boolean (true)"
}
```

### Réponse Succès (201):
```json
{
  "success": true,
  "data": {
    "id_placement": 2,
    "reference": "PLC-2026-00002",
    "montant_initial": 1000000,
    "devise": "CDF",
    "taux_interet": 5,
    "duree_mois": 24,
    "date_debut": "2026-11-14",
    "date_echeance": "2028-11-14",
    "statut": "actif",
    "interets_mensuels": 4166.67,
    "interets_totaux_estimes": 100000,
    "commission_estimee": 1000,
    "montant_final_estime": 1099000,
    "rendement_net": 9.9,
    "conditions_acceptees": true
  },
  "message": "Placement créé avec succès"
}
```

### Validations:
- Montant >= 50,000 CDF (paramètre MONTANT_MIN_PLACEMENT)
- Devise valide (CDF ou USD)
- Durée dans {6, 12, 24, 36} mois
- Solde suffisant
- Conditions acceptées

### Logique Backend:
- Valide paramètres
- Débite solde_epargne (ou solde_cdf/usd)
- Récupère taux depuis `Parametres_Systeme` (5% CDF, 3% USD)
- Calcul intérêts totaux estimés
- INSERT dans `Placements` avec statut='actif'
- Génère référence: `PLC-YYYY-XXXXX`
- Date échéance = date_debut + duree_mois
- Crée entrée calendrier pour calcul intérêts mensuels
- Enregistre dans `Audit_Logs`
- Retourne 201

### Taux par Défaut:
```
CDF: 5% annuel
USD: 3% annuel
Intérêts simples (pas de composition)
Calculés mensuellement
Commission: 1% des intérêts
```

---

## 19. PLACEMENTS WITHDRAW - Retirer un Placement
**Endpoint:** `POST /api/v1/placements/{id}/withdraw`  
**Authentification:** Requise (Bearer token)

### Paramètres:
```json
{
  "motif": "string (optionnel: normal|anticipé|emergency)"
}
```

### Réponse Succès (200):
```json
{
  "success": true,
  "data": {
    "id_transaction": 8,
    "reference": "TRX-2026-00008",
    "placement_reference": "PLC-2026-00001",
    "montant_retiré": 524000,
    "capital_initial": 500000,
    "interets_gagnes": 24000,
    "penalite_anticipee": 0,
    "montant_net_verse": 524000,
    "statut_placement": "cloture",
    "date_retrait": "2026-11-14T14:30:00Z",
    "nouveau_solde": 2174000,
    "recu": {
      "url": "/api/v1/placements/1/retrait/recu",
      "format": "pdf|json"
    }
  },
  "message": "Placement retiré avec succès"
}
```

### Logique Retrait Normal (à l'échéance):
- Aucune pénalité
- Capital + Intérêts totaux nets (après commission)
- Crédite solde client
- Statut placement → 'cloture'

### Logique Retrait Anticipé:
- Calcule intérêts prorata (jours écoulés)
- Pénalité: 0.5% du montant initial (configurable)
- Statut placement → 'anticipe'
- Pénalité déduite des intérêts

### Validations:
- Placement existe et appartient au client
- Statut placement = 'actif'
- Si retrait avant échéance: frais anticipés s'appliquent

---

## 20. PLACEMENTS SIMULATION - Simuler Placement
**Endpoint:** `POST /api/v1/placements/simuler`  
**Authentification:** Requise (Bearer token)

### Paramètres:
```json
{
  "montant": "float",
  "devise": "enum(CDF|USD)",
  "duree_mois": "int (6|12|24|36)"
}
```

### Réponse Succès (200):
```json
{
  "success": true,
  "data": {
    "montant_initial": 1000000,
    "devise": "CDF",
    "duree_mois": 12,
    "taux_annuel": 5,
    "interet_mensuel": 4166.67,
    "interets_totaux_bruts": 50000,
    "commission_totale": 500,
    "interets_nets": 49500,
    "penalite_anticipee_estimee": 5000,
    "montant_a_echeance": 1049500,
    "si_retrait_anticipe_mois_6": 1024500,
    "rendement_net": 4.95,
    "rendement_annualize": 5.94
  }
}
```

---

