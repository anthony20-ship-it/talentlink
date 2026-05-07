-- ============================================================
--  TalentLink — Script SQL pour XAMPP / phpMyAdmin
--
--  IMPORTANT : Ne pas importer ce fichier depuis information_schema
--  Suivre exactement les étapes ci-dessous
-- ============================================================

-- Tables de l'application
-- (La base talentlink_db doit déjà être créée et sélectionnée)

CREATE TABLE IF NOT EXISTS users (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  first_name   VARCHAR(100) NOT NULL,
  last_name    VARCHAR(100) NOT NULL DEFAULT '',
  email        VARCHAR(150) NOT NULL UNIQUE,
  password     VARCHAR(255) NOT NULL,
  role         ENUM('candidat','recruteur','particulier') DEFAULT 'candidat',
  city         VARCHAR(100) DEFAULT '',
  skills       TEXT DEFAULT '',
  diploma      VARCHAR(150) DEFAULT '',
  exp          INT DEFAULT 0,
  company      VARCHAR(150) DEFAULT '',
  sector       VARCHAR(150) DEFAULT '',
  service_type VARCHAR(150) DEFAULT '',
  bio          TEXT DEFAULT '',
  avatar_data  LONGTEXT DEFAULT NULL,
  cover_data   LONGTEXT DEFAULT NULL,
  status       ENUM('disponible','ouvert','en_poste') DEFAULT 'disponible',
  cv_data      LONGTEXT DEFAULT NULL,
  cv_name      VARCHAR(255) DEFAULT NULL,
  is_active    TINYINT(1) DEFAULT 1,
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS offres (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  titre            VARCHAR(200) NOT NULL,
  type_contrat     ENUM('CDI','CDD','Stage','Freelance','Alternance') DEFAULT 'CDI',
  statut           ENUM('Actif','Ferme','Brouillon') DEFAULT 'Actif',
  city             VARCHAR(100) DEFAULT '',
  description      TEXT DEFAULT '',
  tags             TEXT DEFAULT NULL,
  required_skills  TEXT DEFAULT NULL,
  salary           VARCHAR(100) DEFAULT '',
  diploma_required TINYINT(1) DEFAULT 0,
  informal         TINYINT(1) DEFAULT 0,
  author_id        INT DEFAULT NULL,
  created_at       DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS missions (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  titre       VARCHAR(200) NOT NULL,
  description TEXT DEFAULT '',
  pay         VARCHAR(100) DEFAULT '',
  duration    VARCHAR(100) DEFAULT '',
  city        VARCHAR(100) DEFAULT '',
  tags        TEXT DEFAULT NULL,
  statut      ENUM('Actif','Termine','Annule') DEFAULT 'Actif',
  author_id   INT DEFAULT NULL,
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS candidatures (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  offre_id     INT NOT NULL,
  type         ENUM('offre','mission') DEFAULT 'offre',
  candidat_id  INT NOT NULL,
  statut       ENUM('En attente','Accepte','Rejete') DEFAULT 'En attente',
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS conversations (
  id        INT AUTO_INCREMENT PRIMARY KEY,
  user1_id  INT NOT NULL,
  user2_id  INT NOT NULL,
  last_msg  VARCHAR(255) DEFAULT '',
  last_at   DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS messages (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  conv_id    INT NOT NULL,
  from_id    INT NOT NULL,
  to_id      INT NOT NULL,
  content    TEXT NOT NULL,
  read_at    DATETIME DEFAULT NULL,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS creneaux (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  date         DATE NOT NULL,
  heure        TIME NOT NULL,
  note         VARCHAR(255) DEFAULT '',
  statut       ENUM('disponible','reserve','confirme','annule') DEFAULT 'disponible',
  recruteur_id INT NOT NULL,
  candidat_id  INT DEFAULT NULL,
  created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS rdv_notifs (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  to_id      INT NOT NULL,
  from_id    INT NOT NULL,
  slot_id    INT DEFAULT NULL,
  type       ENUM('demande','confirmation','annulation') DEFAULT 'demande',
  message    VARCHAR(255) NOT NULL,
  lue        TINYINT(1) DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
