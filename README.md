# 🏛️ African Trust Fund - Backend PHP

Plateforme complète de gestion d'actifs, prêts et placements pour une institution financière décentralisée.

## 📋 Table des matières

- [Vue d'ensemble](#vue-densemble)
- [Caractéristiques](#caractéristiques)
- [Architecture](#architecture)
- [Installation](#installation)
- [Configuration](#configuration)
- [Base de données](#base-de-données)
- [Documentation](#documentation)
- [Sécurité](#sécurité)
- [Contribution](#contribution)

## 🎯 Vue d'ensemble

**African Trust Fund** est une solution PHP complète pour :
- Gestion d'actifs et placements financiers
- Système de prêts avec calcul automatique d'intérêts et pénalités
- Gestion multi-utilisateurs (Admin, Caissiers, Clients, Actionnaires)
- Audit complet et traçabilité des transactions
- Sécurité avancée avec blocage IP et authentification multi-niveaux
- Assistant IA intégré (Google Gemini)

## ✨ Caractéristiques principales

### 💰 Gestion Financière
- ✅ **Transactions** : Dépôts, retraits, virements
- ✅ **Prêts** : 9 statuts progressifs, calcul auto des intérêts
- ✅ **Placements** : Suivi automatisé avec gains calculés
- ✅ **Multi-devises** : CDF et USD avec soldes séparés
- ✅ **Commissions** : 1% retrait, 0.5% virement (configurable)

### 👥 Gestion des Utilisateurs
- 🔐 Authentification sécurisée par rôle
- 📱 Admin Principal, Caissiers, Clients, Actionnaires
- 🛡️ Blocage IP automatique après tentatives échouées
- 📊 Profils détaillés avec documents

### 📈 Rapports & Statistiques
- 📋 Rapports journaliers, mensuels, annuels
- 📊 Statistiques en temps réel
- 💳 Impressions de relevés et reçus
- 📉 Analyse des gains et pertes

### 🤖 Intelligence Artificielle
- 💡 Assistant IA basé sur Google Gemini
- 🎯 Contexte métier intégré
- 💬 Chat multi-tours avec historique

### 🔐 Sécurité
- 🔑 Mots de passe bcrypt
- 📝 Audit complet de toutes les actions
- 🚫 Blocage d'adresses IP suspectes
- ⏰ Sessions avec timeout
- 🔍 Détection d'attaques

## 🏗️ Architecture

```
african-trust-fund/
├── config/              # Configuration de l'application
├── database/            # Schémas SQL et migrations
├── admin/               # Interface administrateur
├── caissier/            # Interface caissier
├── client/              # Interface client
├── actionnaire/         # Interface actionnaire
├── auth/                # Authentification et sécurité
├── api/                 # Endpoints API
├── cron/                # Tâches planifiées
├── includes/            # Headers, footers, templates
├── assets/              # CSS, JavaScript, images
└── uploads/             # Fichiers uploadés
```

## 🚀 Installation

### Prérequis
- PHP 7.4 ou supérieur
- MySQL 5.7 ou MariaDB 10.3+
- Apache avec mod_rewrite (ou Nginx)
- Composer (optionnel)

### Étapes rapides

1. **Cloner le repository**
```bash
git clone https://github.com/jeanpaulktm/african_strust_fund_php.git
cd african_strust_fund_php
```

2. **Créer le fichier .env**
```bash
cp .env.example .env
# Éditer .env avec vos paramètres
```

3. **Importer la base de données**
```bash
mysql -u root -p african_trust_fund < database/schema_complete.sql
```

4. **Configurer les permissions**
```bash
chmod -R 755 uploads/
chmod -R 755 logs/
chmod 644 .env
```

5. **Accéder à l'application**
- Admin: `http://localhost/african-trust-fund/admin/dashboard.php`
- Client: `http://localhost/african-trust-fund/client/dashboard.php`

### Identifiants par défaut (⚠️ À changer immédiatement)
- **Téléphone**: `+243000000000`
- **Mot de passe**: `ATF@Admin2025`

## ⚙️ Configuration

### Variables d'environnement (.env)

```bash
# Base de données
DB_HOST=localhost
DB_USER=root
DB_PASSWORD=
DB_NAME=african_trust_fund

# API Gemini (IA)
GEMINI_API_KEY=votre_cle_ici
GEMINI_MODEL=gemini-3.5-flash

# Maisha Pay (Paiements)
MAISHAPAY_API_KEY=votre_cle_ici
MAISHAPAY_SECRET_KEY=votre_secret_ici
```

Voir [.env.example](.env.example) pour la liste complète.

## 📊 Base de données

### 27 Tables
- **Utilisateurs** : Admin_Principal, Caissier, Client, Actionnaires
- **Transactions** : Transactions, Prets, Echeances_Pret, Interet_Pret, etc.
- **Placements** : Placements, Interet_Placement
- **Système** : Parametres_Systeme, Depenses, Audit_Logs
- **Sécurité** : IPs_Bloquees

### Schéma SQL

Le fichier complet est disponible à :
```
database/schema_complete.sql
```

Voir aussi :
- [ANALYSE_BD_COMPLET.md](ANALYSE_BD_COMPLET.md) - Structure détaillée
- [DIAGRAMME_ER_COMPLET.md](DIAGRAMME_ER_COMPLET.md) - Schéma relationnel
- [REFERENCE_COLONNES.md](REFERENCE_COLONNES.md) - Liste des colonnes

## 📖 Documentation

- 📄 [QUICK_START.txt](QUICK_START.txt) - Guide rapide 2 minutes
- 📄 [GUIDE_INSTALLATION_BD.md](GUIDE_INSTALLATION_BD.md) - Installation détaillée
- 📄 [ANALYSE_BD_COMPLET.md](ANALYSE_BD_COMPLET.md) - Analyse complète
- 📄 [README_BD.md](README_BD.md) - Documentation base de données
- 🌐 [documentation_index.html](documentation_index.html) - Index interactif

## 🔐 Sécurité

### Bonnes pratiques appliquées
- ✅ Mots de passe hashés en bcrypt
- ✅ Protection CSRF sur tous les formulaires
- ✅ Validation d'entrée stricte
- ✅ Chaînage de paramètres prepared statements
- ✅ Audit complet des actions
- ✅ Blocage IP après tentatives échouées
- ✅ Sessions sécurisées avec timeout
- ✅ Variables sensibles en environnement

### À retenir
- **Ne pas commiter** de fichiers `.env` avec des clés secrètes
- **Changer** immédiatement les identifiants par défaut
- **Monitorer** les logs d'audit régulièrement
- **Utiliser HTTPS** en production

## 🤝 Contribution

Les contributions sont bienvenues ! Merci de :

1. Forker le repository
2. Créer une branche (`git checkout -b feature/AmazingFeature`)
3. Commit les changements (`git commit -m 'Add some AmazingFeature'`)
4. Push vers la branche (`git push origin feature/AmazingFeature`)
5. Ouvrir une Pull Request

## 📞 Support

Pour toute question ou problème :
- 📧 Créer une issue sur GitHub
- 💬 Consulter la documentation
- 🔍 Vérifier les logs d'application

## 📄 Licence

Ce projet est sous [MIT License](LICENSE).

## 👨‍💻 Auteur

**Jean Paul KTM**
- GitHub: [@jeanpaulktm](https://github.com/jeanpaulktm)

---

**Version**: 1.0  
**Dernière mise à jour**: 14 Novembre 2026  
**Statut**: ✅ Prêt pour production

*Construit avec ❤️ pour l'Afrique financière décentralisée*
