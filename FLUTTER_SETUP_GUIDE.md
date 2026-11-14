# 📱 Guide Développement Flutter/Dart

**African Trust Fund Mobile App**

---

## 🎯 Setup Initial

### 1. Créer un nouveau projet Flutter
```bash
flutter create african_trust_fund
cd african_trust_fund
```

### 2. Configurer pubspec.yaml

```yaml
name: african_trust_fund
description: "African Trust Fund Mobile Application"

version: 1.0.0+1

environment:
  sdk: '>=3.0.0 <4.0.0'

dependencies:
  flutter:
    sdk: flutter

  # Networking
  dio: ^5.3.0
  http: ^1.1.0

  # State Management
  provider: ^6.0.0
  riverpod: ^2.4.0
  get: ^4.6.5

  # Local Storage
  shared_preferences: ^2.2.0
  hive: ^2.2.3
  hive_flutter: ^1.1.0

  # JSON Serialization
  json_serializable: ^6.7.0
  json_annotation: ^4.8.1

  # UI
  flutter_svg: ^2.0.0
  intl: ^0.18.0
  cached_network_image: ^3.3.0

  # Logging
  logger: ^2.0.0
  sentry_flutter: ^7.8.0

  # Date Time
  table_calendar: ^3.0.8

  # Charts
  fl_chart: ^0.66.0

  # Others
  connectivity_plus: ^5.0.0
  url_launcher: ^6.1.0
  permission_handler: ^11.4.4

dev_dependencies:
  flutter_test:
    sdk: flutter
  flutter_lints: ^3.0.0
  build_runner: ^2.4.0
  json_serializable: ^6.7.0
```

### 3. Structure du projet

```
lib/
├── main.dart
├── config/
│   ├── api_config.dart
│   ├── theme.dart
│   └── constants.dart
├── models/
│   ├── user_model.dart
│   ├── pret_model.dart
│   ├── transaction_model.dart
│   ├── placement_model.dart
│   └── dashboard_model.dart
├── services/
│   ├── api_service.dart
│   ├── auth_service.dart
│   ├── storage_service.dart
│   └── notification_service.dart
├── providers/
│   ├── auth_provider.dart
│   ├── dashboard_provider.dart
│   ├── prets_provider.dart
│   ├── transactions_provider.dart
│   └── placements_provider.dart
├── screens/
│   ├── auth/
│   │   ├── login_screen.dart
│   │   ├── register_screen.dart
│   │   └── splash_screen.dart
│   ├── home/
│   │   ├── home_screen.dart
│   │   ├── dashboard_screen.dart
│   │   └── profile_screen.dart
│   ├── prets/
│   │   ├── prets_list_screen.dart
│   │   ├── pret_details_screen.dart
│   │   ├── new_pret_screen.dart
│   │   └── rembourser_screen.dart
│   ├── transactions/
│   │   ├── transactions_list_screen.dart
│   │   ├── depot_screen.dart
│   │   ├── retrait_screen.dart
│   │   └── virement_screen.dart
│   ├── placements/
│   │   ├── placements_list_screen.dart
│   │   ├── new_placement_screen.dart
│   │   └── placement_details_screen.dart
│   └── settings/
│       ├── settings_screen.dart
│       ├── edit_profile_screen.dart
│       └── security_screen.dart
├── widgets/
│   ├── custom_app_bar.dart
│   ├── custom_button.dart
│   ├── custom_text_field.dart
│   ├── balance_card.dart
│   ├── transaction_tile.dart
│   └── empty_state.dart
├── utils/
│   ├── extensions.dart
│   ├── formatters.dart
│   ├── validators.dart
│   └── logger_helper.dart
└── repository/
    ├── auth_repository.dart
    ├── prets_repository.dart
    ├── transactions_repository.dart
    └── placements_repository.dart
```

---

## 🔌 Configuration API

### config/api_config.dart

```dart
class ApiConfig {
  static const String baseUrl = 'http://localhost/african-trust-fund/api/v1';
  // Pour production: 'https://api.african-trust-fund.com/api/v1'

  static const int connectionTimeout = 30000; // ms
  static const int receiveTimeout = 30000; // ms

  // Endpoints
  static const String authLogin = '/auth/login';
  static const String authRegister = '/auth/register';
  static const String authRefresh = '/auth/refresh';
  static const String authProfile = '/auth/profile';
  static const String authLogout = '/auth/logout';

  static const String dashboard = '/dashboard';
  static const String prets = '/prets';
  static const String transactions = '/transactions';
  static const String placements = '/placements';
  static const String profil = '/profil';
}
```

---

## 🔐 Service d'authentification

### services/api_service.dart

```dart
import 'package:dio/dio.dart';
import 'package:logger/logger.dart';
import 'package:african_trust_fund/config/api_config.dart';

class ApiService {
  late Dio _dio;
  final Logger _logger = Logger();
  String? _authToken;

  ApiService() {
    _dio = Dio(
      BaseOptions(
        baseUrl: ApiConfig.baseUrl,
        connectTimeout: Duration(milliseconds: ApiConfig.connectionTimeout),
        receiveTimeout: Duration(milliseconds: ApiConfig.receiveTimeout),
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
        },
      ),
    );

    // Interceptors
    _dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) {
          if (_authToken != null) {
            options.headers['Authorization'] = 'Bearer $_authToken';
          }
          _logger.d('REQUEST: ${options.method} ${options.path}');
          return handler.next(options);
        },
        onResponse: (response, handler) {
          _logger.d('RESPONSE: ${response.statusCode} - ${response.path}');
          return handler.next(response);
        },
        onError: (error, handler) {
          _logger.e('ERROR: ${error.message}');
          if (error.response?.statusCode == 401) {
            // Token expiré
            _handleTokenExpired();
          }
          return handler.next(error);
        },
      ),
    );
  }

  // Setter pour le token
  void setAuthToken(String token) {
    _authToken = token;
  }

  // Gestion de l'expiration du token
  void _handleTokenExpired() {
    // Rediriger vers login
    // Émettre un événement ou callback
  }

  // GET request
  Future<T> get<T>(
    String endpoint, {
    Map<String, dynamic>? queryParameters,
  }) async {
    try {
      final response = await _dio.get(
        endpoint,
        queryParameters: queryParameters,
      );
      return _handleResponse(response);
    } catch (e) {
      throw _handleError(e);
    }
  }

  // POST request
  Future<T> post<T>(
    String endpoint, {
    required Map<String, dynamic> data,
  }) async {
    try {
      final response = await _dio.post(endpoint, data: data);
      return _handleResponse(response);
    } catch (e) {
      throw _handleError(e);
    }
  }

  // PUT request
  Future<T> put<T>(
    String endpoint, {
    required Map<String, dynamic> data,
  }) async {
    try {
      final response = await _dio.put(endpoint, data: data);
      return _handleResponse(response);
    } catch (e) {
      throw _handleError(e);
    }
  }

  // DELETE request
  Future<T> delete<T>(String endpoint) async {
    try {
      final response = await _dio.delete(endpoint);
      return _handleResponse(response);
    } catch (e) {
      throw _handleError(e);
    }
  }

  // Upload file
  Future<T> uploadFile<T>(
    String endpoint, {
    required String filePath,
    required String fieldName,
  }) async {
    try {
      final file = await MultipartFile.fromFile(filePath);
      final formData = FormData.fromMap({fieldName: file});
      final response = await _dio.post(endpoint, data: formData);
      return _handleResponse(response);
    } catch (e) {
      throw _handleError(e);
    }
  }

  // Handle response
  T _handleResponse<T>(Response response) {
    if (response.statusCode == 200 || response.statusCode == 201) {
      return response.data['data'] as T;
    }
    throw ApiException(
      message: response.data['message'] ?? 'Erreur inconnue',
      statusCode: response.statusCode ?? 500,
    );
  }

  // Handle error
  Exception _handleError(dynamic error) {
    if (error is DioException) {
      if (error.response != null) {
        return ApiException(
          message: error.response?.data['message'] ?? error.message ?? 'Erreur',
          statusCode: error.response?.statusCode ?? 500,
        );
      }
      return NetworkException(message: error.message ?? 'Erreur réseau');
    }
    return Exception('Erreur inconnue');
  }
}

// Exceptions personnalisées
class ApiException implements Exception {
  final String message;
  final int statusCode;

  ApiException({required this.message, required this.statusCode});

  @override
  String toString() => 'ApiException: [$statusCode] $message';
}

class NetworkException implements Exception {
  final String message;

  NetworkException({required this.message});

  @override
  String toString() => 'NetworkException: $message';
}
```

### services/auth_service.dart

```dart
import 'package:african_trust_fund/config/api_config.dart';
import 'package:african_trust_fund/models/user_model.dart';
import 'package:african_trust_fund/services/api_service.dart';
import 'package:african_trust_fund/services/storage_service.dart';

class AuthService {
  final ApiService _apiService;
  final StorageService _storageService;

  AuthService({
    required ApiService apiService,
    required StorageService storageService,
  })  : _apiService = apiService,
        _storageService = storageService;

  // Login
  Future<UserModel> login(String telephone, String password) async {
    try {
      final response = await _apiService.post(
        ApiConfig.authLogin,
        data: {
          'telephone': telephone,
          'password': password,
        },
      );

      final user = UserModel.fromJson(response);
      
      // Sauvegarder le token
      await _storageService.saveToken(user.token);
      _apiService.setAuthToken(user.token);

      // Sauvegarder l'utilisateur
      await _storageService.saveUser(user);

      return user;
    } catch (e) {
      rethrow;
    }
  }

  // Register
  Future<UserModel> register({
    required String prenom,
    required String nom,
    required String telephone,
    required String email,
    required String password,
  }) async {
    try {
      final response = await _apiService.post(
        ApiConfig.authRegister,
        data: {
          'prenom': prenom,
          'nom': nom,
          'telephone': telephone,
          'email': email,
          'password': password,
        },
      );

      final user = UserModel.fromJson(response);
      
      await _storageService.saveToken(user.token);
      _apiService.setAuthToken(user.token);

      return user;
    } catch (e) {
      rethrow;
    }
  }

  // Logout
  Future<void> logout() async {
    try {
      await _apiService.post(ApiConfig.authLogout, data: {});
      await _storageService.clearAll();
      _apiService.setAuthToken('');
    } catch (e) {
      // Nettoyer même en cas d'erreur
      await _storageService.clearAll();
      rethrow;
    }
  }

  // Refresh token
  Future<String> refreshToken() async {
    try {
      final response = await _apiService.post(
        ApiConfig.authRefresh,
        data: {},
      );
      
      final newToken = response['token'] as String;
      await _storageService.saveToken(newToken);
      _apiService.setAuthToken(newToken);
      
      return newToken;
    } catch (e) {
      rethrow;
    }
  }

  // Get stored token
  Future<String?> getToken() async {
    return _storageService.getToken();
  }

  // Check if logged in
  Future<bool> isLoggedIn() async {
    final token = await getToken();
    return token != null && token.isNotEmpty;
  }
}
```

---

## 📊 Models

### models/user_model.dart

```dart
class UserModel {
  final int clientId;
  final String nomComplet;
  final String telephone;
  final String email;
  final String? photoProfile;
  final String token;
  final SoldesModel soldes;
  final int expiresIn;

  UserModel({
    required this.clientId,
    required this.nomComplet,
    required this.telephone,
    required this.email,
    this.photoProfile,
    required this.token,
    required this.soldes,
    required this.expiresIn,
  });

  factory UserModel.fromJson(Map<String, dynamic> json) {
    return UserModel(
      clientId: json['client_id'] as int,
      nomComplet: json['nom_complet'] as String,
      telephone: json['telephone'] as String,
      email: json['email'] as String,
      photoProfile: json['photo_profil'] as String?,
      token: json['token'] as String,
      soldes: SoldesModel.fromJson(json['soldes'] as Map<String, dynamic>),
      expiresIn: json['expires_in'] as int,
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'client_id': clientId,
      'nom_complet': nomComplet,
      'telephone': telephone,
      'email': email,
      'photo_profil': photoProfile,
      'token': token,
      'soldes': soldes.toJson(),
      'expires_in': expiresIn,
    };
  }
}

class SoldesModel {
  final double cdf;
  final double usd;
  final double epargne_cdf;
  final double epargne_usd;

  SoldesModel({
    required this.cdf,
    required this.usd,
    required this.epargne_cdf,
    required this.epargne_usd,
  });

  factory SoldesModel.fromJson(Map<String, dynamic> json) {
    return SoldesModel(
      cdf: (json['cdf'] as num).toDouble(),
      usd: (json['usd'] as num).toDouble(),
      epargne_cdf: (json['epargne_cdf'] as num).toDouble(),
      epargne_usd: (json['epargne_usd'] as num).toDouble(),
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'cdf': cdf,
      'usd': usd,
      'epargne_cdf': epargne_cdf,
      'epargne_usd': epargne_usd,
    };
  }
}
```

---

## 🎨 UI Examples

### Login Screen

```dart
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:african_trust_fund/providers/auth_provider.dart';

class LoginScreen extends StatefulWidget {
  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _telephoneController = TextEditingController();
  final _passwordController = TextEditingController();
  bool _isLoading = false;
  String? _errorMessage;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text('Connexion'),
        elevation: 0,
      ),
      body: SingleChildScrollView(
        padding: EdgeInsets.all(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // Logo
            Icon(Icons.account_balance, size: 80, color: Colors.blue),
            SizedBox(height: 32),

            Text(
              'African Trust Fund',
              textAlign: TextAlign.center,
              style: Theme.of(context).textTheme.headlineSmall,
            ),
            SizedBox(height: 32),

            // Error message
            if (_errorMessage != null)
              Container(
                padding: EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: Colors.red.shade100,
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Text(
                  _errorMessage!,
                  style: TextStyle(color: Colors.red.shade800),
                ),
              ),
            SizedBox(height: 16),

            // Telephone field
            TextField(
              controller: _telephoneController,
              decoration: InputDecoration(
                labelText: 'Téléphone',
                hintText: '+243900000000',
                prefixIcon: Icon(Icons.phone),
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(8),
                ),
              ),
              keyboardType: TextInputType.phone,
            ),
            SizedBox(height: 16),

            // Password field
            TextField(
              controller: _passwordController,
              obscureText: true,
              decoration: InputDecoration(
                labelText: 'Mot de passe',
                prefixIcon: Icon(Icons.lock),
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(8),
                ),
              ),
            ),
            SizedBox(height: 24),

            // Login button
            ElevatedButton(
              onPressed: _isLoading ? null : _handleLogin,
              style: ElevatedButton.styleFrom(
                padding: EdgeInsets.symmetric(vertical: 16),
              ),
              child: _isLoading
                  ? SizedBox(
                      height: 20,
                      width: 20,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : Text('Se connecter'),
            ),
            SizedBox(height: 16),

            // Register link
            Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Text('Pas encore de compte? '),
                TextButton(
                  onPressed: () => Navigator.pushNamed(context, '/register'),
                  child: Text('S\'inscrire'),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  void _handleLogin() async {
    setState(() {
      _errorMessage = null;
      _isLoading = true;
    });

    try {
      final authProvider = context.read<AuthProvider>();
      await authProvider.login(
        _telephoneController.text,
        _passwordController.text,
      );

      if (mounted) {
        Navigator.pushReplacementNamed(context, '/home');
      }
    } catch (e) {
      setState(() {
        _errorMessage = e.toString();
      });
    } finally {
      setState(() {
        _isLoading = false;
      });
    }
  }

  @override
  void dispose() {
    _telephoneController.dispose();
    _passwordController.dispose();
    super.dispose();
  }
}
```

---

## 📦 Provider Pattern

### providers/auth_provider.dart

```dart
import 'package:flutter/material.dart';
import 'package:african_trust_fund/models/user_model.dart';
import 'package:african_trust_fund/services/auth_service.dart';

class AuthProvider extends ChangeNotifier {
  final AuthService _authService;

  AuthProvider({required AuthService authService}) : _authService = authService;

  UserModel? _user;
  bool _isLoading = false;
  String? _error;

  // Getters
  UserModel? get user => _user;
  bool get isLoading => _isLoading;
  String? get error => _error;
  bool get isAuthenticated => _user != null;

  // Login
  Future<void> login(String telephone, String password) async {
    _isLoading = true;
    _error = null;
    notifyListeners();

    try {
      _user = await _authService.login(telephone, password);
    } catch (e) {
      _error = e.toString();
      rethrow;
    } finally {
      _isLoading = false;
      notifyListeners();
    }
  }

  // Logout
  Future<void> logout() async {
    _isLoading = true;
    notifyListeners();

    try {
      await _authService.logout();
      _user = null;
    } catch (e) {
      _error = e.toString();
    } finally {
      _isLoading = false;
      notifyListeners();
    }
  }
}
```

---

## 🚀 Main App

### main.dart

```dart
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:african_trust_fund/config/theme.dart';
import 'package:african_trust_fund/providers/auth_provider.dart';
import 'package:african_trust_fund/screens/auth/login_screen.dart';
import 'package:african_trust_fund/screens/auth/splash_screen.dart';
import 'package:african_trust_fund/screens/home/home_screen.dart';
import 'package:african_trust_fund/services/api_service.dart';
import 'package:african_trust_fund/services/auth_service.dart';
import 'package:african_trust_fund/services/storage_service.dart';

void main() {
  runApp(const MyApp());
}

class MyApp extends StatelessWidget {
  const MyApp({Key? key}) : super(key: key);

  @override
  Widget build(BuildContext context) {
    return MultiProvider(
      providers: [
        Provider<StorageService>(create: (_) => StorageService()),
        Provider<ApiService>(create: (_) => ApiService()),
        ProxyProvider<StorageService, AuthService>(
          create: (_) => AuthService(
            apiService: _.read<ApiService>(),
            storageService: _.read<StorageService>(),
          ),
          update: (_, storageService, previousAuthService) => AuthService(
            apiService: _.read<ApiService>(),
            storageService: storageService,
          ),
        ),
        ChangeNotifierProvider<AuthProvider>(
          create: (context) => AuthProvider(
            authService: context.read<AuthService>(),
          ),
        ),
      ],
      child: MaterialApp(
        title: 'African Trust Fund',
        theme: AppTheme.lightTheme,
        darkTheme: AppTheme.darkTheme,
        home: const SplashScreen(),
        routes: {
          '/login': (context) => LoginScreen(),
          '/home': (context) => HomeScreen(),
        },
      ),
    );
  }
}
```

---

## ✅ Testing

### Exemple de test

```dart
import 'package:flutter_test/flutter_test.dart';
import 'package:mockito/mockito.dart';
import 'package:african_trust_fund/services/auth_service.dart';

void main() {
  group('AuthService', () {
    late AuthService authService;

    setUp(() {
      // Mock les services
      authService = AuthService(
        apiService: MockApiService(),
        storageService: MockStorageService(),
      );
    });

    test('login returns user on success', () async {
      final user = await authService.login('+243900000000', 'password');
      expect(user.clientId, isNotNull);
      expect(user.token, isNotNull);
    });
  });
}
```

---

## 📱 Build & Deploy

### iOS Build
```bash
flutter build ios --release
# Archive in Xcode for App Store
```

### Android Build
```bash
flutter build apk --release
flutter build appbundle --release
```

---

## 📞 Support

Pour les problèmes ou questions:
- Consulter [API_DOCUMENTATION.md](API_DOCUMENTATION.md)
- Vérifier les logs avec: `flutter logs`
- Déboguer avec: `flutter run --verbose`

---

**Bon développement! 🚀**
