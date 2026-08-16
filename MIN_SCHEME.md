# MIN_SCHEME.md — минимальная схема данных движка ARK Starmap

Что движок карты **реально читает** из JSON. Основано на вычитке
`web/static/starmap/dev/deobfuscated.js` (проверено, не «по мотивам»); в каждой секции —
номера строк кода. Ориентир для «ребилдера»: генератора JSON своего сеттинга.

Схема верна для зеркала этого репозитория (замороженный бандл). Если RSI обновит
бандл — схему надо перепроверить.

---

## 1. Общая картина

Движок — webpack-бандл, data-driven. Все данные — JSON, отдаются локальным сервером.
Запросы движка (метод `getJSON`/`saveJSON` = `fetch` с JSON):

| Путь | Что | Обработчик | Строки |
|---|---|---|---|
| `POST /bootup` | всё для галактики | `cleanBootupResponse` | 42653, 42358 |
| `GET /star-systems/{CODE}` | деталь системы | `cleanSystemDetailResponse` | 43531, 42373 |
| `GET /celestial-objects/{CODE}` | деталь объекта (лениво) | `cleanCelestialBodyResponse` | 46004, 42376 |
| `POST /routes/find` | маршрут | `cleanRoutesResponse` | 42388 |
| `POST /find` | поиск | (модель поиска) | — |

Обёртка ответа (движок читает только это):
```json
{ "success": 1, "code": "OK", "msg": "OK", "data": { "resultset": [] } }
```
Для списков достаточно `data.resultset` (rowcount/pagesize и пр. движок игнорирует).

Нормализация на входе (`toSystemDetailAPI`, `toCelestialBodyAPI`, `toTunnelAPI`,
`toSystemAPI` — строки 42097–42261):
- каждый объект оборачивается в `{ raw: <исходный объект>, <поля> }` — **raw сохраняет
  ВСЁ**, что не распарсено, поэтому «лишние» поля не вредят и доступны инфо-панели;
- поэтому схема ниже = минимум, а не потолок.

---

## 2. bootup.json

`data.config` — см. секцию 5 (объект, можно `{}`).
`data.species.resultset`, `data.affiliations.resultset` — передаются как есть (42413–42417).

### Система в галактике (`systems.resultset[]`, маппер `a`, 42129–42148)

| Поле | Тип | Обязательно | Использование |
|---|---|---|---|
| `id` | int | да | ключ, родительство объектов |
| `code` | string | да | код системы, ключ URL |
| `name` | string | да | метка |
| `type` | string | нет | `SINGLE_STAR` / `BINARY` (только текст, 55603) |
| `status` | string | нет | буквенный код (`P` и т.п., 42264) |
| `position_x/y/z` | float | да | позиция в галактике (строки 43294–43296) |
| `affiliation` | array | нет | `[{code,color,name}]`, берётся `[0].code` (43292) |
| `aggregated_size/population/economy/danger` | number | нет | heatmap галактики + инфо (43291, 42143) |
| `time_modified`, `aggregated_age` | — | нет | запас |
| `description`, `thumbnail`, `info_url` | — | нет | инфо-панель через `raw` |

### Туннель (`tunnels.resultset[]`, маппер `c`, 42210–42229)

| Поле | Тип | Обязательно | Использование |
|---|---|---|---|
| `id` | int | да | |
| `size` | string | да | `S`/`M`/`L` — фильтр маршрутов (42608) |
| `direction` | string | нет | `B` (двустор.) / `U` (42226) |
| `name` | string | нет | |
| `entry_id` / `exit_id` | int | нет | |
| `entry` / `exit` | объект | да | объект типа `JUMPPOINT` (маппер `l`) |

По `entry.code` / `exit.code` движок связывает концы туннеля с объектами в системах
(`jumpPointOtherEnd`, 46073). Если у `entry/exit` нет `type` — принудительно `JUMPPOINT`
(42222–42227).

### Фракция (`affiliations.resultset[]`, 42770–42775)
Поля: `code` (строка, ключ), `color` (hex), `name`. Для каждого кода грузится
`resourcePath/sourceimages/factions/{code}.png` (42781–42783, + фиктивная `NONE`).
Отсутствие фракции → `affiliationCode = "NONE"`, цвет чёрный — не ломает ничего.

### Вид (`species.resultset[]`, 42776)
Достаточно `code` (и `name`). Используется в инфо/поиске, на рендер не влияет.

---

## 3. star-systems/{CODE}.json

`cleanSystemDetailResponse = toSystemDetailAPI(data.resultset[0])` (42373–42374).
Маппер `s` = поля системы `a` + (42149–42158):

| Поле | Тип | Обязательно | Использование |
|---|---|---|---|
| `shader_data` | объект | нет | конфиг системы (дефолты см. 45208–45212) |
| `habitable_zone_inner` / `habitable_zone_outer` | float | нет | кольцо обитаемой зоны (45197–45199) |
| `frost_line` | float | нет | кольцо снеговой линии (45201–45202) |
| `description` | string | нет | инфо |
| `celestial_objects` | **массив** | да | **плоский** список ВСЕХ объектов, связь — `id`/`parent_id` (45225–45243) |

`celestial_objects[]` — плоский список (в боевых данных `children` в этом файле НЕТ).
Движок строит дерево сам по `parent_id` (45232–45243). Каждый элемент — объект из секции 4.
`children` в этом файле игнорируется.

---

## 4. celestial-objects/{CODE}.json

`cleanCelestialBodyResponse = toCelestialBodyAPI(data.resultset[0])` (42376–42377).
Маппер `l` (42159–42209). Поля:

| Поле | Тип | Обязательно | Использование |
|---|---|---|---|
| `id` | int | да | родительство |
| `parent_id` | int/null | да | корень системы = `null` (или ссылка на звезду) |
| `code` | string | да | уникальный код (`SYSTEM.TYPE.NAME`), ключ ленивой загрузки |
| `type` | string | да | см. типы ниже; определяет рендер (45666–45695) |
| `designation` | string | нет | метка JPs, запасной label (45731) |
| `name` | string | нет | label (45731) |
| `appearance` | string | нет | `DEFAULT/PLANET_BLUE/GREEN/BROWN/GAS/CUSTOM` — выбор prefab планеты (45677) |
| `distance` | float | да | АЕ от родителя (позиция, 45924–45936) |
| `latitude` / `longitude` | float | да | град, сферические координаты (45930–45936) |
| `size` | float | да | **км** — радиус планеты/масштаб (46175–46183) |
| `sensor_population/economy/danger` | number | нет | heatmap системы (45702) |
| `habitable` | bool | нет | выбор prefab при DEFAULT (45677) |
| `show_label` | bool | нет | рисовать метку (45715) |
| `show_orbitlines` | bool | нет | орбитальное кольцо (45937) |
| `affiliation` | array | нет | `[0].code` (45703) |
| `axial_tilt` | float | нет | наклон, град (45691) |
| `orbit_period` | float | нет | запас |
| `star_system_id` | int | нет | запас |
| `texture` | объект | планете — да | `{source: <URL>}` — текстура на prefab (45972–45973) |
| `model` | объект | при `CUSTOM` — да | `{source: <URL>}` — свой .dae (45989–45990) |
| `thumbnail` | объект | нет | инфо |
| `shader_data` | объект | нет | конфиг тела (45658) |
| `star_classification`, `subtype_id`, `subtype`, `description`, `time_modified`, `age`, `population`, `fairchanceact` | — | нет | запас/инфо |

`children[]` (рекурсивно тот же маппер) — используется: `showLandingZone` берёт
детей типа `LZ` у родителя (46013–46021) и инфо-панель. Для обычного рендера тела —
не обязателен.

### Типы объектов (enum `CelestialBodyType`, 42287–42299)

| Код | Поведение рендера (45666–45695, 45804–45866) |
|---|---|
| `STAR` | SunGodRays.dae, радиус 0.1 |
| `PLANET` | prefab по `appearance`; радиус — интерполяция размеров планет системы |
| `SATELLITE` | как планета (спутник) |
| `ASTEROID_BELT` | генерируется из Asteroid*.dae вокруг родителя |
| `ASTEROID_FIELD` | AsteroidField.dae |
| `MANMADE` | SpaceStation.dae (станция) |
| `JUMPPOINT` | JumpHead/JumpTail.dae, ориентируется на другую систему |
| `LZ` | LandingZone.dae (рисуется при `showLandingZone`) |
| `BLACKHOLE` | Blackhole.dae |
| `POI` | без модели, метка-треугольник |
| `ANOMALY` | — (в enum есть, отдельного пути нет) |

Ключ по типу: `STAR=0, PLANET=1, SATELLITE=2, ASTEROID_BELT=3, ASTEROID_FIELD=4, ANOMALY=5, MANMADE=6, JUMPPOINT=7, LZ=8, BLACKHOLE=9, POI=10` — движок сопоставляет строку через словарь (42301–42300), так что подавать надо именно строковые коды.

---

## 5. config (bootup.data.config)

Необязателен: движок дополняет дефолтами (`fleshConfig`, 42812, 44185–44211).
Должен быть **объектом** (не null). Читается:
- `nearPlane`, `farPlane` — камера;
- `stars.*` — цвет/размер звёзд галактики (`useStarFading, fadeStart, pitchEffect,
  pitchIntensity, radiusFactor, maxRadius, colorD1/D2/L1/L2/C1/C2`);
- `routes[]` — три записи (S/M/L): `width, color, colorBorder, size, density, speed,
  side, center, pitchEffect, pitchIntensity, highlightFactor, depthFactor`;
- `longRangeScanner.radius`;
- `starfield.radius/count/sizeMin/sizeMax/color1/color2` (галактика и система);
- система: `lightColor` (и тот же `starfield`);
- тело (из `shader_data`): `radius`, `orbitalMin/Max/Factor`, `sun`, `ring`,
  `highlight`, `fullturn` (пояс), `orbitalColor/orbitalHighlightColor`.

`shader_data` на системе/теле аналогично необязателен (45658–45664).

---

## 6. Единицы и математика (критично)

- **`size` объектов — километры**, **`distance` — астрономические единицы (АЕ)**.
  Проверка на данных: `STANTONIVMICROTECH size=10328 dist=2.904`; 1 АЕ = 149 600 000 км.
- Галактика: `obj3d.position.set(position_x/100, position_z/100, -position_y/100)` (43294).
- Система: сферические координаты (45916–45936):
  ```
  lat = latitude° → sin/cos; lon = -longitude° → sin/cos
  position.set( cos(lat)*cos(lon)*d, sin(lat)*d, cos(lat)*sin(lon)*d )
  ```
  где `d = distance`, если родитель — звезда/корень; для спутника — пересчёт через
  размер родителя: `a = max(1, distance / (parent.size / 149600000))` (45924).
- Радиус планеты: интерполяция по `size` всех планет системы (`planetSizeMin/Max`,
  kFactor — 45284–45288, 45528–45529); звезда/ЧД — 0.1; прочие —
  `min(0.5, size * (спутник/станция 0.01 | JP 0.03 | пояс 0.1 | прочее 0.03))` (46175–46183).
- **Всегда включайте в систему `STAR`** (`parent_id=null`): он — точка отсчёта
  (`mainStar`, `sun`), без него ломается ориентирование JP (45963–45964).

---

## 7. routes/find — ответ (для движка и OfflineRouter)

Мапперы `u`/`h`/`d` (42230–42261):
`data: { leastjumps: {...}, shortest: {...} }`, каждый маршрут:
`name, label, first_jump, flight_distance, jumps, segments[]`; сегмент:
`id, name, system_id, object_id, system_code, object_code, segment_type` (`F` полёт / `J` прыжок),
`segment_distance, is_departure, is_destination`.

Локальная реализация (`OfflineRouter->route`) уже воспроизводит этот формат 1:1.

---

## 8. Минимальный пример (песочница на 2 системы)

POST `/bootup`:

```json
{ "success": 1, "code": "OK", "msg": "OK", "data": {
  "config": {},
  "systems": { "resultset": [
    { "id": 1, "code": "ALPHA", "name": "Alpha", "type": "SINGLE_STAR",
      "status": "P", "position_x": 50, "position_y": 0, "position_z": 0,
      "affiliation": [ { "code": "myfaction", "color": "#48bbd4", "name": "My Faction" } ] },
    { "id": 2, "code": "BETA", "name": "Beta", "type": "SINGLE_STAR",
      "status": "P", "position_x": -50, "position_y": 0, "position_z": 0,
      "affiliation": [] }
  ] },
  "tunnels": { "resultset": [
    { "id": 10, "size": "M", "direction": "B",
      "entry": { "id": 101, "code": "ALPHA.JUMPPOINTS.BETA", "designation": "Alpha - Beta",
                 "distance": 1.8, "latitude": -5, "longitude": 130,
                 "star_system_id": 1, "type": "JUMPPOINT" },
      "exit":  { "id": 201, "code": "BETA.JUMPPOINTS.ALPHA", "designation": "Beta - Alpha",
                 "distance": 9, "latitude": -1, "longitude": -5,
                 "star_system_id": 2, "type": "JUMPPOINT" } }
  ] },
  "species": { "resultset": [] },
  "affiliations": { "resultset": [ { "id": 1, "code": "myfaction", "color": "#48bbd4", "name": "My Faction" } ] }
} }
```

GET `/star-systems/ALPHA`:

```json
{ "success": 1, "code": "OK", "msg": "OK", "data": { "resultset": [
  { "id": 1, "code": "ALPHA", "name": "Alpha", "type": "SINGLE_STAR", "status": "P",
    "position_x": 50, "position_y": 0, "position_z": 0,
    "habitable_zone_inner": 0.5, "habitable_zone_outer": 1.2, "frost_line": 2.5,
    "celestial_objects": [
      { "id": 101, "parent_id": null, "code": "ALPHA.JUMPPOINTS.BETA",
        "name": null, "designation": "Alpha - Beta", "type": "JUMPPOINT",
        "distance": 1.8, "latitude": -5, "longitude": 130, "size": 0,
        "show_label": true, "show_orbitlines": false, "affiliation": [] },
      { "id": 102, "parent_id": 101, "code": "ALPHA.PLANET.ONE",
        "name": "One", "designation": "Alpha I", "type": "PLANET",
        "appearance": "PLANET_BLUE", "distance": 0.8, "latitude": 0, "longitude": 0,
        "size": 6000, "habitable": true, "show_label": true, "show_orbitlines": true,
        "texture": { "source": "/media/alpha/planet-one.jpg" },
        "sensor_population": 5, "sensor_economy": 5, "sensor_danger": 1,
        "affiliation": [], "children": [] }
    ] }
] } }
```

Объект планеты — один и тот же объект и в `star-systems` (плоско, с `parent_id`),
и в `celestial-objects/{CODE}` (там же, плюс опциональные `children` с LZ).

---

## 9. Чеклист перед запуском

1. В bootup есть `config` (объект), `systems.resultset` (id/code/name/position_xyz),
   `tunnels.resultset` (size, entry/exit JUMPPOINT с code/distance/latitude/longitude),
   `affiliations.resultset` (code/color/name), `species.resultset`.
2. У каждой системы есть `star-systems/{CODE}.json` с плоским `celestial_objects`
   и корректными `id`/`parent_id`; корневая звезда `type=STAR`.
3. `size` в км, `distance` в АЕ, углы в градусах.
4. Коды объектов уникальны и имеют вид `SYSTEM.TYPE.NAME` (так строятся URL ленивой загрузки).
5. `celestial-objects/{CODE}.json` существует как минимум для тел с `children`/LZ
   (для остальных грузится лениво при клике — 46004).
6. `web/rsi/static/svg/disc.svg` на месте, иначе краш `this[S]` (см. AGENTS.md).
7. Проверка: `make build && make serve` + chromium headless (команда в AGENTS.md).

## 10. Где это в коде (ориентиры)

- Нормализация и мапперы: 42097–42261; enums: 42262–42356.
- Загрузка bootup: 42653; прелоад моделей: 45001–45056.
- Сборка системы из плоского списка по parent_id: 45225–45253.
- Рендер тела (prefab по типу, текстура, метки): 45666–45749, 45916–46056.
- Радиусы/масштаб: 45284–45288, 46175–46183.
- Галактика (позиция систем, heatmap): 43291–43296.
