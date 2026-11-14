# 🚀 API Quick Start - 5 Minutes

**African Trust Fund Mobile API v1.0**

---

## 1️⃣ Démarrer l'API (Backend)

```bash
# Cloner le repository
git clone https://github.com/jeanpaulktm/african_strust_fund_php.git
cd african_strust_fund_php

# Copier la configuration
cp .env.example .env

# Importer la base de données
mysql -u root -p african_trust_fund < database/schema_complete.sql

# Accéder à l'API
http://localhost/african-trust-fund/api/v1
```

---

## 2️⃣ Tester rapidement avec curl

### Login
```bash
curl -X POST http://localhost/african-trust-fund/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "telephone": "+243000000000",
    "password": "ATF@Admin2025"
  }'
```

**Réponse:**
```json
{
  "success": true,
  "code": 200,
  "message": "Connexion réussie",
  "data": {
    "client_id": 1,
    "token": "1:1699949400:abc123hash",
    "soldes": {
      "cdf": 150000,
      "usd": 500
    }
  }
}
```

### Dashboard (ajouter le token)
```bash
TOKEN="1:1699949400:abc123hash"

curl -X GET http://localhost/african-trust-fund/api/v1/dashboard \
  -H "Authorization: Bearer $TOKEN"
```

---

## 3️⃣ Créer un projet Flutter

```bash
# Créer le projet
flutter create african_trust_fund_mobile
cd african_trust_fund_mobile

# Ajouter les dépendances
flutter pub add dio provider json_annotation json_serializable

# Générer le code
flutter pub run build_runner build

# Lancer l'app
flutter run
```

---

## 4️⃣ Implémenter le service API - Dart

Fichier: `lib/services/api_service.dart`

```dart
import 'package:dio/dio.dart';

class ApiService {
  static const String baseUrl = 'http://localhost/african-trust-fund/api/v1';
  
  late Dio _dio;
  String? _token;

  ApiService() {
    _dio = Dio(BaseOptions(
      baseUrl: baseUrl,
      connectTimeout: Duration(seconds: 30),
      receiveTimeout: Duration(seconds: 30),
    ));

    _dio.interceptors.add(InterceptorsWrapper(
      onRequest: (options, handler) {
        if (_token != null) {
          options.headers['Authorization'] = 'Bearer $_token';
        }
        return handler.next(options);
      },
    ));
  }

  // Login
  Future<Map<String, dynamic>> login(String phone, String password) async {
    final response = await _dio.post('/auth/login', data: {
      'telephone': phone,
      'password': password,
    });
    
    if (response.data['success']) {
      _token = response.data['data']['token'];
      return response.data['data'];
    }
    throw Exception(response.data['message']);
  }

  // Get Dashboard
  Future<Map<String, dynamic>> getDashboard() async {
    final response = await _dio.get('/dashboard');
    if (response.data['success']) {
      return response.data['data'];
    }
    throw Exception(response.data['message']);
  }

  // Get Loans
  Future<List<dynamic>> getPrets({int page = 1}) async {
    final response = await _dio.get('/prets', queryParameters: {
      'page': page,
      'per_page': 10,
    });
    if (response.data['success']) {
      return response.data['data'];
    }
    throw Exception(response.data['message']);
  }

  // Make deposit
  Future<Map<String, dynamic>> makeDeposit(double amount, String currency) async {
    final response = await _dio.post('/transactions/depot', data: {
      'montant': amount,
      'devise': currency,
    });
    if (response.data['success']) {
      return response.data['data'];
    }
    throw Exception(response.data['message']);
  }
}
```

---

## 5️⃣ Utiliser dans un Widget

```dart
import 'package:flutter/material.dart';
import 'services/api_service.dart';

void main() {
  runApp(MyApp());
}

class MyApp extends StatelessWidget {
  final apiService = ApiService();

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      home: LoginScreen(apiService: apiService),
    );
  }
}

class LoginScreen extends StatefulWidget {
  final ApiService apiService;

  LoginScreen({required this.apiService});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _phoneCtrl = TextEditingController();
  final _passCtrl = TextEditingController();
  bool _loading = false;

  void _login() async {
    setState(() => _loading = true);
    
    try {
      final data = await widget.apiService.login(
        _phoneCtrl.text,
        _passCtrl.text,
      );
      
      print('Login success: ${data['nom_complet']}');
      print('Solde CDF: ${data['soldes']['cdf']}');
      
      // Naviguer vers le dashboard
      if (mounted) {
        Navigator.push(context, MaterialPageRoute(
          builder: (_) => DashboardScreen(apiService: widget.apiService),
        ));
      }
    } catch (e) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Erreur: $e')),
      );
    } finally {
      setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text('African Trust Fund')),
      body: Padding(
        padding: EdgeInsets.all(16),
        child: Column(
          children: [
            TextField(
              controller: _phoneCtrl,
              decoration: InputDecoration(labelText: 'Téléphone'),
            ),
            SizedBox(height: 16),
            TextField(
              controller: _passCtrl,
              obscureText: true,
              decoration: InputDecoration(labelText: 'Mot de passe'),
            ),
            SizedBox(height: 32),
            ElevatedButton(
              onPressed: _loading ? null : _login,
              child: _loading 
                ? SizedBox(
                    height: 20,
                    width: 20,
                    child: CircularProgressIndicator(),
                  )
                : Text('Se connecter'),
            ),
          ],
        ),
      ),
    );
  }
}

class DashboardScreen extends StatefulWidget {
  final ApiService apiService;

  DashboardScreen({required this.apiService});

  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen> {
  late Future<Map<String, dynamic>> _dashboard;

  @override
  void initState() {
    super.initState();
    _dashboard = widget.apiService.getDashboard();
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: Text('Dashboard')),
      body: FutureBuilder<Map<String, dynamic>>(
        future: _dashboard,
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return Center(child: CircularProgressIndicator());
          }

          if (snapshot.hasError) {
            return Center(child: Text('Erreur: ${snapshot.error}'));
          }

          final data = snapshot.data;
          final soldes = data?['soldes'];
          final prets = data?['prets'];

          return ListView(
            padding: EdgeInsets.all(16),
            children: [
              Card(
                child: Padding(
                  padding: EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('Solde CDF', style: TextStyle(fontSize: 12, color: Colors.grey)),
                      Text(
                        '${soldes?['cdf'] ?? 0} CDF',
                        style: Theme.of(context).textTheme.headlineSmall,
                      ),
                      SizedBox(height: 16),
                      Text('Prêts en cours: ${prets?['en_cours'] ?? 0}'),
                      Text('Capital restant: ${prets?['capital_restant'] ?? 0}'),
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

## 📚 API Endpoints

| Méthode | Endpoint | Description |
|---------|----------|-------------|
| `POST` | `/auth/login` | Se connecter |
| `POST` | `/auth/register` | S'inscrire |
| `GET` | `/dashboard` | Voir le dashboard |
| `GET` | `/prets` | Lister les prêts |
| `POST` | `/prets` | Créer une demande |
| `GET` | `/transactions` | Lister les transactions |
| `POST` | `/transactions/depot` | Faire un dépôt |
| `POST` | `/transactions/retrait` | Faire un retrait |
| `POST` | `/transactions/virement` | Faire un virement |
| `GET` | `/placements` | Lister les placements |
| `POST` | `/placements` | Créer un placement |
| `GET` | `/profil` | Voir le profil |
| `PUT` | `/profil` | Mettre à jour le profil |

---

## 🔑 Identifiants de test

```
Téléphone:      +243000000000
Mot de passe:   ATF@Admin2025
```

---

## 🎯 Exemples pratiques

### 1. Récupérer les soldes
```dart
final dashboard = await apiService.getDashboard();
final soldes = dashboard['soldes'];
print('CDF: ${soldes['cdf']}');
print('USD: ${soldes['usd']}');
```

### 2. Faire un dépôt
```dart
final result = await apiService.makeDeposit(50000, 'CDF');
print('Dépôt créé: ${result['id']}');
print('Statut: ${result['statut']}');
```

### 3. Créer un placement
```dart
final response = await _dio.post('/placements', data: {
  'montant': 500000,
  'devise': 'CDF',
  'duree_jours': 365,
});
```

### 4. Créer un prêt
```dart
final response = await _dio.post('/prets', data: {
  'montant': 200000,
  'duree_mois': 24,
  'id_produit': 1,
});
```

---

## 🐛 Troubleshooting

### Erreur 401 (Unauthorized)
```
✗ Le token est invalide ou expiré
✓ Se reconnecter avec login
✓ Vérifier que le token est dans le header Authorization
```

### Erreur 400 (Bad Request)
```
✗ Les paramètres sont invalides
✓ Vérifier le format des données envoyées
✓ Consulter API_DOCUMENTATION.md
```

### Erreur 404 (Not Found)
```
✗ L'endpoint n'existe pas
✓ Vérifier l'URL
✓ Consulter la liste des endpoints
```

### Erreur 500 (Server Error)
```
✗ Erreur serveur
✓ Vérifier les logs du serveur
✓ Vérifier la base de données
✓ Contacter le support
```

---

## 📖 Ressources

- **API_DOCUMENTATION.md** → Documentation complète
- **FLUTTER_SETUP_GUIDE.md** → Setup détaillé Flutter
- **API_SUMMARY.txt** → Résumé complet
- **GitHub** → https://github.com/jeanpaulktm/african_strust_fund_php

---

## ✅ Checklist de démarrage

- [ ] Backend configuré et en cours d'exécution
- [ ] Base de données importée
- [ ] API testée avec curl
- [ ] Projet Flutter créé
- [ ] Dépendances installées
- [ ] ApiService implémenté
- [ ] Login screen fonctionnel
- [ ] Dashboard screen fonctionnel
- [ ] Transactions opérationnelles
- [ ] App prête pour le marché

---

**Bon développement! 🚀**

Pour plus d'aide, consultez la documentation complète dans API_DOCUMENTATION.md
