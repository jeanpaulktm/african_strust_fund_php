# 📱 API Mobile - Documentation Complète

**African Trust Fund - Mobile API v1.0**  
Base URL: `https://api.african-trust-fund.com/api/v1` (ou `http://localhost/african-trust-fund/api/v1`)

---

## 📋 Table des matières

1. [Authentification](#authentification)
2. [Format des réponses](#format-des-réponses)
3. [Endpoints d'authentification](#endpoints-dauthentification)
4. [Endpoints du dashboard](#endpoints-du-dashboard)
5. [Endpoints des prêts](#endpoints-des-prêts)
6. [Endpoints des transactions](#endpoints-des-transactions)
7. [Endpoints des placements](#endpoints-des-placements)
8. [Endpoints du profil](#endpoints-du-profil)
9. [Gestion des erreurs](#gestion-des-erreurs)
10. [Codes d'erreur](#codes-derreur)
11. [Exemples avec Dart/Flutter](#exemples-avec-dartflutter)

---

## 🔐 Authentification

Tous les endpoints (sauf login/register) requièrent un token Bearer dans le header `Authorization`.

### Header requis
```
Authorization: Bearer {token}
Content-Type: application/json
```

Le token a un format spécial: `{client_id}:{timestamp}:{hash}`

**Durée de vie du token**: 24 heures

---

## 📦 Format des réponses

### Succès (200, 201)
```json
{
  "success": true,
  "code": 200,
  "message": "Success message",
  "data": {...},
  "timestamp": 1699949400
}
```

### Erreur (4xx, 5xx)
```json
{
  "success": false,
  "code": 400,
  "message": "Error message",
  "errors": ["Field error 1", "Field error 2"],
  "timestamp": 1699949400
}
```

### Réponse paginée
```json
{
  "success": true,
  "code": 200,
  "data": [...],
  "pagination": {
    "page": 1,
    "per_page": 10,
    "total": 50,
    "total_pages": 5,
    "has_next": true,
    "has_prev": false
  },
  "timestamp": 1699949400
}
```

---

## 🔑 Endpoints d'authentification

### 1. Connexion
```
POST /auth/login
```

**Body:**
```json
{
  "telephone": "+243900000000",
  "password": "motdepasse123"
}
```

**Réponse (200):**
```json
{
  "success": true,
  "code": 200,
  "message": "Connexion réussie",
  "data": {
    "client_id": 1,
    "nom_complet": "Jean Paul",
    "telephone": "+243900000000",
    "email": "jean@example.com",
    "photo_profil": "/uploads/pp_xxx.jpg",
    "token": "1:1699949400:abc123hash",
    "soldes": {
      "cdf": 150000,
      "usd": 500,
      "epargne_cdf": 50000,
      "epargne_usd": 200
    },
    "expires_in": 86400
  }
}
```

### 2. Inscription
```
POST /auth/register
```

**Body:**
```json
{
  "prenom": "Jean",
  "nom": "Paul",
  "telephone": "+243900000000",
  "email": "jean@example.com",
  "password": "motdepasse123"
}
```

**Réponse (201):**
```json
{
  "success": true,
  "code": 201,
  "message": "Inscription réussie",
  "data": {
    "client_id": 1,
    "nom_complet": "Jean Paul",
    "telephone": "+243900000000",
    "email": "jean@example.com",
    "token": "1:1699949400:abc123hash"
  }
}
```

### 3. Renouveler le token
```
POST /auth/refresh
Headers: Authorization: Bearer {token}
```

**Réponse (200):**
```json
{
  "success": true,
  "data": {
    "token": "1:1699949401:abc123hash",
    "expires_in": 86400
  }
}
```

### 4. Profil utilisateur
```
GET /auth/profile
Headers: Authorization: Bearer {token}
```

**Réponse (200):**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "nom_complet": "Jean Paul",
    "prenom": "Jean",
    "nom": "Paul",
    "telephone": "+243900000000",
    "email": "jean@example.com",
    "photo_profil": "/uploads/pp_xxx.jpg",
    "date_naissance": "1990-01-01",
    "profession": "Ingénieur",
    "adresse": "123 Rue de la Paix",
    "ville": "Kinshasa",
    "pays": "RDC",
    "document": {
      "type": "Passeport",
      "numero": "AB123456",
      "date_expiration": "2025-12-31"
    },
    "statut": "actif",
    "soldes": {
      "cdf": 150000,
      "usd": 500,
      "epargne_cdf": 50000,
      "epargne_usd": 200
    },
    "created_at": "2024-01-01T10:00:00"
  }
}
```

### 5. Déconnexion
```
POST /auth/logout
Headers: Authorization: Bearer {token}
```

**Réponse (200):**
```json
{
  "success": true,
  "message": "Déconnexion réussie"
}
```

---

## 📊 Endpoints du dashboard

### Récupérer le dashboard
```
GET /dashboard
Headers: Authorization: Bearer {token}
```

**Réponse (200):**
```json
{
  "success": true,
  "data": {
    "client": {
      "id": 1,
      "nom_complet": "Jean Paul",
      "photo_profil": "/uploads/pp_xxx.jpg"
    },
    "soldes": {
      "cdf": 150000,
      "usd": 500,
      "epargne_cdf": 50000,
      "epargne_usd": 200,
      "total_cdf": 200000,
      "total_usd": 700
    },
    "prets": {
      "total": 2,
      "en_cours": 1,
      "en_retard": 0,
      "soldes": 1,
      "capital_restant": 100000,
      "interets_restants": 5000
    },
    "placements": {
      "total": 3,
      "actifs": 2,
      "clotures": 1,
      "capital_investi": 500000,
      "interets_gains": 15000
    },
    "dernières_transactions": [
      {
        "id": 1,
        "type": "Depot",
        "montant": 50000,
        "devise": "CDF",
        "mode": "En ligne",
        "statut": "approuve",
        "description": "Dépôt de fonds",
        "date": "2024-11-13T15:30:00"
      }
    ],
    "prets_actifs": [
      {
        "id": 1,
        "montant": 200000,
        "taux": 12.5,
        "statut": "en_cours",
        "capital_restant": 100000,
        "date_echeance": "2025-12-31"
      }
    ]
  }
}
```

---

## 💰 Endpoints des prêts

### Lister les prêts
```
GET /prets?page=1&per_page=10&statut=en_cours
Headers: Authorization: Bearer {token}
```

**Paramètres:**
- `page`: Numéro de page (défaut: 1)
- `per_page`: Résultats par page (défaut: 10, max: 50)
- `statut`: Filtrer par statut (soumis, en_analyse, approuve, decaisse, en_cours, en_retard, solde, rejete, annule)

**Réponse (200):**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "montant": 200000,
      "taux_interet": 12.5,
      "statut": "en_cours",
      "capital_restant": 100000,
      "interets_restants": 5000,
      "produit": "Crédit Personnel",
      "date_debut": "2024-01-01",
      "date_fin": "2025-12-31",
      "date_echeance": "2024-12-01"
    }
  ],
  "pagination": {...}
}
```

### Détails d'un prêt
```
GET /prets/{id}
Headers: Authorization: Bearer {token}
```

**Réponse (200):**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "montant": 200000,
    "taux_interet": 12.5,
    "statut": "en_cours",
    "capital_restant": 100000,
    "interets_restants": 5000,
    "produit": {
      "nom": "Crédit Personnel",
      "description": "Crédit pour besoins personnels"
    },
    "dates": {
      "debut": "2024-01-01",
      "fin": "2025-12-31",
      "prochaine_echeance": "2024-12-01"
    },
    "duree": {
      "mois": 24,
      "total_echeances": 24,
      "payees": 11
    },
    "echeances": [
      {
        "id": 1,
        "numero": 1,
        "montant_capital": 8333.33,
        "montant_interet": 2083.33,
        "montant_penalite": 0,
        "date_due": "2024-02-01",
        "date_paiement": "2024-02-01",
        "statut": "payee"
      }
    ]
  }
}
```

### Créer une demande de prêt
```
POST /prets
Headers: Authorization: Bearer {token}
Content-Type: application/json
```

**Body:**
```json
{
  "montant": 200000,
  "duree_mois": 24,
  "id_produit": 1
}
```

**Réponse (201):**
```json
{
  "success": true,
  "code": 201,
  "message": "Demande créée",
  "data": {
    "id": 1,
    "montant": 200000,
    "statut": "soumis",
    "message": "Demande de prêt soumise avec succès"
  }
}
```

### Rembourser un prêt
```
POST /prets/{id}/rembourser
Headers: Authorization: Bearer {token}
Content-Type: application/json
```

**Body:**
```json
{
  "montant": 10000
}
```

**Réponse (200):**
```json
{
  "success": true,
  "message": "Remboursement effectué",
  "data": {
    "id": 1,
    "montant_rembourse": 10000,
    "capital_restant": 90000,
    "message": "Remboursement enregistré"
  }
}
```

---

## 💸 Endpoints des transactions

### Lister les transactions
```
GET /transactions?page=1&per_page=10&type=Depot&statut=approuve
Headers: Authorization: Bearer {token}
```

**Paramètres:**
- `page`: Numéro de page
- `per_page`: Résultats par page
- `type`: Depot, Retrait, Virement
- `statut`: en_attente, approuve, rejete

**Réponse (200):**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "type": "Depot",
      "montant": 50000,
      "devise": "CDF",
      "mode": "En ligne",
      "statut": "approuve",
      "description": "Dépôt de fonds",
      "date": "2024-11-13T15:30:00"
    }
  ],
  "pagination": {...}
}
```

### Détails d'une transaction
```
GET /transactions/{id}
Headers: Authorization: Bearer {token}
```

### Faire un dépôt
```
POST /transactions/depot
Headers: Authorization: Bearer {token}
Content-Type: application/json
```

**Body:**
```json
{
  "montant": 50000,
  "devise": "CDF"
}
```

**Réponse (201):**
```json
{
  "success": true,
  "code": 201,
  "data": {
    "id": 1,
    "montant": 50000,
    "devise": "CDF",
    "statut": "en_attente",
    "message": "Dépôt en cours de traitement"
  }
}
```

### Faire un retrait
```
POST /transactions/retrait
Headers: Authorization: Bearer {token}
Content-Type: application/json
```

**Body:**
```json
{
  "montant": 10000,
  "devise": "CDF",
  "mode": "En ligne"
}
```

**Réponse (201):**
```json
{
  "success": true,
  "code": 201,
  "data": {
    "id": 1,
    "montant": 10000,
    "devise": "CDF",
    "nouveau_solde": 140000,
    "statut": "en_attente"
  }
}
```

### Faire un virement
```
POST /transactions/virement
Headers: Authorization: Bearer {token}
Content-Type: application/json
```

**Body:**
```json
{
  "montant": 25000,
  "devise": "CDF",
  "telephone_beneficiaire": "+243900000001"
}
```

**Réponse (201):**
```json
{
  "success": true,
  "code": 201,
  "data": {
    "id": 1,
    "montant": 25000,
    "devise": "CDF",
    "beneficiaire": "+243900000001",
    "nouveau_solde": 125000,
    "statut": "approuve"
  }
}
```

---

## 📈 Endpoints des placements

### Lister les placements
```
GET /placements?page=1&per_page=10&statut=actif
Headers: Authorization: Bearer {token}
```

**Réponse (200):**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "montant_initial": 500000,
      "devise": "CDF",
      "taux_interet": 0.08,
      "statut": "actif",
      "interets_cumules": 40000,
      "interets_retires": 0,
      "interets_restants": 40000,
      "date_debut": "2024-01-01",
      "date_echeance": "2025-01-01",
      "duree_jours": 365
    }
  ],
  "pagination": {...}
}
```

### Détails d'un placement
```
GET /placements/{id}
Headers: Authorization: Bearer {token}
```

**Réponse (200):**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "montant_initial": 500000,
    "capital_retire": 0,
    "capital_restant": 500000,
    "devise": "CDF",
    "taux_interet": 0.08,
    "statut": "actif",
    "interets": {
      "cumules": 40000,
      "retires": 0,
      "restants": 40000
    },
    "dates": {
      "debut": "2024-01-01",
      "echeance": "2025-01-01",
      "cloture": null
    },
    "duree": {
      "total_jours": 365,
      "jours_ecoules": 318
    },
    "progression": 87
  }
}
```

### Créer un placement
```
POST /placements
Headers: Authorization: Bearer {token}
Content-Type: application/json
```

**Body:**
```json
{
  "montant": 500000,
  "devise": "CDF",
  "duree_jours": 365
}
```

**Réponse (201):**
```json
{
  "success": true,
  "code": 201,
  "data": {
    "id": 1,
    "montant": 500000,
    "devise": "CDF",
    "taux_interet": 0.08,
    "duree_jours": 365,
    "date_echeance": "2025-01-01",
    "statut": "actif",
    "nouveau_solde": 0
  }
}
```

### Retirer un placement
```
POST /placements/{id}/retirer
Headers: Authorization: Bearer {token}
Content-Type: application/json
```

**Body:**
```json
{
  "type": "total"
}
```

Où `type` peut être:
- `"partiel"`: Retirer seulement les intérêts
- `"total"`: Retirer le capital + intérêts et clôturer le placement

**Réponse (200):**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "montant_retrait": 540000,
    "devise": "CDF",
    "type_retrait": "total",
    "nouveau_statut": "cloture"
  }
}
```

---

## 👤 Endpoints du profil

### Récupérer le profil
```
GET /profil
Headers: Authorization: Bearer {token}
```

**Réponse (200):**
```json
{
  "success": true,
  "data": {
    "id": 1,
    "nom_complet": "Jean Paul",
    "prenom": "Jean",
    "nom": "Paul",
    "telephone": "+243900000000",
    "email": "jean@example.com",
    "photo_profil": "/uploads/pp_xxx.jpg",
    "informations_personnelles": {
      "date_naissance": "1990-01-01",
      "profession": "Ingénieur",
      "adresse": "123 Rue de la Paix",
      "ville": "Kinshasa",
      "pays": "RDC"
    },
    "document": {
      "type": "Passeport",
      "numero": "AB123456",
      "date_expiration": "2025-12-31"
    },
    "statut": "actif",
    "dates": {
      "creation": "2024-01-01T10:00:00",
      "modification": "2024-11-13T15:30:00"
    }
  }
}
```

### Mettre à jour le profil
```
PUT /profil
Headers: Authorization: Bearer {token}
Content-Type: application/json
```

**Body (tous les champs sont optionnels):**
```json
{
  "email": "newemail@example.com",
  "prenom": "Jean",
  "nom": "Paul",
  "date_naissance": "1990-01-01",
  "profession": "Ingénieur",
  "adresse": "123 Rue de la Paix",
  "ville": "Kinshasa",
  "pays": "RDC"
}
```

**Réponse (200):**
```json
{
  "success": true,
  "message": "Profil mis à jour"
}
```

### Uploader un avatar
```
POST /profil/avatar
Headers: Authorization: Bearer {token}
Content-Type: multipart/form-data
```

**Body:**
```
Fichier: avatar (jpeg, png, ou gif, max 5MB)
```

**Réponse (200):**
```json
{
  "success": true,
  "message": "Avatar uploadé",
  "data": {
    "photo_profil": "/uploads/pp_xxx.jpg",
    "filename": "pp_xxx.jpg"
  }
}
```

---

## ❌ Gestion des erreurs

### Codes d'erreur courants

| Code | Message | Cause |
|------|---------|-------|
| 400 | Bad Request | Paramètres invalides |
| 401 | Unauthorized | Token invalide ou expiré |
| 403 | Forbidden | Accès refusé |
| 404 | Not Found | Ressource introuvable |
| 409 | Conflict | Conflit (ex: email déjà utilisé) |
| 422 | Validation Error | Erreurs de validation |
| 500 | Server Error | Erreur serveur |

### Exemple d'erreur
```json
{
  "success": false,
  "code": 400,
  "message": "Montant invalide",
  "errors": ["Le montant doit être supérieur à 0"],
  "timestamp": 1699949400
}
```

---

## 🎯 Exemples avec Dart/Flutter

### Installation des dépendances

Ajouter à `pubspec.yaml`:
```yaml
dependencies:
  http: ^1.1.0
  dio: ^5.3.0
  provider: ^6.0.0
  json_serializable: ^6.7.0
```

### Service API avec Dio

```dart
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';

class ApiService {
  late Dio _dio;
  String? _token;
  static const String baseUrl = 'http://localhost/african-trust-fund/api/v1';

  ApiService() {
    _dio = Dio(BaseOptions(
      baseUrl: baseUrl,
      connectTimeout: Duration(seconds: 30),
      receiveTimeout: Duration(seconds: 30),
    ));

    // Intercepteur pour ajouter le token
    _dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) {
          if (_token != null) {
            options.headers['Authorization'] = 'Bearer $_token';
          }
          return handler.next(options);
        },
        onError: (error, handler) {
          if (error.response?.statusCode == 401) {
            // Token expiré, rediriger vers login
            _token = null;
          }
          return handler.next(error);
        },
      ),
    );
  }

  // Authentification
  Future<Map<String, dynamic>> login(String telephone, String password) async {
    try {
      final response = await _dio.post(
        '/auth/login',
        data: {
          'telephone': telephone,
          'password': password,
        },
      );

      if (response.statusCode == 200 && response.data['success']) {
        _token = response.data['data']['token'];
        return response.data['data'];
      }
      throw Exception(response.data['message']);
    } catch (e) {
      throw Exception('Erreur connexion: $e');
    }
  }

  // Récupérer le dashboard
  Future<Map<String, dynamic>> getDashboard() async {
    try {
      final response = await _dio.get('/dashboard');
      if (response.statusCode == 200 && response.data['success']) {
        return response.data['data'];
      }
      throw Exception(response.data['message']);
    } catch (e) {
      throw Exception('Erreur dashboard: $e');
    }
  }

  // Récupérer les prêts
  Future<Map<String, dynamic>> getPrets({
    int page = 1,
    int perPage = 10,
    String? statut,
  }) async {
    try {
      final response = await _dio.get(
        '/prets',
        queryParameters: {
          'page': page,
          'per_page': perPage,
          if (statut != null) 'statut': statut,
        },
      );

      if (response.statusCode == 200 && response.data['success']) {
        return response.data;
      }
      throw Exception(response.data['message']);
    } catch (e) {
      throw Exception('Erreur prêts: $e');
    }
  }

  // Faire une transaction
  Future<Map<String, dynamic>> makeTransaction({
    required String type, // depot, retrait, virement
    required double montant,
    required String devise,
    String? telephoneBeneficiaire,
  }) async {
    try {
      final data = {
        'montant': montant,
        'devise': devise,
      };

      if (telephoneBeneficiaire != null) {
        data['telephone_beneficiaire'] = telephoneBeneficiaire;
      }

      final response = await _dio.post(
        '/transactions/$type',
        data: data,
      );

      if (response.statusCode == 201 && response.data['success']) {
        return response.data['data'];
      }
      throw Exception(response.data['message']);
    } catch (e) {
      throw Exception('Erreur transaction: $e');
    }
  }

  // Créer un placement
  Future<Map<String, dynamic>> createPlacement({
    required double montant,
    required String devise,
    required int dureJours,
  }) async {
    try {
      final response = await _dio.post(
        '/placements',
        data: {
          'montant': montant,
          'devise': devise,
          'duree_jours': dureJours,
        },
      );

      if (response.statusCode == 201 && response.data['success']) {
        return response.data['data'];
      }
      throw Exception(response.data['message']);
    } catch (e) {
      throw Exception('Erreur placement: $e');
    }
  }

  // Récupérer le profil
  Future<Map<String, dynamic>> getProfile() async {
    try {
      final response = await _dio.get('/profil');
      if (response.statusCode == 200 && response.data['success']) {
        return response.data['data'];
      }
      throw Exception(response.data['message']);
    } catch (e) {
      throw Exception('Erreur profil: $e');
    }
  }
}
```

### Exemple d'utilisation dans un Widget

```dart
import 'package:flutter/material.dart';
import 'api_service.dart';

class DashboardScreen extends StatefulWidget {
  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen> {
  final ApiService _apiService = ApiService();
  late Future<Map<String, dynamic>> _dashboardFuture;

  @override
  void initState() {
    super.initState();
    _dashboardFuture = _apiService.getDashboard();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text('Dashboard'),
      ),
      body: FutureBuilder<Map<String, dynamic>>(
        future: _dashboardFuture,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return Center(child: CircularProgressIndicator());
          }

          if (snapshot.hasError) {
            return Center(child: Text('Erreur: ${snapshot.error}'));
          }

          final data = snapshot.data;
          final soldes = data?['soldes'] as Map<String, dynamic>;
          final prets = data?['prets'] as Map<String, dynamic>;

          return ListView(
            padding: EdgeInsets.all(16),
            children: [
              // Soldes
              Card(
                child: Padding(
                  padding: EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('Solde CDF'),
                      Text(
                        '${soldes['cdf']} CDF',
                        style: Theme.of(context).textTheme.headline5,
                      ),
                      SizedBox(height: 16),
                      Text('Solde USD'),
                      Text(
                        '${soldes['usd']} USD',
                        style: Theme.of(context).textTheme.headline5,
                      ),
                    ],
                  ),
                ),
              ),
              SizedBox(height: 16),

              // Prêts
              Card(
                child: Padding(
                  padding: EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('Prêts en cours: ${prets['en_cours']}'),
                      Text('Capital restant: ${prets['capital_restant']}'),
                    ],
                  ),
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}
```

---

## 📞 Support

Pour toute question ou problème avec l'API:
- 📧 Email: api@african-trust-fund.com
- 📚 Documentation: [Lien vers la documentation]
- 🐛 Signaler un bug: [Lien vers les issues]

---

**Version**: 1.0.0  
**Dernière mise à jour**: 14 Novembre 2026  
**Statut**: ✅ Actif
