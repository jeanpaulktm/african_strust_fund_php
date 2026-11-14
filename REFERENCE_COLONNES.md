# 📋 RÉFÉRENCE RAPIDE - STRUCTURE COMPLÈTE DES TABLES
## African Trust Fund - Toutes les colonnes et types

---

## GESTION DES UTILISATEURS

### 1. Admin_Principal
```sql
id_admin              INT PRIMARY KEY AUTO_INCREMENT
nom                   VARCHAR(60) NOT NULL
postnom               VARCHAR(60) NOT NULL
prenom                VARCHAR(60) NOT NULL
role                  ENUM('super_admin','admin','superviseur') DEFAULT 'admin'
statut                ENUM('actif','inactif','suspendu') DEFAULT 'actif'
numero_phone          VARCHAR(25) NOT NULL UNIQUE
mot_de_passe          VARCHAR(255) NOT NULL -- bcrypt
last_login            DATETIME NULL
failed_attempts       TINYINT UNSIGNED DEFAULT 0
locked_until          DATETIME NULL
created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP
updated_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE
```

### 2. Caissier
```sql
id_caissier           INT PRIMARY KEY AUTO_INCREMENT
nom                   VARCHAR(60) NOT NULL
postnom               VARCHAR(60) NOT NULL
post_prenom           VARCHAR(60) NOT NULL
numero_phone          VARCHAR(25) NOT NULL UNIQUE
mot_de_passe          VARCHAR(255) NOT NULL -- bcrypt
statut                ENUM('actif','inactif') DEFAULT 'actif'
created_by            INT NULL FK → Admin_Principal
last_login            DATETIME NULL
failed_attempts       TINYINT UNSIGNED DEFAULT 0
locked_until          DATETIME NULL
created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP
```

### 3. Client
```sql
id_client             INT PRIMARY KEY AUTO_INCREMENT
nom                   VARCHAR(60) NOT NULL
postnom               VARCHAR(60) NOT NULL
prenom                VARCHAR(60) NOT NULL
adresse_complete      TEXT NOT NULL
numero_phone          VARCHAR(25) NOT NULL UNIQUE
email                 VARCHAR(255) NULL
type_client           ENUM('physique','morale') DEFAULT 'physique'
file_carte_identite   VARCHAR(255) NULL
file_carte_electeur   VARCHAR(255) NULL
photo_profil          VARCHAR(255) NULL
numero_compte         VARCHAR(30) NOT NULL UNIQUE
nom_mandataire        VARCHAR(60) NULL
postnom_mandataire    VARCHAR(60) NULL
numero_mandataire     VARCHAR(25) NULL
adresse_compte_mandataire TEXT NULL
photo_profil_mandataire VARCHAR(255) NULL
mot_de_passe          VARCHAR(255) NOT NULL -- bcrypt
solde                 DECIMAL(15,2) NOT NULL DEFAULT 0.00 -- Legacy
solde_cdf             DECIMAL(15,2) DEFAULT 0.00
solde_usd             DECIMAL(15,2) DEFAULT 0.00
devise                VARCHAR(5) DEFAULT 'CDF'
statut                ENUM('actif','inactif','bloque') DEFAULT 'actif'
failed_attempts       TINYINT UNSIGNED DEFAULT 0
locked_until          DATETIME NULL
last_login            DATETIME NULL
created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP
updated_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE
```

### 4. Actionnaires
```sql
id_actionnaire        INT PRIMARY KEY AUTO_INCREMENT
numero_actionnaire    VARCHAR(20) NOT NULL UNIQUE
nom                   VARCHAR(60) NOT NULL
postnom               VARCHAR(60) NOT NULL
prenom                VARCHAR(60) NOT NULL
adresse               TEXT NOT NULL
numero_phone          VARCHAR(25) NOT NULL
email                 VARCHAR(255) NULL
numero_compte_atf     VARCHAR(30) NULL
mot_de_passe          VARCHAR(255) NOT NULL -- bcrypt
nombre_parts          INT DEFAULT 0
capital_investi_cdf   DECIMAL(15,2) DEFAULT 0.00
capital_investi_usd   DECIMAL(15,2) DEFAULT 0.00
pourcentage_detent    DECIMAL(8,4) DEFAULT 0.00 -- Calculé
statut                ENUM('actif','inactif','bloque') DEFAULT 'actif'
last_login            DATETIME NULL
failed_attempts       TINYINT UNSIGNED DEFAULT 0
locked_until          DATETIME NULL
created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP
updated_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE
```

---

## TRANSACTIONS FINANCIÈRES

### 5. Transactions
```sql
id_transaction        BIGINT PRIMARY KEY AUTO_INCREMENT
id_client             INT NOT NULL FK → Client
type_transaction      ENUM('Depot','Retrait','Virement') NOT NULL
montant               DECIMAL(15,2) NOT NULL
devise                VARCHAR(5) DEFAULT 'CDF'
mode                  ENUM('En ligne','Agence') NOT NULL
effectue_par          ENUM('Client','Caissier','Admin') NOT NULL
id_caissier           INT NULL FK → Caissier
id_destinataire       INT NULL FK → Client
solde_avant           DECIMAL(15,2) NOT NULL
solde_apres           DECIMAL(15,2) NOT NULL
reference             VARCHAR(40) NOT NULL UNIQUE
description           VARCHAR(255) NULL
ip_address            VARCHAR(45) NULL
date_transaction      DATETIME DEFAULT CURRENT_TIMESTAMP
```

### 6. Prets
```sql
id_pret               BIGINT PRIMARY KEY AUTO_INCREMENT
id_client             INT NOT NULL FK → Client
id_produit            INT NULL FK → Produits_Pret
montant_demande       DECIMAL(15,2) NOT NULL
montant_accorde       DECIMAL(15,2) NOT NULL
taux_applique         DECIMAL(5,2) DEFAULT 0.00
duree_mois            INT NOT NULL
devise                VARCHAR(5) DEFAULT 'CDF'
motif_pret            VARCHAR(255) NULL
statut                ENUM('soumis','en_analyse','approuve','decaisse','en_cours',
                           'en_retard','contentieux','solde','rejete','annule') 
                      DEFAULT 'soumis'
date_demande          DATETIME DEFAULT CURRENT_TIMESTAMP
date_approbation      DATETIME NULL
date_decaissement     DATETIME NULL
date_echeance_finale  DATETIME NULL
montant_restant_du    DECIMAL(15,2) NOT NULL
montant_interet_total DECIMAL(15,2) NOT NULL
reference             VARCHAR(40) NOT NULL UNIQUE
motif_rejet           VARCHAR(255) NULL
traite_par            INT NULL FK → Admin_Principal
note_analyse          TEXT NULL
```

### 7. Echeances_Pret
```sql
id_echeance           BIGINT PRIMARY KEY AUTO_INCREMENT
id_pret               BIGINT NOT NULL FK → Prets
numero_echeance       INT NOT NULL
date_echeance         DATETIME NOT NULL
montant_capital       DECIMAL(15,2) NOT NULL
montant_interet       DECIMAL(15,2) NOT NULL
montant_total         DECIMAL(15,2) NOT NULL
montant_paye          DECIMAL(15,2) DEFAULT 0.00
statut                ENUM('en_attente','payee','retard','contentieux') 
                      DEFAULT 'en_attente'
date_paiement         DATETIME NULL
penalite              DECIMAL(15,2) DEFAULT 0.00
date_penalite         DATETIME NULL
```

### 8. Prets_Remboursements
```sql
id_remboursement      BIGINT PRIMARY KEY AUTO_INCREMENT
id_pret               BIGINT NOT NULL FK → Prets
id_client             INT NOT NULL FK → Client
montant_paye          DECIMAL(15,2) NOT NULL
montant_capital       DECIMAL(15,2) NOT NULL
montant_interet       DECIMAL(15,2) NOT NULL
date_remboursement    DATETIME DEFAULT CURRENT_TIMESTAMP
reference             VARCHAR(40) NOT NULL UNIQUE
mode_paiement         VARCHAR(50) NULL
```

### 9. Prets_Historique
```sql
id_histo              BIGINT PRIMARY KEY AUTO_INCREMENT
id_pret               BIGINT NOT NULL FK → Prets
statut_ancien         VARCHAR(50) NULL
statut_nouveau        VARCHAR(50) NOT NULL
date_action           DATETIME DEFAULT CURRENT_TIMESTAMP
action_par            INT NULL FK → Admin_Principal
raison                TEXT NULL
```

### 10. Produits_Pret
```sql
id_produit            INT PRIMARY KEY AUTO_INCREMENT
nom                   VARCHAR(100) NOT NULL
description           TEXT NULL
montant_min           DECIMAL(15,2) NOT NULL DEFAULT 100000
montant_max           DECIMAL(15,2) NOT NULL DEFAULT 5000000
taux_base             DECIMAL(5,2) NOT NULL DEFAULT 12.00
duree_min_mois        INT DEFAULT 3
duree_max_mois        INT DEFAULT 60
type_client           ENUM('physique','morale','tous') DEFAULT 'tous'
devise                VARCHAR(5) DEFAULT 'CDF'
actif                 TINYINT(1) DEFAULT 1
created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP
updated_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE
```

### 11. Placements
```sql
id_placement          BIGINT PRIMARY KEY AUTO_INCREMENT
id_client             INT NOT NULL FK → Client
id_produit            INT NULL FK → Produits_Pret
montant_initial       DECIMAL(15,2) NOT NULL
montant_actuel        DECIMAL(15,2) NOT NULL
devise                VARCHAR(5) DEFAULT 'CDF'
taux_annuel           DECIMAL(5,2) NOT NULL DEFAULT 5.00
duree_mois            INT NOT NULL
type_interet          ENUM('simple','compose') DEFAULT 'simple'
interets_cumules      DECIMAL(15,2) DEFAULT 0.00
montant_commission    DECIMAL(15,2) DEFAULT 0.00
statut                ENUM('actif','clos','suspendu') DEFAULT 'actif'
date_ouverture        DATETIME DEFAULT CURRENT_TIMESTAMP
date_echeance         DATETIME NULL
date_cloture          DATETIME NULL
reference             VARCHAR(40) NULL UNIQUE
```

### 12. Commissions
```sql
id_commission         BIGINT PRIMARY KEY AUTO_INCREMENT
id_client             INT NOT NULL FK → Client
type_commission       ENUM('retrait','placement','virement','autre') 
                      DEFAULT 'retrait'
montant_commission    DECIMAL(15,2) NOT NULL
montant_brut          DECIMAL(15,2) NOT NULL
taux                  DECIMAL(5,2) NOT NULL
motif                 VARCHAR(255) NULL
date_commission       DATETIME DEFAULT CURRENT_TIMESTAMP
```

### 13. Interets_Entreprise
```sql
id_interet            BIGINT PRIMARY KEY AUTO_INCREMENT
id_placement          BIGINT NULL FK → Placements
id_pret               BIGINT NULL FK → Prets
capital               DECIMAL(15,2) NOT NULL
taux                  DECIMAL(5,2) NOT NULL
duree_mois            INT NOT NULL
type_calcul           ENUM('simple','compose') DEFAULT 'simple'
montant_interet       DECIMAL(15,2) NOT NULL
montant_final         DECIMAL(15,2) NOT NULL
date_calcul           DATETIME DEFAULT CURRENT_TIMESTAMP
```

### 14. Capital_ATF
```sql
id_capital            INT PRIMARY KEY AUTO_INCREMENT
annee_fiscale         INT NOT NULL UNIQUE
nombre_parts_emises   INT NOT NULL
nombre_parts_vendues  INT NOT NULL DEFAULT 0
prix_unitaire         DECIMAL(15,2) NOT NULL
valeur_nominale       DECIMAL(15,2) NOT NULL
total_capital         DECIMAL(15,2) NOT NULL
created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP
updated_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE
```

### 15. Parts_Sociales
```sql
id_part               BIGINT PRIMARY KEY AUTO_INCREMENT
id_actionnaire        INT NOT NULL FK → Actionnaires
nombre_parts          INT NOT NULL
prix_unitaire         DECIMAL(15,2) NOT NULL
montant_total         DECIMAL(15,2) NOT NULL
devise                VARCHAR(5) DEFAULT 'CDF'
type_mouvement        ENUM('souscription','augmentation','diminution','dividende') 
                      DEFAULT 'souscription'
statut                ENUM('valide','en_attente','rejete') DEFAULT 'valide'
reference             VARCHAR(40) NOT NULL UNIQUE
description           VARCHAR(255) NULL
date_souscription     DATETIME DEFAULT CURRENT_TIMESTAMP
valide_par            INT NULL FK → Admin_Principal
date_validation       DATETIME NULL
```

### 16. Dividendes
```sql
id_dividende          BIGINT PRIMARY KEY AUTO_INCREMENT
id_actionnaire        INT NOT NULL FK → Actionnaires
annee_exercice        INT NOT NULL
montant_dividende     DECIMAL(15,2) NOT NULL
devise                VARCHAR(5) DEFAULT 'CDF'
taux_distribution     DECIMAL(5,2) NOT NULL
date_declaration      DATETIME DEFAULT CURRENT_TIMESTAMP
date_paiement         DATETIME NULL
statut                ENUM('declare','paye','annule') DEFAULT 'declare'
reference             VARCHAR(40) NULL UNIQUE
```

### 17. Client_Documents
```sql
id_document           INT PRIMARY KEY AUTO_INCREMENT
id_client             INT NOT NULL FK → Client
type_document         VARCHAR(50) NOT NULL
nom_fichier           VARCHAR(255) NOT NULL
chemin_fichier        VARCHAR(255) NOT NULL
date_upload           DATETIME DEFAULT CURRENT_TIMESTAMP
verifie               TINYINT(1) DEFAULT 0
date_verification     DATETIME NULL
verifie_par           INT NULL FK → Admin_Principal
```

---

## SÉCURITÉ & AUDIT

### 18. Audit_Logs
```sql
id_log                BIGINT PRIMARY KEY AUTO_INCREMENT
user_type             ENUM('admin','caissier','client','actionnaire','guest') 
                      NOT NULL
user_id               INT NULL
action                VARCHAR(100) NOT NULL
details               TEXT NULL
ip_address            VARCHAR(45) NULL
user_agent            VARCHAR(255) NULL
created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP
```

### 19. IPs_Bloquees
```sql
id_ip                 BIGINT PRIMARY KEY AUTO_INCREMENT
adresse_ip            VARCHAR(45) NOT NULL UNIQUE
raison                VARCHAR(255) NULL
date_blocage          DATETIME DEFAULT CURRENT_TIMESTAMP
date_deblocage        DATETIME NULL
bloquee_par           INT NULL FK → Admin_Principal
```

---

## CONFIGURATION

### 20. Parametres_Systeme
```sql
id_param              INT PRIMARY KEY AUTO_INCREMENT
cle                   VARCHAR(100) NOT NULL UNIQUE
valeur                LONGTEXT NOT NULL
description           TEXT NULL
type_param            ENUM('string','number','boolean','json') DEFAULT 'string'
updated_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE
```

### 21. Depenses
```sql
id_depense            BIGINT PRIMARY KEY AUTO_INCREMENT
categorie             VARCHAR(50) NOT NULL
description           VARCHAR(255) NOT NULL
montant               DECIMAL(15,2) NOT NULL
devise                VARCHAR(5) DEFAULT 'CDF'
date_depense          DATETIME DEFAULT CURRENT_TIMESTAMP
enregistre_par        INT NULL FK → Admin_Principal
```

---

## TESTS & QA

### 22. Tests_Flux
```sql
id_test               INT PRIMARY KEY AUTO_INCREMENT
categorie             VARCHAR(50) NOT NULL
code                  VARCHAR(50) NOT NULL UNIQUE
nom                   VARCHAR(100) NOT NULL
description           TEXT NULL
etapes_json           LONGTEXT
resultats_json        LONGTEXT
dernier_statut        ENUM('succes','partiel','echec') NULL
derniere_execution    DATETIME NULL
duree_ms              INT DEFAULT 0
created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP
```

### 23. Tests_Executions
```sql
id_execution          BIGINT PRIMARY KEY AUTO_INCREMENT
execute_par           INT NOT NULL
execute_par_role      VARCHAR(20) NOT NULL
nb_tests              INT NOT NULL
nb_succes             INT DEFAULT 0
nb_echecs             INT DEFAULT 0
nb_warnings           INT DEFAULT 0
duree_totale_ms       INT DEFAULT 0
date_execution        DATETIME DEFAULT CURRENT_TIMESTAMP
```

### 24. Tests_Resultats_Detail
```sql
id_resultat           BIGINT PRIMARY KEY AUTO_INCREMENT
id_test               INT NOT NULL FK → Tests_Flux
id_execution          INT NOT NULL
etape                 VARCHAR(100) NOT NULL
statut                VARCHAR(20) NOT NULL
message               TEXT NULL
donnees_json          LONGTEXT
duree_ms              INT DEFAULT 0
created_at            TIMESTAMP DEFAULT CURRENT_TIMESTAMP
```

---

## RÉSUMÉ DES TYPES DE DONNÉES

```
INT              - Identifiants (id_*)
BIGINT           - Identifiants pour tables volumineuses (id_transaction, id_pret)
DECIMAL(15,2)    - Montants (toujours précis, jamais FLOAT)
VARCHAR(n)       - Chaînes avec limite (nom, email, numéro)
TEXT             - Texte long (adresse, description, details)
LONGTEXT         - Très long (JSON, documents)
DATETIME         - Date et heure précise
TIMESTAMP        - Date et heure système
ENUM             - Énumération (statut, type)
TINYINT(1)       - Booléen (0/1)
TINYINT UNSIGNED - Compteur (attempts)
```

---

## CONVENTIONS UTILISÉES

```
Clés primaires      : id_* (AUTO_INCREMENT)
Clés étrangères     : *_id ou id_* (FK → TableName)
Contraintes unique  : Numéro de téléphone, compte, reference
Dates de création   : created_at TIMESTAMP DEFAULT
Dates de mise à jour: updated_at TIMESTAMP DEFAULT ... ON UPDATE
Mots de passe       : Toujours VARCHAR(255) - bcrypt
Montants            : Toujours DECIMAL(15,2) - jamais FLOAT
Enums               : Valeurs pré-définies
Indices             : Sur FK, statut, date, recherche
```

---

**Référence générale le :** 14 Novembre 2024  
**Version :** 1.0 Final
