# 🚀 GUIDE D'INSTALLATION & RESTAURATION DE LA BASE DE DONNÉES
## African Trust Fund - Plateforme de Gestion d'Actifs

---

## 📋 PRÉREQUIS

✅ **Logiciels requis :**
- PHP 7.4+ (avec PDO MySQL)
- MySQL 5.7+ ou MariaDB 10.3+
- XAMPP / WAMP / LAMP (ou installation manuelle)
- phpMyAdmin (optionnel mais recommandé)

✅ **Configuration serveur :**
- 512 MB RAM minimum
- 100 MB espace disque libre
- UTF-8 mb4 support

---

## 🔧 MÉTHODE 1 : RESTAURATION AVEC PHPMYADMIN

### **Étape 1 : Accéder à phpMyAdmin**
```
http://localhost/phpmyadmin/
```
Identifiants par défaut (XAMPP) :
- Utilisateur : `root`
- Mot de passe : (laisser vide)

### **Étape 2 : Créer une nouvelle base**
1. Cliquer sur **"Nouvelle base de données"**
2. Nom : `atf_db`
3. Interclassement : `utf8mb4_unicode_ci`
4. Cliquer **Créer**

### **Étape 3 : Importer le schéma SQL**
1. Sélectionner la base `atf_db`
2. Aller à l'onglet **"Importer"**
3. **Parcourir** et choisir : `database/schema_complete.sql`
4. Format : `SQL`
5. Cliquer **"Importer"**

### **✅ Résultat attendu**
```
✔ 27 tables créées
✔ 1 admin créé
✔ 4 produits de prêt créés
✔ 9 paramètres système créés
```

---

## 🔧 MÉTHODE 2 : RESTAURATION VIA LIGNE DE COMMANDE

### **Étape 1 : Ouvrir le terminal/CMD**

**Windows (Command Prompt) :**
```cmd
cd C:\xampp\mysql\bin
```

**Linux/Mac (Terminal) :**
```bash
cd /usr/local/mysql/bin
```

### **Étape 2 : Se connecter à MySQL**

**Windows :**
```cmd
mysql -u root -p
```

**Linux/Mac :**
```bash
mysql -u root -p
```

À la demande de mot de passe : Appuyer sur Entrée (par défaut : aucun)

### **Étape 3 : Importer le schéma**

Une fois connecté (prompt `mysql>`), exécuter :

```sql
source C:/xampp/htdocs/african-trust-fund/database/schema_complete.sql;
```

Ou sur Linux/Mac :
```sql
source /Applications/XAMPP/xamppfiles/htdocs/african-trust-fund/database/schema_complete.sql;
```

### **Étape 4 : Vérifier l'installation**

```sql
USE atf_db;
SHOW TABLES;
SELECT COUNT(*) as total_tables FROM information_schema.tables WHERE table_schema='atf_db';
```

### **✅ Résultat attendu**
```
total_tables : 27
```

---

## 🔄 MÉTHODE 3 : RESTAURATION DEPUIS UN FICHIER BASH/BATCH

### **Pour Windows (create_db.bat)**

Créer un fichier `create_db.bat` dans `C:\xampp\mysql\bin\` :

```batch
@echo off
echo Installation de la base de donnees ATF...
echo.

cd C:\xampp\mysql\bin

mysql -u root -p < C:\xampp\htdocs\african-trust-fund\database\schema_complete.sql

echo.
echo Installation terminee !
pause
```

Puis exécuter :
```cmd
C:\xampp\mysql\bin\create_db.bat
```

### **Pour Linux/Mac (create_db.sh)**

Créer un fichier `create_db.sh` :

```bash
#!/bin/bash

echo "Installation de la base de données ATF..."
echo

mysql -u root -p < /Applications/XAMPP/xamppfiles/htdocs/african-trust-fund/database/schema_complete.sql

echo
echo "Installation terminée !"
```

Puis exécuter :
```bash
chmod +x create_db.sh
./create_db.sh
```

---

## 🔑 CONFIGURATION INITIALE DE L'ADMIN

### **Étape 1 : Connexion admin**

Accéder à : `http://localhost/african-trust-fund/admin/`

Identifiants par défaut :
```
Téléphone : +243000000000
Mot de passe : ATF@Admin2025
```

### **Étape 2 : CHANGER LE MOT DE PASSE ⚠️**

**IMPORTANT : Générer un hash bcrypt pour le nouveau mot de passe**

Ouvrir un terminal dans le dossier du projet et exécuter :

```bash
php -r "echo password_hash('VotreNouveauMotDePasse', PASSWORD_BCRYPT, ['cost'=>12]);"
```

Exemple :
```bash
php -r "echo password_hash('Admin@ATF2024', PASSWORD_BCRYPT, ['cost'=>12]);"
```

**Résultat :** Un hash commençant par `$2y$12$...`

### **Étape 3 : Mettre à jour en base de données**

Via phpMyAdmin :
1. Aller à l'onglet **SQL**
2. Exécuter :

```sql
UPDATE Admin_Principal 
SET mot_de_passe = '$2y$12$COPIEZ_VOTRE_HASH_ICE'
WHERE id_admin = 1;
```

Ou en ligne de commande :
```sql
mysql -u root -p atf_db -e "UPDATE Admin_Principal SET mot_de_passe = '\$2y\$12\$VOTRE_HASH' WHERE id_admin = 1;"
```

---

## ✅ VÉRIFICATION POST-INSTALLATION

### **Checklist**
```
☑ Base de données 'atf_db' créée
☑ 27 tables présentes
☑ Admin_Principal : 1 enregistrement
☑ Produits_Pret : 4 produits
☑ Capital_ATF : Configuration 2024
☑ Parametres_Systeme : 9 paramètres
☑ Connexion admin fonctionne
☑ Nouveau mot de passe configuré
```

### **Test de connexion PHP**

Créer un fichier `test_db.php` :

```php
<?php
try {
    $pdo = new PDO(
        'mysql:host=127.0.0.1;dbname=atf_db;charset=utf8mb4',
        'root',
        '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo "✅ Connexion réussie à atf_db";
    
    $result = $pdo->query("SHOW TABLES")->fetchAll();
    echo "\n✅ " . count($result) . " tables trouvées\n";
    
} catch (PDOException $e) {
    echo "❌ Erreur : " . $e->getMessage();
}
?>
```

Accéder à : `http://localhost/african-trust-fund/test_db.php`

---

## 🆘 DÉPANNAGE

### **Problème : "Access denied for user 'root'@'localhost'"**

**Solution :**
```bash
# Vérifier les permissions MySQL
mysql -u root

# Ou utiliser le mot de passe si configuré
mysql -u root -p
```

### **Problème : "Unknown character set 'utf8mb4'"**

**Solution :** Mettre à jour MySQL vers version 5.7+
```sql
-- Vérifier la version
SELECT VERSION();

-- Vérifier le support utf8mb4
SHOW CHARACTER SET LIKE 'utf8mb4';
```

### **Problème : "The used command is not allowed with this MySQL version"**

**Solution :** Utiliser `USE` d'abord
```sql
USE atf_db;
-- Puis vos commandes
```

### **Problème : Les tables ne sont pas créées**

**Solution :**
1. Vérifier les erreurs d'import dans phpMyAdmin
2. Vérifier la syntaxe SQL
3. Réessayer en supprimant la base d'abord :

```sql
DROP DATABASE IF EXISTS atf_db;
-- Puis réimporter
```

### **Problème : "File not found" lors de l'import**

**Solution :** Vérifier le chemin du fichier SQL
```bash
# Lister les fichiers
ls -la /xampp/htdocs/african-trust-fund/database/

# Ou sur Windows
dir C:\xampp\htdocs\african-trust-fund\database\
```

---

## 📊 DONNÉES INITIALES

Après l'installation, la base contient :

### **1. Admin**
```sql
SELECT * FROM Admin_Principal;
-- 1 admin super_admin
```

### **2. Produits de Prêt**
```sql
SELECT * FROM Produits_Pret;
-- 4 produits : Classique CDF, Classique USD, PME, Personnel
```

### **3. Configuration Capital**
```sql
SELECT * FROM Capital_ATF;
-- 2024 : 10 000 parts à 100 000 CDF
```

### **4. Paramètres Système**
```sql
SELECT * FROM Parametres_Systeme;
-- Taux, commissions, limites, changes
```

---

## 🔐 SÉCURITÉ RECOMMANDÉE

### **Avant la production :**

1. **Changer le mot de passe admin** ✅
2. **Changer l'utilisateur MySQL** (ne pas utiliser root)
3. **Configurer les permissions** :

```sql
CREATE USER 'atf_user'@'localhost' IDENTIFIED BY 'MotDePasseFort';
GRANT ALL PRIVILEGES ON atf_db.* TO 'atf_user'@'localhost';
FLUSH PRIVILEGES;
```

4. **Mettre à jour `config/database.php`** :

```php
$host = getenv('DB_HOST') ?: '127.0.0.1';
$db   = 'atf_db';
$user = 'atf_user';  // Nouveau
$pass = 'MotDePasseFort';  // Nouveau
```

5. **Configurer les backups** :

```bash
# Sauvegarde quotidienne
mysqldump -u atf_user -p atf_db > backup_$(date +\%Y\%m\%d).sql
```

6. **Configurer SSL/HTTPS** pour l'accès admin

---

## 📈 TAILLE DE LA BASE

| Élément | Taille approximative |
|---------|--------|
| Schéma vide | 250 KB |
| Avec 1 000 clients | 5 MB |
| Avec 10 000 transactions | 20 MB |
| Avec 1 an d'audit logs | 50 MB |

**Recommandation :** Archiver les données anciennes (>2 ans)

---

## 🔄 MISE À JOUR FUTURE

Si vous avez besoin de **modifier le schéma** :

1. **Ne JAMAIS modifier le fichier original**
2. Créer un fichier migration :

```sql
-- migrations/001_add_email_column.sql
ALTER TABLE Client ADD COLUMN email VARCHAR(255) NULL;
```

3. Documenter les changements
4. Tester avant de déployer en production

---

## ✨ FICHIERS IMPORTANTS

```
african-trust-fund/
├── database/
│   ├── schema_complete.sql         ← Schéma complet (À importer)
│   └── schema.sql                  ← Ancien schéma (pour référence)
├── config/
│   └── database.php                ← Configuration de connexion
├── ANALYSE_BD_COMPLET.md           ← Documentation complète
└── README.md                       ← Guide général du projet
```

---

## 📞 SUPPORT

Si vous rencontrez un problème :

1. Vérifier la section **Dépannage** ci-dessus
2. Consulter les logs MySQL :
   ```bash
   tail -f /xampp/mysql/data/error.log
   ```
3. Vérifier que tous les prérequis sont installés

---

## 🎉 APRÈS L'INSTALLATION

1. ✅ Accéder à : `http://localhost/african-trust-fund/`
2. ✅ Connexion admin : `http://localhost/african-trust-fund/admin/`
3. ✅ Créer un client test
4. ✅ Effectuer une transaction test
5. ✅ Vérifier les logs d'audit

---

**Installation terminée ! 🚀**

La base de données ATF est maintenant **opérationnelle**.

Date de ce guide : 14 Novembre 2024
Version : 1.0
