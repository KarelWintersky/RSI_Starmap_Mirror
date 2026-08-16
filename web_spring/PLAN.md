# PLAN.md — SpringGalaxy (web_spring)

Новый движок карты, пишется с нуля на свежем three.js. Наследует идеи и формулы
движка ARK Starmap (см. `../ENGINE.md`), но код полностью свой, читаемый.
Рабочий документ: дорабатывается по ходу разработки, это основа плана, а не застывший ТЗ.

## Цели

- Своя 3D-карта галактики с трёхуровневым переходом:
  1. **2D-схема** — плоская карта звёзд (верхний уровень);
  2. **3D-галактика** — пространство с вращением, звёзды кликабельны;
  3. **звёздная система** — солнце в фокусе, планеты/объекты, zoom-out ограничен облаком Оорта.
- Оптимизированные данные: на систему — ОДИН файл со всей информацией
  (`celestial-objects/*` из ARK не нужны вовсе). JSON до ~100 КБ — это норма.
- Вся «красота» (шейдеры, эффекты) переиспользуется из оригинального бандла:
  шейдеры там — открытый GLSL-текст, модели `.dae` уже скачаны в `../web/static/starmap/models/`.
- Движок должен легко пересаживаться на реальные данные Star Citizen
  (конвертер: `data/` → формат SpringGalaxy) и на любые свои сеттинги.

## Стек и окружение

- three.js **0.185.1** (ESM), забендорен локально в `three/` (офлайн, без CDN).
  Установка: `npm install three@0.185.1`; вендор: `three/three.module.js`
  (+ обязательный `three/three.core.js` — r185 `three.module.js` импортирует его!),
  `three/OrbitControls.js`, `three/loaders/{ColladaLoader,TGALoader}.js`,
  `three/loaders/collada/*`.
- Чистые ES-модули, без сборщика: `<script type="module">` + import map
  (`"three": "/three/three.module.js"`).
- PHP (CLI + `php -S`) — только генератор данных и локальный сервер.
- `node_modules/` в git НЕ идёт; `three/` идёт (движок работает офлайн).

## Структура web_spring/

```
web_spring/
  PLAN.md                 этот план
  index.html              страница приложения (import map + HUD)
  server.php              роутер php -S (отдаёт страницу, js/, three/, api/)
  make_data.php           генератор тестового датасета (все типы объектов)
  api/starmap/            данные (генерятся, в git не идут)
    bootup.json           системы, туннели, фракции, конфиг
    star-systems/{CODE}.json   ВСЯ инфа по системе (один файл, без celestial-objects)
  three/                  забендоренный three.js + OrbitControls + ColladaLoader
  js/                     движок
    main.js               вход: создаёт App
    data.js               DataStore: загрузка, дерево объектов (parent_id)
    coords.js             координаты/масштабы (формулы из ENGINE.md)
    effects.js            текстуры-глоу, шейдеры, орбиты, звёздное поле
    states.js             state-машина: State2D / State3D / StateSystem
    app.js                рендерер, сцена, камера, controls, цикл
  media/                  текстуры планет тестового датасета (копии)
  vendor.js               (не нужен — import map решает)
```

## Данные

### bootup.json (формат как у боевого API, чтобы конвертер реальных данных был тривиальным)

```
data.systems.resultset[]    id, code, name, type(SINGLE_STAR/BINARY), position_x/y/z,
                            affiliation[] (code,color,name), thumbnail, aggregated_*
data.tunnels.resultset[]    id, direction(B/U), entry_id, exit_id, size(S/M/L), entry, exit
data.affiliations.resultset[]
data.species.resultset[]
data.config                 параметры рендера (цвета, масштабы)
```

### star-systems/{CODE}.json — ВСЯ инфа по системе

```
data.resultset[0]:
  id, code, name, type, description, position_x/y/z,
  habitable_zone_inner/outer, frost_line,
  oort_radius                НОВОЕ: радиус облака Оорта в АЕ, лимит zoom-out в системе
  affiliation[]
  celestial_objects[]        плоский список ВСЕХ объектов, дерево — по parent_id
```

Поля объекта: `id, code, name, designation, type, appearance, size(км), distance(АЕ),
latitude, longitude, parent_id, show_label, show_orbitlines, shader_data,
texture{source}, affiliation[]`, а также `oort_radius` для типа OORT.

### Типы небесных объектов (enum движка)

```
STAR=0 PLANET=1 SATELLITE=2 ASTEROID_BELT=3 ASTEROID_FIELD=4 ANOMALY=5 MANMADE=6
JUMPPOINT=7 LZ=8 BLACKHOLE=9 POI=10  +  OORT=11  (НЕ существовал в ARK)
```

- **OORT** — облако Оорта: объект системы, задаёт радиус внешней границы
  (максимальный zoom-out в системном виде). Рендерится как полупрозрачная
  сфера частиц на радиусе `oort_radius`; `camera.maxDistance` = `oort_radius * k`.

### Координаты и масштабы (унаследованы из ENGINE.md, §4)

- Система: `obj.position.set(x/100, z/100, -y/100)` (y-up).
- Объект (сферические → декартовы):
  `lat = latitude·π/180; lon = −longitude·π/180;`
  `x = d·cos(lat)·cos(lon); y = d·sin(lat); z = −d·cos(lat)·sin(lon)`
  (знак z для рендера важен, для дистанций нет).
- Масштаб системы: 1 АЕ = 1 мировая единица; `oort_radius` — внешняя граница.
- Радиус тела: `size` в км → мировая нормализация (по экстремумам системы,
  как в ARK: `lerp(minR, maxR, n^k)`), звёзды/ЧД/джамп-поинты — фиксированные.

## Архитектура движка

- **Один renderer + сцена + камера + OrbitControls** (damping). Переходы между
  уровнями — анимация `controls.target` и позиции камеры (lerp/damp), не смена сцен.
- **State-машина**:
  - `State2D`: камера сверху (ортографическая перспектива), системы — спрайты на плоскости.
    Двойной клик по системе → перелёт в 3D-галактику.
  - `State3D`: свободное вращение (OrbitControls). Звёзды — глоу-спрайты.
    Клик по звезде → вход в систему.
  - `StateSystem`: фокус на солнце. Клик по объекту → камера летит к нему.
    Двойной клик по пустоте → zoom-out (в пределах OORT).
- **Клик/двойной клик**: нативный `dblclick` (браузер сам детектит двойной клик) +
  таймер ~250 мс для одиночного. Не классификатор с ручным оконном (не дружит
  с задержками/эмуляцией) и не чтение `e.detail` из pointerup (у PointerEvent
  detail всегда 0; у mouseup/dblclick — 2).
- **Raycasting**: спрайты систем (2D/3D) и меши объектов (системный вид).
  ВАЖНО: данные хита лежат в `hit.object.userData` (в intersection-объекте
  в r185 поля `userData` НЕТ!).
- **HUD**: DOM-оверлей — имя выбранного объекта, подсказки уровня, hint-текст.

## Рендер (фаза 1 — процедурный, «чисто на three.js»)

- Звёзды: сфера + эмиссивный материал + глоу-спрайт (canvas-градиент), цвет из `shader_data.sun.color1/color2`.
- Планеты/луны: сфера + текстура (`texture.source`) или базовый материал; атмосфера — внешняя сфера с френель-шейдером (additive).
- Чёрная дыра: тёмная сфера + аккреционный диск (RingGeometry + additive шейдер) + глоу.
- Гиперканал (JUMPPOINT): билборд-кольцо (аддитивный шейдер с вращением).
- Пояса/поля астероидов: `THREE.Points` (кольцо/облако), прозрачность.
- Аномалия: пульсирующий спрайт. MANMADE/LZ/POI: маркеры (примитивы + иконки).
- OORT: полупрозрачная сфера частиц на `oort_radius`.
- Орбиты: `THREE.LineLoop`-окружности, подпись — спрайт с canvas-текстурой.
- Звёздное поле фона: `THREE.Points` на дальнем кубе.

## Этапы (дорожная карта)

- **[done] Фаза 0 — каркас**: three.js vendored, план, генератор данных, сервер,
  вертикальный срез: 2D → 3D → система, все типы объектов + OORT.
  Проверено headless chromium (CDP): инициализация, двойной клик по звезде → 3D,
  клик по звезде → система Alpha, двойной клик по пустоте → zoom out (лимит OORT),
  без ошибок в консоли; `make lint` зелёный.
- **Фаза 1 — реальные данные**: конвертер `data/` (боевые bootup + star-systems)
  в формат SpringGalaxy: 90 систем, 135 туннелей, фракции, config.
  Текстуры планет — из `../web/media/` (симлинк или копия). Проверка: полёт по Солу.
- **Фаза 2 — красота**: перенос оригинальных GLSL-шейдеров (звёзды, джамп-поинты,
  гиперканал-туннель `DustGoTrhu`, атмосферы), модели `.dae` через ColladaLoader,
  эффект входа в систему (пылевой туннель), god rays, анимации времени.
- **Фаза 3 — интерактив**: инфо-панели, поиск (`_index.json`), маршруты
  (порт `OfflineRouter` на JS или переиспользование `/api/starmap/routes/find`),
  закладки, цвета фракций, long-range scanner.
- **Фаза 4 — полировка**: постпроцессинг (bloom), звуки, тайминги переходов,
  производительность (LOD, instancing), мобильная вёрстка HUD.

## Конвенции

- Код — чистые ES-модули, без сборщика, без зависимостей кроме three.
- Комментарии в коде — по делу, без воды (агентский стиль: что, а не как).
- Новая функциональность добавляется фазами; каждая фаза заканчивается
  работающей версией (проверка: headless chromium + `php -l`).

## Запуск

```
php web_spring/make_data.php      # сгенерировать тестовые данные
php -S 0.0.0.0:8090 web_spring/server.php
# → http://localhost:8090/
```
