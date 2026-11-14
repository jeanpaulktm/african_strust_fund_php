# 📱 Services API Complets - Flutter Integration (PART 2)

## 💰 5. Services API - Investissements

### Fichier: `lib/services/investment_service.dart`

```dart
import 'api_client.dart';

class InvestmentService {
  final ApiClient _apiClient;
  
  InvestmentService({required ApiClient apiClient}) : _apiClient = apiClient;
  
  /// Créer un nouvel investissement
  Future<Map<String, dynamic>> createInvestment(
    Map<String, dynamic> data,
  ) async {
    return await _apiClient.post('/investments', data);
  }
  
  /// Récupérer tous les investissements
  Future<Map<String, dynamic>> getAllInvestments({
    int page = 1,
    int perPage = 10,
    String? status,
    int? clientId,
  }) async {
    String endpoint = '/investments?page=$page&per_page=$perPage';
    if (status != null) endpoint += '&status=$status';
    if (clientId != null) endpoint += '&client_id=$clientId';
    
    return await _apiClient.get(endpoint);
  }
  
  /// Récupérer un investissement par ID
  Future<Map<String, dynamic>> getInvestmentById(int id) async {
    return await _apiClient.get('/investments/$id');
  }
  
  /// Investissements d'un client
  Future<Map<String, dynamic>> getClientInvestments(int clientId) async {
    return await _apiClient.get('/clients/$clientId/investments');
  }
  
  /// Mettre à jour un investissement
  Future<Map<String, dynamic>> updateInvestment(
    int id,
    Map<String, dynamic> data,
  ) async {
    return await _apiClient.put('/investments/$id', data);
  }
  
  /// Changer le statut d'un investissement
  Future<Map<String, dynamic>> updateInvestmentStatus(
    int id,
    String status,
  ) async {
    return await _apiClient.put('/investments/$id/status', {'status': status});
  }
  
  /// Supprimer un investissement
  Future<Map<String, dynamic>> deleteInvestment(int id) async {
    return await _apiClient.delete('/investments/$id');
  }
  
  /// Récupérer le détail d'un investissement
  Future<Map<String, dynamic>> getInvestmentDetail(int id) async {
    return await _apiClient.get('/investments/$id/details');
  }
  
  /// Approuver un investissement
  Future<Map<String, dynamic>> approveInvestment(int id) async {
    return await _apiClient.put('/investments/$id/approve', {});
  }
  
  /// Rejeter un investissement
  Future<Map<String, dynamic>> rejectInvestment(
    int id,
    String reason,
  ) async {
    return await _apiClient.put(
      '/investments/$id/reject',
      {'rejection_reason': reason},
    );
  }
}
```

---

## 🏦 6. Services API - Fonds

### Fichier: `lib/services/fund_service.dart`

```dart
import 'api_client.dart';

class FundService {
  final ApiClient _apiClient;
  
  FundService({required ApiClient apiClient}) : _apiClient = apiClient;
  
  /// Créer un nouveau fonds
  Future<Map<String, dynamic>> createFund(Map<String, dynamic> data) async {
    return await _apiClient.post('/funds', data);
  }
  
  /// Récupérer tous les fonds
  Future<Map<String, dynamic>> getAllFunds({
    int page = 1,
    int perPage = 10,
    String? status,
    String? type,
  }) async {
    String endpoint = '/funds?page=$page&per_page=$perPage';
    if (status != null) endpoint += '&status=$status';
    if (type != null) endpoint += '&type=$type';
    
    return await _apiClient.get(endpoint);
  }
  
  /// Récupérer un fonds par ID
  Future<Map<String, dynamic>> getFundById(int id) async {
    return await _apiClient.get('/funds/$id');
  }
  
  /// Détails complets d'un fonds
  Future<Map<String, dynamic>> getFundDetails(int id) async {
    return await _apiClient.get('/funds/$id/details');
  }
  
  /// Mettre à jour un fonds
  Future<Map<String, dynamic>> updateFund(
    int id,
    Map<String, dynamic> data,
  ) async {
    return await _apiClient.put('/funds/$id', data);
  }
  
  /// Activer un fonds
  Future<Map<String, dynamic>> activateFund(int id) async {
    return await _apiClient.put('/funds/$id/activate', {});
  }
  
  /// Désactiver un fonds
  Future<Map<String, dynamic>> deactivateFund(int id) async {
    return await _apiClient.put('/funds/$id/deactivate', {});
  }
  
  /// Supprimer un fonds
  Future<Map<String, dynamic>> deleteFund(int id) async {
    return await _apiClient.delete('/funds/$id');
  }
  
  /// Récupérer les performances d'un fonds
  Future<Map<String, dynamic>> getFundPerformance(int id) async {
    return await _apiClient.get('/funds/$id/performance');
  }
  
  /// Récupérer les distributions d'un fonds
  Future<Map<String, dynamic>> getFundDistributions(int id) async {
    return await _apiClient.get('/funds/$id/distributions');
  }
}
```

---

## 💼 7. Services API - Portefeuille

### Fichier: `lib/services/portfolio_service.dart`

```dart
import 'api_client.dart';

class PortfolioService {
  final ApiClient _apiClient;
  
  PortfolioService({required ApiClient apiClient}) : _apiClient = apiClient;
  
  /// Récupérer le portefeuille d'un client
  Future<Map<String, dynamic>> getClientPortfolio(int clientId) async {
    return await _apiClient.get('/clients/$clientId/portfolio');
  }
  
  /// Récupérer le résumé du portefeuille
  Future<Map<String, dynamic>> getPortfolioSummary(int clientId) async {
    return await _apiClient.get('/clients/$clientId/portfolio/summary');
  }
  
  /// Récupérer les positions du portefeuille
  Future<Map<String, dynamic>> getPortfolioPositions(int clientId) async {
    return await _apiClient.get('/clients/$clientId/portfolio/positions');
  }
  
  /// Ajouter une position au portefeuille
  Future<Map<String, dynamic>> addPortfolioPosition(
    int clientId,
    Map<String, dynamic> data,
  ) async {
    return await _apiClient.post('/clients/$clientId/portfolio/positions', data);
  }
  
  /// Mettre à jour une position du portefeuille
  Future<Map<String, dynamic>> updatePortfolioPosition(
    int clientId,
    int positionId,
    Map<String, dynamic> data,
  ) async {
    return await _apiClient.put(
      '/clients/$clientId/portfolio/positions/$positionId',
      data,
    );
  }
  
  /// Supprimer une position du portefeuille
  Future<Map<String, dynamic>> deletePortfolioPosition(
    int clientId,
    int positionId,
  ) async {
    return await _apiClient.delete(
      '/clients/$clientId/portfolio/positions/$positionId',
    );
  }
  
  /// Récupérer l'allocation du portefeuille
  Future<Map<String, dynamic>> getPortfolioAllocation(int clientId) async {
    return await _apiClient.get('/clients/$clientId/portfolio/allocation');
  }
  
  /// Récupérer la performance du portefeuille
  Future<Map<String, dynamic>> getPortfolioPerformance(int clientId) async {
    return await _apiClient.get('/clients/$clientId/portfolio/performance');
  }
  
  /// Récupérer l'historique du portefeuille
  Future<Map<String, dynamic>> getPortfolioHistory(int clientId) async {
    return await _apiClient.get('/clients/$clientId/portfolio/history');
  }
}
```

---

## 💳 8. Services API - Transactions

### Fichier: `lib/services/transaction_service.dart`

```dart
import 'api_client.dart';

class TransactionService {
  final ApiClient _apiClient;
  
  TransactionService({required ApiClient apiClient}) : _apiClient = apiClient;
  
  /// Créer une nouvelle transaction
  Future<Map<String, dynamic>> createTransaction(
    Map<String, dynamic> data,
  ) async {
    return await _apiClient.post('/transactions', data);
  }
  
  /// Récupérer toutes les transactions
  Future<Map<String, dynamic>> getAllTransactions({
    int page = 1,
    int perPage = 10,
    String? type,
    String? status,
    int? clientId,
  }) async {
    String endpoint = '/transactions?page=$page&per_page=$perPage';
    if (type != null) endpoint += '&type=$type';
    if (status != null) endpoint += '&status=$status';
    if (clientId != null) endpoint += '&client_id=$clientId';
    
    return await _apiClient.get(endpoint);
  }
  
  /// Récupérer une transaction par ID
  Future<Map<String, dynamic>> getTransactionById(int id) async {
    return await _apiClient.get('/transactions/$id');
  }
  
  /// Transactions d'un client
  Future<Map<String, dynamic>> getClientTransactions(int clientId) async {
    return await _apiClient.get('/clients/$clientId/transactions');
  }
  
  /// Transactions d'un investissement
  Future<Map<String, dynamic>> getInvestmentTransactions(int investmentId) async {
    return await _apiClient.get('/investments/$investmentId/transactions');
  }
  
  /// Mettre à jour une transaction
  Future<Map<String, dynamic>> updateTransaction(
    int id,
    Map<String, dynamic> data,
  ) async {
    return await _apiClient.put('/transactions/$id', data);
  }
  
  /// Changer le statut d'une transaction
  Future<Map<String, dynamic>> updateTransactionStatus(
    int id,
    String status,
  ) async {
    return await _apiClient.put('/transactions/$id/status', {'status': status});
  }
  
  /// Approuver une transaction
  Future<Map<String, dynamic>> approveTransaction(int id) async {
    return await _apiClient.put('/transactions/$id/approve', {});
  }
  
  /// Rejeter une transaction
  Future<Map<String, dynamic>> rejectTransaction(
    int id,
    String reason,
  ) async {
    return await _apiClient.put(
      '/transactions/$id/reject',
      {'rejection_reason': reason},
    );
  }
  
  /// Supprimer une transaction
  Future<Map<String, dynamic>> deleteTransaction(int id) async {
    return await _apiClient.delete('/transactions/$id');
  }
  
  /// Récupérer le reçu d'une transaction
  Future<Map<String, dynamic>> getTransactionReceipt(int id) async {
    return await _apiClient.get('/transactions/$id/receipt');
  }
}
```

---

## 📊 9. Services API - Rapports

### Fichier: `lib/services/report_service.dart`

```dart
import 'api_client.dart';

class ReportService {
  final ApiClient _apiClient;
  
  ReportService({required ApiClient apiClient}) : _apiClient = apiClient;
  
  /// Créer un nouveau rapport
  Future<Map<String, dynamic>> createReport(Map<String, dynamic> data) async {
    return await _apiClient.post('/reports', data);
  }
  
  /// Récupérer tous les rapports
  Future<Map<String, dynamic>> getAllReports({
    int page = 1,
    int perPage = 10,
    String? type,
    int? clientId,
  }) async {
    String endpoint = '/reports?page=$page&per_page=$perPage';
    if (type != null) endpoint += '&type=$type';
    if (clientId != null) endpoint += '&client_id=$clientId';
    
    return await _apiClient.get(endpoint);
  }
  
  /// Récupérer un rapport par ID
  Future<Map<String, dynamic>> getReportById(int id) async {
    return await _apiClient.get('/reports/$id');
  }
  
  /// Rapports d'un client
  Future<Map<String, dynamic>> getClientReports(int clientId) async {
    return await _apiClient.get('/clients/$clientId/reports');
  }
  
  /// Générer un rapport financier
  Future<Map<String, dynamic>> generateFinancialReport(
    int clientId,
    Map<String, dynamic> params,
  ) async {
    return await _apiClient.post(
      '/clients/$clientId/reports/financial',
      params,
    );
  }
  
  /// Générer un rapport de performance
  Future<Map<String, dynamic>> generatePerformanceReport(
    int clientId,
    Map<String, dynamic> params,
  ) async {
    return await _apiClient.post(
      '/clients/$clientId/reports/performance',
      params,
    );
  }
  
  /// Générer un rapport de portefeuille
  Future<Map<String, dynamic>> generatePortfolioReport(
    int clientId,
    Map<String, dynamic> params,
  ) async {
    return await _apiClient.post(
      '/clients/$clientId/reports/portfolio',
      params,
    );
  }
  
  /// Mettre à jour un rapport
  Future<Map<String, dynamic>> updateReport(
    int id,
    Map<String, dynamic> data,
  ) async {
    return await _apiClient.put('/reports/$id', data);
  }
  
  /// Supprimer un rapport
  Future<Map<String, dynamic>> deleteReport(int id) async {
    return await _apiClient.delete('/reports/$id');
  }
  
  /// Télécharger un rapport (PDF)
  Future<dynamic> downloadReport(int id, String format) async {
    return await _apiClient.get('/reports/$id/download?format=$format');
  }
  
  /// Récupérer l'historique des rapports
  Future<Map<String, dynamic>> getReportHistory(int clientId) async {
    return await _apiClient.get('/clients/$clientId/reports/history');
  }
}
```

---

## ⚙️ 10. Services API - Administration

### Fichier: `lib/services/admin_service.dart`

```dart
import 'api_client.dart';

class AdminService {
  final ApiClient _apiClient;
  
  AdminService({required ApiClient apiClient}) : _apiClient = apiClient;
  
  /// Récupérer toutes les utilisateurs
  Future<Map<String, dynamic>> getAllUsers({
    int page = 1,
    int perPage = 10,
    String? role,
  }) async {
    String endpoint = '/admin/users?page=$page&per_page=$perPage';
    if (role != null) endpoint += '&role=$role';
    
    return await _apiClient.get(endpoint);
  }
  
  /// Créer un nouvel utilisateur
  Future<Map<String, dynamic>> createUser(Map<String, dynamic> data) async {
    return await _apiClient.post('/admin/users', data);
  }
  
  /// Récupérer un utilisateur par ID
  Future<Map<String, dynamic>> getUserById(int id) async {
    return await _apiClient.get('/admin/users/$id');
  }
  
  /// Mettre à jour un utilisateur
  Future<Map<String, dynamic>> updateUser(
    int id,
    Map<String, dynamic> data,
  ) async {
    return await _apiClient.put('/admin/users/$id', data);
  }
  
  /// Changer le rôle d'un utilisateur
  Future<Map<String, dynamic>> changeUserRole(int id, String role) async {
    return await _apiClient.put('/admin/users/$id/role', {'role': role});
  }
  
  /// Désactiver un utilisateur
  Future<Map<String, dynamic>> disableUser(int id) async {
    return await _apiClient.put('/admin/users/$id/disable', {});
  }
  
  /// Activer un utilisateur
  Future<Map<String, dynamic>> enableUser(int id) async {
    return await _apiClient.put('/admin/users/$id/enable', {});
  }
  
  /// Supprimer un utilisateur
  Future<Map<String, dynamic>> deleteUser(int id) async {
    return await _apiClient.delete('/admin/users/$id');
  }
  
  /// Réinitialiser le mot de passe d'un utilisateur
  Future<Map<String, dynamic>> resetUserPassword(int id) async {
    return await _apiClient.post('/admin/users/$id/reset-password', {});
  }
  
  /// Statistiques du système
  Future<Map<String, dynamic>> getSystemStats() async {
    return await _apiClient.get('/admin/stats');
  }
  
  /// Statistiques des clients
  Future<Map<String, dynamic>> getClientStats() async {
    return await _apiClient.get('/admin/stats/clients');
  }
  
  /// Statistiques des investissements
  Future<Map<String, dynamic>> getInvestmentStats() async {
    return await _apiClient.get('/admin/stats/investments');
  }
  
  /// Statistiques des transactions
  Future<Map<String, dynamic>> getTransactionStats() async {
    return await _apiClient.get('/admin/stats/transactions');
  }
  
  /// Logs du système
  Future<Map<String, dynamic>> getSystemLogs({
    int page = 1,
    int perPage = 20,
  }) async {
    return await _apiClient.get('/admin/logs?page=$page&per_page=$perPage');
  }
  
  /// Logs d'audit
  Future<Map<String, dynamic>> getAuditLogs({
    int page = 1,
    int perPage = 20,
  }) async {
    return await _apiClient.get('/admin/audit-logs?page=$page&per_page=$perPage');
  }
  
  /// Paramètres du système
  Future<Map<String, dynamic>> getSystemSettings() async {
    return await _apiClient.get('/admin/settings');
  }
  
  /// Mettre à jour les paramètres du système
  Future<Map<String, dynamic>> updateSystemSettings(
    Map<String, dynamic> data,
  ) async {
    return await _apiClient.put('/admin/settings', data);
  }
}
```

---

## 📦 11. Configuration de Dependency Injection

### Fichier: `lib/services/service_locator.dart`

```dart
import 'package:get_it/get_it.dart';
import 'api_client.dart';
import 'auth_service.dart';
import 'client_service.dart';
import 'investment_service.dart';
import 'fund_service.dart';
import 'portfolio_service.dart';
import 'transaction_service.dart';
import 'report_service.dart';
import 'admin_service.dart';

final getIt = GetIt.instance;

void setupServiceLocator() {
  // Enregistrer ApiClient
  getIt.registerSingleton<ApiClient>(ApiClient());
  
  // Enregistrer tous les services
  getIt.registerSingleton<AuthService>(
    AuthService(apiClient: getIt<ApiClient>()),
  );
  
  getIt.registerSingleton<ClientService>(
    ClientService(apiClient: getIt<ApiClient>()),
  );
  
  getIt.registerSingleton<InvestmentService>(
    InvestmentService(apiClient: getIt<ApiClient>()),
  );
  
  getIt.registerSingleton<FundService>(
    FundService(apiClient: getIt<ApiClient>()),
  );
  
  getIt.registerSingleton<PortfolioService>(
    PortfolioService(apiClient: getIt<ApiClient>()),
  );
  
  getIt.registerSingleton<TransactionService>(
    TransactionService(apiClient: getIt<ApiClient>()),
  );
  
  getIt.registerSingleton<ReportService>(
    ReportService(apiClient: getIt<ApiClient>()),
  );
  
  getIt.registerSingleton<AdminService>(
    AdminService(apiClient: getIt<ApiClient>()),
  );
}
```

---

## 🎯 12. Utilisation dans main.dart

```dart
import 'package:flutter/material.dart';
import 'services/service_locator.dart';
import 'services/auth_service.dart';

void main() {
  setupServiceLocator();
  runApp(const MyApp());
}

class MyApp extends StatelessWidget {
  const MyApp({Key? key}) : super(key: key);

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'African Trust Fund',
      theme: ThemeData(primarySwatch: Colors.blue),
      home: const HomePage(),
    );
  }
}

// Exemple d'utilisation
class HomePage extends StatefulWidget {
  const HomePage({Key? key}) : super(key: key);

  @override
  State<HomePage> createState() => _HomePageState();
}

class _HomePageState extends State<HomePage> {
  late AuthService _authService;

  @override
  void initState() {
    super.initState();
    _authService = getIt<AuthService>();
  }

  Future<void> _login() async {
    try {
      final response = await _authService.login('user@example.com', 'password');
      if (response['status'] == 'success') {
        // Naviguer vers l'écran principal
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Connexion réussie')),
        );
      }
    } catch (e) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Erreur: $e')),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('African Trust Fund')),
      body: Center(
        child: ElevatedButton(
          onPressed: _login,
          child: const Text('Se connecter'),
        ),
      ),
    );
  }
}
```

