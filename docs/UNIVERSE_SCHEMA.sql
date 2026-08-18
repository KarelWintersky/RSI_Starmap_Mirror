-- UNIVERSE_SCHEMA.sql — Минимальная схема БД для вселенной ARK Starmap
-- На основе анализа JSON-формата原始ного движка.
-- Все JSON-ответы генерируются запросами к этим таблицам.

-- ============================================================
-- 1. Фракции
-- ============================================================
CREATE TABLE affiliations (
    id          INTEGER PRIMARY KEY,
    code        TEXT    NOT NULL UNIQUE,    -- 'uee', 'banu', 'vanduul'
    name        TEXT    NOT NULL,           -- 'UEE'
    color       TEXT    NOT NULL DEFAULT '#888888'  -- '#48bbd4'
);

-- ============================================================
-- 2. Виды разумных
-- ============================================================
CREATE TABLE species (
    id          INTEGER PRIMARY KEY,
    code        TEXT    NOT NULL UNIQUE,    -- 'HUMAN', 'BANU'
    name        TEXT    NOT NULL            -- 'Human'
);

-- ============================================================
-- 3. Звёздные системы
-- ============================================================
CREATE TABLE star_systems (
    id                      INTEGER PRIMARY KEY,
    code                    TEXT    NOT NULL UNIQUE,   -- 'STANTON'
    name                    TEXT    NOT NULL,          -- 'Stanton'
    description             TEXT,
    type                    TEXT    NOT NULL DEFAULT 'SINGLE_STAR',  -- SINGLE_STAR | BINARY
    status                  TEXT    NOT NULL DEFAULT 'P',            -- P | M | N

    -- координаты в галактике
    position_x              REAL    NOT NULL DEFAULT 0,
    position_y              REAL    NOT NULL DEFAULT 0,
    position_z              REAL    NOT NULL DEFAULT 0,

    -- зоны (AU)
    habitable_zone_inner    REAL,           -- 0.89
    habitable_zone_outer    REAL,           -- 3.0
    frost_line              REAL,           -- 4.96
    oort_radius             REAL    NOT NULL DEFAULT 39.5,  -- радиус обзора камеры

    -- агрегированные метрики
    aggregated_size         REAL    NOT NULL DEFAULT 0,
    aggregated_population   REAL    NOT NULL DEFAULT 0,
    aggregated_economy      REAL    NOT NULL DEFAULT 0,
    aggregated_danger       REAL    NOT NULL DEFAULT 0,

    -- рендер-конфиг (JSON)
    shader_data             TEXT,            -- { lightColor, starfield, planetsSize }

    -- превью
    thumbnail_slug          TEXT,
    thumbnail_source        TEXT
);

-- ============================================================
-- 4. Небесные тела (ЕДИНАЯ ТАБЛИЦА — иерархия через parent_id)
-- ============================================================
CREATE TABLE celestial_objects (
    id              INTEGER PRIMARY KEY,
    code            TEXT    NOT NULL UNIQUE,   -- 'STANTON.STARS.STANTON'
    name            TEXT,                      -- 'Stanton' (null для JP и станций)
    designation     TEXT,                      -- 'Stanton IV'
    description     TEXT,

    -- классификация
    type            TEXT    NOT NULL,          -- STAR | PLANET | SATELLITE | JUMPPOINT | ASTEROID_BELT | ASTEROID_FIELD | BLACKHOLE | MANMADE | POI | LZ
    appearance      TEXT,                      -- PLANET_GREEN | PLANET_BLUE | PLANET_BROWN | PLANET_GAS | DEFAULT | CUSTOM | WARNING_RED | null
    subtype_id      INTEGER,
    subtype_name    TEXT,                      -- 'Super-Earth', 'Gas Giant' (denormalized из справочника)

    -- иерархия
    star_system_id  INTEGER NOT NULL REFERENCES star_systems(id),
    parent_id       INTEGER REFERENCES celestial_objects(id),  -- null = корень системы

    -- позиция внутри системы (сферические координаты от parent)
    distance        REAL    NOT NULL DEFAULT 0,  -- AU
    latitude        REAL    NOT NULL DEFAULT 0,  -- градусы
    longitude       REAL    NOT NULL DEFAULT 0,  -- градусы

    -- физические параметры
    size            REAL    NOT NULL DEFAULT 0,  -- радиус в км (0 для JP/поясов)
    age             REAL,                         -- млрд лет
    axial_tilt      REAL,                         -- градусы
    orbit_period    REAL,                         -- дни

    -- биологические/социальные
    habitable       INTEGER,                      -- 0 | 1 | null
    fairchanceact   INTEGER,                      -- 0 | 1 | null

    -- сенсоры (строки "0"-"10" для совместимости с API)
    sensor_danger       TEXT    NOT NULL DEFAULT '0',
    sensor_economy      TEXT    NOT NULL DEFAULT '0',
    sensor_population   TEXT    NOT NULL DEFAULT '0',

    -- отображение
    show_label          INTEGER,                  -- 0 | 1 | null
    show_orbitlines     INTEGER,                  -- 0 | 1 | null

    -- рендер (JSON) — полиморфный: sun для STAR, highlight/ring для PLANET, и т.д.
    shader_data         TEXT,

    -- медиа (опционально)
    texture_slug    TEXT,
    texture_source  TEXT,
    model_slug      TEXT,                         -- DAE-модель (MANMADE)
    model_source    TEXT,

    time_modified   TEXT
);

CREATE INDEX idx_co_system ON celestial_objects(star_system_id);
CREATE INDEX idx_co_parent ON celestial_objects(parent_id);
CREATE INDEX idx_co_type   ON celestial_objects(type);

-- ============================================================
-- 5. Гиперканалы (туннели)
-- ============================================================
CREATE TABLE tunnels (
    id          INTEGER PRIMARY KEY,
    name        TEXT,                   -- 'Nyx - Stanton' (nullable)
    direction   TEXT    NOT NULL DEFAULT 'B',  -- B = bidirectional
    size        TEXT    NOT NULL DEFAULT 'M',  -- S | M | L

    -- FK на jump points
    entry_id    INTEGER NOT NULL REFERENCES celestial_objects(id),
    exit_id     INTEGER NOT NULL REFERENCES celestial_objects(id)
);

-- ============================================================
-- 6. Привязка к фракциям (M:N)
-- ============================================================
CREATE TABLE object_affiliations (
    object_id       INTEGER NOT NULL REFERENCES celestial_objects(id),
    affiliation_id  INTEGER NOT NULL REFERENCES affiliations(id),
    membership_id   INTEGER,                        -- опционально
    PRIMARY KEY (object_id, affiliation_id)
);

CREATE TABLE system_affiliations (
    system_id       INTEGER NOT NULL REFERENCES star_systems(id),
    affiliation_id  INTEGER NOT NULL REFERENCES affiliations(id),
    membership_id   INTEGER,
    PRIMARY KEY (system_id, affiliation_id)
);

-- ============================================================
-- 7. Конфиг движка (JSON-блок)
-- ============================================================
CREATE TABLE engine_config (
    id      INTEGER PRIMARY KEY DEFAULT 1,
    config  TEXT NOT NULL    -- весь JSON config целиком
);
