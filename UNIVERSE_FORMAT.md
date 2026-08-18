# UNIVERSE_FORMAT.md — Формат данных вселенной для оригинального движка ARK Starmap

Формат JSON, который движок `web/static/starmap/starmap.bundle.js` читает из API.
Запуск: `php -S 0.0.0.0:8080 server.php` — роутер отдаёт `web/index.html` + данные из `web/api/starmap/`.

## 1. Bootup: `POST /api/starmap/bootup` → `web/api/starmap/bootup.json`

Главный запрос. Движок загружает его при старте (после экрана `.launch`).

```json
{
  "success": 1,
  "code": "OK",
  "msg": "OK",
  "data": {
    "config": { },
    "systems": { "rowcount": 90, "resultset": [ ] },
    "tunnels": { "rowcount": 135, "resultset": [ ] },
    "affiliations": { "rowcount": 9, "resultset": [ ] },
    "species": { "rowcount": 9, "resultset": [ ] }
  }
}
```

### config

```json
{
  "nearPlane": 2,
  "farPlane": 12345,
  "stars": {
    "useStarFading": true,
    "fadeStart": 0.5,
    "pitchEffect": 0.31,
    "pitchIntensity": 0.70,
    "radiusFactor": 26,
    "maxRadius": 16,
    "colorD1": "#e6e600",
    "colorD2": "#ac9800",
    "colorL1": "rgb(0,255,0)",
    "colorL2": "rgb(128,255,129)",
    "colorC1": "rgb(255,0,0)",
    "colorC2": "rgb(255,128,128)"
  },
  "routes": [
    {
      "width": 0.2,
      "color": "#4a2727",
      "colorBorder": "#2f0d00",
      "size": 0.175,
      "density": 0.643,
      "speed": 1,
      "side": 0,
      "center": 0,
      "pitchEffect": 1,
      "pitchIntensity": 0.5,
      "highlightFactor": 0.15,
      "depthFactor": 0.1
    }
  ],
  "starfield": {
    "radius": 3,
    "count": 2000,
    "sizeMin": 1,
    "sizeMax": 1,
    "color1": "#7a7a7a",
    "color2": "#96b4cf"
  }
}
```

### systems.resultset[]

```json
{
  "id": 314,
  "code": "STANTON",
  "name": "Stanton",
  "description": "...",
  "type": "SINGLE_STAR",
  "position_x": 49.534718,
  "position_y": -2.6339645,
  "position_z": 16.475292,
  "status": "P",
  "affiliation": [
    { "id": 1, "code": "uee", "color": "#48bbd4", "name": "UEE" }
  ],
  "thumbnail": {
    "slug": "...",
    "source": "https://...",
    "images": { "post": "https://...", "product_thumb_large": "https://..." }
  }
}
```

**Обязательные**: `id`, `code`, `name`, `position_x/y/z`, `type`.
**thumbnail** — опционально, но без него нет превью при наведении.

### tunnels.resultset[]

```json
{
  "id": 1188,
  "direction": "B",
  "size": "M",
  "entry": {
    "id": 1689,
    "code": "STANTON.JUMPPOINTS.PYRO",
    "designation": "Stanton - Pyro",
    "distance": 1.8,
    "latitude": -5,
    "longitude": 130,
    "star_system_id": 314,
    "type": "JUMPPOINT"
  },
  "exit": {
    "id": 1740,
    "code": "PYRO.JUMPPOINTS.STANTON",
    "designation": "Pyro - Stanton",
    "distance": 9,
    "latitude": 0,
    "longitude": 0,
    "star_system_id": 340,
    "type": "JUMPPOINT"
  }
}
```

**size**: `"S"` | `"M"` | `"L"` — размер корабля для прохода.
**direction**: `"B"` (bidirectional) — туннель двусторонний.
**entry/exit.code**: формат `{SYSTEM}.JUMPPOINTS.{NAME}`.
**entry/exit.star_system_id**: `id` системы из `systems.resultset[]`.

### affiliations.resultset[]

```json
{ "id": 1, "code": "uee", "color": "#48bbd4", "name": "UEE" }
```

### species.resultset[]

```json
{ "id": 1, "code": "HUMAN", "name": "Human" }
```

---

## 2. Star System: `GET /api/starmap/star-systems/{CODE}.json`

Детали системы. Загружается при входе в систему.

```
web/api/starmap/star-systems/STANTON.json
```

### Обёртка

```json
{
  "success": 1,
  "code": "OK",
  "msg": "OK",
  "data": {
    "resultset": [ { ... } ]
  }
}
```

### resultset[0]

```json
{
  "id": 314,
  "code": "STANTON",
  "name": "Stanton",
  "type": "SINGLE_STAR",
  "position_x": 49.534718,
  "position_y": -2.6339645,
  "position_z": 16.475292,
  "habitable_zone_inner": 0.89,
  "habitable_zone_outer": 3,
  "frost_line": 4.96,
  "oort_radius": 39.5,
  "status": "P",
  "affiliation": [ { "id": 1, "code": "uee", "color": "#48bbd4", "name": "UEE" } ],
  "thumbnail": { "slug": "...", "source": "...", "images": { ... } },
  "shader_data": {
    "lightColor": "#fffaac",
    "starfield": {
      "radius": 30,
      "count": 1286.08,
      "sizeMin": 1,
      "sizeMax": 1,
      "color1": "#616060",
      "color2": "rgb(100,100,100)"
    },
    "planetsSize": {
      "min": 0.0519,
      "max": 0.0727,
      "kFactor": 0.274
    }
  },
  "celestial_objects": [ ... ]
}
```

**Ключевые поля**:
- `oort_radius` — радиус обзора камеры в системе (AU). Без него движок не знает куда zoom out.
- `shader_data.lightColor` — цвет освещения системы.
- `shader_data.starfield` — фоновое звёздное поле внутри системы.
- `shader_data.planetsSize` — min/max размер планет (коррекция пропорций).

### celestial_objects[] — Звезда

```json
{
  "id": 1691,
  "code": "STANTON.STARS.STANTON",
  "type": "STAR",
  "appearance": "DEFAULT",
  "distance": 0,
  "latitude": 0,
  "longitude": 0,
  "parent_id": null,
  "size": 1.2,
  "show_orbitlines": false,
  "show_label": true,
  "name": null,
  "designation": "Stanton",
  "shader_data": {
    "sun": {
      "color1": "#fce28f",
      "color2": "#ffffff",
      "flare1": 0.15,
      "flare2": 0.61,
      "flare3": 0.76,
      "flare4": 0.16,
      "flare5": 0.40,
      "flare6": 0.1,
      "sphere": 0.90,
      "texture": 1,
      "corona": 0.63,
      "glow": 0.5,
      "alpha": 1,
      "rotation1": 0.42,
      "rotation2": 0.74,
      "map": "2",
      "iterations": 0,
      "scaleMin": 1,
      "scaleMax": 1
    }
  },
  "subtype": { "code": "G", "description": "Yellow-White" }
}
```

**Звезда — `shader_data.sun`**: `color1` — основной цвет, `color2` — белый центр.
`corona`/`glow`/`sphere` — интенсивности эффектов. `flare1-6` — лучи.

### celestial_objects[] — Планета

```json
{
  "id": 1692,
  "code": "STANTON.PLANETS.STANTONIVMICROTECH",
  "type": "PLANET",
  "appearance": "PLANET_GREEN",
  "distance": 2.904,
  "latitude": 0,
  "longitude": -90,
  "parent_id": 1691,
  "size": 10328,
  "show_orbitlines": true,
  "show_label": true,
  "name": "microTech",
  "designation": "Stanton IV",
  "shader_data": null,
  "orbit_period": 1804.39,
  "habitable": true
}
```

**Положение**: `distance` (AU от родителя), `latitude` (°), `longitude` (°).
**parent_id**: `id` родительского объекта (звезды для планет, планеты для спутников).
**size**: размер в км (для отображения текстур).
**appearance**: определяет DAE-модель и текстуру (см. таблицу ниже).

### celestial_objects[] — Спутник

```json
{
  "id": 2737,
  "code": "STANTON.MOONS.CELLIN",
  "type": "SATELLITE",
  "appearance": "DEFAULT",
  "distance": 0.0006,
  "latitude": -10,
  "longitude": 100,
  "parent_id": 1695,
  "size": 0.67,
  "show_orbitlines": null,
  "show_label": null,
  "name": "Cellin",
  "designation": "Stanton 2a"
}
```

### celestial_objects[] — Гиперканал

```json
{
  "id": 1689,
  "code": "STANTON.JUMPPOINTS.PYRO",
  "type": "JUMPPOINT",
  "appearance": "DEFAULT",
  "distance": 1.8,
  "latitude": -5,
  "longitude": 130,
  "parent_id": null,
  "size": 0,
  "show_orbitlines": false,
  "show_label": true,
  "designation": "Stanton - Pyro"
}
```

### celestial_objects[] — Пояс астероидов

```json
{
  "id": 1705,
  "code": "STANTON.BELTS.STANTIONBAND",
  "type": "ASTEROID_BELT",
  "appearance": "DEFAULT",
  "distance": 2.3,
  "latitude": 0,
  "longitude": 0,
  "parent_id": 1691,
  "size": 20,
  "show_orbitlines": false
}
```

---

## 3. Celestial Object: `GET /api/starmap/celestial-objects/{CODE}.json`

Детали одного объекта. Загружается при клике на объект в системе.

```
web/api/starmap/celestial-objects/STANTON.PLANETS.STANTONIVMICROTECH.json
```

### Обёртка

```json
{
  "success": 1,
  "code": "OK",
  "msg": "OK",
  "data": {
    "resultset": [ { ... } ]
  }
}
```

Содержимое — тот же объект что и в `celestial_objects[]` системы.

---

## 4. Типы объектов (type)

| type | Описание | DAE-модель |
|---|---|---|
| `STAR` | Звезда | Собственный шейдер `makeStarMaterial` |
| `PLANET` | Планета | `{appearance}.dae` |
| `SATELLITE` | Спутник | `{appearance}.dae` (или DEFAULT) |
| `JUMPPOINT` | Гиперканал | `JumpHead.dae` |
| `ASTEROID_BELT` | Пояс астероидов | Визуализация движком |
| `ASTEROID_FIELD` | Поле астероидов | Визуализация движком |
| `BLACKHOLE` | Чёрная дыра | `Blackhole.dae` |
| `MANMADE` | Станция | `SpaceStation.dae` |
| `ANOMALY` | Аномалия | Спрайт |
| `LZ` | Посадочная зона | Спрайт |
| `POI` | Точка интереса | Спрайт |
| `OORT` | Облако Оорта | Спрайты/частицы |

## 5. Appearance (appearance) — DAE-модели и текстуры

| appearance | DAE-модель | Текстура |
|---|---|---|
| `PLANET_BLUE` | `Planet_Blue.dae` | `Planet_Blue512.jpg` |
| `PLANET_GREEN` | `Planet_Green.dae` | `Planet_Green512.jpg` |
| `PLANET_BROWN` | `Planet_Brown.dae` | `Planet_Brown.jpg` |
| `PLANET_GAS` | `Planet_Gas.dae` | `Planet_Gas.jpg` |
| `PLANET_DEFAULT` | `Planet_Green.dae` | `Planet_Green512.jpg` |
| `DEFAULT` | Нет (шейдер/спрайт) | — |

## 6. Кодировка системы (code)

Формат: `{SYSTEM_CODE}.{TYPE}.{NAME}`

Примеры:
- `STANTON.STARS.STANTON` — звезда
- `STANTON.PLANETS.STANTONIVMICROTECH` — планета
- `STANTON.MOONS.CELLIN` — спутник
- `STANTON.JUMPPOINTS.PYRO` — гиперканал
- `STANTON.BELTS.STANTIONBAND` — пояс астероидов

**Важно**: код туннеля в `bootup.json` (`entry.code`, `exit.code`) должен совпадать с `code` объекта в системе.

## 7. Координаты

- **Галактика**: `position_x`, `position_y`, `position_z` — 3D-координаты в пространстве галактики.
- **Внутри системы**: `distance` (AU), `latitude` (°), `longitude` (°) — сферические координаты.
- `parent_id` связывает спутники с планетами, планеты со звездой.

## 8. Сервер

```bash
php -S 0.0.0.0:8080 server.php
```

Роутер `server.php` автоматически раздаёт:
- `/` → `web/index.html`
- `/api/starmap/bootup` → `web/api/starmap/bootup.json`
- `/api/starmap/star-systems/{CODE}` → `web/api/starmap/star-systems/{CODE}.json`
- `/api/starmap/celestial-objects/{CODE}` → `web/api/starmap/celestial-objects/{CODE}.json`
- `/static/*`, `/media/*`, `/rsi/*`, `/assets/*` → `web/...`

Для обхода экранов загрузки: `localStorage.skip=1` → сразу вход на карту.

## 9. Workflow

1. Создать `web/api/starmap/bootup.json` — список систем, туннелей, фракций.
2. Для каждой системы: `web/api/starmap/star-systems/{CODE}.json` — объекты.
3. Для каждого кликабельного объекта: `web/api/starmap/celestial-objects/{CODE}.json`.
4. `php -S 0.0.0.0:8080 server.php` → открыть `http://localhost:8080/`.

---

## 10. Схема БД (UNIVERSE_SCHEMA.sql)

Вся вселенная — 6 таблиц. Иерархия небесных тел через `parent_id` в одной таблице `celestial_objects`.

### Таблицы

| Таблица | Описание | Записей |
|---|---|---|
| `affiliations` | Фракции (UEE, Banu, ...) | 6 |
| `species` | Виды разумных | 6 |
| `star_systems` | Звёздные системы | 90 |
| `celestial_objects` | **Все** небесные тела (иерархия) | ~870 |
| `tunnels` | Гиперканалы между JP | 135 |
| `engine_config` | Рендер-конфиг движка (1 строка) | 1 |

### Связи

```
affiliations ←── object_affiliations ──→ celestial_objects
affiliations ←── system_affiliations ──→ star_systems
star_systems ←── celestial_objects (star_system_id)
celestial_objects ←── celestial_objects (parent_id)  ← самосвязь
celestial_objects ←── tunnels.entry_id / exit_id
```

### Иерархия небесных тел

```
SYSTEM (star_systems)
  └── STAR (parent_id = null, star_system_id = X)
  └── PLANET (parent_id = STAR.id)
        └── SATELLITE (parent_id = PLANET.id)
  └── JUMPPOINT (parent_id = null)
  └── ASTEROID_BELT (parent_id = STAR.id)
  └── BLACKHOLE (parent_id = null)  ← редко
```

Для BINARY-систем: два STAR с `parent_id = null`.

### Генерация JSON из БД

#### bootup.json

```sql
-- systems
SELECT s.*, json_group_array(
  json_object('id', a.id, 'code', a.code, 'name', a.name, 'color', a.color)
) as affiliation
FROM star_systems s
LEFT JOIN system_affiliations sa ON sa.system_id = s.id
LEFT JOIN affiliations a ON a.id = sa.affiliation_id
GROUP BY s.id;

-- tunnels
SELECT t.*,
  json_object('id', ej.id, 'code', ej.code, ...) as entry,
  json_object('id', ex.id, 'code', ex.code, ...) as exit
FROM tunnels t
JOIN celestial_objects ej ON ej.id = t.entry_id
JOIN celestial_objects ex ON ex.id = t.exit_id;
```

#### star-systems/{CODE}.json

```sql
SELECT s.*,
  (SELECT json_group_array(co.*) FROM celestial_objects co
   WHERE co.star_system_id = s.id) as celestial_objects
FROM star_systems s WHERE s.code = ?;
```

#### celestial-objects/{CODE}.json

```sql
SELECT co.*,
  (SELECT json_group_array(child.*) FROM celestial_objects child
   WHERE child.parent_id = co.id) as children
FROM celestial_objects co WHERE co.code = ?;
```

### Ключевые решения

1. **Единая таблица `celestial_objects`** — все типы (STAR, PLANET, SATELLITE, ...) в одной таблице. `type`区分яет, `shader_data` (JSON) — полиморфный для разных типов.

2. **`shader_data` как JSON-столбец** — разная структура для STAR (sun.color1, flare...), PLANET (highlight, ring), ASTEROID_BELT (orbital). Хранить как TEXT, парсить при генерации.

3. **Сенсоры — строки** (`'0'`..`'10'`), не числа — для совместимости с форматом API.

4. **`parent_id` → `id` самосвязь** — произвольная глубина вложенности (STAR→PLANET→SATELLITE).

5. **M:N привязки** — `object_affiliations` и `system_affiliations` для-many-to-many фракций.

6. **`tunnels`** — отдельная таблица, FK на два JP. Entry/exit подзапросами генерируются в JSON.

7. **`engine_config`** — одна запись, весь JSON конфига. Один UPDATE для настройки.
