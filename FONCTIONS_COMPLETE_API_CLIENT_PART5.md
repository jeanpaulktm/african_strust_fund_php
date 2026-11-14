# 👤 PROFIL CLIENT

## 21. PROFIL VIEW - Consulter Profil Complet
**Endpoint:** `GET /api/v1/profil`  
**Authentification:** Requise (Bearer token)

### Réponse Succès (200):
```json
{
  "success": true,
  "data": {
    "informations_personnelles": {
      "id_client": 1,
      "nom": "Jean Paul Kadeghu",
      "email": "jean@example.com",
      "telephone": "0812345678",
      "type_client": "physique",
      "statut": "actif",
      "date_adhesion": "2026-10-14",
      "avatar_url": "https://api.atf.local/uploads/avatars/1.jpg",
      "date_modification": "2026-11-14"
    },
    "adresse": {
      "rue": "Rue Principale 123",
      "ville": "Kinshasa",
      "province": "Kinshasa",
      "pays": "RDC",
      "code_postal": "00000"
    },
    "informations_professionnelles": {
      "profession": "Développeur",
      "entreprise": "Tech Solutions",
      "secteur": "Technologie",
      "statut_emploi": "employé|independant|entrepreneur"
    },
    "identite": {
      "type_piece": "CNI|Passeport|Permis",
      "numero": "****1234",
      "date_expiration": "2028-10-14",
      "date_delivrance": "2020-10-14",
      "lieu_delivrance": "Kinshasa"
    },
    "documents": {
      "pieces_jointes": [
        {
          "id": "int",
          "type": "CNI|Passeport|Permis",
          "nom_fichier": "cni_scan.pdf",
          "date_upload": "2026-10-14",
          "statut_verification": "approuve|en_attente|rejete",
          "url": "/api/v1/documents/1"
        }
      ],
      "documents_supplementaires": [
        {
          "type": "contrat_travail|declaration_revenus",
          "date_upload": "2026-10-14"
        }
      ]
    },
    "soldes": {
      "solde_cdf": 2100000,
      "solde_usd": 500,
      "solde_epargne_cdf": 500000,
      "solde_epargne_usd": 0,
      "total_actifs": 2600500
    },
    "statistiques": {
      "nombre_prets": 3,
      "nombre_prets_actifs": 2,
      "montant_prets_actifs": 1500000,
      "nombre_placements": 2,
      "nombre_placements_actifs": 2,
      "montant_placements": 1500000,
      "nombre_transactions": 42,
      "transactions_30j": 12
    },
    "preferences": {
      "langue": "fr",
      "devise_principale": "CDF",
      "notifications_email": true,
      "notifications_sms": false,
      "accepte_newsletter": true,
      "2fa_active": false
    }
  },
  "timestamp": "2026-11-14T14:30:00Z"
}
```

### Queries SQL:
```sql
-- Infos client
SELECT * FROM Client WHERE id_client = ?

-- Identité
SELECT * FROM Client_Documents WHERE id_client = ? AND type='identite'

-- Statistiques
SELECT 
  (SELECT COUNT(*) FROM Prets WHERE id_client = ?) as nb_prets,
  (SELECT COUNT(*) FROM Prets WHERE id_client = ? AND statut IN ('en_cours', 'decaisse')) as nb_actifs,
  (SELECT COUNT(*) FROM Placements WHERE id_client = ? AND statut='actif') as nb_placements,
  (SELECT COUNT(*) FROM Transactions WHERE id_client = ?) as total_transactions
```

---

## 22. PROFIL UPDATE - Modifier Profil
**Endpoint:** `PUT /api/v1/profil`  
**Authentification:** Requise (Bearer token)

### Paramètres:
```json
{
  "nom": "string (optionnel)",
  "telephone": "string (optionnel)",
  "adresse": "string (optionnel)",
  "ville": "string (optionnel)",
  "profession": "string (optionnel)",
  "entreprise": "string (optionnel)",
  "langue": "enum(fr|en|pt)",
  "devise_principale": "enum(CDF|USD)",
  "notifications_email": "boolean",
  "notifications_sms": "boolean"
}
```

### Réponse Succès (200):
```json
{
  "success": true,
  "data": {
    "id_client": 1,
    "nom": "Jean Paul Kadeghu",
    "telephone": "0812345679",
    "adresse": "Rue Nouvelle 456",
    "ville": "Kinshasa",
    "profession": "Ingénieur",
    "langue": "fr",
    "devise_principale": "CDF",
    "date_modification": "2026-11-14T14:30:00Z"
  },
  "message": "Profil mis à jour avec succès"
}
```

### Validations:
- Nom: min 2 caractères
- Téléphone: min 10 chiffres (unique par client)
- Adresse/Ville: max 100 caractères
- Profession: dans liste prédéfinie
- Email NON modifiable via endpoint

### Logique Backend:
- UPDATE Client table avec champs fournis
- Valide données avant mise à jour
- Enregistre modification dans `Audit_Logs`
- Notification optionnelle utilisateur
- Retourne profil mis à jour

---

## 23. PROFIL AVATAR - Uploader Avatar
**Endpoint:** `POST /api/v1/profil/avatar`  
**Authentification:** Requise (Bearer token)  
**Content-Type:** multipart/form-data

### Paramètres:
```
avatar: file (JPG|PNG|WebP, max 2MB)
```

### Réponse Succès (200):
```json
{
  "success": true,
  "data": {
    "id_client": 1,
    "avatar_url": "https://api.atf.local/uploads/avatars/1_1699953000.jpg",
    "avatar_thumb_url": "https://api.atf.local/uploads/avatars/thumbs/1_200x200.jpg",
    "size_bytes": 156234,
    "upload_date": "2026-11-14T14:30:00Z"
  },
  "message": "Avatar uploadé avec succès"
}
```

### Validations:
- Format: JPG, PNG, WebP uniquement
- Taille: < 2MB
- Dimensions: >= 200x200px
- MIME type correct

### Logique Backend:
- Valide fichier
- Redimensionne image (200x200, 500x500)
- Nomme fichier: `{id_client}_{timestamp}.{ext}`
- Stocke dans `/uploads/avatars/`
- Génère thumb 200x200px
- UPDATE Client avatar_url
- Enregistre dans `Audit_Logs`
- Supprime ancien avatar
- Retourne 200

---

## 24. DOCUMENTS - Consulter/Uploader Documents
**Endpoint:** `GET /api/v1/profil/documents`  
**Authentification:** Requise (Bearer token)

### Réponse Succès (200):
```json
{
  "success": true,
  "data": {
    "documents_identite": [
      {
        "id_document": 1,
        "type": "CNI",
        "numero": "****1234",
        "date_delivrance": "2020-10-14",
        "date_expiration": "2028-10-14",
        "fichier": "cni_scan.pdf",
        "url": "https://api.atf.local/uploads/documents/1.pdf",
        "date_upload": "2026-10-14",
        "statut_verification": "approuve",
        "approuve_par": "Admin Name",
        "date_approbation": "2026-10-15"
      }
    ],
    "documents_supplementaires": [
      {
        "id_document": 2,
        "type": "contrat_travail",
        "nom_fichier": "contrat_2026.pdf",
        "url": "https://api.atf.local/uploads/documents/2.pdf",
        "date_upload": "2026-10-14",
        "statut_verification": "en_attente"
      }
    ]
  }
}
```

### POST Upload Document:
**Endpoint:** `POST /api/v1/profil/documents`

```json
{
  "type": "enum(CNI|Passeport|Permis|contrat_travail|declaration_revenus|preuve_adresse)",
  "fichier": "file (PDF|JPG|PNG, max 5MB)"
}
```

### Réponse:
```json
{
  "success": true,
  "data": {
    "id_document": 3,
    "type": "contrat_travail",
    "url": "https://api.atf.local/uploads/documents/3.pdf",
    "statut_verification": "en_attente",
    "date_upload": "2026-11-14T14:30:00Z"
  },
  "message": "Document uploadé. En attente de vérification."
}
```

---

# 📋 DOCUMENTS & COMPLIANCE

## 25. CONTRAT PRET - Télécharger Contrat Prêt
**Endpoint:** `GET /api/v1/prets/{id}/contrat?format=pdf`  
**Authentification:** Requise (Bearer token)

### Réponse PDF (Content-Type: application/pdf):
```
═══════════════════════════════════════════════════════════
            CONTRAT DE PRÊT PERSONNEL
═══════════════════════════════════════════════════════════

ENTRE LES SOUSSIGNÉS:

1) AFRICAN TRUST FUND (ATF)
   Représentée par: Administrateur
   Siège: Kinshasa, RDC
   Ci-après dénommée "Le Prêteur"

2) Jean Paul Kadeghu
   Email: jean@example.com
   Adresse: Rue Principale 123, Kinshasa
   Ci-après dénommé(e) "L'Emprunteur"

━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

ARTICLE 1: MONTANT ET TERMES
  Montant: 500,000 CDF
  Taux d'intérêt annuel: 12%
  Durée: 12 mois
  Date de décaissement: 25 Octobre 2026
  Date d'échéance: 25 Octobre 2027

ARTICLE 2: MODALITÉS DE REMBOURSEMENT
  Fréquence: Mensuelle
  Montant mensuel: 46,667 CDF
  Date limite: 25 de chaque mois
  Lieu: Plateforme ATF

ARTICLE 3: INTÉRÊTS
  Intérêt total: 60,000 CDF
  Montant total à rembourser: 560,000 CDF

ARTICLE 4: CONDITIONS PARTICULIÈRES
  - Remboursement anticipé autorisé sans pénalité
  - En cas de non-paiement: frais de retard 1% par mois
  - Contentieux après 3 mois de non-paiement

SIGNATURES:

Le Prêteur: ________________    Date: 25 Octobre 2026
L'Emprunteur: _____________     Date: 25 Octobre 2026
═══════════════════════════════════════════════════════════
```

---

## 26. RELEVE COMPTE - Relevé Complet
**Endpoint:** `GET /api/v1/releve?date_debut=2026-10-14&date_fin=2026-11-14&format=pdf`  
**Authentification:** Requise (Bearer token)

### Paramètres Query:
```
date_debut=string (YYYY-MM-DD)
date_fin=string (YYYY-MM-DD)
format=string (pdf|csv|json)
type=string (all|prets|transactions|placements)
```

### Réponse PDF: Relevé formaté avec tous les mouvements

### Réponse JSON:
```json
{
  "success": true,
  "data": {
    "periode": {
      "date_debut": "2026-10-14",
      "date_fin": "2026-11-14"
    },
    "solde_initial": {
      "solde_cdf": 750000,
      "solde_usd": 0
    },
    "mouvements": {
      "depots": 1000000,
      "retraits": 100000,
      "virements_envoyes": 250000,
      "virements_recus": 50000,
      "remboursements_prets": 100000,
      "placements": 500000
    },
    "frais": {
      "frais_retrait": 1000,
      "frais_virement": 1250,
      "commission_placement": 5000,
      "total_frais": 7250
    },
    "interets": {
      "interets_placements": 15000,
      "interets_prets": -60000,
      "net_interets": -45000
    },
    "solde_final": {
      "solde_cdf": 2145750,
      "solde_usd": 0
    },
    "transactions": [
      {
        "date": "2026-10-20",
        "reference": "TRX-2026-00001",
        "type": "depot",
        "montant": 1000000,
        "frais": 0,
        "solde_apres": 1750000
      }
    ]
  }
}
```

---

# 💾 AUTRES FONCTIONS BACKEND CLIENT

## 27. SIMULATEUR PRET - Simulation Prêt
**Endpoint:** `POST /api/v1/prets/simuler`  
**Authentification:** Requise (Bearer token)

### Paramètres:
```json
{
  "id_produit": "int (1-4)",
  "montant": "float",
  "devise": "enum(CDF|USD)",
  "duree_mois": "int (3-60)"
}
```

### Réponse:
```json
{
  "success": true,
  "data": {
    "montant_emprunt": 1000000,
    "devise": "CDF",
    "duree_mois": 24,
    "taux_applique": 12,
    "mensualite": 45833.33,
    "interet_total": 100000,
    "montant_total_rembourse": 1100000,
    "date_debut_estime": "2026-11-14",
    "date_fin_estimee": "2028-11-14",
    "echeances_preview": [
      {
        "num": 1,
        "date": "2026-12-14",
        "capital": 41666.67,
        "interet": 10000,
        "total": 51666.67,
        "solde": 958333.33
      }
    ]
  }
}
```

---

## 28. TAUX CHANGE - Récupérer Taux de Change
**Endpoint:** `GET /api/v1/taux-change`  
**Authentification:** Non requise

### Réponse:
```json
{
  "success": true,
  "data": {
    "taux_usd_cdf": 2300,
    "date_mise_a_jour": "2026-11-14T08:00:00Z",
    "source": "Banque Centrale"
  }
}
```

---

## 29. PARAMETRES SYSTEME - Récupérer Tarifs/Commissions
**Endpoint:** `GET /api/v1/parametres`  
**Authentification:** Non requise

### Réponse:
```json
{
  "success": true,
  "data": {
    "commissions": {
      "retrait": 0.01,
      "virement": 0.005,
      "placement": 0.01
    },
    "taux_placement": {
      "cdf": 0.05,
      "usd": 0.03
    },
    "taux_pret": {
      "classique_cdf": 0.12,
      "classique_usd": 0.08,
      "pme_tpe": 0.10,
      "personnel": 0.15
    },
    "limites": {
      "montant_min_placement": 50000,
      "montant_min_pret": 100000,
      "retrait_max_quotidien": 10000000
    }
  }
}
```

---

## 30. NOTIFICATION PREFERENCES - Gérer Notifications
**Endpoint:** `PUT /api/v1/notifications/preferences`  
**Authentification:** Requise (Bearer token)

### Paramètres:
```json
{
  "email_transactions": true,
  "email_prets": true,
  "email_placements": true,
  "email_newsletter": false,
  "sms_alerts": true,
  "push_notifications": true
}
```

---

## 📊 RÉSUMÉ COMPLET DES FONCTIONS

| # | Endpoint | Méthode | Auth | Description |
|---|----------|---------|------|------------|
| 1 | `/auth/login` | POST | ❌ | Connexion utilisateur |
| 2 | `/auth/register` | POST | ❌ | Inscription nouveau client |
| 3 | `/auth/refresh` | POST | ✅ | Renouveler token |
| 4 | `/auth/logout` | POST | ✅ | Déconnexion |
| 5 | `/auth/profile` | GET | ✅ | Récupérer profil |
| 6 | `/dashboard` | GET | ✅ | Tableau de bord complet |
| 7 | `/prets` | GET | ✅ | Liste prêts (paginated) |
| 8 | `/prets/{id}` | GET | ✅ | Détails + échéancier |
| 9 | `/prets/demand` | POST | ✅ | Créer demande prêt |
| 10 | `/prets/{id}/repay` | POST | ✅ | Rembourser prêt |
| 11 | `/transactions` | GET | ✅ | Liste transactions |
| 12 | `/transactions/deposit` | POST | ✅ | Créer dépôt |
| 13 | `/transactions/withdrawal` | POST | ✅ | Créer retrait |
| 14 | `/transactions/transfer` | POST | ✅ | Virement entre clients |
| 15 | `/transactions/{id}/recu` | GET | ✅ | Reçu transaction |
| 16 | `/placements` | GET | ✅ | Liste placements |
| 17 | `/placements/{id}` | GET | ✅ | Détails placement |
| 18 | `/placements` | POST | ✅ | Créer placement |
| 19 | `/placements/{id}/withdraw` | POST | ✅ | Retirer placement |
| 20 | `/placements/simuler` | POST | ✅ | Simulation placement |
| 21 | `/profil` | GET | ✅ | Consulter profil complet |
| 22 | `/profil` | PUT | ✅ | Modifier profil |
| 23 | `/profil/avatar` | POST | ✅ | Uploader avatar |
| 24 | `/profil/documents` | GET | ✅ | Consulter documents |
| 24b | `/profil/documents` | POST | ✅ | Uploader document |
| 25 | `/prets/{id}/contrat` | GET | ✅ | Contrat PDF |
| 26 | `/releve` | GET | ✅ | Relevé compte |
| 27 | `/prets/simuler` | POST | ✅ | Simulateur prêt |
| 28 | `/taux-change` | GET | ❌ | Taux USD/CDF |
| 29 | `/parametres` | GET | ❌ | Tarifs et commissions |
| 30 | `/notifications/preferences` | PUT | ✅ | Gérer notifications |

**TOTAL: 30 endpoints API complètement documentés et fonctionnels**

---

## 🎯 PRÊT POUR MOBILE FLUTTER!

Tous les endpoints sont disponibles pour l'intégration dans l'application mobile.
Documentation complète avec exemples JSON et validation.
