-- Base de données du parc informatique IT CORP

CREATE DATABASE IF NOT EXISTS parc_informatique
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE parc_informatique;

SET FOREIGN_KEY_CHECKS = 0;
-- Anciens objets supprimés du modèle (nettoyage d'une base existante)
DROP VIEW  IF EXISTS v_garanties_statut;
DROP VIEW  IF EXISTS v_affectations_actuelles;
DROP TABLE IF EXISTS commentaires_rapports;
DROP TABLE IF EXISTS intervention_mises_a_jour;
DROP TABLE IF EXISTS garanties;

DROP TABLE IF EXISTS rapports;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS historique_evenements;
DROP TABLE IF EXISTS interventions;
DROP TABLE IF EXISTS equipement_licence;
DROP TABLE IF EXISTS licences;
DROP TABLE IF EXISTS logiciels_installes;
DROP TABLE IF EXISTS affectations;
DROP TABLE IF EXISTS equipements;
DROP TABLE IF EXISTS categories_equipement;
DROP TABLE IF EXISTS techniciens;
DROP TABLE IF EXISTS utilisateurs;
DROP TABLE IF EXISTS clients;
DROP TABLE IF EXISTS services;
DROP TABLE IF EXISTS sites;
SET FOREIGN_KEY_CHECKS = 1;


-- Sites, services, clients

CREATE TABLE sites (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nom          VARCHAR(100)    NOT NULL,
  adresse      VARCHAR(255)    NULL,
  ville        VARCHAR(100)    NULL,
  description  TEXT            NULL,
  created_at   TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sites_nom (nom)
) ENGINE=InnoDB;

CREATE TABLE services (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nom          VARCHAR(100)    NOT NULL,
  description  TEXT            NULL,
  site_id      BIGINT UNSIGNED NULL,
  created_at   TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_services_nom (nom),
  CONSTRAINT fk_services_site FOREIGN KEY (site_id) REFERENCES sites (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

-- Clients externes dont on gère le parc
CREATE TABLE clients (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nom          VARCHAR(150)    NOT NULL,
  telephone    VARCHAR(30)     NULL,
  email        VARCHAR(150)    NULL,
  adresse      VARCHAR(255)    NULL,
  created_at   TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_clients_nom (nom)
) ENGINE=InnoDB;


-- Utilisateurs et rôles

CREATE TABLE utilisateurs (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nom                 VARCHAR(100)    NOT NULL,
  prenom              VARCHAR(100)    NOT NULL,
  email               VARCHAR(150)    NOT NULL,
  mot_de_passe        VARCHAR(255)    NOT NULL,
  role                ENUM('administrateur','technicien','ceo') NOT NULL DEFAULT 'technicien',
  telephone           VARCHAR(30)     NULL,
  service_id          BIGINT UNSIGNED NULL,
  site_id             BIGINT UNSIGNED NULL,
  statut              ENUM('actif','inactif') NOT NULL DEFAULT 'actif',
  derniere_connexion  DATETIME        NULL,
  created_at          TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_utilisateurs_email (email),
  KEY idx_utilisateurs_role (role),
  CONSTRAINT fk_utilisateurs_service FOREIGN KEY (service_id) REFERENCES services (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_utilisateurs_site FOREIGN KEY (site_id) REFERENCES sites (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE techniciens (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  utilisateur_id  BIGINT UNSIGNED NOT NULL,
  specialite      VARCHAR(100)    NULL,
  created_at      TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_techniciens_utilisateur (utilisateur_id),
  CONSTRAINT fk_techniciens_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;


-- Parc informatique : catégories, équipements, logiciels

CREATE TABLE categories_equipement (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  libelle             VARCHAR(100)    NOT NULL,
  prefixe_inventaire  VARCHAR(10)     NOT NULL,
  created_at          TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_categories_libelle (libelle),
  UNIQUE KEY uq_categories_prefixe (prefixe_inventaire)
) ENGINE=InnoDB;

-- L'affectation actuelle d'un équipement se trouve dans la table "affectations"
-- (la ligne dont date_fin est NULL), pas ici.
CREATE TABLE equipements (
  id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  numero_inventaire     VARCHAR(30)     NOT NULL,
  categorie_id          BIGINT UNSIGNED NOT NULL,
  -- Nom de l'objet, précisé pour le type "Autre" (ex. Projecteur -> N° PRO-01)
  nom_objet             VARCHAR(100)    NULL,
  marque                VARCHAR(100)    NULL,
  modele                VARCHAR(150)    NULL,
  numero_serie          VARCHAR(100)    NULL,
  processeur            VARCHAR(100)    NULL,
  ram                   VARCHAR(50)     NULL,
  stockage              VARCHAR(100)    NULL,
  systeme_exploitation  VARCHAR(100)    NULL,
  adresse_ip            VARCHAR(45)     NULL,
  adresse_mac           VARCHAR(17)     NULL,
  caracteristiques      TEXT            NULL,
  emplacement           VARCHAR(150)    NULL,
  etat                  ENUM('en_service','disponible','en_panne','en_reparation','hors_service')
                          NOT NULL DEFAULT 'disponible',
  date_acquisition      DATE            NULL,
  fournisseur           VARCHAR(150)    NULL,
  -- Garantie
  garantie_date_debut   DATE            NULL,
  garantie_date_fin     DATE            NULL,
  garantie_description  VARCHAR(255)    NULL,
  -- Fiche créée directement depuis une intervention, sans passer par l'inventaire
  est_temporaire        BOOLEAN         NOT NULL DEFAULT FALSE,
  statut_validation     ENUM('valide','en_attente') NOT NULL DEFAULT 'valide',
  valide_par            BIGINT UNSIGNED NULL,
  date_validation       DATETIME        NULL,
  cree_par              BIGINT UNSIGNED NULL,
  created_at            TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at            TIMESTAMP       NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_equipements_inventaire (numero_inventaire),
  UNIQUE KEY uq_equipements_serie (numero_serie),
  KEY idx_equipements_etat (etat),
  KEY idx_equipements_temporaire (est_temporaire, statut_validation),
  KEY idx_equipements_garantie_fin (garantie_date_fin),
  CONSTRAINT chk_equipements_garantie CHECK (garantie_date_fin IS NULL OR garantie_date_debut IS NULL
                                             OR garantie_date_fin >= garantie_date_debut),
  CONSTRAINT fk_equipements_categorie FOREIGN KEY (categorie_id) REFERENCES categories_equipement (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_equipements_valide_par FOREIGN KEY (valide_par) REFERENCES utilisateurs (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_equipements_cree_par FOREIGN KEY (cree_par) REFERENCES utilisateurs (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE logiciels_installes (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  equipement_id      BIGINT UNSIGNED NOT NULL,
  nom                VARCHAR(150)    NOT NULL,
  version            VARCHAR(50)     NULL,
  date_installation  DATE            NULL,
  created_at         TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  CONSTRAINT fk_logiciels_equipement FOREIGN KEY (equipement_id) REFERENCES equipements (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;


-- Affectations : chaque changement crée une nouvelle ligne, l'ancienne
-- reste en base avec sa date de fin renseignée.

CREATE TABLE affectations (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  equipement_id   BIGINT UNSIGNED NOT NULL,
  utilisateur_id  BIGINT UNSIGNED NULL,
  service_id      BIGINT UNSIGNED NULL,
  site_id         BIGINT UNSIGNED NULL,
  client_id       BIGINT UNSIGNED NULL COMMENT 'NULL = parc interne',
  date_debut      DATETIME        NOT NULL,
  date_fin        DATETIME        NULL,
  motif           VARCHAR(255)    NULL,
  effectue_par    BIGINT UNSIGNED NULL,
  equipement_actif BIGINT UNSIGNED AS (IF(date_fin IS NULL, equipement_id, NULL)) STORED,
  created_at      TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_affectations_une_active (equipement_actif),
  KEY idx_affectations_equipement (equipement_id, date_debut),
  CONSTRAINT chk_affectations_dates CHECK (date_fin IS NULL OR date_fin >= date_debut),
  CONSTRAINT fk_affectations_equipement FOREIGN KEY (equipement_id) REFERENCES equipements (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_affectations_utilisateur FOREIGN KEY (utilisateur_id) REFERENCES utilisateurs (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_affectations_service FOREIGN KEY (service_id) REFERENCES services (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_affectations_site FOREIGN KEY (site_id) REFERENCES sites (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_affectations_client FOREIGN KEY (client_id) REFERENCES clients (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_affectations_effectue_par FOREIGN KEY (effectue_par) REFERENCES utilisateurs (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;


-- Licences

CREATE TABLE licences (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  nom_logiciel     VARCHAR(150)    NOT NULL,
  version          VARCHAR(50)     NULL,
  numero_licence   VARCHAR(150)    NOT NULL,
  nombre_postes    INT UNSIGNED    NOT NULL DEFAULT 1,
  date_debut       DATE            NULL,
  date_expiration  DATE            NULL,
  fournisseur      VARCHAR(150)    NULL,
  created_at       TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_licences_numero (numero_licence),
  KEY idx_licences_expiration (date_expiration)
) ENGINE=InnoDB;

CREATE TABLE equipement_licence (
  equipement_id      BIGINT UNSIGNED NOT NULL,
  licence_id         BIGINT UNSIGNED NOT NULL,
  date_attribution   DATE            NULL,
  created_at         TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (equipement_id, licence_id),
  CONSTRAINT fk_eqlic_equipement FOREIGN KEY (equipement_id) REFERENCES equipements (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_eqlic_licence FOREIGN KEY (licence_id) REFERENCES licences (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

-- Interventions

CREATE TABLE interventions (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  numero              VARCHAR(30)     NOT NULL COMMENT 'ex. INT-2026-001',
  equipement_id       BIGINT UNSIGNED NULL,
  technicien_id       BIGINT UNSIGNED NULL,
  user_id             BIGINT UNSIGNED NULL COMMENT 'Utilisateur qui a créé l''intervention',
  demandeur           VARCHAR(150)    NULL,
  priorite            ENUM('faible','normale','haute','critique') NOT NULL DEFAULT 'normale',
  statut              ENUM('nouvelle','en_attente','en_cours','resolue','cloturee') NOT NULL DEFAULT 'nouvelle',
  etape               ENUM('creation','qualification','diagnostic','intervention',
                           'test_validation','documentation','cloture') NOT NULL DEFAULT 'creation',
  constat             TEXT            NOT NULL,
  diagnostic          TEXT            NULL,
  actions_realisees   TEXT            NULL,
  tests_effectues     TEXT            NULL,
  resultat            TEXT            NULL,
  probleme_resolu     BOOLEAN         NULL,
  compte_rendu        TEXT            NULL,
  date_planification  DATETIME        NULL,
  date_debut          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Par défaut : date de création',
  date_fin            DATETIME        NULL,
  -- Rappel "intervention en retard" déjà envoyé au technicien
  rappel_retard_envoye BOOLEAN        NOT NULL DEFAULT FALSE,
  created_at          TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_interventions_numero (numero),
  KEY idx_interventions_statut (statut),
  KEY idx_interventions_priorite (priorite),
  KEY idx_interventions_planification (date_planification),
  CONSTRAINT chk_interventions_dates CHECK (date_fin IS NULL OR date_fin >= date_debut),
  CONSTRAINT fk_interventions_equipement FOREIGN KEY (equipement_id) REFERENCES equipements (id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_interventions_technicien FOREIGN KEY (technicien_id) REFERENCES techniciens (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_interventions_user FOREIGN KEY (user_id) REFERENCES utilisateurs (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;


-- Historique des événements (journal en ajout seul)

CREATE TABLE historique_evenements (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  type             ENUM('creation_equipement','modification_equipement','changement_affectation',
                        'changement_site','changement_etat','changement_licence','intervention',
                        'maintenance','validation_fiche','utilisateur') NOT NULL,
  description      TEXT            NOT NULL,
  ancienne_valeur  TEXT            NULL,
  nouvelle_valeur  TEXT            NULL,
  equipement_id    BIGINT UNSIGNED NULL,
  intervention_id  BIGINT UNSIGNED NULL,
  affectation_id   BIGINT UNSIGNED NULL,
  auteur_id        BIGINT UNSIGNED NULL,
  date_evenement   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_historique_equipement (equipement_id, date_evenement),
  KEY idx_historique_type (type),
  CONSTRAINT fk_historique_equipement FOREIGN KEY (equipement_id) REFERENCES equipements (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_historique_intervention FOREIGN KEY (intervention_id) REFERENCES interventions (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_historique_affectation FOREIGN KEY (affectation_id) REFERENCES affectations (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_historique_auteur FOREIGN KEY (auteur_id) REFERENCES utilisateurs (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;


-- Notifications et rapports

-- Les changements d'une intervention (statut, étape, technicien, priorité)
-- génèrent automatiquement une notification (voir trg_interventions_notification).
CREATE TABLE notifications (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  destinataire_id  BIGINT UNSIGNED NOT NULL,
  type             ENUM('critique','panne','garantie','licence','affectation',
                        'intervention','commentaire','fiche_provisoire',
                        'assignation','retard','mdp_oublie') NOT NULL,
  message          VARCHAR(500)    NOT NULL,
  lien             VARCHAR(255)    NULL,
  intervention_id  BIGINT UNSIGNED NULL,
  lue              BOOLEAN         NOT NULL DEFAULT FALSE,
  date_lecture     DATETIME        NULL,
  created_at       TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notifications_destinataire (destinataire_id, lue),
  KEY idx_notifications_intervention (intervention_id, created_at),
  CONSTRAINT fk_notifications_destinataire FOREIGN KEY (destinataire_id) REFERENCES utilisateurs (id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_notifications_intervention FOREIGN KEY (intervention_id) REFERENCES interventions (id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE rapports (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  titre           VARCHAR(200)    NOT NULL,
  type            ENUM('parc','interventions','pannes','techniciens') NOT NULL,
  periode_debut   DATE            NULL,
  periode_fin     DATE            NULL,
  chemin_fichier  VARCHAR(255)    NULL,
  -- Contenu figé à la date de génération (JSON : {"headers": [...], "rows": [[...], ...]})
  contenu         LONGTEXT        NULL,
  user_id         BIGINT UNSIGNED NULL COMMENT 'Auteur du rapport',
  commentaire     TEXT            NULL COMMENT 'Commentaire du CEO (HTML de mise en forme simple)',
  commentaire_auteur_id BIGINT UNSIGNED NULL,
  commentaire_date DATETIME       NULL,
  commentaire_lu  BOOLEAN         NOT NULL DEFAULT FALSE,
  created_at      TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP       NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rapports_user (user_id),
  CONSTRAINT fk_rapports_user FOREIGN KEY (user_id) REFERENCES utilisateurs (id)
    ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_rapports_commentaire_auteur FOREIGN KEY (commentaire_auteur_id) REFERENCES utilisateurs (id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB;


-- Déclencheurs

DELIMITER $$

CREATE TRIGGER trg_interventions_cloture_insert
BEFORE INSERT ON interventions
FOR EACH ROW
BEGIN
  IF NEW.date_debut IS NULL THEN
    SET NEW.date_debut = NOW();
  END IF;

  IF NEW.statut = 'cloturee' AND (
       NULLIF(TRIM(NEW.diagnostic), '') IS NULL OR
       NULLIF(TRIM(NEW.actions_realisees), '') IS NULL OR
       NULLIF(TRIM(NEW.resultat), '') IS NULL) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Impossible de clôturer : diagnostic, actions réalisées et résultat obligatoires.';
  END IF;
END$$

CREATE TRIGGER trg_interventions_cloture_update
BEFORE UPDATE ON interventions
FOR EACH ROW
BEGIN
  IF OLD.statut = 'cloturee' AND NEW.statut <> 'cloturee' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Une intervention clôturée ne peut pas être rouverte.';
  END IF;

  IF NEW.statut = 'cloturee' AND (
       NULLIF(TRIM(NEW.diagnostic), '') IS NULL OR
       NULLIF(TRIM(NEW.actions_realisees), '') IS NULL OR
       NULLIF(TRIM(NEW.resultat), '') IS NULL) THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Impossible de clôturer : diagnostic, actions réalisées et résultat obligatoires.';
  END IF;

  IF NEW.statut = 'cloturee' AND OLD.statut <> 'cloturee' THEN
    SET NEW.etape = 'cloture';
    IF NEW.date_fin IS NULL THEN
      SET NEW.date_fin = NOW();
    END IF;
  END IF;
END$$

-- Notifie le créateur et le technicien quand une intervention change
CREATE TRIGGER trg_interventions_notification
AFTER UPDATE ON interventions
FOR EACH ROW
BEGIN
  DECLARE v_message   VARCHAR(500);
  DECLARE v_tech_user BIGINT UNSIGNED DEFAULT NULL;

  IF NOT (OLD.statut <=> NEW.statut AND OLD.etape <=> NEW.etape
          AND OLD.technicien_id <=> NEW.technicien_id AND OLD.priorite <=> NEW.priorite) THEN
    SET v_message = CONCAT('Intervention ', NEW.numero,
                           ' : statut ', REPLACE(NEW.statut, '_', ' '),
                           ', étape ', REPLACE(NEW.etape, '_', ' '),
                           ', priorité ', NEW.priorite, '.');

    IF NEW.technicien_id IS NOT NULL THEN
      SELECT utilisateur_id INTO v_tech_user FROM techniciens WHERE id = NEW.technicien_id;
    END IF;

    IF NEW.user_id IS NOT NULL THEN
      INSERT INTO notifications (destinataire_id, type, message, intervention_id)
      VALUES (NEW.user_id, 'intervention', v_message, NEW.id);
    END IF;

    IF v_tech_user IS NOT NULL AND NOT (v_tech_user <=> NEW.user_id) THEN
      INSERT INTO notifications (destinataire_id, type, message, intervention_id)
      VALUES (v_tech_user, 'intervention', v_message, NEW.id);
    END IF;
  END IF;
END$$

CREATE TRIGGER trg_interventions_suppression
BEFORE DELETE ON interventions
FOR EACH ROW
BEGIN
  IF OLD.statut = 'cloturee' THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'Une intervention clôturée ne peut pas être supprimée.';
  END IF;
END$$

CREATE TRIGGER trg_historique_pas_de_suppression
BEFORE DELETE ON historique_evenements
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'L''historique ne peut pas être supprimé.';
END$$

DELIMITER ;


-- Données de référence
-- Aucun compte utilisateur n'est créé ici : le premier compte (Administrateur)
-- se crée depuis l'application au premier lancement.

INSERT INTO categories_equipement (libelle, prefixe_inventaire) VALUES
  ('Ordinateur portable',  'PC-P'),
  ('Ordinateur de bureau', 'PC-B'),
  ('Serveur',              'SRV-'),
  ('Imprimante',           'IMP-'),
  ('Écran',                'ECR-'),
  ('Switch',               'SW-'),
  ('Routeur',              'RTR-'),
  ('Téléphone',            'TEL-'),
  ('Autre',                'AUT-');

INSERT INTO sites (nom, adresse, ville, description) VALUES
  ('Siège Libreville',       'Boulevard Triomphal Omar Bongo', 'Libreville',  'Siège social et direction générale d''IT CORP.'),
  ('Agence Port-Gentil',     'Avenue du Général de Gaulle',    'Port-Gentil', 'Agence commerciale et pôle infogérance régional.'),
  ('Datacenter Franceville', 'Zone Industrielle',              'Franceville', 'Salle serveurs et infrastructure réseau.');

INSERT INTO services (nom, description, site_id) VALUES
  ('Direction Générale',   NULL, 1),
  ('Ressources Humaines',  NULL, 1),
  ('Comptabilité',         NULL, 1),
  ('Infogérance',          'Gestion du parc et des interventions.', 1),
  ('Commercial',           NULL, 2),
  ('Support Technique',    NULL, 2),
  ('Marketing',            NULL, 1),
  ('Juridique',            NULL, 1);

INSERT INTO clients (nom, telephone) VALUES
  ('Gabon Telecom',            '+241 01 76 40 00'),
  ('Banque Agricole du Gabon', '+241 01 44 12 30');
