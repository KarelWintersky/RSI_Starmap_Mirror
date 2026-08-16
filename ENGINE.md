# Как устроен движок ARK Starmap (ENGINE.md)

Технический разбор оригинального движка карты Star Citizen и нашего офлайн-зеркала.
Написано с прицелом на то, чтобы **переиспользовать этот движок для своей 3D-карты
своего сеттинга** (галактика/миры/звёздные системы, туннели/прыжки, маршруты, поиск).

Всё ниже проверено на реальных данных (дампы API) и на самом `starmap.bundle.js` —
это не «по мотивам», а выписки из кода движка.

---

## 1. Общая картина

Система состоит из трёх слоёв:

```
боевой сайт (robertsspaceindustries.com)
   │  POST /api/starmap/* (JSON), CDN /static/starmap/*
   ▼
data/   — сырые JSON, скачанные граббером (источник истины)
   ▼  (build)
web/    — офлайн-зеркало: страница + static + api/starmap/* + media/*
   ▼
server.php — роутер php -S, отдаёт зеркало и отвечает на локальный API
```

Ключевой факт: **движок (весь рендер и логика) — это один JS-файл
`starmap.bundle.js`**. Он ничего не «знает» про сайт: данные он получает по
`POST /api/starmap/*`, а ассеты грузит по `resourcePath`. Поэтому его можно
пересадить на любые данные, сохранив формат.

---

## 2. Как движок стартует

Страница должна содержать:

```html
<div id="starmap-application"></div>
<script src="/static/starmap/fontdetect.min.js"></script>
<script src="/static/starmap/starmap.bundle.js"></script>
```

В самом бандле при `DOM ready` создаётся приложение:

```js
new s.default({
    el: "#starmap-application",
    resourcePath: "/static/starmap",   // базовый путь к моделям/звукам/текстурам
    apiBaseURL: ""                     // базовый путь к API ("" = same-origin)
});
```

- `resourcePath` — префикс для `resourcePath + "/models/Planet_Blue.dae"`,
  `"/sounds/..."`, `"/sourceimages/..."` и т.д.
- `apiBaseURL` — префикс для `apiBaseURL + "/api/starmap/bootup"` и т.д.

Оба пути относительные — потому зеркало работает с локального сервера без правок.
Бандл мы дополнительно пропатчили: `https://cdn.robertsspaceindustries.com/static/starmap`
заменён на `/static/starmap`.

---

## 3. Поток данных

Движок ходит на API последовательно:

1. `POST /api/starmap/bootup` — галактика целиком:
   - `data.systems.resultset` — все звёздные системы: `code`, `name`, `type`
     (`SINGLE_STAR`/`BINARY`), позиция `position_x/y/z`, `affiliation`, `thumbnail`,
     агрегированные `aggregated_size/population/economy/danger`;
   - `data.tunnels.resultset` — все прыжковые туннели (см. §6);
   - `data.affiliations.resultset` — фракции (`id`, `code`, `color`, `name`);
   - `data.species.resultset` — виды;
   - `data.config` — параметры рендера (см. §7).
2. При входе в систему: `POST /api/starmap/star-systems/{CODE}` →
   детали системы, включая `celestial_objects` — **объекты верхнего уровня**,
   у которых `parent_id === null`.
3. Для каждого верхнего объекта: `POST /api/starmap/celestial-objects/{CODE}` →
   полный объект с **деревом** `children` (рекурсивно: планеты → луны → и т.д.).
   В объекте живут координаты, размеры, appearance, текстуры/модели, media.

Дополнительно по требованию UI:

- `POST /api/starmap/find` `{"query": "..."}` — поиск по системам и объектам;
- `POST /api/starmap/routes/find` — построение маршрута (§6);
- `POST /api/starmap/bookmarks/find` — закладки (нужна авторизация).

### 3.1 Откуда на самом деле читается описание объекта

Одно и то же описание (например Меркурия) лежит в **трёх** файлах, текст
идентичен (проверено по всем 862 объектам — расхождений 0):

1. `star-systems/{CODE}.json` → `data.resultset[0].celestial_objects[]` —
   **плоский список ВСЕХ объектов системы** (не только верхний уровень:
   у каждого `parent_id`, в том числе луны/станции/LZ). В Солнце таких 40,
   по всей вселенной — 865.
2. `celestial-objects/{CODE}.json` → `data.resultset[0]` — собственный файл
   объекта с деревом `children` (луны/станции/LZ именно этого объекта;
   у Земли, например, `children` = Луна + 3 LZ).
3. `celestial-objects/{ROOT}.json` → `children[]` — для объектов верхнего
   уровня описание дублируется ещё и в файле родителя: все 9 планет Солнца
   лежат и в `SOL.STARS.SOL.json`. Глубина дерева там — только прямые дети
   звезды, лун в нём нет.

Что реально читает движок (по коду `starmap.bundle.js`):

- При входе в систему (`System.loadDetail()`) грузится **только**
  `star-systems/{CODE}.json`. Из его `celestial_objects` строятся все тела
  системы; у каждого тела `info = celestial_objects[i]` (маппер `l()` в
  бандле сохраняет `description`, `raw`, координаты, текстуры).
- Инфо-панель берёт данные через `getBodyInfo(system, code)`:
  `body.data.detail || body.info`.
  - `body.info` — запись из `star-systems/{CODE}.json`;
  - `body.data.detail` — результат `Body.loadDetail()`, который ходит на
    `celestial-objects/{CODE}.json` и **кэширует** ответ в `data.detail`.
- `Body.loadDetail()` вызывается только из `showLandingZone()`, а её дёргает
  состояние `StateCelestialBody` при входе в планетарный вид (левый клик по
  объекту → zoom в планету). То есть **до зума описание читается из
  star-systems-файла, после зума — из собственного файла объекта** (текст тот
  же, загружается один раз на сессию).
- Файл верхнего объекта (`celestial-objects/{ROOT}.json`) движок грузит лишь
  когда зумишь в сам верхний объект (например в звезду). Описания из его
  `children` панель **не использует** — там движку нужны только `children`
  типа `LZ` (`showLandingZone()` расставляет посадочные зоны).

Вывод по избыточности:

- **Минимум, без которого движок не строит систему вообще** —
  `star-systems/{CODE}.json` (сама система + все её объекты с описаниями,
  координатами, текстурами).
- `celestial-objects/{CODE}.json` нужен только **лениво** (вход в планетарный
  вид / LZ-зоны). Для галактической/системной карты его можно не отдавать —
  панель молча покажет описание из `info`.
- Дубли в `children[]` файла родителя — мёртвые: панель их не читает. Для
  своего сеттинга достаточно двух мест: запись объекта в star-system (инфо до
  зума) и собственный файл объекта (планетарный вид).

Побочный факт: у 3 объектов (`HADUR.JUMPPOINTS.AYR'KA`, `INDRA.JUMPPOINTS.AYR'KA`,
`TOHIL,PLANETS.TOHILII`) и 2 систем (`AYR'KA`, `EL'SIN`) файлы-зеркала пустые
(`resultset: []`) — апостроф/запятая в коде ломают запрос к боевому API, так что
граббер сохранил пустой ответ. Записи в star-systems-файлах при этом есть.

---

## 4. Координаты и математика

### 4.1 Позиция системы в галактике

Системы задаются плоскими координатами `position_x/y/z` (в bootup и в деталях
системы). Движок помещает систему так:

```js
obj3d.position.set(position_x / 100, position_z / 100, -position_y / 100)
```

То есть оси переставлены: `x → x`, `z → y`, `y → -z` (y-up), и всё делится на 100.

### 4.2 Позиция объекта внутри системы (сферические координаты)

Каждый объект имеет `distance` (АЕ), `latitude`, `longitude` (градусы).
Движок переводит их в декартовы координаты (из кода бандла):

```js
lat = latitude  * Math.PI / 180
lon = -longitude * Math.PI / 180      // долгота БЕРЁТСЯ С МИНУСОМ
x = distance * cos(lat) * cos(lon)
y = distance * sin(lat)
z = distance * cos(lat) * sin(lon)   // из-за минуса фактически z = -d·cos(lat)·sin(lon)
```

Или в формулах (lat/lon в градусах):

```
x = d · cos(lat) · cos(lon)
y = d · sin(lat)
z = −d · cos(lat) · sin(lon)
```

> Замечание для тех, кто будет пересчитывать: знак `z` на расстояния не влияет
> (там сумма квадратов), поэтому наш локальный роутер использует формулу без
> минуса — совпадает с боевым API до 10-го знака. Для рендера важно то, что выше.

### 4.3 Масштабы и единицы

- `size` объекта — радиус в **километрах**.
- `distance` объекта — в **астрономических единицах**. Движок нормализует
  расстояние относительно размера родителя:

  ```
  AU = 1.496e8 км  (в коде: parent.size / 1496e5)
  a = max(1, distance / (parent.size / 1.496e8))
  ```

- Радиус рендера (из кода бандла):

  ```
  if (isStar || isBlackHole)            radius = 0.1
  else if (isPlanet)                    radius = computePlanetRadius(size)  // см. ниже
  else                                  radius = min(0.5, size * coeff)
      coeff = 0.01  — луна / станция
              0.03  — джамп-поинт
              0.1   — пояс астероидов
              0.03  — всё остальное
  ```

- Радиус планеты нормализуется по экстремумам размеров планет **этой системы**:

  ```
  n = (size - minSize) / (maxSize - minSize)
  radius = lerp(planetsSize.min, planetsSize.max, n^kFactor)
  // по умолчанию planetsSize = {min: 0.03, max: 0.03, kFactor: 1}
  ```

  minSize/maxSize вычисляются автоматически по всем `size` планет системы.

---

## 5. Рендер объектов

### 5.1 Типы объектов

Enum движка: `STAR=0, PLANET=1, SATELLITE=2, ASTEROID_BELT=3, ASTEROID_FIELD=4,
ANOMALY=5, MANMADE=6, JUMPPOINT=7, LZ=8, BLACKHOLE=9, POI=10`.
Типы систем: `SINGLE_STAR=0, BINARY=1`.

### 5.2 Внешний вид (appearance)

У объекта есть поле `appearance`. Enum:
`DEFAULT, PLANET_BLUE, PLANET_GREEN, PLANET_BROWN, PLANET_GAS, CUSTOM`.

- Не-`CUSTOM` — движок берёт **готовый префаб-модель** из
  `resourcePath + "/models/..."` (`Planet_Blue.dae`, `Planet_Green.dae`,
  `Planet_Brown.dae`, `Planet_Gas.dae`, `SpaceStation.dae`, `Asteroids*.dae`,
  `JumpHead.dae`/`JumpTail.dae`/`JumpGoTrhu.dae` и т.д. — всего 22 модели).
- `CUSTOM` — грузит собственную геометрию из `model.source` (файл `.dae`)
  и текстуру из `texture.source` (оба — абсолютные URL в API).

В системе один общий пул префабов (`planetBluePrefab` и т.д.) — они загружаются
один раз при старте системы.

### 5.3 Текстуры

- Планеты с `appearance != CUSTOM` используют **общую текстуру-картинку**
  из `media[].images.texture` этого объекта (это и есть «рисунок планеты»).
- Звёзды раскрашиваются через `shader_data.sun` (цвета и параметры вспышек —
  см. ниже).
- Надписи, выделение, орбиты, кольца (`show_orbitlines`, `show_label`,
  `axial_tilt`, `orbit_period`) — управляются полями объекта.

### 5.4 Цвет звёзд и туманности

Каждая звезда имеет `shader_data`, например:

```json
"shader_data": {
  "sun": {
    "color1": "#fce28f", "color2": "#ffffff",
    "flare1": 0.15, "flare2": 0.6, "flare3": 0.76,
    "sphere": 0.9, "texture": 1, "corona": 0.63, "glow": 0.5, ...
  },
  "orbitalMin": 1, "orbitalMax": 5, ...
}
```

Меняя `color1/color2` — получаете разные по цвету звёзды/системы на карте.

---

## 6. Туннели и маршруты

### 6.1 Туннели (bootup)

```json
{
  "id": 1188,
  "direction": "B",            // B = bidirectional, U = unidirectional
  "entry_id": 1689, "exit_id": 1740,
  "size": "M",                 // S / M / L
  "entry":  { "id":..., "code":"STANTON.JUMPPOINTS.PYRO", "designation":"Stanton - Pyro",
              "distance":1.8, "latitude":-5, "longitude":130,
              "type":"JUMPPOINT", "star_system_id":314 },
  "exit":   { ... }
}
```

Туннель соединяет два джамп-поинта, каждый принадлежит своей системе.
Размер корабля (`S/M/L`) должен соответствовать размеру туннеля (L-корабль
летит только по L-туннелям).

### 6.2 Ответ routes/find

```json
{
  "data": {
    "shortest": {
      "name": "Stanton to Sol",
      "label": "Through Magnus",
      "first_jump": "Stanton - Magnus",
      "flight_distance": 53.6558,
      "jumps": 5,
      "segments": [
        { "id": "STANTON", "name": "Stanton", "type": "system",
          "system_id": 314, "system_code": "STANTON",
          "object_id": null, "object_code": null,
          "segment_type": "F",          // F = перелёт внутри системы, J = прыжок
          "segment_distance": 0,
          "is_departure": 1, "is_destination": 0 },
        { "id": 1690, "name": "Stanton - Magnus", "type": "jump",
          "system_id": 314, "system_code": "STANTON",
          "object_id": 1690, "object_code": "STANTON.JUMPPOINTS.MAGNUS",
          "segment_type": "J", "segment_distance": ..., ... }
      ]
    },
    "leastjumps": { ... }
  }
}
```

Движок вызывает `/routes/find` с `{departure, destination, ship_size}`.
`segment_distance` вычисляется из сферических координат джамп-поинтов —
это чистая геометрия, поэтому наш локальный роутер даёт те же числа, что и боевой API.

### 6.3 Наш локальный роутер (src/OfflineRouter.php)

- Граф: вершины = системы + джамп-поинты; рёбра = туннели (фильтр по размеру корабля).
- Вес ребра = евклидова дистанция между джамп-поинтами двух систем (в «АЕ-масштабе»).
- `shortest` — Дейкстра по суммарной дистанции, `leastjumps` — по числу прыжков.
- Внутри систем добавляются сегменты «F» (от системы до её джамп-поинта).

---

## 7. Конфигурация рендера (bootup.config)

Берётся из `bootup` и меняет картинку целиком — удобно для своего сеттинга:

```json
{
  "nearPlane": 2, "farPlane": 12345,
  "stars": { "radiusFactor": 26, "colorD1": "#e6e600", ... },      // вид звёзд-систем
  "routes": [ { "width": 0.2, "color": "#4a2727", "density": 0.64, ... }, ... ], // линии маршрутов
  "starfield": { "radius": 3, "count": 2000, "color1": "#7a7a7a", ... },        // фон-звёзды
  "longRangeScanner": { "radius": 1, "colorD1": "#9be80d", ... }
}
```

Можно менять цвета, плотность, размеры — и получать другой «жанр» карты
без правки движка.

---

## 8. Наше зеркало

### 8.1 Структура

| Путь | Содержимое |
|---|---|
| `data/bootup.json` | полный ответ `/bootup` (90 систем, 135 туннелей, фракции, виды, config) |
| `data/star-systems/{CODE}.json` | ответ `star-systems/{CODE}` |
| `data/celestial-objects/{CODE}.json` | ответ `celestial-objects/{CODE}` (с деревом `children`) |
| `data/urlindex.json` | список media-URL по категориям (texture/model/thumbnail/post/...) |
| `data/page_starmap.html` | исходная страница `/en/starmap` |
| `web/index.html` | офлайн-страница (почищена от аналитики, пути → локальные) |
| `web/static/starmap/` | `starmap.bundle.js` (пропатчен), `main.css`, модели, звуки, ui-images |
| `web/static/fonts/` | шрифты Electrolize/Orbitron |
| `web/api/starmap/` | JSON-зеркало API + `_index.json` для поиска |
| `web/media/` | текстуры планет, превью, галерея |
| `web/assets/fonts.css` | `@font-face` для страницы |

### 8.2 Что делает build

- `web/index.html` — из `page_starmap.html` вырезаны внешние скрипты
  (cookiebot, analytics, платформенная шапка), CDN-пути → локальные.
- `starmap.bundle.js` — `resourcePath` прописан в бандле как `/static/starmap`;
  оставшиеся `https://cdn.robertsspaceindustries.com/static/starmap` → `/static/starmap`.
- `main.css` — ссылки на `cdn.robertsspaceindustries.com/static/starmap/` → `/static/starmap/`.
- JSON в `web/api/starmap/*` — все медиа-URL
  `https://robertsspaceindustries.com/media/...` → `/media/...`.
- `_index.json` — сжатый индекс (системы + объекты) для локального поиска.

### 8.3 Что отвечает server.php (роутер php -S)

- `GET /`, `/en/starmap`, `/index.html` → `web/index.html`.
- `GET` по `/static/*`, `/media/*`, `/rsi/*`, `/assets/*` → файлы зеркала
  (MIME по расширению).
- `GET /api/starmap/*.json` и `POST /api/starmap/{bootup|star-systems/..|celestial-objects/..}`
  → готовые JSON из `web/api/starmap/`.
- `POST /api/starmap/routes/find` → `OfflineRouter` (локальный Дейкстра).
- `POST /api/starmap/find` → `StarmapLocalSearch` (по `_index.json`).
- `POST /api/starmap/bookmarks/*` → заглушка `ErrNotAuthenticated`.

---

## 9. Как сделать СВОЮ карту на этом движке

### Вариант А (проще): свои данные в том же формате

Движку всё равно, откуда данные. Меняем только данные:

1. Сгенерируйте свои JSON точно в формате API:
   - `bootup.json` (обязательно: `data.systems.resultset`, `data.tunnels.resultset`,
     `data.affiliations.resultset`, `data.config`);
   - по файлу на систему и на объект (можно упрощённо: если отдавать только
     системы и объекты верхнего уровня с `children`, движок построит дерево сам);
2. Положите в `web/api/starmap/` (то же место, куда кладёт `build`);
3. Если есть свои модели/текстуры — положите в `web/static/starmap/models/`
   и дайте объектам `appearance: "CUSTOM"` + `model.source`/`texture.source`
   (URL, на который будет смотреть движок);
4. Запустите `php grab.php serve`.

Минимальные поля, которые реально использует движок:

| Где | Поля |
|---|---|
| система (bootup) | `id`, `code`, `name`, `type` (`SINGLE_STAR`/`BINARY`), `position_x/y/z`, `affiliation[]` (`code`, `color`, `name`), `thumbnail` |
| объект | `id`, `code`, `name`, `designation`, `type`, `appearance`, `size` (км), `distance` (АЕ), `latitude`, `longitude`, `parent_id`, `show_label`, `show_orbitlines`, `shader_data.sun` (для звёзд), `media[]` с `images.texture` |
| туннель | `id`, `entry_id`, `exit_id`, `size` (S/M/L), `direction` (B/U), `entry`/`exit` (объекты-джамп-поинты с `distance/latitude/longitude`) |

Если поля нет — движок подставляет значения по умолчанию, но карта будет
«голой» (нет подписей, нет текстур).

### Вариант Б (продвинутый): свой сервер API

Реализуйте свои эндпоинты `/api/starmap/{bootup, star-systems/*,
celestial-objects/*, find, routes/find}` — движок не знает разницы.
Это удобно, если карта должна жить «вживую» (режим в системе, динамические
позиции) — достаточно, чтобы ваш бэкенд отдавал правильные JSON на те же пути.

### Практические советы для своего сеттинга

1. **Планируйте координаты заранее.** Галактика — плоская `position_x/y/z`
   (делится на 100). Системы — сферические `distance/latitude/longitude`.
   Держите `distance` в «АЕ»-масштабе относительно размера родителя.
2. **Размеры — в километрах.** `size` планеты влияет и на радиус, и на
   нормализацию расстояний. Крошечные луны и гигантские газовики дают красивый
   контраст автоматически.
3. **Используйте префабы.** `appearance: "PLANET_BLUE/GREEN/BROWN/GAS"` —
   готовые модели из бандла. `CUSTOM` нужен только для уникальных объектов.
4. **Текстуры планет** — обычные картинки (`images.texture`), их легко
   сгенерировать. Звёзды — `shader_data.sun` (`color1/color2`).
5. **Маршруты считаются автоматически.** Граф из ваших туннелей + Дейкстра —
   движок и наш `OfflineRouter` сделают всё сами. Фильтр по `ship_size`
   встроен.
6. **Настройте `config`** (цвета звёзд, линии маршрутов, фон-звёзды) — меняет
   атмосферу карты без кода.

---

## 10. Ограничения и зацепки

- `bookmarks/*` требует авторизации — локально заглушка.
- Бандл — минифицированный код движка; менять логику можно, но читать тяжело.
- Внешние вызовы вне `/api/starmap` и `resourcePath` (аналитика googletagmanager,
  телеметрия cloudimperiumgames) офлайн молча падают — на карту не влияют.
- Для полноценного «живого» режима (движение объектов, время) данных из статики
  мало — но для статичной карты сеттинга их достаточно.
