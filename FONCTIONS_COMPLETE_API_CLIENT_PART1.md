# 📱 FONCTIONS COMPLÈTES API & CLIENT
## African Trust Fund - Documentation Complète des Fonctionnalités

**Date:** 14 Novembre 2026  
**Version:** 1.0  
**Statut:** Production Ready  

---

## 📋 TABLE DES MATIÈRES

1. [AUTHENTIFICATION](#authentification)
2. [TABLEAU DE BORD](#tableau-de-bord)
3. [PRÊTS](#prêts)
4. [TRANSACTIONS](#transactions)
5. [PLACEMENTS & ÉPARGNE](#placements--épargne)
6. [PROFIL CLIENT](#profil-client)
7. [DOCUMENTS & COMPLIANCE](#documents--compliance)
8. [RAPPORTS & RELEVÉS](#rapports--relevés)
9. [FONCTIONS UTILITAIRES](#fonctions-utilitaires)

---

# 🔐 AUTHENTIFICATION

## 1. LOGIN - Connexion Utilisateur
**Endpoint:** `POST /api/v1/auth/login`  
**Authentification:** Non requise

### Paramètres:
```json
{
  "email": "string (email valide)",
  "password": "string (min 6 caractères)"
}
```

### Réponse Succès (200):
```json
{
  "success": true,
  "data": {
    "token": "client_id:timestamp:hash",
    "expires_in": 86400,
    "user": {
      "id_client": "int",
      "nom": "string",
      "email": "string",
      "telephone": "string",
      "type_client": "enum(physique|morale)",
      "statut": "actif|suspendu|inactif"
    }
  },
  "message": "Connexion réussie"
}
```

### Cas d'Erreur:
```json
{
  "success": false,
  "error": "invalid_credentials",
  "message": "Email ou mot de passe incorrect",
  "errors": ["Email ou mot de passe incorrect"]
}
```

### Logique Backend:
- Récupère client par email dans table `Client`
- Valide mot de passe avec `password_verify()`
- Génère token: `{client_id}:{time()}:{hash_hmac('sha256', client_id.time(), CIPHER_KEY)}`
- Enregistre connexion dans `Audit_Logs`
- Retourne token avec expiration 24h
- Rate limiting: 5 tentatives max en 15min

---

## 2. REGISTER - Inscription Nouveau Client
**Endpoint:** `POST /api/v1/auth/register`  
**Authentification:** Non requise

### Paramètres:
```json
{
  "nom": "string (min 2, max 100)",
  "email": "string (email unique)",
  "password": "string (min 8, uppercase + number + special)",
  "telephone": "string (10+ chiffres)",
  "type_client": "enum(physique|morale)",
  "adresse": "string (optional)",
  "ville": "string (optional)",
  "pays": "string (default: RDC)"
}
```

### Réponse Succès (201):
```json
{
  "success": true,
  "data": {
    "id_client": "int",
    "nom": "string",
    "email": "string",
    "type_client": "string",
    "created_at": "2026-11-14T10:30:00Z",
    "message": "Compte créé avec succès. Veuillez vérifier votre email."
  }
}
```

### Validations:
- Email doit être unique (sinon 409)
- Mot de passe: min 8 char, 1 majuscule, 1 chiffre, 1 caractère spécial
- Téléphone: min 10 chiffres
- Type client: `physique` ou `morale`

### Logique Backend:
- INSERT dans `Client` table
- Hash password avec `password_hash($password, PASSWORD_BCRYPT, ['cost' => 12])`
- Soldes initialisés: `solde_cdf=0, solde_usd=0`
- Statut initial: `actif`
- Enregistre dans `Audit_Logs`
- Envoie email de confirmation
- Retourne 201 Created

---

## 3. REFRESH TOKEN - Renouveler Token
**Endpoint:** `POST /api/v1/auth/refresh`  
**Authentification:** Requise (Bearer token)

### Paramètres:
```json
{
  "token": "string (ancien token)"
}
```

### Réponse Succès (200):
```json
{
  "success": true,
  "data": {
    "token": "new_token_hash",
    "expires_in": 86400
  },
  "message": "Token renouvelé"
}
```

### Logique:
- Valide ancien token avec `hash_equals()`
- Génère nouveau token
- Ancien token reste valide pendant 5 minutes (transition)
- Retourne nouveau token avec expiration 24h

---

## 4. LOGOUT - Déconnexion
**Endpoint:** `POST /api/v1/auth/logout`  
**Authentification:** Requise (Bearer token)

### Réponse Succès (200):
```json
{
  "success": true,
  "message": "Déconnexion réussie"
}
```

### Logique:
- Enregistre logout dans `Audit_Logs`
- Token invalidé immédiatement côté client
- Nettoyage cache serveur optionnel

---

## 5. PROFILE - Récupérer Profil Utilisateur
**Endpoint:** `GET /api/v1/auth/profile`  
**Authentification:** Requise (Bearer token)

### Réponse Succès (200):
```json
{
  "success": true,
  "data": {
    "id_client": "int",
    "nom": "string",
    "email": "string",
    "telephone": "string",
    "type_client": "physique|morale",
    "adresse": "string",
    "ville": "string",
    "pays": "string",
    "avatar_url": "url|null",
    "solde_cdf": "float",
    "solde_usd": "float",
    "solde_epargne_cdf": "float",
    "solde_epargne_usd": "float",
    "statut": "actif|suspendu",
    "created_at": "2026-11-14T10:30:00Z",
    "derniere_connexion": "2026-11-14T14:45:00Z"
  }
}
```

### Query SQL Utilisée:
```sql
SELECT 
  id_client, nom, email, telephone, type_client,
  adresse, ville, pays, avatar_url,
  solde_cdf, solde_usd,
  solde_epargne_cdf, solde_epargne_usd,
  statut, created_at, derniere_connexion
FROM Client
WHERE id_client = ?
```

---

# 📊 TABLEAU DE BORD

## 6. DASHBOARD - Récupérer Toutes les Données du Dashboard
**Endpoint:** `GET /api/v1/dashboard`  
**Authentification:** Requise (Bearer token)

### Réponse Succès (200):
```json
{
  "success": true,
  "data": {
    "client_info": {
      "nom": "Jean Paul",
      "email": "jean@example.com",
      "type_client": "physique",
      "statut": "actif"
    },
    "soldes": {
      "solde_cdf": 1250000.50,
      "solde_usd": 500.25,
      "solde_epargne_cdf": 500000,
      "solde_epargne_usd": 0,
      "total_combined": 2750000.50
    },
    "prets": {
      "total_actifs": 3,
      "total_en_retard": 1,
      "montant_total_dû": 800000,
      "prochain_paiement": {
        "date": "2026-11-20",
        "montant": 150000
      }
    },
    "placements": {
      "nombre_actifs": 2,
      "capital_investi": 1000000,
      "interets_gagnes": 45000,
      "estimation_finale": 1045000
    },
    "transactions_recentes": [
      {
        "id": "int",
        "type": "depot|retrait|virement",
        "montant": "float",
        "devise": "CDF|USD",
        "statut": "completed|pending",
        "date": "2026-11-14T10:30:00Z"
      }
    ]
  }
}
```

### Queries SQL Combinées:
```sql
-- Soldes du client
SELECT solde_cdf, solde_usd, solde_epargne_cdf, solde_epargne_usd
FROM Client WHERE id_client = ?

-- Statistiques prêts
SELECT 
  COUNT(*) as total_actifs,
  SUM(CASE WHEN statut='en_retard' THEN 1 ELSE 0 END) as total_en_retard,
  SUM(solde_dû) as montant_total_dû
FROM Prets WHERE id_client = ? AND statut IN ('decaisse', 'en_cours', 'en_retard')

-- Prochaine échéance
SELECT MIN(date_echeance) as date, SUM(montant_dû) as montant
FROM Echeances_Pret 
WHERE id_pret IN (SELECT id_pret FROM Prets WHERE id_client = ?)
AND date_echeance >= CURDATE()

-- Statistiques placements
SELECT 
  COUNT(*) as nombre_actifs,
  SUM(montant_initial) as capital_investi,
  SUM(interets_cumules) as interets_gagnes
FROM Placements WHERE id_client = ? AND statut='actif'

-- Transactions récentes
SELECT * FROM Transactions
WHERE id_client = ?
ORDER BY date_transaction DESC
LIMIT 5
```

### Logique Backend:
- Agrège données de 5+ tables
- Cache le résultat 30 secondes
- Calcul automatique des totaux
- Estimation de revenus futurs

---

