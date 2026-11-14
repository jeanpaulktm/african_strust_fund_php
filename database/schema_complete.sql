-- ============================================================
-- AFRICAN TRUST FUND — Base de données MySQL COMPLÈTE
-- Encodage : utf8mb4 — Moteur : InnoDB
-- Date : 2024
-- ============================================================

DROP DATABASE IF EXISTS atf_db;
CREATE DATABASE atf_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE atf_db;

-- ============================================================
-- TABLE Admin_Principal
-- ============================================================
CREATE TABLE Admin_Principal (
    id_admin        INT AUTO_INCREMENT PRIMARY KEY,
    nom             VARCHAR(60) NOT NULL,
    postnom         VARCHAR(60) NOT NULL,
    prenom          VARCHAR(60) NOT NULL,
    role            ENUM('super_admin','admin','superviseur') DEFAULT 'admin',
    statut          ENUM('actif','inactif','suspendu')        DEFAULT 'actif',
    numero_phone    VARCHAR(25) NOT NULL UNIQUE,
    mot_de_passe    VARCHAR(255) NOT NULL,
    last_login      DATETIME NULL,
    failed_attempts TINYINT UNSIGNED DEFAULT 0,
    locked_until    DATETIME NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_admin_nom (nom),
    INDEX idx_admin_statut (statut)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE Caissier
-- ============================================================
CREATE TABLE Caissier (
    id_caissier     INT AUTO_INCREMENT PRIMARY KEY,
    nom             VARCHAR(60) NOT NULL,
    postnom         VARCHAR(60) NOT NULL,
    post_prenom     VARCHAR(60) NOT NULL,
    numero_phone    VARCHAR(25) NOT NULL UNIQUE,
    mot_de_passe    VARCHAR(255) NOT NULL,
    statut          ENUM('actif','inactif') DEFAULT 'actif',
    created_by      INT NULL,
    last_login      DATETIME NULL,
    failed_attempts TINYINT UNSIGNED DEFAULT 0,
    locked_until    DATETIME NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES Admin_Principal(id_admin) ON DELETE SET NULL,
    INDEX idx_caissier_nom (nom),
    INDEX idx_caissier_statut (statut)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE Client
-- ============================================================
CREATE TABLE Client (
    id_client                   INT AUTO_INCREMENT PRIMARY KEY,
    nom                         VARCHAR(60)  NOT NULL,
    postnom                     VARCHAR(60)  NOT NULL,
    prenom                      VARCHAR(60)  NOT NULL,
    adresse_complete            TEXT         NOT NULL,
    numero_phone                VARCHAR(25)  NOT NULL UNIQUE,
    email                       VARCHAR(255) NULL,
    type_client                 ENUM('physique','morale') DEFAULT 'physique',
    file_carte_identite         VARCHAR(255) NULL,
    file_carte_electeur         VARCHAR(255) NULL,
    photo_profil                VARCHAR(255) NULL,
    numero_compte               VARCHAR(30)  NOT NULL UNIQUE,
    nom_mandataire              VARCHAR(60)  NULL,
    postnom_mandataire          VARCHAR(60)  NULL,
    numero_mandataire           VARCHAR(25)  NULL,
    adresse_compte_mandataire   TEXT         NULL,
    photo_profil_mandataire     VARCHAR(255) NULL,
    mot_de_passe                VARCHAR(255) NOT NULL,
    solde                       DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    solde_cdf                   DECIMAL(15,2) DEFAULT 0.00,
    solde_usd                   DECIMAL(15,2) DEFAULT 0.00,
    devise                      VARCHAR(5)   DEFAULT 'CDF',
    statut                      ENUM('actif','inactif','bloque') DEFAULT 'actif',
    failed_attempts             TINYINT UNSIGNED DEFAULT 0,
    locked_until                DATETIME NULL,
    last_login                  DATETIME NULL,
    created_at                  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at                  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_client_nom (nom),
    INDEX idx_client_postnom (postnom),
    INDEX idx_client_phone (numero_phone),
    INDEX idx_client_compte (numero_compte),
    INDEX idx_client_statut (statut),
    INDEX idx_client_type (type_client)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE Transactions
-- ============================================================
CREATE TABLE Transactions (
    id_transaction      BIGINT AUTO_INCREMENT PRIMARY KEY,
    id_client           INT NOT NULL,
    type_transaction    ENUM('Depot','Retrait','Virement') NOT NULL,
    montant             DECIMAL(15,2) NOT NULL,
    devise              VARCHAR(5) DEFAULT 'CDF',
    mode                ENUM('En ligne','Agence')          NOT NULL,
    effectue_par        ENUM('Client','Caissier','Admin')  NOT NULL,
    id_caissier         INT NULL,
    id_destinataire     INT NULL,
    solde_avant         DECIMAL(15,2) NOT NULL,
    solde_apres         DECIMAL(15,2) NOT NULL,
    reference           VARCHAR(40) NOT NULL UNIQUE,
    description         VARCHAR(255) NULL,
    ip_address          VARCHAR(45)  NULL,
    date_transaction    DATETIME DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_trans_client
        FOREIGN KEY (id_client) REFERENCES Client(id_client) ON DELETE CASCADE,
    CONSTRAINT fk_trans_caissier
        FOREIGN KEY (id_caissier) REFERENCES Caissier(id_caissier) ON DELETE SET NULL,
    CONSTRAINT fk_trans_dest
        FOREIGN KEY (id_destinataire) REFERENCES Client(id_client) ON DELETE SET NULL,

    INDEX idx_trans_client (id_client),
    INDEX idx_trans_type (type_transaction),
    INDEX idx_trans_date (date_transaction),
    INDEX idx_trans_ref (reference),
    INDEX idx_trans_devise (devise)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE Prets
-- ============================================================
CREATE TABLE Prets (
    id_pret                 BIGINT AUTO_INCREMENT PRIMARY KEY,
    id_client               INT NOT NULL,
    id_produit              INT NULL,
    montant_demande         DECIMAL(15,2) NOT NULL,
    montant_accorde         DECIMAL(15,2) NOT NULL,
    taux_applique           DECIMAL(5,2) DEFAULT 0.00,
    duree_mois              INT NOT NULL,
    devise                  VARCHAR(5) DEFAULT 'CDF',
    motif_pret              VARCHAR(255) NULL,
    statut                  ENUM('soumis','en_analyse','approuve','decaisse','en_cours','en_retard','contentieux','solde','rejete','annule') DEFAULT 'soumis',
    date_demande            DATETIME DEFAULT CURRENT_TIMESTAMP,
    date_approbation        DATETIME NULL,
    date_decaissement       DATETIME NULL,
    date_echeance_finale    DATETIME NULL,
    montant_restant_du      DECIMAL(15,2) NOT NULL,
    montant_interet_total   DECIMAL(15,2) NOT NULL,
    reference               VARCHAR(40) NOT NULL UNIQUE,
    motif_rejet             VARCHAR(255) NULL,
    traite_par              INT NULL,
    note_analyse            TEXT NULL,
    
    CONSTRAINT fk_pret_client
        FOREIGN KEY (id_client) REFERENCES Client(id_client) ON DELETE CASCADE,
    CONSTRAINT fk_pret_produit
        FOREIGN KEY (id_produit) REFERENCES Produits_Pret(id_produit) ON DELETE SET NULL,
    CONSTRAINT fk_pret_admin
        FOREIGN KEY (traite_par) REFERENCES Admin_Principal(id_admin) ON DELETE SET NULL,

    INDEX idx_pret_client (id_client),
    INDEX idx_pret_statut (statut),
    INDEX idx_pret_date (date_demande),
    INDEX idx_pret_ref (reference),
    INDEX idx_pret_devise (devise)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE Echeances_Pret (Échéancier des prêts)
-- ============================================================
CREATE TABLE Echeances_Pret (
    id_echeance         BIGINT AUTO_INCREMENT PRIMARY KEY,
    id_pret             BIGINT NOT NULL,
    numero_echeance     INT NOT NULL,
    date_echeance       DATETIME NOT NULL,
    montant_capital     DECIMAL(15,2) NOT NULL,
    montant_interet     DECIMAL(15,2) NOT NULL,
    montant_total       DECIMAL(15,2) NOT NULL,
    montant_paye        DECIMAL(15,2) DEFAULT 0.00,
    statut              ENUM('en_attente','payee','retard','contentieux') DEFAULT 'en_attente',
    date_paiement       DATETIME NULL,
    penalite            DECIMAL(15,2) DEFAULT 0.00,
    date_penalite       DATETIME NULL,

    CONSTRAINT fk_echo_pret
        FOREIGN KEY (id_pret) REFERENCES Prets(id_pret) ON DELETE CASCADE,

    INDEX idx_echo_pret (id_pret),
    INDEX idx_echo_date (date_echeance),
    INDEX idx_echo_statut (statut)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE Prets_Remboursements
-- ============================================================
CREATE TABLE Prets_Remboursements (
    id_remboursement    BIGINT AUTO_INCREMENT PRIMARY KEY,
    id_pret             BIGINT NOT NULL,
    id_client           INT NOT NULL,
    montant_paye        DECIMAL(15,2) NOT NULL,
    montant_capital     DECIMAL(15,2) NOT NULL,
    montant_interet     DECIMAL(15,2) NOT NULL,
    date_remboursement  DATETIME DEFAULT CURRENT_TIMESTAMP,
    reference           VARCHAR(40) NOT NULL UNIQUE,
    mode_paiement       VARCHAR(50) NULL,

    CONSTRAINT fk_rembourse_pret
        FOREIGN KEY (id_pret) REFERENCES Prets(id_pret) ON DELETE CASCADE,
    CONSTRAINT fk_rembourse_client
        FOREIGN KEY (id_client) REFERENCES Client(id_client) ON DELETE CASCADE,

    INDEX idx_rembourse_pret (id_pret),
    INDEX idx_rembourse_client (id_client),
    INDEX idx_rembourse_date (date_remboursement)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE Prets_Historique
-- ============================================================
CREATE TABLE Prets_Historique (
    id_histo        BIGINT AUTO_INCREMENT PRIMARY KEY,
    id_pret         BIGINT NOT NULL,
    statut_ancien   VARCHAR(50) NULL,
    statut_nouveau  VARCHAR(50) NOT NULL,
    date_action     DATETIME DEFAULT CURRENT_TIMESTAMP,
    action_par      INT NULL,
    raison          TEXT NULL,

    CONSTRAINT fk_histo_pret
        FOREIGN KEY (id_pret) REFERENCES Prets(id_pret) ON DELETE CASCADE,
    CONSTRAINT fk_histo_admin
        FOREIGN KEY (action_par) REFERENCES Admin_Principal(id_admin) ON DELETE SET NULL,

    INDEX idx_histo_pret (id_pret),
    INDEX idx_histo_date (date_action)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE Produits_Pret
-- ============================================================
CREATE TABLE Produits_Pret (
    id_produit          INT AUTO_INCREMENT PRIMARY KEY,
    nom                 VARCHAR(100) NOT NULL,
    description         TEXT NULL,
    montant_min         DECIMAL(15,2) NOT NULL DEFAULT 100000,
    montant_max         DECIMAL(15,2) NOT NULL DEFAULT 5000000,
    taux_base           DECIMAL(5,2) NOT NULL DEFAULT 12.00,
    duree_min_mois      INT DEFAULT 3,
    duree_max_mois      INT DEFAULT 60,
    type_client         ENUM('physique','morale','tous') DEFAULT 'tous',
    devise              VARCHAR(5) DEFAULT 'CDF',
    actif               TINYINT(1) DEFAULT 1,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_produit_nom (nom),
    INDEX idx_produit_actif (actif)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE Client_Documents
-- ============================================================
CREATE TABLE Client_Documents (
    id_document         INT AUTO_INCREMENT PRIMARY KEY,
    id_client           INT NOT NULL,
    type_document       VARCHAR(50) NOT NULL,
    nom_fichier         VARCHAR(255) NOT NULL,
    chemin_fichier      VARCHAR(255) NOT NULL,
    date_upload         DATETIME DEFAULT CURRENT_TIMESTAMP,
    verifie             TINYINT(1) DEFAULT 0,
    date_verification   DATETIME NULL,
    verifie_par         INT NULL,

    CONSTRAINT fk_doc_client
        FOREIGN KEY (id_client) REFERENCES Client(id_client) ON DELETE CASCADE,
    CONSTRAINT fk_doc_admin
        FOREIGN KEY (verifie_par) REFERENCES Admin_Principal(id_admin) ON DELETE SET NULL,

    INDEX idx_doc_client (id_client),
    INDEX idx_doc_type (type_document),
    INDEX idx_doc_verifie (verifie)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE Placements
-- ============================================================
CREATE TABLE Placements (
    id_placement        BIGINT AUTO_INCREMENT PRIMARY KEY,
    id_client           INT NOT NULL,
    id_produit          INT NULL,
    montant_initial     DECIMAL(15,2) NOT NULL,
    montant_actuel      DECIMAL(15,2) NOT NULL,
    devise              VARCHAR(5) DEFAULT 'CDF',
    taux_annuel         DECIMAL(5,2) NOT NULL DEFAULT 5.00,
    duree_mois          INT NOT NULL,
    type_interet        ENUM('simple','compose') DEFAULT 'simple',
    interets_cumules    DECIMAL(15,2) DEFAULT 0.00,
    montant_commission  DECIMAL(15,2) DEFAULT 0.00,
    statut              ENUM('actif','clos','suspendu') DEFAULT 'actif',
    date_ouverture      DATETIME DEFAULT CURRENT_TIMESTAMP,
    date_echeance       DATETIME NULL,
    date_cloture        DATETIME NULL,
    reference           VARCHAR(40) NULL UNIQUE,

    CONSTRAINT fk_place_client
        FOREIGN KEY (id_client) REFERENCES Client(id_client) ON DELETE CASCADE,
    CONSTRAINT fk_place_produit
        FOREIGN KEY (id_produit) REFERENCES Produits_Pret(id_produit) ON DELETE SET NULL,

    INDEX idx_place_client (id_client),
    INDEX idx_place_statut (statut),
    INDEX idx_place_date (date_ouverture),
    INDEX idx_place_devise (devise)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE Interets_Entreprise (Calculs d'intérêts)
-- ============================================================
CREATE TABLE Interets_Entreprise (
    id_interet          BIGINT AUTO_INCREMENT PRIMARY KEY,
    id_placement        BIGINT NULL,
    id_pret             BIGINT NULL,
    capital             DECIMAL(15,2) NOT NULL,
    taux                DECIMAL(5,2) NOT NULL,
    duree_mois          INT NOT NULL,
    type_calcul         ENUM('simple','compose') DEFAULT 'simple',
    montant_interet     DECIMAL(15,2) NOT NULL,
    montant_final       DECIMAL(15,2) NOT NULL,
    date_calcul         DATETIME DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_interet_placement
        FOREIGN KEY (id_placement) REFERENCES Placements(id_placement) ON DELETE SET NULL,
    CONSTRAINT fk_interet_pret
        FOREIGN KEY (id_pret) REFERENCES Prets(id_pret) ON DELETE SET NULL,

    INDEX idx_interet_placement (id_placement),
    INDEX idx_interet_pret (id_pret),
    INDEX idx_interet_date (date_calcul)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE Commissions
-- ============================================================
CREATE TABLE Commissions (
    id_commission       BIGINT AUTO_INCREMENT PRIMARY KEY,
    id_client           INT NOT NULL,
    type_commission     ENUM('retrait','placement','virement','autre') DEFAULT 'retrait',
    montant_commission  DECIMAL(15,2) NOT NULL,
    montant_brut        DECIMAL(15,2) NOT NULL,
    taux                DECIMAL(5,2) NOT NULL,
    motif               VARCHAR(255) NULL,
    date_commission     DATETIME DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_comm_client
        FOREIGN KEY (id_client) REFERENCES Client(id_client) ON DELETE CASCADE,

    INDEX idx_comm_client (id_client),
    INDEX idx_comm_type (type_commission),
    INDEX idx_comm_date (date_commission)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE Actionnaires
-- ============================================================
CREATE TABLE Actionnaires (
    id_actionnaire      INT AUTO_INCREMENT PRIMARY KEY,
    numero_actionnaire  VARCHAR(20) NOT NULL UNIQUE,
    nom                 VARCHAR(60) NOT NULL,
    postnom             VARCHAR(60) NOT NULL,
    prenom              VARCHAR(60) NOT NULL,
    adresse             TEXT NOT NULL,
    numero_phone        VARCHAR(25) NOT NULL,
    email               VARCHAR(255) NULL,
    numero_compte_atf   VARCHAR(30) NULL,
    mot_de_passe        VARCHAR(255) NOT NULL,
    nombre_parts        INT DEFAULT 0,
    capital_investi_cdf DECIMAL(15,2) DEFAULT 0.00,
    capital_investi_usd DECIMAL(15,2) DEFAULT 0.00,
    pourcentage_detent  DECIMAL(8,4) DEFAULT 0.00,
    statut              ENUM('actif','inactif','bloque') DEFAULT 'actif',
    last_login          DATETIME NULL,
    failed_attempts     TINYINT UNSIGNED DEFAULT 0,
    locked_until        DATETIME NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_actionnaire_nom (nom),
    INDEX idx_actionnaire_phone (numero_phone),
    INDEX idx_actionnaire_statut (statut)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE Parts_Sociales
-- ============================================================
CREATE TABLE Parts_Sociales (
    id_part             BIGINT AUTO_INCREMENT PRIMARY KEY,
    id_actionnaire      INT NOT NULL,
    nombre_parts        INT NOT NULL,
    prix_unitaire       DECIMAL(15,2) NOT NULL,
    montant_total       DECIMAL(15,2) NOT NULL,
    devise              VARCHAR(5) DEFAULT 'CDF',
    type_mouvement      ENUM('souscription','augmentation','diminution','dividende') DEFAULT 'souscription',
    statut              ENUM('valide','en_attente','rejete') DEFAULT 'valide',
    reference           VARCHAR(40) NOT NULL UNIQUE,
    description         VARCHAR(255) NULL,
    date_souscription   DATETIME DEFAULT CURRENT_TIMESTAMP,
    valide_par          INT NULL,
    date_validation     DATETIME NULL,

    CONSTRAINT fk_part_actionnaire
        FOREIGN KEY (id_actionnaire) REFERENCES Actionnaires(id_actionnaire) ON DELETE CASCADE,
    CONSTRAINT fk_part_admin
        FOREIGN KEY (valide_par) REFERENCES Admin_Principal(id_admin) ON DELETE SET NULL,

    INDEX idx_part_actionnaire (id_actionnaire),
    INDEX idx_part_statut (statut),
    INDEX idx_part_date (date_souscription)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE Capital_ATF
-- ============================================================
CREATE TABLE Capital_ATF (
    id_capital          INT AUTO_INCREMENT PRIMARY KEY,
    annee_fiscale       INT NOT NULL UNIQUE,
    nombre_parts_emises INT NOT NULL,
    nombre_parts_vendues INT NOT NULL DEFAULT 0,
    prix_unitaire       DECIMAL(15,2) NOT NULL,
    valeur_nominale     DECIMAL(15,2) NOT NULL,
    total_capital       DECIMAL(15,2) NOT NULL,
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ============================================================
-- TABLE Dividendes
-- ============================================================
CREATE TABLE Dividendes (
    id_dividende        BIGINT AUTO_INCREMENT PRIMARY KEY,
    id_actionnaire      INT NOT NULL,
    annee_exercice      INT NOT NULL,
    montant_dividende   DECIMAL(15,2) NOT NULL,
    devise              VARCHAR(5) DEFAULT 'CDF',
    taux_distribution   DECIMAL(5,2) NOT NULL,
    date_declaration    DATETIME DEFAULT CURRENT_TIMESTAMP,
    date_paiement       DATETIME NULL,
    statut              ENUM('declare','paye','annule') DEFAULT 'declare',
    reference           VARCHAR(40) NULL UNIQUE,

    CONSTRAINT fk_dividende_actionnaire
        FOREIGN KEY (id_actionnaire) REFERENCES Actionnaires(id_actionnaire) ON DELETE CASCADE,

    INDEX idx_dividende_actionnaire (id_actionnaire),
    INDEX idx_dividende_annee (annee_exercice),
    INDEX idx_dividende_statut (statut)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE Audit_Logs (sécurité & traçabilité)
-- ============================================================
CREATE TABLE Audit_Logs (
    id_log          BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_type       ENUM('admin','caissier','client','actionnaire','guest') NOT NULL,
    user_id         INT NULL,
    action          VARCHAR(100) NOT NULL,
    details         TEXT NULL,
    ip_address      VARCHAR(45) NULL,
    user_agent      VARCHAR(255) NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_log_user (user_type,user_id),
    INDEX idx_log_action (action),
    INDEX idx_log_date (created_at)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE IPs_Bloquees
-- ============================================================
CREATE TABLE IPs_Bloquees (
    id_ip           BIGINT AUTO_INCREMENT PRIMARY KEY,
    adresse_ip      VARCHAR(45) NOT NULL UNIQUE,
    raison          VARCHAR(255) NULL,
    date_blocage    DATETIME DEFAULT CURRENT_TIMESTAMP,
    date_deblocage  DATETIME NULL,
    bloquee_par     INT NULL,

    CONSTRAINT fk_ip_admin
        FOREIGN KEY (bloquee_par) REFERENCES Admin_Principal(id_admin) ON DELETE SET NULL,

    INDEX idx_ip_adresse (adresse_ip)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE Parametres_Systeme
-- ============================================================
CREATE TABLE Parametres_Systeme (
    id_param        INT AUTO_INCREMENT PRIMARY KEY,
    cle             VARCHAR(100) NOT NULL UNIQUE,
    valeur          LONGTEXT NOT NULL,
    description     TEXT NULL,
    type_param      ENUM('string','number','boolean','json') DEFAULT 'string',
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    INDEX idx_param_cle (cle)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE Tests_Flux (Tests unitaires et d'intégration)
-- ============================================================
CREATE TABLE Tests_Flux (
    id_test         INT AUTO_INCREMENT PRIMARY KEY,
    categorie       VARCHAR(50) NOT NULL,
    code            VARCHAR(50) NOT NULL UNIQUE,
    nom             VARCHAR(100) NOT NULL,
    description     TEXT NULL,
    etapes_json     LONGTEXT,
    resultats_json  LONGTEXT,
    dernier_statut  ENUM('succes','partiel','echec') NULL,
    derniere_execution DATETIME NULL,
    duree_ms        INT DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_test_categorie (categorie),
    INDEX idx_test_statut (dernier_statut)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE Tests_Executions
-- ============================================================
CREATE TABLE Tests_Executions (
    id_execution    BIGINT AUTO_INCREMENT PRIMARY KEY,
    execute_par     INT NOT NULL,
    execute_par_role VARCHAR(20) NOT NULL,
    nb_tests        INT NOT NULL,
    nb_succes       INT DEFAULT 0,
    nb_echecs       INT DEFAULT 0,
    nb_warnings     INT DEFAULT 0,
    duree_totale_ms INT DEFAULT 0,
    date_execution  DATETIME DEFAULT CURRENT_TIMESTAMP,

    INDEX idx_exec_date (date_execution),
    INDEX idx_exec_par (execute_par)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE Tests_Resultats_Detail
-- ============================================================
CREATE TABLE Tests_Resultats_Detail (
    id_resultat     BIGINT AUTO_INCREMENT PRIMARY KEY,
    id_test         INT NOT NULL,
    id_execution    INT NOT NULL,
    etape           VARCHAR(100) NOT NULL,
    statut          VARCHAR(20) NOT NULL,
    message         TEXT NULL,
    donnees_json    LONGTEXT,
    duree_ms        INT DEFAULT 0,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_result_test
        FOREIGN KEY (id_test) REFERENCES Tests_Flux(id_test) ON DELETE CASCADE,

    INDEX idx_result_test (id_test),
    INDEX idx_result_execution (id_execution)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE Depenses (Gestion des dépenses système)
-- ============================================================
CREATE TABLE Depenses (
    id_depense      BIGINT AUTO_INCREMENT PRIMARY KEY,
    categorie       VARCHAR(50) NOT NULL,
    description     VARCHAR(255) NOT NULL,
    montant         DECIMAL(15,2) NOT NULL,
    devise          VARCHAR(5) DEFAULT 'CDF',
    date_depense    DATETIME DEFAULT CURRENT_TIMESTAMP,
    enregistre_par  INT NULL,

    CONSTRAINT fk_depense_admin
        FOREIGN KEY (enregistre_par) REFERENCES Admin_Principal(id_admin) ON DELETE SET NULL,

    INDEX idx_depense_categorie (categorie),
    INDEX idx_depense_date (date_depense)
) ENGINE=InnoDB;

-- ============================================================
-- COMPTE ADMIN PAR DÉFAUT
-- Mot de passe : ATF@Admin2025
-- ============================================================
INSERT INTO Admin_Principal (nom, postnom, prenom, role, statut, numero_phone, mot_de_passe)
VALUES (
  'ATF','Root','SuperAdmin','super_admin','actif','+243000000000',
  '$2y$12$Q8Y9xT1xVfJZK5c9G3e9NOEIVX0wv2tGzW9mYhV0Lk5lKpWqWQz1u'
);

-- ============================================================
-- CONFIGURATION INITIALE
-- ============================================================

/* Paramètres système */
INSERT INTO Parametres_Systeme (cle, valeur, description, type_param) VALUES
('taux_commission_retrait', '1.00', 'Commission de retrait en %', 'number'),
('taux_commission_virement', '0.50', 'Commission de virement en %', 'number'),
('taux_interet_placement_cdf', '5.00', 'Taux intérêt placement CDF en %', 'number'),
('taux_interet_placement_usd', '3.00', 'Taux intérêt placement USD en %', 'number'),
('taux_interet_pret_cdf', '12.00', 'Taux intérêt prêt CDF en %', 'number'),
('taux_interet_pret_usd', '8.00', 'Taux intérêt prêt USD en %', 'number'),
('montant_min_placement', '50000', 'Montant minimum pour un placement', 'number'),
('montant_min_pret', '100000', 'Montant minimum pour un prêt', 'number'),
('taux_change_usd_cdf', '2300', 'Taux de change USD/CDF', 'number');

/* Produits de prêt par défaut */
INSERT INTO Produits_Pret (nom, description, montant_min, montant_max, taux_base, duree_min_mois, duree_max_mois, type_client, devise, actif) VALUES
('Prêt Classique CDF', 'Prêt standard en francs congolais', 100000, 5000000, 12.00, 3, 60, 'tous', 'CDF', 1),
('Prêt Classique USD', 'Prêt standard en dollars américains', 100, 50000, 8.00, 3, 60, 'tous', 'USD', 1),
('Prêt PME/TPE', 'Crédit destiné aux petites et moyennes entreprises', 500000, 10000000, 10.00, 6, 60, 'morale', 'CDF', 1),
('Prêt Personnel', 'Crédit personnel pour les individus', 50000, 3000000, 15.00, 3, 48, 'physique', 'CDF', 1);

/* Capital ATF pour 2024 */
INSERT INTO Capital_ATF (annee_fiscale, nombre_parts_emises, nombre_parts_vendues, prix_unitaire, valeur_nominale, total_capital) VALUES
(2024, 10000, 0, 100000, 100000, 1000000000);

-- ============================================================
-- FIN SCRIPT
-- Remplacez le hash du mot de passe admin :
-- php -r "echo password_hash('VotreMotDePasse', PASSWORD_BCRYPT, ['cost'=>12]);"
-- ============================================================
