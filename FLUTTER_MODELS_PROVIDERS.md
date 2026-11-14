# 📱 Modèles et Providers Dart - Flutter Integration (PART 3)

## 📦 Modèles de Données Complets

### Fichier: `lib/models/investment.dart`

```dart
class Investment {
  final int id;
  final int clientId;
  final int fundId;
  final double amount;
  final String investmentType;
  final String status;
  final DateTime investmentDate;
  final DateTime? maturityDate;
  final double? expectedReturn;
  final String? description;
  final DateTime createdAt;
  
  Investment({
    required this.id,
    required this.clientId,
    required this.fundId,
    required this.amount,
    required this.investmentType,
    required this.status,
    required this.investmentDate,
    this.maturityDate,
    this.expectedReturn,
    this.description,
    required this.createdAt,
  });
  
  factory Investment.fromJson(Map<String, dynamic> json) {
    return Investment(
      id: json['id'],
      clientId: json['client_id'],
      fundId: json['fund_id'],
      amount: double.parse(json['amount'].toString()),
      investmentType: json['investment_type'],
      status: json['status'],
      investmentDate: DateTime.parse(json['investment_date']),
      maturityDate: json['maturity_date'] != null 
        ? DateTime.parse(json['maturity_date']) 
        : null,
      expectedReturn: json['expected_return'] != null
        ? double.parse(json['expected_return'].toString())
        : null,
      description: json['description'],
      createdAt: DateTime.parse(json['created_at']),
    );
  }
  
  Map<String, dynamic> toJson() => {
    'client_id': clientId,
    'fund_id': fundId,
    'amount': amount,
    'investment_type': investmentType,
    'status': status,
    'investment_date': investmentDate.toIso8601String(),
    'maturity_date': maturityDate?.toIso8601String(),
    'expected_return': expectedReturn,
    'description': description,
  };
}
```

### Fichier: `lib/models/fund.dart`

```dart
class Fund {
  final int id;
  final String name;
  final String description;
  final String fundType;
  final double minimumInvestment;
  final double maximumInvestment;
  final double currentValue;
  final double targetValue;
  final String status;
  final double interestRate;
  final int durationMonths;
  final DateTime createdAt;
  
  Fund({
    required this.id,
    required this.name,
    required this.description,
    required this.fundType,
    required this.minimumInvestment,
    required this.maximumInvestment,
    required this.currentValue,
    required this.targetValue,
    required this.status,
    required this.interestRate,
    required this.durationMonths,
    required this.createdAt,
  });
  
  factory Fund.fromJson(Map<String, dynamic> json) {
    return Fund(
      id: json['id'],
      name: json['name'],
      description: json['description'],
      fundType: json['fund_type'],
      minimumInvestment: double.parse(json['minimum_investment'].toString()),
      maximumInvestment: double.parse(json['maximum_investment'].toString()),
      currentValue: double.parse(json['current_value'].toString()),
      targetValue: double.parse(json['target_value'].toString()),
      status: json['status'],
      interestRate: double.parse(json['interest_rate'].toString()),
      durationMonths: json['duration_months'],
      createdAt: DateTime.parse(json['created_at']),
    );
  }
  
  Map<String, dynamic> toJson() => {
    'name': name,
    'description': description,
    'fund_type': fundType,
    'minimum_investment': minimumInvestment,
    'maximum_investment': maximumInvestment,
    'current_value': currentValue,
    'target_value': targetValue,
    'status': status,
    'interest_rate': interestRate,
    'duration_months': durationMonths,
  };
}
```

### Fichier: `lib/models/portfolio.dart`

```dart
class Portfolio {
  final int id;
  final int clientId;
  final double totalValue;
  final double totalInvested;
  final double totalReturns;
  final double returnPercentage;
  final List<PortfolioPosition> positions;
  final DateTime lastUpdated;
  
  Portfolio({
    required this.id,
    required this.clientId,
    required this.totalValue,
    required this.totalInvested,
    required this.totalReturns,
    required this.returnPercentage,
    required this.positions,
    required this.lastUpdated,
  });
  
  factory Portfolio.fromJson(Map<String, dynamic> json) {
    return Portfolio(
      id: json['id'],
      clientId: json['client_id'],
      totalValue: double.parse(json['total_value'].toString()),
      totalInvested: double.parse(json['total_invested'].toString()),
      totalReturns: double.parse(json['total_returns'].toString()),
      returnPercentage: double.parse(json['return_percentage'].toString()),
      positions: (json['positions'] as List)
        .map((p) => PortfolioPosition.fromJson(p))
        .toList(),
      lastUpdated: DateTime.parse(json['last_updated']),
    );
  }
}

class PortfolioPosition {
  final int id;
  final int fundId;
  final String fundName;
  final double quantity;
  final double unitPrice;
  final double totalValue;
  final double percentageOfPortfolio;
  
  PortfolioPosition({
    required this.id,
    required this.fundId,
    required this.fundName,
    required this.quantity,
    required this.unitPrice,
    required this.totalValue,
    required this.percentageOfPortfolio,
  });
  
  factory PortfolioPosition.fromJson(Map<String, dynamic> json) {
    return PortfolioPosition(
      id: json['id'],
      fundId: json['fund_id'],
      fundName: json['fund_name'],
      quantity: double.parse(json['quantity'].toString()),
      unitPrice: double.parse(json['unit_price'].toString()),
      totalValue: double.parse(json['total_value'].toString()),
      percentageOfPortfolio: double.parse(
        json['percentage_of_portfolio'].toString(),
      ),
    );
  }
}
```

### Fichier: `lib/models/transaction.dart`

```dart
class Transaction {
  final int id;
  final int clientId;
  final int? investmentId;
  final String transactionType;
  final double amount;
  final String status;
  final String paymentMethod;
  final String? description;
  final DateTime transactionDate;
  final DateTime createdAt;
  
  Transaction({
    required this.id,
    required this.clientId,
    this.investmentId,
    required this.transactionType,
    required this.amount,
    required this.status,
    required this.paymentMethod,
    this.description,
    required this.transactionDate,
    required this.createdAt,
  });
  
  factory Transaction.fromJson(Map<String, dynamic> json) {
    return Transaction(
      id: json['id'],
      clientId: json['client_id'],
      investmentId: json['investment_id'],
      transactionType: json['transaction_type'],
      amount: double.parse(json['amount'].toString()),
      status: json['status'],
      paymentMethod: json['payment_method'],
      description: json['description'],
      transactionDate: DateTime.parse(json['transaction_date']),
      createdAt: DateTime.parse(json['created_at']),
    );
  }
  
  Map<String, dynamic> toJson() => {
    'client_id': clientId,
    'investment_id': investmentId,
    'transaction_type': transactionType,
    'amount': amount,
    'status': status,
    'payment_method': paymentMethod,
    'description': description,
    'transaction_date': transactionDate.toIso8601String(),
  };
}
```

### Fichier: `lib/models/report.dart`

```dart
class Report {
  final int id;
  final int clientId;
  final String reportType;
  final String title;
  final String content;
  final String status;
  final DateTime generatedDate;
  final DateTime? fromDate;
  final DateTime? toDate;
  final DateTime createdAt;
  
  Report({
    required this.id,
    required this.clientId,
    required this.reportType,
    required this.title,
    required this.content,
    required this.status,
    required this.generatedDate,
    this.fromDate,
    this.toDate,
    required this.createdAt,
  });
  
  factory Report.fromJson(Map<String, dynamic> json) {
    return Report(
      id: json['id'],
      clientId: json['client_id'],
      reportType: json['report_type'],
      title: json['title'],
      content: json['content'],
      status: json['status'],
      generatedDate: DateTime.parse(json['generated_date']),
      fromDate: json['from_date'] != null 
        ? DateTime.parse(json['from_date']) 
        : null,
      toDate: json['to_date'] != null 
        ? DateTime.parse(json['to_date']) 
        : null,
      createdAt: DateTime.parse(json['created_at']),
    );
  }
}
```

---

## 🎯 State Management avec Riverpod

### Fichier: `lib/providers/auth_provider.dart`

```dart
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../models/user.dart';
import '../services/service_locator.dart';
import '../services/auth_service.dart';

// État d'authentification
class AuthState {
  final User? user;
  final String? token;
  final bool isLoading;
  final String? error;
  
  AuthState({
    this.user,
    this.token,
    this.isLoading = false,
    this.error,
  });
  
  AuthState copyWith({
    User? user,
    String? token,
    bool? isLoading,
    String? error,
  }) {
    return AuthState(
      user: user ?? this.user,
      token: token ?? this.token,
      isLoading: isLoading ?? this.isLoading,
      error: error ?? this.error,
    );
  }
}

// Provider pour l'état d'authentification
final authStateProvider = StateNotifierProvider<AuthNotifier, AuthState>((ref) {
  return AuthNotifier();
});

class AuthNotifier extends StateNotifier<AuthState> {
  final _authService = getIt<AuthService>();
  
  AuthNotifier() : super(AuthState());
  
  Future<bool> login(String email, String password) async {
    state = state.copyWith(isLoading: true, error: null);
    
    try {
      final response = await _authService.login(email, password);
      
      if (response['status'] == 'success') {
        final userData = response['data']['user'];
        final user = User.fromJson(userData);
        final token = response['data']['token'];
        
        state = state.copyWith(
          user: user,
          token: token,
          isLoading: false,
        );
        
        return true;
      } else {
        state = state.copyWith(
          error: response['message'] ?? 'Erreur de connexion',
          isLoading: false,
        );
        return false;
      }
    } catch (e) {
      state = state.copyWith(
        error: e.toString(),
        isLoading: false,
      );
      return false;
    }
  }
  
  Future<bool> logout() async {
    state = state.copyWith(isLoading: true);
    
    try {
      await _authService.logout();
      state = AuthState();
      return true;
    } catch (e) {
      state = state.copyWith(
        error: e.toString(),
        isLoading: false,
      );
      return false;
    }
  }
}
```

### Fichier: `lib/providers/client_provider.dart`

```dart
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../models/client.dart';
import '../services/service_locator.dart';
import '../services/client_service.dart';

class ClientListState {
  final List<Client> clients;
  final bool isLoading;
  final String? error;
  final int currentPage;
  final int totalPages;
  
  ClientListState({
    this.clients = const [],
    this.isLoading = false,
    this.error,
    this.currentPage = 1,
    this.totalPages = 1,
  });
  
  ClientListState copyWith({
    List<Client>? clients,
    bool? isLoading,
    String? error,
    int? currentPage,
    int? totalPages,
  }) {
    return ClientListState(
      clients: clients ?? this.clients,
      isLoading: isLoading ?? this.isLoading,
      error: error ?? this.error,
      currentPage: currentPage ?? this.currentPage,
      totalPages: totalPages ?? this.totalPages,
    );
  }
}

// Provider pour la liste des clients
final clientListProvider = StateNotifierProvider<ClientNotifier, ClientListState>(
  (ref) => ClientNotifier(),
);

class ClientNotifier extends StateNotifier<ClientListState> {
  final _clientService = getIt<ClientService>();
  
  ClientNotifier() : super(ClientListState());
  
  Future<void> fetchClients({int page = 1}) async {
    state = state.copyWith(isLoading: true, error: null);
    
    try {
      final response = await _clientService.getAllClients(
        page: page,
        perPage: 10,
      );
      
      final clients = (response['data'] as List)
        .map((c) => Client.fromJson(c))
        .toList();
      
      state = state.copyWith(
        clients: clients,
        isLoading: false,
        currentPage: page,
        totalPages: response['pagination']['last_page'] ?? 1,
      );
    } catch (e) {
      state = state.copyWith(
        error: e.toString(),
        isLoading: false,
      );
    }
  }
  
  Future<bool> createClient(Map<String, dynamic> data) async {
    state = state.copyWith(isLoading: true, error: null);
    
    try {
      await _clientService.createClient(data);
      await fetchClients();
      return true;
    } catch (e) {
      state = state.copyWith(
        error: e.toString(),
        isLoading: false,
      );
      return false;
    }
  }
  
  Future<bool> updateClient(int id, Map<String, dynamic> data) async {
    state = state.copyWith(isLoading: true, error: null);
    
    try {
      await _clientService.updateClient(id, data);
      await fetchClients();
      return true;
    } catch (e) {
      state = state.copyWith(
        error: e.toString(),
        isLoading: false,
      );
      return false;
    }
  }
}

// Provider pour un client spécifique
final clientProvider = FutureProvider.family<Client, int>((ref, clientId) async {
  final clientService = getIt<ClientService>();
  final response = await clientService.getClientById(clientId);
  return Client.fromJson(response['data']);
});
```

### Fichier: `lib/providers/investment_provider.dart`

```dart
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../models/investment.dart';
import '../services/service_locator.dart';
import '../services/investment_service.dart';

class InvestmentListState {
  final List<Investment> investments;
  final bool isLoading;
  final String? error;
  
  InvestmentListState({
    this.investments = const [],
    this.isLoading = false,
    this.error,
  });
  
  InvestmentListState copyWith({
    List<Investment>? investments,
    bool? isLoading,
    String? error,
  }) {
    return InvestmentListState(
      investments: investments ?? this.investments,
      isLoading: isLoading ?? this.isLoading,
      error: error ?? this.error,
    );
  }
}

final investmentListProvider = StateNotifierProvider<
  InvestmentNotifier,
  InvestmentListState
>((ref) => InvestmentNotifier());

class InvestmentNotifier extends StateNotifier<InvestmentListState> {
  final _investmentService = getIt<InvestmentService>();
  
  InvestmentNotifier() : super(InvestmentListState());
  
  Future<void> fetchInvestments({int? clientId}) async {
    state = state.copyWith(isLoading: true, error: null);
    
    try {
      final response = clientId != null
        ? await _investmentService.getClientInvestments(clientId)
        : await _investmentService.getAllInvestments();
      
      final investments = (response['data'] as List)
        .map((i) => Investment.fromJson(i))
        .toList();
      
      state = state.copyWith(
        investments: investments,
        isLoading: false,
      );
    } catch (e) {
      state = state.copyWith(
        error: e.toString(),
        isLoading: false,
      );
    }
  }
  
  Future<bool> createInvestment(Map<String, dynamic> data) async {
    state = state.copyWith(isLoading: true, error: null);
    
    try {
      await _investmentService.createInvestment(data);
      await fetchInvestments();
      return true;
    } catch (e) {
      state = state.copyWith(
        error: e.toString(),
        isLoading: false,
      );
      return false;
    }
  }
}

// Provider pour les investissements d'un client
final clientInvestmentsProvider = FutureProvider.family<
  List<Investment>,
  int
>((ref, clientId) async {
  final investmentService = getIt<InvestmentService>();
  final response = await investmentService.getClientInvestments(clientId);
  return (response['data'] as List)
    .map((i) => Investment.fromJson(i))
    .toList();
});
```

### Fichier: `lib/providers/portfolio_provider.dart`

```dart
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../models/portfolio.dart';
import '../services/service_locator.dart';
import '../services/portfolio_service.dart';

// Provider pour le portefeuille d'un client
final portfolioProvider = FutureProvider.family<Portfolio, int>(
  (ref, clientId) async {
    final portfolioService = getIt<PortfolioService>();
    final response = await portfolioService.getClientPortfolio(clientId);
    return Portfolio.fromJson(response['data']);
  },
);

// Provider pour les performances du portefeuille
final portfolioPerformanceProvider = FutureProvider.family<
  Map<String, dynamic>,
  int
>((ref, clientId) async {
  final portfolioService = getIt<PortfolioService>();
  return await portfolioService.getPortfolioPerformance(clientId);
});

// Provider pour l'allocation du portefeuille
final portfolioAllocationProvider = FutureProvider.family<
  Map<String, dynamic>,
  int
>((ref, clientId) async {
  final portfolioService = getIt<PortfolioService>();
  return await portfolioService.getPortfolioAllocation(clientId);
});
```

### Fichier: `lib/providers/transaction_provider.dart`

```dart
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../models/transaction.dart';
import '../services/service_locator.dart';
import '../services/transaction_service.dart';

class TransactionListState {
  final List<Transaction> transactions;
  final bool isLoading;
  final String? error;
  
  TransactionListState({
    this.transactions = const [],
    this.isLoading = false,
    this.error,
  });
}

final transactionListProvider = StateNotifierProvider<
  TransactionNotifier,
  TransactionListState
>((ref) => TransactionNotifier());

class TransactionNotifier extends StateNotifier<TransactionListState> {
  final _transactionService = getIt<TransactionService>();
  
  TransactionNotifier() : super(TransactionListState());
  
  Future<void> fetchTransactions({int? clientId}) async {
    try {
      final response = clientId != null
        ? await _transactionService.getClientTransactions(clientId)
        : await _transactionService.getAllTransactions();
      
      final transactions = (response['data'] as List)
        .map((t) => Transaction.fromJson(t))
        .toList();
      
      state = TransactionListState(transactions: transactions);
    } catch (e) {
      state = TransactionListState(error: e.toString());
    }
  }
}

// Provider pour les transactions d'un client
final clientTransactionsProvider = FutureProvider.family<
  List<Transaction>,
  int
>((ref, clientId) async {
  final transactionService = getIt<TransactionService>();
  final response = await transactionService.getClientTransactions(clientId);
  return (response['data'] as List)
    .map((t) => Transaction.fromJson(t))
    .toList();
});
```

---

## 📝 Configuration pubspec.yaml

Ajouter les dépendances suivantes à votre `pubspec.yaml` :

```yaml
dependencies:
  flutter:
    sdk: flutter
  http: ^1.1.0
  flutter_secure_storage: ^9.0.0
  get_it: ^7.5.0
  flutter_riverpod: ^2.4.0
  riverpod_annotation: ^2.3.0
  provider: ^6.0.0

dev_dependencies:
  flutter_test:
    sdk: flutter
  riverpod_generator: ^2.3.0
  build_runner: ^2.4.0
```

Installer les dépendances :
```bash
flutter pub get
flutter pub run build_runner build
```

---

## ✅ Prochaines Étapes

1. **Créer les écrans UI** avec les widgets Flutter
2. **Implémenter la navigation** entre les écrans
3. **Ajouter les validations de formulaires**
4. **Gérer les erreurs et les états de chargement**
5. **Tester les intégrations API**
6. **Déployer l'application**

