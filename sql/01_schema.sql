-- =====================================================================
--  Système de gestion d'un entrepôt logistique — BD70
--  Script 1/2 : modèle relationnel (46 tables, clés, contraintes, index)
--  SGBD cible : MariaDB 10.4+ (CREATE SEQUENCE n'existe pas sous MySQL)
-- =====================================================================
--  Conventions :
--   - chaque table possède une clé primaire artificielle `id` (sauf les
--     tables de jointure, dont la clé est composée des clés étrangères) ;
--   - les identifiants « métier » (SIRET, immatriculation, numéro de RDV…)
--     sont déclarés UNIQUE : ce sont eux qui sont affichés à l'utilisateur ;
--   - les numéros métier (RDV-000001, REC-000001…) sont générés par des
--     séquences dans les déclencheurs (voir 02_automatisation.sql) ;
--     leur valeur par défaut '' signale au déclencheur qu'il doit numéroter.
-- =====================================================================

DROP DATABASE IF EXISTS entrepot;
CREATE DATABASE entrepot CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE entrepot;

-- ---------------------------------------------------------------------
--  Séquences des numéros métier
-- ---------------------------------------------------------------------
CREATE SEQUENCE seq_rdv;
CREATE SEQUENCE seq_passage;
CREATE SEQUENCE seq_avis;
CREATE SEQUENCE seq_reception;
CREATE SEQUENCE seq_reserve;
CREATE SEQUENCE seq_lot;
CREATE SEQUENCE seq_mouvement;
CREATE SEQUENCE seq_commande;
CREATE SEQUENCE seq_liste;
CREATE SEQUENCE seq_expedition;
CREATE SEQUENCE seq_document;

-- =====================================================================
--  A. Utilisateurs et profils
-- =====================================================================
-- Référentiel des rôles attribuables aux comptes de l'application.
-- `code` sert aux contrôles de droits et `description` documente le périmètre du rôle.
CREATE TABLE profil (
    id          TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code        VARCHAR(20)  NOT NULL UNIQUE,      -- utilisé par l'application pour les droits
    libelle     VARCHAR(50)  NOT NULL UNIQUE,
    description VARCHAR(255) NULL
) ENGINE=InnoDB;

-- =====================================================================
--  C. Transporteurs, chauffeurs, véhicules
-- =====================================================================
-- Entreprises de transport intervenant sur le site ; le SIRET identifie
-- chaque société et les coordonnées servent à la gestion des opérations.
CREATE TABLE transporteur (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    siret          CHAR(14)     NOT NULL UNIQUE,
    raison_sociale VARCHAR(100) NOT NULL,
    adresse        VARCHAR(150) NOT NULL,
    code_postal    CHAR(5)      NOT NULL,
    ville          VARCHAR(80)  NOT NULL,
    telephone      VARCHAR(20)  NULL,
    email          VARCHAR(120) NULL,
    CONSTRAINT ck_transporteur_siret CHECK (siret REGEXP '^[0-9]{14}$'),
    CONSTRAINT ck_transporteur_cp    CHECK (code_postal REGEXP '^[0-9]{5}$'),
    INDEX ix_transporteur_raison (raison_sociale)
) ENGINE=InnoDB;

-- Comptes des personnes qui utilisent l'application : identité, accès,
-- profil de droits et, pour un compte transporteur, société de rattachement.
CREATE TABLE utilisateur (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email             VARCHAR(120)     NOT NULL UNIQUE,
    nom               VARCHAR(60)      NOT NULL,
    prenom            VARCHAR(60)      NOT NULL,
    telephone         VARCHAR(20)      NULL,
    mot_de_passe_hash VARCHAR(255)     NOT NULL,          -- password_hash() (bcrypt)
    date_creation     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actif             BOOLEAN          NOT NULL DEFAULT FALSE, -- compte validé par un administrateur
    profil_id         TINYINT UNSIGNED NOT NULL,
    transporteur_id   INT UNSIGNED     NULL,              -- renseigné pour le profil « transporteur »
    CONSTRAINT fk_utilisateur_profil       FOREIGN KEY (profil_id)       REFERENCES profil (id),
    CONSTRAINT fk_utilisateur_transporteur FOREIGN KEY (transporteur_id) REFERENCES transporteur (id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- Contacts opérationnels rattachés à un transporteur (ex. interlocuteur
-- administratif ou logistique) ; leur suppression suit celle de la société.
CREATE TABLE contact (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transporteur_id INT UNSIGNED NOT NULL,
    nom             VARCHAR(60)  NOT NULL,
    prenom          VARCHAR(60)  NOT NULL,
    fonction        VARCHAR(60)  NULL,
    telephone       VARCHAR(20)  NULL,
    email           VARCHAR(120) NOT NULL UNIQUE,
    CONSTRAINT fk_contact_transporteur FOREIGN KEY (transporteur_id) REFERENCES transporteur (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Contrats commerciaux ou opérationnels conclus avec un transporteur,
-- avec période de validité et montant annuel éventuellement renseigné.
CREATE TABLE contrat (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference       VARCHAR(30)   NOT NULL UNIQUE,
    transporteur_id INT UNSIGNED  NOT NULL,
    objet           VARCHAR(255)  NOT NULL,
    date_debut      DATE          NOT NULL,
    date_fin        DATE          NULL,
    montant_annuel  DECIMAL(12,2) NULL,
    CONSTRAINT fk_contrat_transporteur FOREIGN KEY (transporteur_id) REFERENCES transporteur (id) ON DELETE CASCADE,
    CONSTRAINT ck_contrat_dates   CHECK (date_fin IS NULL OR date_fin >= date_debut),
    CONSTRAINT ck_contrat_montant CHECK (montant_annuel IS NULL OR montant_annuel >= 0)
) ENGINE=InnoDB;

-- Chauffeurs employés par les transporteurs ; le numéro et la validité du
-- permis permettent d'identifier le conducteur et de contrôler son aptitude.
CREATE TABLE chauffeur (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transporteur_id  INT UNSIGNED NOT NULL,
    nom              VARCHAR(60)  NOT NULL,
    prenom           VARCHAR(60)  NOT NULL,
    telephone        VARCHAR(20)  NULL,
    numero_permis    VARCHAR(20)  NOT NULL UNIQUE,
    categorie_permis VARCHAR(10)  NOT NULL,          -- ex. C, CE, C1
    validite_permis  DATE         NOT NULL,
    CONSTRAINT fk_chauffeur_transporteur FOREIGN KEY (transporteur_id) REFERENCES transporteur (id),
    INDEX ix_chauffeur_nom (nom, prenom)
) ENGINE=InnoDB;

-- Référentiel des habilitations pouvant être détenues par les chauffeurs,
-- identifié par un code stable et un libellé lisible.
CREATE TABLE habilitation (
    id      SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code    VARCHAR(20)  NOT NULL UNIQUE,
    libelle VARCHAR(100) NOT NULL
) ENGINE=InnoDB;

-- Association N:N « Détenir » entre chauffeurs et habilitations.
-- Les dates d'obtention et d'expiration décrivent la validité de chaque droit.
CREATE TABLE chauffeur_habilitation (
    chauffeur_id    INT UNSIGNED      NOT NULL,
    habilitation_id SMALLINT UNSIGNED NOT NULL,
    date_obtention  DATE              NOT NULL,
    date_expiration DATE              NULL,
    PRIMARY KEY (chauffeur_id, habilitation_id),
    CONSTRAINT fk_ch_chauffeur    FOREIGN KEY (chauffeur_id)    REFERENCES chauffeur (id) ON DELETE CASCADE,
    CONSTRAINT fk_ch_habilitation FOREIGN KEY (habilitation_id) REFERENCES habilitation (id),
    CONSTRAINT ck_ch_dates CHECK (date_expiration IS NULL OR date_expiration > date_obtention)
) ENGINE=InnoDB;

-- Référentiel des catégories de véhicules utilisées pour affecter les quais
-- et vérifier qu'un véhicule est accepté sur un rendez-vous.
CREATE TABLE type_vehicule (
    id      TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    libelle VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB;

-- Parc de véhicules des transporteurs, avec type, capacités maximales et
-- équipements utiles (frigorifique, hayon) pour organiser les opérations.
CREATE TABLE vehicule (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    immatriculation  VARCHAR(15)       NOT NULL UNIQUE,
    transporteur_id  INT UNSIGNED      NOT NULL,
    type_vehicule_id TINYINT UNSIGNED  NOT NULL,
    poids_max_kg     MEDIUMINT UNSIGNED NOT NULL,
    palettes_max     TINYINT UNSIGNED  NOT NULL,
    frigorifique     BOOLEAN           NOT NULL DEFAULT FALSE,
    hayon            BOOLEAN           NOT NULL DEFAULT FALSE,
    CONSTRAINT fk_vehicule_transporteur FOREIGN KEY (transporteur_id)  REFERENCES transporteur (id),
    CONSTRAINT fk_vehicule_type         FOREIGN KEY (type_vehicule_id) REFERENCES type_vehicule (id),
    CONSTRAINT ck_vehicule_capacite CHECK (poids_max_kg > 0 AND palettes_max > 0)
) ENGINE=InnoDB;

-- =====================================================================
--  B. Quais et rendez-vous
-- =====================================================================
-- Quais physiques du site, caractérisés par le flux accepté et leur état.
-- `statut` est mis à jour par les passages ; la réservation est calculée par une vue.
CREATE TABLE quai (
    id     SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    numero VARCHAR(10) NOT NULL UNIQUE,
    type   ENUM('reception','expedition','mixte') NOT NULL,
    -- Statut tenu à jour automatiquement par les déclencheurs des passages
    -- (occupé / libre) ; « hors service » est positionné manuellement.
    -- L'état « réservé » dépend de l'heure courante : il est calculé par la
    -- vue v_quai_temps_reel et n'est donc pas stocké.
    statut ENUM('libre','occupe','hors_service') NOT NULL DEFAULT 'libre'
) ENGINE=InnoDB;

-- Référentiel des équipements pouvant être présents sur les quais
-- (par exemple un dispositif de manutention particulier).
CREATE TABLE equipement (
    id      SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    libelle VARCHAR(60) NOT NULL UNIQUE
) ENGINE=InnoDB;

-- Association N:N entre quais et équipements ; une ligne indique qu'un
-- équipement est disponible sur un quai donné.
CREATE TABLE quai_equipement (
    quai_id       SMALLINT UNSIGNED NOT NULL,
    equipement_id SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (quai_id, equipement_id),
    CONSTRAINT fk_qe_quai       FOREIGN KEY (quai_id)       REFERENCES quai (id) ON DELETE CASCADE,
    CONSTRAINT fk_qe_equipement FOREIGN KEY (equipement_id) REFERENCES equipement (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Association N:N entre quais et types de véhicules autorisés.
-- Elle est consultée lors de la validation des rendez-vous et des appels à quai.
CREATE TABLE quai_type_vehicule (
    quai_id          SMALLINT UNSIGNED NOT NULL,
    type_vehicule_id TINYINT UNSIGNED  NOT NULL,
    PRIMARY KEY (quai_id, type_vehicule_id),
    CONSTRAINT fk_qtv_quai FOREIGN KEY (quai_id)          REFERENCES quai (id) ON DELETE CASCADE,
    CONSTRAINT fk_qtv_type FOREIGN KEY (type_vehicule_id) REFERENCES type_vehicule (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Créneaux planifiés pour l'arrivée d'un véhicule sur un quai, dans un sens
-- donné ; les index servent aux contrôles de chevauchement et au planning.
CREATE TABLE rendez_vous (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    numero           VARCHAR(12)       NOT NULL DEFAULT '' UNIQUE,
    quai_id          SMALLINT UNSIGNED NOT NULL,
    vehicule_id      INT UNSIGNED      NOT NULL,
    debut            DATETIME          NOT NULL,
    fin              DATETIME          NOT NULL,
    sens             ENUM('reception','expedition') NOT NULL,
    statut           ENUM('planifie','honore','annule') NOT NULL DEFAULT 'planifie',
    commentaire      VARCHAR(255)      NULL,
    CONSTRAINT fk_rdv_quai     FOREIGN KEY (quai_id)     REFERENCES quai (id),
    CONSTRAINT fk_rdv_vehicule FOREIGN KEY (vehicule_id) REFERENCES vehicule (id),
    -- au plus 4 h : borne aussi la recherche de chevauchement (debut > p_debut - 4 h)
    CONSTRAINT ck_rdv_creneau  CHECK (fin > debut AND fin <= debut + INTERVAL 4 HOUR),
    -- index utilisés par les contrôles de chevauchement (quai, véhicule) et le planning
    INDEX ix_rdv_quai_creneau (quai_id, debut, fin),
    INDEX ix_rdv_vehicule_creneau (vehicule_id, debut, fin),
    INDEX ix_rdv_debut (debut)
) ENGINE=InnoDB;

-- Évènements inscrits au planning en dehors des rendez-vous : maintenance ou
-- nettoyage d'un quai, inventaire, audit, formation, fermeture du site (jour férié).
-- `quai_id` NULL = l'évènement concerne tout le site. Un évènement `bloquant`
-- interdit la prise de rendez-vous sur le créneau (contrôlé par les déclencheurs).
CREATE TABLE evenement (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type        ENUM('maintenance','nettoyage','inventaire','audit','formation','fermeture') NOT NULL,
    libelle     VARCHAR(100)      NOT NULL,
    quai_id     SMALLINT UNSIGNED NULL,                   -- NULL = tout le site
    debut       DATETIME          NOT NULL,
    fin         DATETIME          NOT NULL,
    bloquant    BOOLEAN           NOT NULL DEFAULT TRUE,  -- FALSE = simple information
    commentaire VARCHAR(255)      NULL,
    auteur_id   INT UNSIGNED      NULL,
    CONSTRAINT fk_evenement_quai   FOREIGN KEY (quai_id)   REFERENCES quai (id) ON DELETE CASCADE,
    CONSTRAINT fk_evenement_auteur FOREIGN KEY (auteur_id) REFERENCES utilisateur (id) ON DELETE SET NULL,
    CONSTRAINT ck_evenement_creneau CHECK (fin > debut),
    -- planning d'une journée et contrôle des rendez-vous d'un quai
    INDEX ix_evenement_debut (debut, fin),
    INDEX ix_evenement_quai (quai_id, debut)
) ENGINE=InnoDB;

-- =====================================================================
--  D. Suivi des flux de camions
-- =====================================================================
-- Référentiel ordonné des étapes du parcours d'un camion (arrivée, appel à
-- quai, début/fin d'opération, départ) ; `ordre` définit leur chronologie.
CREATE TABLE type_etape (
    id      TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code    VARCHAR(20) NOT NULL UNIQUE,
    libelle VARCHAR(60) NOT NULL UNIQUE,
    ordre   TINYINT UNSIGNED NOT NULL UNIQUE
) ENGINE=InnoDB;

-- Visite effective d'un véhicule sur le site, éventuellement liée à un
-- rendez-vous ; mémorise le chauffeur, le quai et la dernière étape franchie.
CREATE TABLE passage (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    numero         VARCHAR(12)       NOT NULL DEFAULT '' UNIQUE,
    rendez_vous_id INT UNSIGNED      NULL UNIQUE,     -- NULL = arrivée sans rendez-vous
    vehicule_id    INT UNSIGNED      NOT NULL,
    chauffeur_id   INT UNSIGNED      NOT NULL,
    quai_id        SMALLINT UNSIGNED NULL,            -- quai affecté (après appel à quai)
    sens           ENUM('reception','expedition') NOT NULL,
    -- Dernière étape franchie : dérivée de passage_etape, maintenue par
    -- déclencheur pour éviter une sous-requête dans chaque écran temps réel.
    etape_courante_id TINYINT UNSIGNED NULL,
    observations   VARCHAR(255)      NULL,
    CONSTRAINT fk_passage_rdv       FOREIGN KEY (rendez_vous_id)    REFERENCES rendez_vous (id) ON DELETE SET NULL,
    CONSTRAINT fk_passage_vehicule  FOREIGN KEY (vehicule_id)       REFERENCES vehicule (id),
    CONSTRAINT fk_passage_chauffeur FOREIGN KEY (chauffeur_id)      REFERENCES chauffeur (id),
    CONSTRAINT fk_passage_quai      FOREIGN KEY (quai_id)           REFERENCES quai (id),
    CONSTRAINT fk_passage_etape     FOREIGN KEY (etape_courante_id) REFERENCES type_etape (id)
) ENGINE=InnoDB;

-- Association N:N « Franchir » entre passages et types d'étape.
-- Chaque ligne horodate une étape réalisée et peut porter un commentaire ; elle est immuable.
CREATE TABLE passage_etape (
    passage_id    INT UNSIGNED     NOT NULL,
    type_etape_id TINYINT UNSIGNED NOT NULL,
    date_heure    DATETIME         NOT NULL,
    commentaire   VARCHAR(255)     NULL,
    PRIMARY KEY (passage_id, type_etape_id),
    CONSTRAINT fk_pe_passage FOREIGN KEY (passage_id)    REFERENCES passage (id) ON DELETE CASCADE,
    CONSTRAINT fk_pe_etape   FOREIGN KEY (type_etape_id) REFERENCES type_etape (id),
    INDEX ix_pe_date (date_heure)
) ENGINE=InnoDB;

-- =====================================================================
--  F. Emplacements, articles, stocks
-- =====================================================================
-- Clients propriétaires des articles stockés ou préparés dans l'entrepôt ;
-- les coordonnées et le SIRET sont conservés pour les opérations commerciales.
CREATE TABLE client (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    siret          CHAR(14)     NOT NULL UNIQUE,
    raison_sociale VARCHAR(100) NOT NULL,
    adresse        VARCHAR(150) NOT NULL,
    code_postal    CHAR(5)      NOT NULL,
    ville          VARCHAR(80)  NOT NULL,
    telephone      VARCHAR(20)  NULL,
    email          VARCHAR(120) NULL,
    CONSTRAINT ck_client_siret CHECK (siret REGEXP '^[0-9]{14}$'),
    CONSTRAINT ck_client_cp    CHECK (code_postal REGEXP '^[0-9]{5}$')
) ENGINE=InnoDB;

-- Conditions requises pour conserver une marchandise, avec plage de
-- température facultative, utilisées pour contrôler la compatibilité des zones.
CREATE TABLE condition_stockage (
    id              TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    libelle         VARCHAR(50)  NOT NULL UNIQUE,
    temperature_min DECIMAL(4,1) NULL,
    temperature_max DECIMAL(4,1) NULL,
    CONSTRAINT ck_cs_temp CHECK (temperature_min IS NULL OR temperature_max IS NULL OR temperature_min <= temperature_max)
) ENGINE=InnoDB;

-- Secteurs logistiques de l'entrepôt ; leurs codes servent à repérer les
-- emplacements et à distinguer les règles de stockage applicables.
CREATE TABLE zone (
    id      TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code    VARCHAR(10) NOT NULL UNIQUE,
    libelle VARCHAR(60) NOT NULL
) ENGINE=InnoDB;

-- Association N:N « Autoriser » entre zones et conditions de stockage.
-- Elle définit les catégories d'articles qui peuvent être rangées dans chaque zone.
CREATE TABLE zone_condition (
    zone_id               TINYINT UNSIGNED NOT NULL,
    condition_stockage_id TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (zone_id, condition_stockage_id),
    CONSTRAINT fk_zc_zone      FOREIGN KEY (zone_id)               REFERENCES zone (id) ON DELETE CASCADE,
    CONSTRAINT fk_zc_condition FOREIGN KEY (condition_stockage_id) REFERENCES condition_stockage (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Allées appartenant à une zone ; elles organisent physiquement les racks
-- et permettent de remonter de l'emplacement à son secteur de stockage.
CREATE TABLE allee (
    id      SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    zone_id TINYINT UNSIGNED NOT NULL,
    code    VARCHAR(10) NOT NULL UNIQUE,
    CONSTRAINT fk_allee_zone FOREIGN KEY (zone_id) REFERENCES zone (id)
) ENGINE=InnoDB;

-- Structures de rangement installées dans une allée, identifiées par un
-- code et un nombre de niveaux limité par la contrainte métier.
CREATE TABLE rack (
    id          SMALLINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    allee_id    SMALLINT UNSIGNED NOT NULL,
    code        VARCHAR(15) NOT NULL UNIQUE,
    nb_niveaux  TINYINT UNSIGNED NOT NULL,
    CONSTRAINT fk_rack_allee FOREIGN KEY (allee_id) REFERENCES allee (id),
    CONSTRAINT ck_rack_niveaux CHECK (nb_niveaux BETWEEN 1 AND 12)
) ENGINE=InnoDB;

-- Emplacements physiques d'un rack, avec niveau, capacité en palettes et
-- poids maximal ; `bloque` interdit leur sélection pour un nouveau stockage.
CREATE TABLE emplacement (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    rack_id           SMALLINT UNSIGNED NOT NULL,
    code              VARCHAR(20) NOT NULL UNIQUE,
    niveau            TINYINT UNSIGNED  NOT NULL,
    capacite_palettes TINYINT UNSIGNED  NOT NULL,
    poids_max_kg      SMALLINT UNSIGNED NOT NULL,
    bloque            BOOLEAN NOT NULL DEFAULT FALSE,
    CONSTRAINT fk_emplacement_rack FOREIGN KEY (rack_id) REFERENCES rack (id),
    CONSTRAINT ck_emplacement_capacite CHECK (capacite_palettes > 0 AND poids_max_kg > 0)
) ENGINE=InnoDB;

-- Catalogue des articles appartenant à un client ; dimensions, poids unitaire
-- et condition de stockage permettent de calculer les capacités et compatibilités.
CREATE TABLE article (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reference             VARCHAR(20)  NOT NULL UNIQUE,
    designation           VARCHAR(150) NOT NULL,
    client_id             INT UNSIGNED NOT NULL,       -- propriétaire de la marchandise
    condition_stockage_id TINYINT UNSIGNED NOT NULL,
    longueur_cm           DECIMAL(6,1) NOT NULL,
    largeur_cm            DECIMAL(6,1) NOT NULL,
    hauteur_cm            DECIMAL(6,1) NOT NULL,
    poids_kg              DECIMAL(8,3) NOT NULL,        -- poids d'une unité
    unites_par_colis      SMALLINT UNSIGNED NOT NULL,
    colis_par_palette     SMALLINT UNSIGNED NOT NULL,
    CONSTRAINT fk_article_client    FOREIGN KEY (client_id)             REFERENCES client (id),
    CONSTRAINT fk_article_condition FOREIGN KEY (condition_stockage_id) REFERENCES condition_stockage (id),
    CONSTRAINT ck_article_dims CHECK (longueur_cm > 0 AND largeur_cm > 0 AND hauteur_cm > 0 AND poids_kg > 0),
    CONSTRAINT ck_article_cond CHECK (unites_par_colis > 0 AND colis_par_palette > 0),
    FULLTEXT INDEX ft_article_designation (designation)
) ENGINE=InnoDB;

-- Lots physiques d'un article, avec références fournisseur et dates utiles
-- à la traçabilité et à la gestion des dates limites de consommation.
CREATE TABLE lot (
    id                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code_lot               VARCHAR(12)  NOT NULL DEFAULT '' UNIQUE,
    article_id             INT UNSIGNED NOT NULL,
    numero_lot_fournisseur VARCHAR(30)  NULL,
    date_fabrication       DATE         NULL,
    date_limite_conso      DATE         NULL,          -- DLC / DLUO le cas échéant
    CONSTRAINT fk_lot_article FOREIGN KEY (article_id) REFERENCES article (id),
    CONSTRAINT ck_lot_dates CHECK (date_fabrication IS NULL OR date_limite_conso IS NULL OR date_limite_conso >= date_fabrication),
    INDEX ix_lot_dlc (date_limite_conso)
) ENGINE=InnoDB;

-- Association N:N « Stocker » : quantité disponible de chaque lot sur chaque
-- emplacement ; table de synthèse maintenue exclusivement par les mouvements
-- de stock (le compte applicatif ne dispose que d'un accès en lecture).
CREATE TABLE stock (
    emplacement_id INT UNSIGNED NOT NULL,
    lot_id         INT UNSIGNED NOT NULL,
    quantite       INT UNSIGNED NOT NULL,
    PRIMARY KEY (emplacement_id, lot_id),
    CONSTRAINT fk_stock_emplacement FOREIGN KEY (emplacement_id) REFERENCES emplacement (id),
    CONSTRAINT fk_stock_lot         FOREIGN KEY (lot_id)         REFERENCES lot (id),
    INDEX ix_stock_lot (lot_id)
) ENGINE=InnoDB;

-- Journal immuable des entrées, sorties, transferts et ajustements.
-- Les emplacements source/destination dépendent du type de mouvement et les
-- déclencheurs appliquent chaque mouvement à la table `stock`.
CREATE TABLE mouvement_stock (
    id                         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    numero                     VARCHAR(12)  NOT NULL DEFAULT '' UNIQUE,
    type                       ENUM('entree','transfert','sortie','ajustement') NOT NULL,
    lot_id                     INT UNSIGNED NOT NULL,
    quantite                   INT UNSIGNED NOT NULL,
    emplacement_source_id      INT UNSIGNED NULL,
    emplacement_destination_id INT UNSIGNED NULL,
    utilisateur_id             INT UNSIGNED NOT NULL,
    date_heure                 DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    motif                      VARCHAR(255) NULL,
    CONSTRAINT fk_mvt_lot         FOREIGN KEY (lot_id)                     REFERENCES lot (id),
    CONSTRAINT fk_mvt_source      FOREIGN KEY (emplacement_source_id)      REFERENCES emplacement (id),
    CONSTRAINT fk_mvt_destination FOREIGN KEY (emplacement_destination_id) REFERENCES emplacement (id),
    CONSTRAINT fk_mvt_utilisateur FOREIGN KEY (utilisateur_id)             REFERENCES utilisateur (id),
    CONSTRAINT ck_mvt_quantite CHECK (quantite > 0),
    -- cohérence source / destination selon le type de mouvement
    CONSTRAINT ck_mvt_sens CHECK (
           (type = 'entree'     AND emplacement_source_id IS NULL     AND emplacement_destination_id IS NOT NULL)
        OR (type = 'sortie'     AND emplacement_source_id IS NOT NULL AND emplacement_destination_id IS NULL)
        OR (type = 'transfert'  AND emplacement_source_id IS NOT NULL AND emplacement_destination_id IS NOT NULL
                                AND emplacement_source_id <> emplacement_destination_id)
        OR (type = 'ajustement' AND (emplacement_source_id IS NULL) <> (emplacement_destination_id IS NULL))
    ),
    INDEX ix_mvt_date (date_heure),
    INDEX ix_mvt_lot_date (lot_id, date_heure)
) ENGINE=InnoDB;

-- =====================================================================
--  E. Réception des marchandises
-- =====================================================================
-- Fournisseurs de marchandises ; leurs coordonnées et leur SIRET sont utilisés
-- pour rattacher les avis d'expédition aux entreprises expéditrices.
CREATE TABLE fournisseur (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    siret          CHAR(14)     NOT NULL UNIQUE,
    raison_sociale VARCHAR(100) NOT NULL,
    adresse        VARCHAR(150) NOT NULL,
    code_postal    CHAR(5)      NOT NULL,
    ville          VARCHAR(80)  NOT NULL,
    telephone      VARCHAR(20)  NULL,
    email          VARCHAR(120) NULL,
    CONSTRAINT ck_fournisseur_siret CHECK (siret REGEXP '^[0-9]{14}$'),
    CONSTRAINT ck_fournisseur_cp    CHECK (code_postal REGEXP '^[0-9]{5}$')
) ENGINE=InnoDB;

-- Avis d'expédition annoncés par un fournisseur, avec dates et statut de suivi.
-- La liste des articles et quantités attendues est portée par `ligne_avis`.
CREATE TABLE avis_expedition (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    numero           VARCHAR(12)  NOT NULL DEFAULT '' UNIQUE,
    fournisseur_id   INT UNSIGNED NOT NULL,
    date_emission    DATE         NOT NULL,
    livraison_prevue DATE         NOT NULL,
    statut           ENUM('attendu','recu','annule') NOT NULL DEFAULT 'attendu',
    CONSTRAINT fk_avis_fournisseur FOREIGN KEY (fournisseur_id) REFERENCES fournisseur (id),
    CONSTRAINT ck_avis_dates CHECK (livraison_prevue >= date_emission),
    INDEX ix_avis_livraison (livraison_prevue)
) ENGINE=InnoDB;

-- Association N:N « Annoncer » entre avis d'expédition et articles.
-- Chaque ligne précise la quantité attendue pour l'article concerné.
CREATE TABLE ligne_avis (
    avis_id           INT UNSIGNED NOT NULL,
    article_id        INT UNSIGNED NOT NULL,
    quantite_attendue INT UNSIGNED NOT NULL,
    PRIMARY KEY (avis_id, article_id),
    CONSTRAINT fk_la_avis    FOREIGN KEY (avis_id)    REFERENCES avis_expedition (id) ON DELETE CASCADE,
    CONSTRAINT fk_la_article FOREIGN KEY (article_id) REFERENCES article (id),
    CONSTRAINT ck_la_quantite CHECK (quantite_attendue > 0)
) ENGINE=InnoDB;

-- Opération de réception rattachée à un passage de camion et, si elle a été
-- annoncée, à un avis d'expédition ; enregistre son début, sa fin et sa clôture.
CREATE TABLE reception (
    id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    numero            VARCHAR(12)  NOT NULL DEFAULT '' UNIQUE,
    passage_id        INT UNSIGNED NOT NULL,
    avis_id           INT UNSIGNED NULL,               -- NULL = livraison non annoncée
    debut             DATETIME     NOT NULL,
    fin               DATETIME     NULL,
    statut            ENUM('en_cours','cloturee') NOT NULL DEFAULT 'en_cours',
    CONSTRAINT fk_reception_passage FOREIGN KEY (passage_id)        REFERENCES passage (id),
    CONSTRAINT fk_reception_avis    FOREIGN KEY (avis_id)           REFERENCES avis_expedition (id),
    CONSTRAINT ck_reception_dates CHECK (fin IS NULL OR fin >= debut),
    CONSTRAINT ck_reception_cloture CHECK (statut = 'en_cours' OR fin IS NOT NULL)
) ENGINE=InnoDB;

-- Association N:N « Recevoir » entre réceptions et lots effectivement livrés.
-- La quantité et l'état des palettes servent au calcul automatique des écarts.
CREATE TABLE reception_lot (
    reception_id   INT UNSIGNED NOT NULL,
    lot_id         INT UNSIGNED NOT NULL,
    quantite_recue INT UNSIGNED NOT NULL,
    etat_palette   ENUM('bon','endommage','refuse') NOT NULL DEFAULT 'bon',
    PRIMARY KEY (reception_id, lot_id),
    CONSTRAINT fk_rl_reception FOREIGN KEY (reception_id) REFERENCES reception (id) ON DELETE CASCADE,
    CONSTRAINT fk_rl_lot       FOREIGN KEY (lot_id)       REFERENCES lot (id),
    CONSTRAINT ck_rl_quantite CHECK (quantite_recue > 0)
) ENGINE=InnoDB;

-- Réserves émises à la suite d'une réception ; chaque réserve décrit un motif
-- et indique si elle a été transmise au transporteur concerné.
CREATE TABLE reserve (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    numero        VARCHAR(12)  NOT NULL DEFAULT '' UNIQUE,
    reception_id  INT UNSIGNED NOT NULL,
    date_emission DATETIME     NOT NULL,
    motif         VARCHAR(255) NOT NULL,
    transmise     BOOLEAN      NOT NULL DEFAULT FALSE,  -- transmise au transporteur
    CONSTRAINT fk_reserve_reception FOREIGN KEY (reception_id) REFERENCES reception (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Référentiel des écarts constatables à la réception (manquant, excédent,
-- endommagé) ; le code est utilisé par la procédure de clôture.
CREATE TABLE type_ecart (
    id      TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code    VARCHAR(20) NOT NULL UNIQUE,                -- manquant, excedent, endommage
    libelle VARCHAR(60) NOT NULL
) ENGINE=InnoDB;

-- Association ternaire « Constater » : réception × article × type d'écart.
-- Elle conserve la quantité et une explication pour chaque anomalie constatée.
CREATE TABLE constat_ecart (
    reception_id  INT UNSIGNED     NOT NULL,
    article_id    INT UNSIGNED     NOT NULL,
    type_ecart_id TINYINT UNSIGNED NOT NULL,
    quantite      INT UNSIGNED     NOT NULL,
    description   VARCHAR(255)     NULL,
    PRIMARY KEY (reception_id, article_id, type_ecart_id),
    CONSTRAINT fk_ce_reception  FOREIGN KEY (reception_id)  REFERENCES reception (id) ON DELETE CASCADE,
    CONSTRAINT fk_ce_article    FOREIGN KEY (article_id)    REFERENCES article (id),
    CONSTRAINT fk_ce_type_ecart FOREIGN KEY (type_ecart_id) REFERENCES type_ecart (id),
    CONSTRAINT ck_ce_quantite CHECK (quantite > 0)
) ENGINE=InnoDB;

-- =====================================================================
--  G. Préparation et expédition des commandes
-- =====================================================================
-- Commandes clients à préparer, avec adresse de livraison, échéance souhaitée
-- et état d'avancement de la réception jusqu'à l'expédition.
CREATE TABLE commande (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    numero               VARCHAR(12)  NOT NULL DEFAULT '' UNIQUE,
    client_id            INT UNSIGNED NOT NULL,
    date_commande        DATE         NOT NULL,
    expedition_souhaitee DATE         NOT NULL,
    adresse_livraison    VARCHAR(150) NOT NULL,
    code_postal          CHAR(5)      NOT NULL,
    ville                VARCHAR(80)  NOT NULL,
    statut               ENUM('recue','en_preparation','preparee','expediee','annulee') NOT NULL DEFAULT 'recue',
    CONSTRAINT fk_commande_client FOREIGN KEY (client_id) REFERENCES client (id),
    CONSTRAINT ck_commande_dates CHECK (expedition_souhaitee >= date_commande),
    INDEX ix_commande_expedition (expedition_souhaitee)
) ENGINE=InnoDB;

-- Association N:N « Comprendre » entre commandes et articles.
-- Chaque ligne porte la quantité commandée d'un article.
CREATE TABLE ligne_commande (
    commande_id INT UNSIGNED NOT NULL,
    article_id  INT UNSIGNED NOT NULL,
    quantite    INT UNSIGNED NOT NULL,
    PRIMARY KEY (commande_id, article_id),
    CONSTRAINT fk_lc_commande FOREIGN KEY (commande_id) REFERENCES commande (id) ON DELETE CASCADE,
    CONSTRAINT fk_lc_article  FOREIGN KEY (article_id)  REFERENCES article (id),
    CONSTRAINT ck_lc_quantite CHECK (quantite > 0)
) ENGINE=InnoDB;

-- Liste de préparation associée à une commande : règle FIFO/FEFO, préparateur,
-- dates de traitement et état de progression de la liste.
CREATE TABLE liste_prelevement (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    numero         VARCHAR(12)  NOT NULL DEFAULT '' UNIQUE,
    commande_id    INT UNSIGNED NOT NULL,
    preparateur_id INT UNSIGNED NULL,
    regle_sortie   ENUM('FIFO','FEFO') NOT NULL,
    date_creation  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    debut          DATETIME     NULL,
    fin            DATETIME     NULL,
    statut         ENUM('a_faire','en_cours','terminee') NOT NULL DEFAULT 'a_faire',
    CONSTRAINT fk_lp_commande    FOREIGN KEY (commande_id)    REFERENCES commande (id) ON DELETE CASCADE,
    CONSTRAINT fk_lp_preparateur FOREIGN KEY (preparateur_id) REFERENCES utilisateur (id) ON DELETE SET NULL,
    CONSTRAINT ck_lp_dates CHECK (fin IS NULL OR (debut IS NOT NULL AND fin >= debut))
) ENGINE=InnoDB;

-- Association ternaire « Prélever » : liste × lot × emplacement.
-- Elle détaille l'ordre et les quantités demandées puis effectivement prélevées.
CREATE TABLE ligne_prelevement (
    liste_id            INT UNSIGNED NOT NULL,
    lot_id              INT UNSIGNED NOT NULL,
    emplacement_id      INT UNSIGNED NOT NULL,
    ordre               SMALLINT UNSIGNED NOT NULL,
    quantite_a_prelever INT UNSIGNED NOT NULL,
    quantite_prelevee   INT UNSIGNED NULL,
    PRIMARY KEY (liste_id, lot_id, emplacement_id),
    CONSTRAINT fk_lpr_liste       FOREIGN KEY (liste_id)       REFERENCES liste_prelevement (id) ON DELETE CASCADE,
    CONSTRAINT fk_lpr_lot         FOREIGN KEY (lot_id)         REFERENCES lot (id),
    CONSTRAINT fk_lpr_emplacement FOREIGN KEY (emplacement_id) REFERENCES emplacement (id),
    CONSTRAINT ck_lpr_quantite CHECK (quantite_a_prelever > 0)
) ENGINE=InnoDB;

-- Expédition physique associée au passage de camion qui emporte la commande ;
-- son état suit les phases de chargement, de chargement terminé et de départ.
CREATE TABLE expedition (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    numero          VARCHAR(12)  NOT NULL DEFAULT '' UNIQUE,
    passage_id      INT UNSIGNED NOT NULL,
    date_chargement DATETIME     NULL,
    statut          ENUM('en_chargement','chargee','partie') NOT NULL DEFAULT 'en_chargement',
    CONSTRAINT fk_expedition_passage FOREIGN KEY (passage_id) REFERENCES passage (id)
) ENGINE=InnoDB;

-- Colis ou palettes identifiés par un SSCC, rattachés à une commande et
-- éventuellement à l'expédition sur laquelle ils sont chargés.
CREATE TABLE unite_expedition (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sscc          CHAR(18)     NOT NULL UNIQUE,         -- Serial Shipping Container Code
    commande_id   INT UNSIGNED NOT NULL,
    expedition_id INT UNSIGNED NULL,                    -- NULL tant que non chargée
    type          ENUM('colis','palette') NOT NULL,
    poids_kg      DECIMAL(8,2) NOT NULL,
    CONSTRAINT fk_ue_commande   FOREIGN KEY (commande_id)   REFERENCES commande (id),
    CONSTRAINT fk_ue_expedition FOREIGN KEY (expedition_id) REFERENCES expedition (id) ON DELETE SET NULL,
    CONSTRAINT ck_ue_poids CHECK (poids_kg > 0)
) ENGINE=InnoDB;

-- Association N:N « Contenir » entre unités d'expédition et lots.
-- Elle indique les quantités de chaque lot présentes dans chaque colis ou palette.
CREATE TABLE contenu_unite (
    unite_id INT UNSIGNED NOT NULL,
    lot_id   INT UNSIGNED NOT NULL,
    quantite INT UNSIGNED NOT NULL,
    PRIMARY KEY (unite_id, lot_id),
    CONSTRAINT fk_cu_unite FOREIGN KEY (unite_id) REFERENCES unite_expedition (id) ON DELETE CASCADE,
    CONSTRAINT fk_cu_lot   FOREIGN KEY (lot_id)   REFERENCES lot (id),
    CONSTRAINT ck_cu_quantite CHECK (quantite > 0)
) ENGINE=InnoDB;

-- Documents produits pour une expédition (bon de livraison ou lettre CMR),
-- avec leur numéro et leur date d'émission.
CREATE TABLE document_transport (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    numero        VARCHAR(12)  NOT NULL DEFAULT '' UNIQUE,
    expedition_id INT UNSIGNED NOT NULL,
    type          ENUM('BL','CMR') NOT NULL,          -- bon de livraison / lettre de voiture
    date_emission DATETIME     NOT NULL,
    CONSTRAINT fk_dt_expedition FOREIGN KEY (expedition_id) REFERENCES expedition (id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- =====================================================================
--  H. Historisation des statuts
-- =====================================================================
-- Journal écrit exclusivement par les déclencheurs lors d'un changement de
-- statut de rendez-vous, commande ou quai ; il n'est jamais modifié ni supprimé.
-- objet_id ne porte volontairement pas de clé étrangère : un journal doit survivre
-- à la suppression de l'élément qu'il décrit (commande supprimée, quai démonté).
-- `utilisateur_id` est nullable pour conserver également les changements automatiques.
CREATE TABLE historique_statut (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    objet          ENUM('rendez_vous','commande','quai') NOT NULL,
    objet_id       INT UNSIGNED NOT NULL,
    ancien_statut  VARCHAR(20)  NOT NULL,
    nouveau_statut VARCHAR(20)  NOT NULL,
    date_heure     DATETIME     NOT NULL,
    utilisateur_id INT UNSIGNED NULL,                   -- NULL = changement automatique (installation)
    CONSTRAINT fk_hs_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES utilisateur (id) ON DELETE SET NULL,
    CONSTRAINT ck_hs_changement CHECK (ancien_statut <> nouveau_statut),
    INDEX ix_hs_objet (objet, objet_id, date_heure)
) ENGINE=InnoDB;
