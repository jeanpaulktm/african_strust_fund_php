-- ============================================================
-- AFRICAN TRUST FUND — Base de données MySQL
-- Encodage : utf8mb4 — Moteur : InnoDB
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
    INDEX idx_client_statut (statut)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE Transactions
-- ============================================================
CREATE TABLE Transactions (
    id_transaction      BIGINT AUTO_INCREMENT PRIMARY KEY,
    id_client           INT NOT NULL,
    type_transaction    ENUM('Depot','Retrait','Virement') NOT NULL,
    montant             DECIMAL(15,2) NOT NULL,
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
    INDEX idx_trans_ref (reference)
) ENGINE=InnoDB;

-- ============================================================
-- TABLE Audit_Logs (sécurité & traçabilité)
-- ============================================================
CREATE TABLE Audit_Logs (
    id_log          BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_type       ENUM('admin','caissier','client','guest') NOT NULL,
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
-- COMPTE ADMIN PAR DÉFAUT
-- Mot de passe : ATF@Admin2025
-- ============================================================
INSERT INTO Admin_Principal (nom, postnom, prenom, role, statut, numero_phone, mot_de_passe)
VALUES (
  'ATF','Root','SuperAdmin','super_admin','actif','+243000000000',
  '$2y$12$Q8Y9xT1xVfJZK5c9G3e9NOEIVX0wv2tGzW9mYhV0Lk5lKpWqWQz1u'
);

-- ⚠️ Remplacez ce hash : générez-en un nouveau via :
-- php -r "echo password_hash('VotreMotDePasse', PASSWORD_BCRYPT, ['cost'=>12]);"