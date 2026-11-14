# 📱 Guide d'Intégration Flutter - African Trust Fund

## Vue d'ensemble

Ce guide explique comment intégrer **toutes les APIs du backend PHP** dans le projet Flutter situé à `C:\flut\african\`.

---

## 🏗️ Architecture Recommandée pour le Projet Flutter

```
lib/
├── services/              # Tous les services API
│   ├── api_client.dart    # Client HTTP centralisé
│   ├── auth_service.dart  # Authentification
│   ├── client_service.dart # Gestion des clients
│   ├── investment_service.dart # Investissements
│   ├── fund_service.dart  # Fonds
│   ├── portfolio_service.dart # Portefeuille
│   ├── transaction_service.dart # Transactions
│   ├── report_service.dart # Rapports
│   └── admin_service.dart # Administration
├── models/                # Modèles de données
│   ├── user.dart
│   ├── client.dart
│   ├── investment.dart
│   ├── fund.dart
│   ├── portfolio.dart
│   ├── transaction.dart
│   ├── report.dart
│   └── admin.dart
├── providers/             # State Management (Riverpod/Provider)
│   ├── auth_provider.dart
│   ├── client_provider.dart
│   ├── investment_provider.dart
│   └── ...
└── screens/               # Écrans UI
    ├── auth/
    ├── client/
    ├── investment/
    └── ...
```

---

## 🔐 1. Configuration de Base - API Client

### Fichier: `lib/services/api_client.dart`

```dart
import 'package:http/http.dart' as http;
import 'dart:convert';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

class ApiClient {
  static const String BASE_URL = 'http://192.168.1.100:80';
  static const String API_PATH = '/api';
  
  final http.Client _httpClient;
  final _secureStorage = const FlutterSecureStorage();
  
  ApiClient({http.Client? client}) : _httpClient = client ?? http.Client();
  
  /// Envoyer une requête GET
  Future<dynamic> get(String endpoint, {Map<String, String>? headers}) async {
    try {
      final token = await _getToken();
      final response = await _httpClient.get(
        Uri.parse('$BASE_URL$API_PATH$endpoint'),
        headers: _buildHeaders(headers, token),
      );
      return _handleResponse(response);
    } catch (e) {
      throw Exception('GET Error: $e');
    }
  }
  
  /// Envoyer une requête POST
  Future<dynamic> post(
    String endpoint,
    dynamic body, {
    Map<String, String>? headers,
  }) async {
    try {
      final token = await _getToken();
      final response = await _httpClient.post(
        Uri.parse('$BASE_URL$API_PATH$endpoint'),
        headers: _buildHeaders(headers, token),
        body: jsonEncode(body),
      );
      return _handleResponse(response);
    } catch (e) {
      throw Exception('POST Error: $e');
    }
  }
  
  /// Envoyer une requête PUT
  Future<dynamic> put(
    String endpoint,
    dynamic body, {
    Map<String, String>? headers,
  }) async {
    try {
      final token = await _getToken();
      final response = await _httpClient.put(
        Uri.parse('$BASE_URL$API_PATH$endpoint'),
        headers: _buildHeaders(headers, token),
        body: jsonEncode(body),
      );
      return _handleResponse(response);
    } catch (e) {
      throw Exception('PUT Error: $e');
    }
  }
  
  /// Envoyer une requête DELETE
  Future<dynamic> delete(String endpoint, {Map<String, String>? headers}) async {
    try {
      final token = await _getToken();
      final response = await _httpClient.delete(
        Uri.parse('$BASE_URL$API_PATH$endpoint'),
        headers: _buildHeaders(headers, token),
      );
      return _handleResponse(response);
    } catch (e) {
      throw Exception('DELETE Error: $e');
    }
  }
  
  /// Construire les headers avec le token
  Map<String, String> _buildHeaders(Map<String, String>? customHeaders, String? token) {
    final headers = {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    };
    
    if (token != null) {
      headers['Authorization'] = 'Bearer $token';
    }
    
    if (customHeaders != null) {
      headers.addAll(customHeaders);
    }
    
    return headers;
  }
  
  /// Récupérer le token du stockage sécurisé
  Future<String?> _getToken() async {
    return await _secureStorage.read(key: 'auth_token');
  }
  
  /// Sauvegarder le token
  Future<void> saveToken(String token) async {
    await _secureStorage.write(key: 'auth_token', value: token);
  }
  
  /// Supprimer le token
  Future<void> deleteToken() async {
    await _secureStorage.delete(key: 'auth_token');
  }
  
  /// Gérer la réponse HTTP
  dynamic _handleResponse(http.Response response) {
    if (response.statusCode == 200 || response.statusCode == 201) {
      return jsonDecode(response.body);
    } else if (response.statusCode == 401) {
      throw Exception('Non autorisé - Token expiré');
    } else if (response.statusCode == 403) {
      throw Exception('Accès refusé');
    } else if (response.statusCode == 404) {
      throw Exception('Ressource non trouvée');
    } else if (response.statusCode == 500) {
      throw Exception('Erreur serveur');
    } else {
      throw Exception('Erreur HTTP ${response.statusCode}: ${response.body}');
    }
  }
}
```

---

## 📋 2. Modèles de Données Dart

### Fichier: `lib/models/user.dart`

```dart
class User {
  final int id;
  final String email;
  final String firstName;
  final String lastName;
  final String phone;
  final String role;
  final DateTime createdAt;
  
  User({
    required this.id,
    required this.email,
    required this.firstName,
    required this.lastName,
    required this.phone,
    required this.role,
    required this.createdAt,
  });
  
  factory User.fromJson(Map<String, dynamic> json) {
    return User(
      id: json['id'],
      email: json['email'],
      firstName: json['first_name'],
      lastName: json['last_name'],
      phone: json['phone'],
      role: json['role'],
      createdAt: DateTime.parse(json['created_at']),
    );
  }
  
  Map<String, dynamic> toJson() => {
    'id': id,
    'email': email,
    'first_name': firstName,
    'last_name': lastName,
    'phone': phone,
    'role': role,
    'created_at': createdAt.toIso8601String(),
  };
}
```

### Fichier: `lib/models/client.dart`

```dart
class Client {
  final int id;
  final String firstName;
  final String lastName;
  final String email;
  final String phone;
  final String? address;
  final String? city;
  final String? country;
  final String? idNumber;
  final String status;
  final DateTime createdAt;
  
  Client({
    required this.id,
    required this.firstName,
    required this.lastName,
    required this.email,
    required this.phone,
    this.address,
    this.city,
    this.country,
    this.idNumber,
    required this.status,
    required this.createdAt,
  });
  
  factory Client.fromJson(Map<String, dynamic> json) {
    return Client(
      id: json['id'],
      firstName: json['first_name'],
      lastName: json['last_name'],
      email: json['email'],
      phone: json['phone'],
      address: json['address'],
      city: json['city'],
      country: json['country'],
      idNumber: json['id_number'],
      status: json['status'],
      createdAt: DateTime.parse(json['created_at']),
    );
  }
  
  Map<String, dynamic> toJson() => {
    'first_name': firstName,
    'last_name': lastName,
    'email': email,
    'phone': phone,
    'address': address,
    'city': city,
    'country': country,
    'id_number': idNumber,
    'status': status,
  };
}
```

---

## 🔑 3. Services API - Authentification

### Fichier: `lib/services/auth_service.dart`

```dart
import 'api_client.dart';

class AuthService {
  final ApiClient _apiClient;
  
  AuthService({required ApiClient apiClient}) : _apiClient = apiClient;
  
  /// Connexion utilisateur
  Future<Map<String, dynamic>> login(String email, String password) async {
    final response = await _apiClient.post(
      '/auth/login',
      {'email': email, 'password': password},
    );
    
    if (response['status'] == 'success') {
      await _apiClient.saveToken(response['data']['token']);
    }
    
    return response;
  }
  
  /// Inscription nouveau client
  Future<Map<String, dynamic>> registerClient(Map<String, dynamic> data) async {
    return await _apiClient.post('/auth/register-client', data);
  }
  
  /// Déconnexion
  Future<void> logout() async {
    await _apiClient.delete('/auth/logout');
    await _apiClient.deleteToken();
  }
  
  /// Récupérer le profil actuel
  Future<Map<String, dynamic>> getProfile() async {
    return await _apiClient.get('/auth/profile');
  }
  
  /// Mettre à jour le profil
  Future<Map<String, dynamic>> updateProfile(Map<String, dynamic> data) async {
    return await _apiClient.put('/auth/profile', data);
  }
  
  /// Changer le mot de passe
  Future<Map<String, dynamic>> changePassword(
    String oldPassword,
    String newPassword,
  ) async {
    return await _apiClient.post(
      '/auth/change-password',
      {'old_password': oldPassword, 'new_password': newPassword},
    );
  }
  
  /// Récupérer tous les clients
  Future<Map<String, dynamic>> getAllClients() async {
    return await _apiClient.get('/clients');
  }
  
  /// Récupérer un client spécifique
  Future<Map<String, dynamic>> getClientById(int id) async {
    return await _apiClient.get('/clients/$id');
  }
}
```

---

## 💼 4. Services API - Gestion des Clients

### Fichier: `lib/services/client_service.dart`

```dart
import 'api_client.dart';

class ClientService {
  final ApiClient _apiClient;
  
  ClientService({required ApiClient apiClient}) : _apiClient = apiClient;
  
  /// Créer un nouveau client
  Future<Map<String, dynamic>> createClient(Map<String, dynamic> data) async {
    return await _apiClient.post('/clients', data);
  }
  
  /// Récupérer tous les clients
  Future<Map<String, dynamic>> getAllClients({
    int page = 1,
    int perPage = 10,
    String? search,
    String? status,
  }) async {
    String endpoint = '/clients?page=$page&per_page=$perPage';
    if (search != null) endpoint += '&search=$search';
    if (status != null) endpoint += '&status=$status';
    
    return await _apiClient.get(endpoint);
  }
  
  /// Récupérer un client par ID
  Future<Map<String, dynamic>> getClientById(int id) async {
    return await _apiClient.get('/clients/$id');
  }
  
  /// Mettre à jour les données d'un client
  Future<Map<String, dynamic>> updateClient(
    int id,
    Map<String, dynamic> data,
  ) async {
    return await _apiClient.put('/clients/$id', data);
  }
  
  /// Changer le statut d'un client
  Future<Map<String, dynamic>> updateClientStatus(
    int id,
    String status,
  ) async {
    return await _apiClient.put('/clients/$id/status', {'status': status});
  }
  
  /// Supprimer un client
  Future<Map<String, dynamic>> deleteClient(int id) async {
    return await _apiClient.delete('/clients/$id');
  }
  
  /// Récupérer l'historique d'un client
  Future<Map<String, dynamic>> getClientHistory(int id) async {
    return await _apiClient.get('/clients/$id/history');
  }
  
  /// Rechercher des clients
  Future<Map<String, dynamic>> searchClients(String query) async {
    return await _apiClient.get('/clients/search?q=$query');
  }
}
```

---

## 📊 Continues dans les prochains fichiers...

**À suivre:**
- Services Investissements
- Services Fonds
- Services Portefeuille
- Services Transactions
- Services Rapports
- Services Administration
- Providers State Management
- Screens et Widgets UI

