-- UNIVERSE_SCHEMA_MYSQL.sql — MariaDB/MySQL совместимая схема
-- Создание: mysql -u rsi_starmap -ppassword rsi_starmap < UNIVERSE_SCHEMA_MYSQL.sql

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS object_affiliations;
DROP TABLE IF EXISTS system_affiliations;
DROP TABLE IF EXISTS tunnels;
DROP TABLE IF EXISTS celestial_objects;
DROP TABLE IF EXISTS star_systems;
DROP TABLE IF EXISTS engine_config;
DROP TABLE IF EXISTS affiliations;
DROP TABLE IF EXISTS species;

SET FOREIGN_KEY_CHECKS = 1;

-- Фракции
CREATE TABLE affiliations (
    id          INT         NOT NULL PRIMARY KEY,
    code        VARCHAR(32) NOT NULL UNIQUE,
    name        VARCHAR(64) NOT NULL,
    color       VARCHAR(16) NOT NULL DEFAULT '#888888'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Виды
CREATE TABLE species (
    id          INT         NOT NULL PRIMARY KEY,
    code        VARCHAR(32) NOT NULL UNIQUE,
    name        VARCHAR(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Звёздные системы
CREATE TABLE star_systems (
    id                      INT         NOT NULL PRIMARY KEY,
    code                    VARCHAR(64) NOT NULL UNIQUE,
    name                    VARCHAR(128) NOT NULL,
    description             TEXT,
    type                    VARCHAR(32) NOT NULL DEFAULT 'SINGLE_STAR',
    status                  CHAR(1)     NOT NULL DEFAULT 'P',

    position_x              DOUBLE      NOT NULL DEFAULT 0,
    position_y              DOUBLE      NOT NULL DEFAULT 0,
    position_z              DOUBLE      NOT NULL DEFAULT 0,

    habitable_zone_inner    DOUBLE,
    habitable_zone_outer    DOUBLE,
    frost_line              DOUBLE,
    oort_radius             DOUBLE      NOT NULL DEFAULT 39.5,

    aggregated_size         DOUBLE      NOT NULL DEFAULT 0,
    aggregated_population   DOUBLE      NOT NULL DEFAULT 0,
    aggregated_economy      DOUBLE      NOT NULL DEFAULT 0,
    aggregated_danger       DOUBLE      NOT NULL DEFAULT 0,

    shader_data             JSON,
    thumbnail_slug          VARCHAR(128),
    thumbnail_source        TEXT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Небесные тела (единая таблица, иерархия через parent_id)
CREATE TABLE celestial_objects (
    id              INT         NOT NULL PRIMARY KEY,
    code            VARCHAR(128) NOT NULL UNIQUE,
    name            VARCHAR(128),
    designation     VARCHAR(128),
    description     TEXT,

    type            VARCHAR(32)  NOT NULL,
    appearance      VARCHAR(64),
    subtype_id      INT,
    subtype_name    VARCHAR(128),

    star_system_id  INT          NOT NULL,
    parent_id       INT,

    distance        DOUBLE       NOT NULL DEFAULT 0,
    latitude        DOUBLE       NOT NULL DEFAULT 0,
    longitude       DOUBLE       NOT NULL DEFAULT 0,

    size            DOUBLE       NOT NULL DEFAULT 0,
    age             DOUBLE,
    axial_tilt      DOUBLE,
    orbit_period    DOUBLE,

    habitable       TINYINT(1),
    fairchanceact   TINYINT(1),

    sensor_danger       VARCHAR(4) NOT NULL DEFAULT '0',
    sensor_economy      VARCHAR(4) NOT NULL DEFAULT '0',
    sensor_population   VARCHAR(4) NOT NULL DEFAULT '0',

    show_label      TINYINT(1),
    show_orbitlines TINYINT(1),

    shader_data     JSON,

    texture_slug    VARCHAR(128),
    texture_source  TEXT,
    model_slug      VARCHAR(128),
    model_source    TEXT,

    time_modified   VARCHAR(32),

    FOREIGN KEY (star_system_id) REFERENCES star_systems(id),
    FOREIGN KEY (parent_id)      REFERENCES celestial_objects(id),
    INDEX idx_co_system  (star_system_id),
    INDEX idx_co_parent  (parent_id),
    INDEX idx_co_type    (type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Туннели
CREATE TABLE tunnels (
    id          INT         NOT NULL PRIMARY KEY,
    name        VARCHAR(128),
    direction   CHAR(1)     NOT NULL DEFAULT 'B',
    size        CHAR(1)     NOT NULL DEFAULT 'M',
    entry_id    INT         NOT NULL,
    exit_id     INT         NOT NULL,
    FOREIGN KEY (entry_id) REFERENCES celestial_objects(id),
    FOREIGN KEY (exit_id)  REFERENCES celestial_objects(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- M:N фракции ↔ объекты
CREATE TABLE object_affiliations (
    object_id       INT NOT NULL,
    affiliation_id  INT NOT NULL,
    membership_id   INT,
    PRIMARY KEY (object_id, affiliation_id),
    FOREIGN KEY (object_id)      REFERENCES celestial_objects(id),
    FOREIGN KEY (affiliation_id) REFERENCES affiliations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- M:N фракции ↔ системы
CREATE TABLE system_affiliations (
    system_id       INT NOT NULL,
    affiliation_id  INT NOT NULL,
    membership_id   INT,
    PRIMARY KEY (system_id, affiliation_id),
    FOREIGN KEY (system_id)      REFERENCES star_systems(id),
    FOREIGN KEY (affiliation_id) REFERENCES affiliations(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Конфиг движка
CREATE TABLE engine_config (
    id      INT         NOT NULL PRIMARY KEY DEFAULT 1,
    config  JSON        NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
