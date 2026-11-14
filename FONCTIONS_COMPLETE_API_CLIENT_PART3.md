# 💳 TRANSACTIONS - Gestion Financière

## 11. TRANSACTIONS - Liste Toutes les Transactions
**Endpoint:** `GET /api/v1/transactions?page=1&per_page=10&type=all&devise=CDF&sort=date_desc`  
**Authentification:** Requise (Bearer token)

### Paramètres Query:
```
page=int (default 1)
per_page=int (default 10, max 50)
type=string (all|depot|retrait|virement)
devise=string (CDF|USD|all)
sort=string (date_desc|date_asc|montant_desc|montant_asc)
date_from=string (YYYY-MM-DD)
date_to=string (YYYY-MM-DD)
```

### Réponse Succès (200):
```json
{
  "success": true,
  "data": [
    {
      "id_transaction": 1,
      "reference": "TRX-2026-00001",
      "type": "depot",
      "montant": 500000,
      "devise": "CDF",
      "frais": 0,
      "montant_net": 500000,
      "statut": "complete",
      "date_transaction": "2026-11-14T10:30:00Z",
      "methode_paiement": "mobile_money",
      "description": "Dépôt via Orange Money",
      "reference_externe": "OMD-123456"
    },
    {
      "id_transaction": 2,
      "reference": "TRX-2026-00002",
      "type": "virement",
      "montant": 100000,
      "devise": "CDF",
      "frais": 500,
      "montant_net": 99500,
      "statut": "complete",
      "date_transaction": "2026-11-14T12:15:00Z",
      "beneficiaire": "Paul Kapenda",
      "reference_interne": "CLIENT-003",
      "motif": "Remboursement dette"
    }
  ],
  "pagination": {
    "page": 1,
    "per_page": 10,
    "total": 42,
    "total_pages": 5,
    "has_next": true,
    "has_prev": false
  },
  "timestamp": "2026-11-14T14:30:00Z"
}
```

### Query SQL:
```sql
SELECT 
  id_transaction, reference, type, montant, devise,
  COALESCE(frais, 0) as frais,
  (montant - COALESCE(frais, 0)) as montant_net,
  statut, date_transaction, methode_paiement,
  description, reference_externe
FROM Transactions
WHERE id_client = ?
  AND (? IS NULL OR type = ?)
  AND (? IS NULL OR devise = ?)
  AND (? IS NULL OR DATE(date_transaction) >= ?)
  AND (? IS NULL OR DATE(date_transaction) <= ?)
ORDER BY date_transaction DESC
LIMIT ? OFFSET ?
```

---

## 12. TRANSACTIONS DEPOSIT - Créer un Dépôt
**Endpoint:** `POST /api/v1/transactions/deposit`  
**Authentification:** Requise (Bearer token)

### Paramètres:
```json
{
  "montant": "float (> 0)",
  "devise": "enum(CDF|USD)",
  "methode_paiement": "enum(mobile_money|carte|virement_bancaire|espece)",
  "description": "string (optionnel, max 255)",
  "reference_externe": "string (optionnel)"
}
```

### Réponse Succès (201):
```json
{
  "success": true,
  "data": {
    "id_transaction": 5,
    "reference": "TRX-2026-00005",
    "type": "depot",
    "montant": 500000,
    "devise": "CDF",
    "frais": 0,
    "montant_net": 500000,
    "statut": "complete",
    "date_transaction": "2026-11-14T14:30:00Z",
    "solde_avant": 1250000,
    "solde_apres": 1750000,
    "recu": {
      "url": "/api/v1/transactions/5/recu",
      "format": "pdf|json"
    }
  },
  "message": "Dépôt enregistré avec succès"
}
```

### Logique Backend:
- Valide montant > 0
- Vérifie devise valide
- INSERT dans `Transactions` avec statut='complete'
- UPDATE `Client` solde (solde_cdf ou solde_usd) += montant
- Frais = 0 pour dépôt (optionnel selon config)
- Génère référence: `TRX-YYYY-XXXXX`
- Enregistre dans `Audit_Logs`
- Crée reçu PDF
- Retourne 201

### Méthodes de Paiement Supportées:
```
mobile_money   : Orange Money, Airtel Money, Vodafone Cash
carte          : Visa, Mastercard
virement_bancaire : Virement banque
espece         : Dépôt en espèces à guichet
```

---

## 13. TRANSACTIONS WITHDRAWAL - Créer un Retrait
**Endpoint:** `POST /api/v1/transactions/withdrawal`  
**Authentification:** Requise (Bearer token)

### Paramètres:
```json
{
  "montant": "float (> 0)",
  "devise": "enum(CDF|USD)",
  "methode_paiement": "enum(mobile_money|carte|virement_bancaire|espece)",
  "motif": "string (optionnel, max 255)"
}
```

### Réponse Succès (201):
```json
{
  "success": true,
  "data": {
    "id_transaction": 6,
    "reference": "TRX-2026-00006",
    "type": "retrait",
    "montant": 100000,
    "devise": "CDF",
    "frais": 1000,
    "pourcentage_frais": 1,
    "montant_net": 99000,
    "statut": "pending",
    "date_transaction": "2026-11-14T14:30:00Z",
    "date_execution_estimee": "2026-11-14T18:00:00Z",
    "solde_avant": 1750000,
    "solde_apres": 1650000,
    "recu": {
      "url": "/api/v1/transactions/6/recu",
      "format": "pdf|json"
    }
  },
  "message": "Retrait demandé. Traitement en cours."
}
```

### Validations:
- Montant > 0
- Solde suffisant (montant + frais)
- Devise correcte
- Méthode valide

### Logique Backend:
- Calcul frais: 1% du montant (configurable dans `Parametres_Systeme`)
- Valide solde suffisant: `solde >= montant + frais`
- INSERT dans `Transactions` avec statut='pending'
- UPDATE `Client` solde -= (montant + frais)
- INSERT dans `Commissions` pour frais prélevés
- Enregistre dans `Audit_Logs`
- Crée reçu PDF
- Status: 'pending' (traité après 24-48h selon méthode)
- Retourne 201

### Commission de Retrait:
```
Pourcentage: 1% (paramètre COMMISSION_RETRAIT)
Minimum: 500 CDF
Maximum: 50,000 CDF

Exemple: Retrait 100,000 CDF
  → Frais = 100,000 * 0.01 = 1,000 CDF
  → Montant net reçu = 99,000 CDF
```

---

## 14. TRANSACTIONS TRANSFER - Virement Vers Autre Client
**Endpoint:** `POST /api/v1/transactions/transfer`  
**Authentification:** Requise (Bearer token)

### Paramètres:
```json
{
  "montant": "float (> 0)",
  "devise": "enum(CDF|USD)",
  "beneficiaire_email": "string (email du destinataire)",
  "ou": {
    "beneficiaire_id": "int (ID du client)"
  },
  "motif": "string (optionnel, max 255)",
  "reference": "string (optionnel)"
}
```

### Réponse Succès (201):
```json
{
  "success": true,
  "data": {
    "id_transaction": 7,
    "reference": "TRX-2026-00007",
    "type": "virement",
    "montant": 250000,
    "devise": "CDF",
    "frais": 1250,
    "pourcentage_frais": 0.5,
    "montant_net_recu_beneficiaire": 248750,
    "statut": "complete",
    "date_transaction": "2026-11-14T14:30:00Z",
    "beneficiaire": {
      "id_client": 2,
      "nom": "Marie Dupont",
      "email": "marie@example.com"
    },
    "solde_avant_emetteur": 1650000,
    "solde_apres_emetteur": 1398750,
    "recu": {
      "url": "/api/v1/transactions/7/recu",
      "format": "pdf|json"
    }
  },
  "message": "Virement effectué avec succès"
}
```

### Validations:
- Montant > 0
- Email ou ID du bénéficiaire valide
- Bénéficiaire existe et statut='actif'
- Solde suffisant (montant + frais)
- Devise correspond
- Pas de virement à soi-même

### Logique Backend:
- Cherche bénéficiaire par email ou ID
- Calcul frais: 0.5% du montant
- Valide solde suffisant
- INSERT dans `Transactions` (2 lignes: débit emetteur + crédit bénéficiaire)
- UPDATE `Client` solde des deux clients
- INSERT dans `Commissions`
- Notification email au bénéficiaire
- Enregistre dans `Audit_Logs`
- Crée reçu PDF
- Retourne 201

### Commission de Virement:
```
Pourcentage: 0.5% (paramètre COMMISSION_VIREMENT)
Minimum: 250 CDF
Maximum: 25,000 CDF

Exemple: Virement 500,000 CDF
  → Frais = 500,000 * 0.005 = 2,500 CDF
  → Montant reçu bénéficiaire = 497,500 CDF
```

---

## 15. TRANSACTIONS RECU - Télécharger Reçu Transaction
**Endpoint:** `GET /api/v1/transactions/{id}/recu?format=pdf`  
**Authentification:** Requise (Bearer token)

### Paramètres Query:
```
format=string (pdf|json, default pdf)
```

### Réponse PDF (Content-Type: application/pdf):
```
Reçu de Transaction
─────────────────────
ATF - African Trust Fund
Date: 14 Novembre 2026

Détails Transaction:
  Référence: TRX-2026-00007
  Type: Virement
  Montant: 250,000 CDF
  Frais: 1,250 CDF (0.5%)
  Montant Net: 248,750 CDF
  
Expéditeur:
  Jean Paul Kadeghu
  jean@example.com

Bénéficiaire:
  Marie Dupont
  marie@example.com

Statut: Complété
Date: 14 Novembre 2026 14:30

Code QR: [verification_code]
```

### Réponse JSON (format=json):
```json
{
  "success": true,
  "data": {
    "reference": "TRX-2026-00007",
    "date": "2026-11-14T14:30:00Z",
    "type": "virement",
    "montant": 250000,
    "frais": 1250,
    "montant_net": 248750,
    "emetteur": "Jean Paul Kadeghu",
    "beneficiaire": "Marie Dupont",
    "motif": "Remboursement",
    "statut": "complete"
  }
}
```

---

