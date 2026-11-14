# 📊 ANALYSE COMPLÈTE DU PROJET AFRICAN TRUST FUND
## Document de Reconstruction de Base de Données

---

## 🎯 RÉSUMÉ EXÉCUTIF

**Projet :** African Trust Fund (ATF) - Plateforme de Gestion d'Actifs
**Type :** Application web PHP/MySQL pour la gestion de comptes clients, prêts, placements et parts sociales
**Base de données :** atf_db (MySQL InnoDB UTF8MB4)

---

## 📋 STRUCTURE DE LA BASE DE DONNÉES

### **ENTITÉS PRINCIPALES (8 tables)**

#### 1. **Admin_Principal**
- Gestionnaires du système
- Rôles : super_admin, admin, superviseur
- Sécurité : mot de passe bcrypt, tentatives de connexion bloquées

#### 2. **Caissier**
- Agents de caisse
- Autorisations de transactions
- Audit et traçabilité

#### 3. **Client**
- Comptes clients (physique/morale)
- Soldes en CDF et USD séparés
- Mandataires et documents d'identité
- États : actif, inactif, bloqué

#### 4. **Transactions**
- Dépôts, retraits, virements
- Types : Dépôt, Retrait, Virement
- Modes : En ligne, Agence
- Traçage complet (IP, référence unique)

#### 5. **Prets**
- Demandes de prêt et suivi complet
- États : soumis → en_analyse → approuvé → décaissé → en_cours → solde/retard
- Calcul d'intérêts et durée flexible
- Taux appliqué et montant restant dû

#### 6. **Placements**
- Épargne et placements clients
- Calcul d'intérêts (simple/composé)
- Suivi des commissions prélevées
- Devise supportée : CDF, USD

#### 7. **Actionnaires**
- Actionnaires de la société
- Gestion des parts sociales
- Suivi du capital investi
- Pourcentage de détention calculé

#### 8. **Audit_Logs**
- Enregistrement de tous les accès
- Actions par type d'utilisateur
- IP et user-agent tracés

---

## 📊 TABLES DÉTAILLÉES (27 tables totales)

### **GESTION DES UTILISATEURS**
```
Admin_Principal
├─ Caissier
├─ Client (type: physique/morale)
└─ Actionnaires
```

### **TRANSACTIONS FINANCIÈRES**
```
Transactions
├─ Prets
│  ├─ Echeances_Pret (échéancier)
│  ├─ Prets_Remboursements (historique)
│  ├─ Prets_Historique (suivi statut)
│  └─ Produits_Pret (types de prêts)
├─ Placements
│  └─ Interets_Entreprise (calculs)
└─ Commissions (frais prélevés)
```

### **ACTIONNARIAT**
```
Actionnaires
├─ Parts_Sociales (mouvements)
├─ Dividendes (distributions)
└─ Capital_ATF (configuration par an)
```

### **DOCUMENTS & SÉCURITÉ**
```
Client_Documents (pièces d'identité)
IPs_Bloquees (sécurité)
Audit_Logs (traçabilité)
Parametres_Systeme (configuration)
```

### **TESTS & MONITORING**
```
Tests_Flux
├─ Tests_Executions (historique)
└─ Tests_Resultats_Detail (détails)
```

### **COMPTABILITÉ**
```
Depenses (gestion des dépenses)
```

---

## 🔄 FLUX MÉTIER PRINCIPAUX

### **1. GESTION DES COMPTES**
```
Client (création) 
  → Authentification (mot_de_passe bcrypt)
  → Solde initial (solde_cdf = 0, solde_usd = 0)
  → Transactions (Dépôt → mise à jour solde)
```

### **2. CYCLE DE PRÊT**
```
Demande de Prêt
  → Soumis (statut = 'soumis')
  → Analyse (statut = 'en_analyse')
  → Approbation (statut = 'approuve', date_approbation)
  → Décaissement (statut = 'decaisse', date_decaissement)
  → Générer Échéancier (Echeances_Pret)
  → Remboursements (Prets_Remboursements)
  → Suivi Retards (statut = 'en_retard', penalites)
  → Soldé (statut = 'solde', date_echeance_finale)
```

### **3. PLACEMENT & ÉPARGNE**
```
Placement Client
  → Montant initial enregistré
  → Taux annuel appliqué
  → Calcul des intérêts (simple/composé)
  → Commissions prélevées (1% par défaut)
  → Cloture à l'échéance
```

### **4. ACTIONNARIAT**
```
Actionnaire
  → Souscription de parts (Parts_Sociales)
  → Validation admin
  → Mise à jour Capital_ATF
  → Distribution de dividendes (Dividendes)
```

---

## 💰 DEVISES SUPPORTÉES

| Devise | Code | Notation | Utilisation |
|--------|------|----------|-------------|
| Franc Congolais | CDF | solde_cdf | Par défaut |
| Dollar Américain | USD | solde_usd | Optionnel |
| Taux de change | - | 2300 | 1 USD = 2300 CDF |

### **Important : Pas de conversion automatique**
- Chaque devise a son propre solde
- Les clients peuvent deposer/retirer en CDF ou USD
- Les soldes restent séparés (solde_cdf, solde_usd)

---

## 🔐 SÉCURITÉ & AUDIT

### **Authentification**
- Mots de passe hachés en bcrypt (cost=12)
- Tentatives de connexion limitées (failed_attempts)
- Compte temporairement bloqué (locked_until)

### **Audit & Traçabilité**
- Chaque action enregistrée dans Audit_Logs
- IP address tracée
- User-agent enregistré
- Timestamp automatique

### **Blocage de Sécurité**
- Adresses IP bloquées (IPs_Bloquees)
- Raison de blocage enregistrée
- Déblocage possible par admin

---

## 📈 TAUX & PARAMÈTRES PAR DÉFAUT

```javascript
{
  "commission_retrait": 1.00,           // 1%
  "commission_virement": 0.50,          // 0.5%
  "taux_interet_placement_cdf": 5.00,   // 5% par an
  "taux_interet_placement_usd": 3.00,   // 3% par an
  "taux_interet_pret_cdf": 12.00,       // 12% par an
  "taux_interet_pret_usd": 8.00,        // 8% par an
  "montant_min_placement": 50000,       // CDF
  "montant_min_pret": 100000,           // CDF
  "taux_change_usd_cdf": 2300           // Taux de change
}
```

---

## 📊 PRODUITS PAR DÉFAUT

### **Produits de Prêt**

1. **Prêt Classique CDF**
   - Montant : 100 000 - 5 000 000 CDF
   - Taux : 12% par an
   - Durée : 3 - 60 mois
   - Type : Tous clients

2. **Prêt Classique USD**
   - Montant : 100 - 50 000 USD
   - Taux : 8% par an
   - Durée : 3 - 60 mois
   - Type : Tous clients

3. **Prêt PME/TPE**
   - Montant : 500 000 - 10 000 000 CDF
   - Taux : 10% par an
   - Durée : 6 - 60 mois
   - Type : Entreprises (morale)

4. **Prêt Personnel**
   - Montant : 50 000 - 3 000 000 CDF
   - Taux : 15% par an
   - Durée : 3 - 48 mois
   - Type : Individus (physique)

---

## 🗄️ COLONNES CLÉS PAR TABLE

### **Client**
```
id_client, nom, postnom, prenom
adresse_complete, numero_phone, email
type_client (physique/morale)
numero_compte (UNIQUE), solde_cdf, solde_usd
mot_de_passe (bcrypt), statut
failed_attempts, locked_until, last_login
created_at, updated_at
```

### **Transactions**
```
id_transaction, id_client, type_transaction
montant, devise, mode
effectue_par (Client/Caissier/Admin), id_caissier
solde_avant, solde_apres, reference
date_transaction, ip_address
```

### **Prets**
```
id_pret, id_client, id_produit
montant_demande, montant_accorde, montant_restant_du
taux_applique, duree_mois, devise
statut (soumis→approuve→decaisse→en_cours→solde)
date_demande, date_approbation, date_decaissement
montant_interet_total, reference
```

### **Placements**
```
id_placement, id_client, id_produit
montant_initial, montant_actuel, devise
taux_annuel, duree_mois, type_interet (simple/compose)
interets_cumules, montant_commission
statut (actif/clos/suspendu)
date_ouverture, date_echeance, date_cloture
```

### **Echeances_Pret**
```
id_echeance, id_pret, numero_echeance
date_echeance, montant_capital, montant_interet, montant_total
montant_paye, statut (en_attente/payee/retard/contentieux)
date_paiement, penalite, date_penalite
```

---

## 🔗 RELATIONS & CONTRAINTES

### **Foreign Keys**
```
Client ←→ Transactions (ON DELETE CASCADE)
Client ←→ Prets (ON DELETE CASCADE)
Client ←→ Placements (ON DELETE CASCADE)
Client ←→ Commissions (ON DELETE CASCADE)
Client ←→ Client_Documents (ON DELETE CASCADE)
Prets ←→ Echeances_Pret (ON DELETE CASCADE)
Prets ←→ Prets_Remboursements (ON DELETE CASCADE)
Prets ←→ Produits_Pret (ON DELETE SET NULL)
Actionnaires ←→ Parts_Sociales (ON DELETE CASCADE)
Actionnaires ←→ Dividendes (ON DELETE CASCADE)
Admin_Principal ← Audit_Logs
```

### **Unique Constraints**
```
numero_phone (Admin_Principal, Caissier, Client, Actionnaires)
numero_compte (Client)
reference (Transactions, Prets, Commissions, Placements)
numero_actionnaire (Actionnaires)
```

---

## 📑 ÉNUMÉRATIONS (ENUMS)

### **Statuts des Prêts**
```
soumis | en_analyse | approuve | decaisse | en_cours | 
en_retard | contentieux | solde | rejete | annule
```

### **Statuts des Placements**
```
actif | clos | suspendu
```

### **Statuts des Écheances**
```
en_attente | payee | retard | contentieux
```

### **Types de Mouvement Parts**
```
souscription | augmentation | diminution | dividende
```

### **Types de Commission**
```
retrait | placement | virement | autre
```

---

## 📱 MODULES & FONCTIONNALITÉS

### **Module Client**
- ✅ Gestion de compte
- ✅ Transactions (dépôts/retraits)
- ✅ Virements vers autres clients
- ✅ Demandes de prêt
- ✅ Placement/épargne
- ✅ Consultation reçus
- ✅ Relevé de compte

### **Module Caissier**
- ✅ Traitement des transactions
- ✅ Vérification des dépôts/retraits
- ✅ Dashboard des opérations

### **Module Admin**
- ✅ Gestion des clients
- ✅ Gestion des prêts (approbation/rejet)
- ✅ Gestion des placements
- ✅ Gestion des actionnaires
- ✅ Consultation des placements et commissions
- ✅ Rapports et statistiques
- ✅ Gestion des paramètres
- ✅ Tests système (flux de prêt)

### **Module Actionnaire**
- ✅ Souscription de parts
- ✅ Consultation de parts
- ✅ Historique de capital investi
- ✅ Reçus de souscription

---

## 🛠️ UTILISATION DU SCRIPT SQL

### **Installation**
```bash
# 1. Accéder à phpMyAdmin ou MySQL CLI
mysql -u root -p

# 2. Exécuter le script
source /xampp/htdocs/african-trust-fund/database/schema_complete.sql;

# 3. Vérifier la création
USE atf_db;
SHOW TABLES;
```

### **Résultat attendu**
```
Tables créées : 27
Utilisateurs : 1 (admin par défaut)
Produits : 4 (prêts classiques)
Capital : 1 (2024 : 10 000 parts)
Paramètres : 9 (taux, commissions, limites)
```

---

## ⚙️ CONFIGURATION INITIALE

### **Admin par défaut**
```
Nom : ATF
Prénom : SuperAdmin
Rôle : super_admin
Téléphone : +243000000000
Mot de passe : ATF@Admin2025 (à modifier)
```

### **Changer le mot de passe admin**
```php
php -r "echo password_hash('VotreNouveauMotDePasse', PASSWORD_BCRYPT, ['cost'=>12]);"
```
Puis mettre à jour la base :
```sql
UPDATE Admin_Principal 
SET mot_de_passe = '$2y$12$...' 
WHERE id_admin = 1;
```

---

## 📊 STATISTIQUES DU PROJET

| Élément | Nombre |
|---------|--------|
| **Tables totales** | 27 |
| **Colonnes (approx.)** | 250+ |
| **Foreign Keys** | 18 |
| **Indices** | 60+ |
| **Enums** | 15+ |
| **Procédures/Triggers** | 0 (logique en PHP) |

---

## 🔍 REQUÊTES UTILES

### **Récupérer le solde d'un client**
```sql
SELECT id_client, solde_cdf, solde_usd FROM Client WHERE id_client = 1;
```

### **Lister tous les prêts actifs**
```sql
SELECT * FROM Prets 
WHERE statut IN ('decaisse', 'en_cours', 'en_retard') 
ORDER BY date_decaissement DESC;
```

### **Total des placements par client**
```sql
SELECT c.nom, c.postnom, 
       COUNT(p.id_placement) as nb_placements,
       COALESCE(SUM(p.montant_actuel), 0) as total_place
FROM Client c
LEFT JOIN Placements p ON p.id_client = c.id_client AND p.statut = 'actif'
GROUP BY c.id_client
ORDER BY total_place DESC;
```

### **Commissions perçues par jour**
```sql
SELECT DATE(date_commission) as date,
       SUM(montant_commission) as total_commissions
FROM Commissions
GROUP BY DATE(date_commission)
ORDER BY date DESC;
```

### **Prêts en retard de paiement**
```sql
SELECT p.*, c.nom, c.postnom
FROM Prets p
JOIN Client c ON c.id_client = p.id_client
WHERE p.statut = 'en_retard'
ORDER BY p.date_demande ASC;
```

---

## 🚀 CONSEILS D'OPTIMISATION

1. **Indexes** : Vérifier l'utilisation des indexes sur DATE et status
2. **Partitioning** : Envisager un partitioning par date pour Transactions/Audit_Logs
3. **Archivage** : Archiver les records anciens (>2 ans)
4. **Backups** : Sauvegarde quotidienne + replication
5. **Monitoring** : Surveiller la croissance et les requêtes lentes

---

## 📝 NOTES IMPORTANTES

- ⚠️ **Devises** : CDF par défaut, USD optionnel
- ⚠️ **Soldes** : Toujours séparés (pas de conversion auto)
- ⚠️ **Mots de passe** : JAMAIS stocker en clair, utiliser bcrypt
- ⚠️ **Références** : Toujours générées comme UNIQUE
- ⚠️ **Audit** : Tous les accès sensibles enregistrés
- ⚠️ **Transactions** : Utiliser BEGIN/COMMIT en PHP pour cohérence

---

## 📞 SUPPORT & MAINTENANCE

**Fichier de schéma :** `database/schema_complete.sql`
**Fichier de configuration :** `config/database.php`
**Fichier d'audit :** `Audit_Logs table`

---

**Document généré le :** 14 Novembre 2024
**Version :** 1.0
**Statut :** ✅ Prêt pour production
