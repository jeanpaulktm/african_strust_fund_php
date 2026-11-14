# 🗂️ DIAGRAMME RELATIONNEL - BASE DE DONNÉES ATF
## African Trust Fund - Système Complet

---

## 📊 STRUCTURE VISUELLE

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                   AFRICAN TRUST FUND - BASE DE DONNÉES                       │
│                              (atf_db - MySQL)                                │
└─────────────────────────────────────────────────────────────────────────────┘


1️⃣ GESTION DES UTILISATEURS
════════════════════════════════════════════════════════════════════════════════

    ┌──────────────────┐
    │  Admin_Principal │
    ├──────────────────┤
    │ id_admin (PK)    │
    │ nom              │
    │ postnom          │
    │ prenom           │
    │ role             │ ◄─── super_admin, admin, superviseur
    │ statut           │ ◄─── actif, inactif, suspendu
    │ numero_phone     │ (UNIQUE)
    │ mot_de_passe     │ (bcrypt)
    │ last_login       │
    │ created_at       │
    └──────────────────┘
           │
           │ créé par (created_by)
           ▼
    ┌──────────────────┐          ┌──────────────────┐
    │    Caissier      │          │     Client       │
    ├──────────────────┤          ├──────────────────┤
    │ id_caissier (PK) │          │ id_client (PK)   │
    │ nom              │          │ nom              │
    │ postnom          │          │ postnom          │
    │ post_prenom      │          │ prenom           │
    │ numero_phone     │          │ type_client      │ ◄─── physique/morale
    │ mot_de_passe     │          │ adresse_complete │
    │ statut           │          │ numero_phone     │
    │ created_at       │          │ numero_compte    │ (UNIQUE)
    └──────────────────┘          │ solde_cdf        │
                                   │ solde_usd        │
                                   │ devise           │
                                   │ statut           │
                                   │ mot_de_passe     │
                                   │ created_at       │
                                   └──────────────────┘
                                          │
                                          │
           ┌──────────────────────────────┼──────────────────────────────┐
           │                              │                              │
           ▼                              ▼                              ▼
    ┌──────────────────┐   ┌──────────────────┐   ┌──────────────────┐
    │  Transactions    │   │     Prets        │   │   Placements     │
    ├──────────────────┤   ├──────────────────┤   ├──────────────────┤
    │ id_transaction   │   │ id_pret          │   │ id_placement     │
    │ id_client (FK)   │   │ id_client (FK)   │   │ id_client (FK)   │
    │ type_transaction │   │ id_produit (FK)  │   │ id_produit (FK)  │
    │ montant          │   │ montant_demande  │   │ montant_initial  │
    │ devise           │   │ montant_accorde  │   │ montant_actuel   │
    │ mode             │   │ taux_applique    │   │ devise           │
    │ effectue_par     │   │ duree_mois       │   │ taux_annuel      │
    │ id_caissier (FK) │   │ statut           │   │ duree_mois       │
    │ id_destinataire  │   │ date_demande     │   │ type_interet     │
    │ solde_avant      │   │ date_approbation │   │ interets_cumules │
    │ solde_apres      │   │ date_decaissement│   │ montant_commission
    │ reference        │   │ montant_restant  │   │ statut           │
    │ date_transaction │   │ reference        │   │ date_ouverture   │
    └──────────────────┘   │ created_at       │   │ date_echeance    │
                           └──────────────────┘   │ reference        │
                                   │              └──────────────────┘
                                   │
                                   ▼
                        ┌──────────────────┐
                        │ Echeances_Pret   │
                        ├──────────────────┤
                        │ id_echeance      │
                        │ id_pret (FK)     │
                        │ numero_echeance  │
                        │ date_echeance    │
                        │ montant_capital  │
                        │ montant_interet  │
                        │ montant_total    │
                        │ montant_paye     │
                        │ statut           │
                        │ date_paiement    │
                        │ penalite         │
                        └──────────────────┘


2️⃣ ACTIONNARIAT & CAPITAL
════════════════════════════════════════════════════════════════════════════════

    ┌──────────────────────┐
    │   Actionnaires       │
    ├──────────────────────┤
    │ id_actionnaire (PK)  │
    │ numero_actionnaire   │ (UNIQUE)
    │ nom                  │
    │ postnom              │
    │ prenom               │
    │ adresse              │
    │ numero_phone         │
    │ email                │
    │ nombre_parts         │ ◄─── Calculé
    │ capital_investi_cdf  │
    │ capital_investi_usd  │
    │ pourcentage_detent   │ ◄─── Calculé
    │ statut               │
    │ created_at           │
    └──────────────────────┘
           │
           ├─ (1:N) ──────────────┐
           │                      ▼
           │            ┌──────────────────┐
           │            │  Parts_Sociales  │
           │            ├──────────────────┤
           │            │ id_part          │
           │            │ id_actionnaire   │
           │            │ nombre_parts     │
           │            │ prix_unitaire    │
           │            │ montant_total    │
           │            │ devise           │
           │            │ type_mouvement   │ ◄─── souscription, dividende
           │            │ statut           │
           │            │ reference        │
           │            │ date_souscription│
           │            └──────────────────┘
           │
           └─ (1:N) ──────────────┐
                                  ▼
                        ┌──────────────────┐
                        │   Dividendes     │
                        ├──────────────────┤
                        │ id_dividende     │
                        │ id_actionnaire   │
                        │ annee_exercice   │
                        │ montant_dividende│
                        │ devise           │
                        │ taux_distribution│
                        │ date_declaration │
                        │ date_paiement    │
                        │ statut           │
                        └──────────────────┘

    ┌──────────────────────┐
    │   Capital_ATF        │
    ├──────────────────────┤
    │ id_capital (PK)      │
    │ annee_fiscale        │ (UNIQUE)
    │ nombre_parts_emises  │
    │ nombre_parts_vendues │
    │ prix_unitaire        │
    │ total_capital        │
    └──────────────────────┘


3️⃣ GESTION DES PRÊTS
════════════════════════════════════════════════════════════════════════════════

    ┌──────────────────────┐
    │  Produits_Pret       │
    ├──────────────────────┤
    │ id_produit (PK)      │
    │ nom                  │
    │ description          │
    │ montant_min          │
    │ montant_max          │
    │ taux_base            │
    │ duree_min_mois       │
    │ duree_max_mois       │
    │ type_client          │ ◄─── physique/morale/tous
    │ devise               │
    │ actif                │
    └──────────────────────┘
             │
             │ (1:N)
             ▼
       ┌──────────────────────┐
       │      Prets           │◄─── (voir ci-dessus)
       └──────────────────────┘
             │
             ├─────────────────────────────────┐
             │                                 │
             ▼                                 ▼
    ┌──────────────────────┐       ┌──────────────────────┐
    │Prets_Remboursements  │       │ Prets_Historique     │
    ├──────────────────────┤       ├──────────────────────┤
    │ id_remboursement (PK)│       │ id_histo             │
    │ id_pret (FK)         │       │ id_pret (FK)         │
    │ id_client (FK)       │       │ statut_ancien        │
    │ montant_paye         │       │ statut_nouveau       │
    │ montant_capital      │       │ date_action          │
    │ montant_interet      │       │ action_par (FK)      │
    │ date_remboursement   │       │ raison               │
    │ reference            │       └──────────────────────┘
    └──────────────────────┘


4️⃣ GESTION DES DOCUMENTS & VÉRIFICATIONS
════════════════════════════════════════════════════════════════════════════════

    ┌──────────────────────┐
    │ Client_Documents     │
    ├──────────────────────┤
    │ id_document (PK)     │
    │ id_client (FK)       │
    │ type_document        │ ◄─── carte_id, électeur, RIB...
    │ nom_fichier          │
    │ chemin_fichier       │
    │ date_upload          │
    │ verifie              │ (BOOLEAN)
    │ date_verification    │
    │ verifie_par (FK)     │ → Admin_Principal
    └──────────────────────┘


5️⃣ FRAIS & COMMISSIONS
════════════════════════════════════════════════════════════════════════════════

    ┌──────────────────────┐
    │   Commissions        │
    ├──────────────────────┤
    │ id_commission (PK)   │
    │ id_client (FK)       │
    │ type_commission      │ ◄─── retrait/placement/virement/autre
    │ montant_commission   │
    │ montant_brut         │
    │ taux                 │
    │ motif                │
    │ date_commission      │
    └──────────────────────┘

    ┌──────────────────────┐
    │ Interets_Entreprise  │
    ├──────────────────────┤
    │ id_interet (PK)      │
    │ id_placement (FK)    │
    │ id_pret (FK)         │
    │ capital              │
    │ taux                 │
    │ duree_mois           │
    │ type_calcul          │ ◄─── simple/compose
    │ montant_interet      │
    │ montant_final        │
    │ date_calcul          │
    └──────────────────────┘


6️⃣ SÉCURITÉ & AUDIT
════════════════════════════════════════════════════════════════════════════════

    ┌──────────────────────┐
    │   Audit_Logs         │
    ├──────────────────────┤
    │ id_log (PK)          │
    │ user_type            │ ◄─── admin/caissier/client/actionnaire
    │ user_id              │
    │ action               │
    │ details              │
    │ ip_address           │
    │ user_agent           │
    │ created_at           │
    └──────────────────────┘

    ┌──────────────────────┐
    │   IPs_Bloquees       │
    ├──────────────────────┤
    │ id_ip (PK)           │
    │ adresse_ip           │ (UNIQUE)
    │ raison               │
    │ date_blocage         │
    │ date_deblocage       │
    │ bloquee_par (FK)     │ → Admin_Principal
    └──────────────────────┘

    ┌──────────────────────┐
    │Parametres_Systeme    │
    ├──────────────────────┤
    │ id_param (PK)        │
    │ cle                  │ (UNIQUE)
    │ valeur               │
    │ description          │
    │ type_param           │ ◄─── string/number/boolean/json
    └──────────────────────┘


7️⃣ TESTS & QUALITÉ
════════════════════════════════════════════════════════════════════════════════

    ┌──────────────────────┐
    │   Tests_Flux         │
    ├──────────────────────┤
    │ id_test (PK)         │
    │ categorie            │
    │ code                 │ (UNIQUE)
    │ nom                  │
    │ description          │
    │ etapes_json          │
    │ resultats_json       │
    │ dernier_statut       │ ◄─── succes/partiel/echec
    │ derniere_execution   │
    │ duree_ms             │
    └──────────────────────┘
             │
             ├─────────────────────┐
             │                     │
             ▼                     ▼
    ┌──────────────────────┐   ┌──────────────────────┐
    │Tests_Executions      │   │Tests_Resultats_Detail│
    ├──────────────────────┤   ├──────────────────────┤
    │ id_execution (PK)    │   │ id_resultat          │
    │ execute_par          │   │ id_test (FK)         │
    │ execute_par_role     │   │ id_execution         │
    │ nb_tests             │   │ etape                │
    │ nb_succes            │   │ statut               │
    │ nb_echecs            │   │ message              │
    │ nb_warnings          │   │ donnees_json         │
    │ duree_totale_ms      │   │ duree_ms             │
    │ date_execution       │   └──────────────────────┘
    └──────────────────────┘


8️⃣ DIVERS
════════════════════════════════════════════════════════════════════════════════

    ┌──────────────────────┐
    │    Depenses          │
    ├──────────────────────┤
    │ id_depense (PK)      │
    │ categorie            │
    │ description          │
    │ montant              │
    │ devise               │
    │ date_depense         │
    │ enregistre_par (FK)  │ → Admin_Principal
    └──────────────────────┘
```

---

## 🔗 RELATIONS PRINCIPALES

### **Cardinalité**

```
Admin_Principal (1) ──n─→ (n) Caissier
Admin_Principal (1) ──n─→ (n) Client_Documents
Admin_Principal (1) ──n─→ (n) Prets_Historique
Admin_Principal (1) ──n─→ (n) IPs_Bloquees
Admin_Principal (1) ──n─→ (n) Depenses
Admin_Principal (1) ──n─→ (n) Parts_Sociales (valide_par)
Admin_Principal (1) ──n─→ (n) Audit_Logs

Caissier (1) ──n─→ (n) Transactions

Client (1) ──n─→ (n) Transactions (id_client)
Client (1) ──n─→ (n) Transactions (id_destinataire)
Client (1) ──n─→ (n) Prets
Client (1) ──n─→ (n) Placements
Client (1) ──n─→ (n) Commissions
Client (1) ──n─→ (n) Client_Documents

Produits_Pret (1) ──n─→ (n) Prets
Produits_Pret (1) ──n─→ (n) Placements

Prets (1) ──n─→ (n) Echeances_Pret
Prets (1) ──n─→ (n) Prets_Remboursements
Prets (1) ──n─→ (n) Prets_Historique
Prets (1) ──n─→ (n) Interets_Entreprise

Placements (1) ──n─→ (n) Interets_Entreprise

Actionnaires (1) ──n─→ (n) Parts_Sociales
Actionnaires (1) ──n─→ (n) Dividendes

Tests_Flux (1) ──n─→ (n) Tests_Executions
Tests_Flux (1) ──n─→ (n) Tests_Resultats_Detail
```

---

## 📊 FLUXES DE DONNÉES

### **FLUX 1 : DÉPÔT CLIENT**
```
Client login
  → Page depot.php
    → POST montant + devise
      → Update Client (solde_cdf ou solde_usd)
        → INSERT Transactions
          → INSERT Audit_Logs
            → Reçu généré (recu.php)
```

### **FLUX 2 : DEMANDE DE PRÊT**
```
Client login
  → Page demande_pret.php
    → POST (montant, produit, duree)
      → INSERT Prets (statut='soumis')
        → Admin review (prets.php)
          → Approbation : UPDATE Prets (statut='approuve')
            → Admin décaissement (prets.php)
              → UPDATE Prets (statut='decaisse')
                → Générer Echeances_Pret
                  → Client rembourse (rembourser_pret.php)
                    → INSERT Prets_Remboursements
                      → UPDATE Echeances_Pret
                        → Prêt soldé (statut='solde')
```

### **FLUX 3 : PLACEMENT**
```
Client login
  → Page epargne.php
    → POST (montant, duree, taux)
      → INSERT Placements (statut='actif')
        → Calcul intérêts mensuel (cron ou manuel)
          → Mettre à jour interets_cumules
            → Prélever commission (1%)
              → INSERT Commissions
                → À l'échéance : clôturer Placements
```

### **FLUX 4 : ACTIONNARIAT**
```
Actionnaire login
  → Page souscrire.php
    → POST (nb_parts)
      → INSERT Parts_Sociales (statut='en_attente')
        → Admin validation (actionnaires.php)
          → UPDATE Parts_Sociales (statut='valide')
            → UPDATE Capital_ATF (nombre_parts_vendues++)
              → INSERT Dividendes (à fin d'exercice)
```

---

## 🔐 CONTRAINTES D'INTÉGRITÉ

```sql
FOREIGN KEY CONSTRAINTS :
  Client_Documents.id_client → Client.id_client ON DELETE CASCADE
  Transactions.id_client → Client.id_client ON DELETE CASCADE
  Transactions.id_caissier → Caissier.id_caissier ON DELETE SET NULL
  Prets.id_client → Client.id_client ON DELETE CASCADE
  Prets.id_produit → Produits_Pret.id_produit ON DELETE SET NULL
  Placements.id_client → Client.id_client ON DELETE CASCADE
  Commissions.id_client → Client.id_client ON DELETE CASCADE
  Echeances_Pret.id_pret → Prets.id_pret ON DELETE CASCADE
  Prets_Remboursements.id_pret → Prets.id_pret ON DELETE CASCADE
  Prets_Historique.id_pret → Prets.id_pret ON DELETE CASCADE
  Parts_Sociales.id_actionnaire → Actionnaires.id_actionnaire ON DELETE CASCADE
  Dividendes.id_actionnaire → Actionnaires.id_actionnaire ON DELETE CASCADE
  Interets_Entreprise.id_placement → Placements.id_placement ON DELETE SET NULL
  Interets_Entreprise.id_pret → Prets.id_pret ON DELETE SET NULL

UNIQUE CONSTRAINTS :
  Admin_Principal.numero_phone
  Caissier.numero_phone
  Client.numero_phone
  Client.numero_compte
  Transactions.reference
  Prets.reference
  Placements.reference
  Actionnaires.numero_actionnaire
  Parts_Sociales.reference
  Dividendes.reference
  Commissions. (ID seul)
  Parametres_Systeme.cle
  Tests_Flux.code

PRIMARY KEYS :
  Tous les id_* (INT AUTO_INCREMENT ou BIGINT AUTO_INCREMENT)
```

---

## 📈 INDICES POUR PERFORMANCE

```sql
INDEX sur :
  Admin_Principal : (nom, statut)
  Caissier : (nom, statut)
  Client : (nom, postnom, numero_phone, numero_compte, statut, type_client)
  Transactions : (id_client, type_transaction, date_transaction, reference, devise)
  Prets : (id_client, statut, date_demande, reference, devise)
  Placements : (id_client, statut, date_ouverture, devise)
  Echeances_Pret : (id_pret, date_echeance, statut)
  Commissions : (id_client, type_commission, date_commission)
  Actionnaires : (nom, numero_phone, statut)
  Parts_Sociales : (id_actionnaire, statut, date_souscription)
  Dividendes : (id_actionnaire, annee_exercice, statut)
  Audit_Logs : (user_type, user_id, action, created_at)
  Client_Documents : (id_client, type_document, verifie)
  IPs_Bloquees : (adresse_ip)
  Parametres_Systeme : (cle)
  Tests_Flux : (categorie, dernier_statut)
```

---

## 📐 DIMENSIONS TYPIQUES

```
Nombre total d'enregistrements estimés (1 an) :

Table                    | Volume
-------------------------|----------
Admin_Principal          | 10
Caissier                 | 20
Client                   | 5 000
Transactions             | 150 000 (30/jour/client)
Prets                    | 10 000
Echeances_Pret           | 300 000
Placements               | 2 000
Commissions              | 100 000
Audit_Logs               | 1 000 000
Actionnaires             | 500
Parts_Sociales           | 5 000
Dividendes               | 1 000

TOTAL ESTIMATION :
- Schéma vide : 250 KB
- Avec données : 500 MB à 1 GB
- Avec 3 ans d'archivage : 2-3 GB
```

---

## 🎯 POINTS CLÉS À RETENIR

1. **27 tables** avec relations multiples
2. **Cascade delete** sur les données critiques
3. **Audit trail complet** pour chaque action
4. **Devises séparées** (CDF et USD)
5. **États complexes** pour les prêts (9 statuts)
6. **Calculs d'intérêts** en base de données
7. **Commissions automatiques** sur retraits/placements
8. **Validation multi-niveaux** pour l'actionnariat

---

**Diagramme généré le :** 14 Novembre 2024
**Version :** 1.0 Final
