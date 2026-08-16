# AGENTS.md — ARK Starmap (офлайн-зеркало Star Citizen)

Технический справочник репозитория. Не часть проекта — файл для агента.

## Overview

Граббер интерактивной 3D-карты вселенной Star Citizen (`robertsspaceindustries.com/en/starmap`)
и локальное офлайн-зеркало, где оригинальный JS-движок карты работает без интернета.

- Чистый PHP **8.2** (CLI). Нет `composer.json`, нет `vendor/`, автозагрузка — ручными `require` в `grab.php` и `server.php`. Расширения: `curl`, `json`, `mbstring`.
- Два процесса: **граббер** (скачивает данные) и **сервер** (`php -S` + `server.php`, раздаёт зеркало).
- Data flow: боевой сайт → `data/` (сырые JSON, источник истины) → `web/` (офлайн-зеркало).
- Поток данных и устройство движка описаны подробно в `ENGINE.md`; история UX-патчей — в `PATCH_FOR_DCLICK.md`.

## Структура

```
grab.php                  CLI-вход (все команды сборки)
server.php                роутер локального сервера (php -S ... server.php)
src/
  Util.php                константы путей/URL, хелперы (media-URL, rewrite, JSON)
  HttpClient.php          параллельный HTTP-клиент на curl_multi
  StarmapGrabber.php      граббер: fetch/index/assets/media/build (+ patchBundle)
  OfflineRouter.php       локальный /api/starmap/routes/find (Dijkstra)
  StarmapLocalSearch.php  локальный /api/starmap/find (поиск по _index.json)
data/                     сырые JSON с API (игнорируется git)
  bootup.json             90 систем, 135 туннелей, фракции, виды
  star-systems/           90 деталей систем + объекты верхнего уровня
  celestial-objects/      865 деталей объектов
  urlindex.json           список media-URL по категориям
  page_starmap.html       сырая страница /en/starmap (исходник для web/index.html)
web/                      офлайн-зеркало (запускается), частично в git (см. .gitignore)
  index.html              страница приложения (собирается из page_starmap.html)
  index_dev.html          dev-страница: грузит распакованный бандл (ручной, в git)
  api/starmap/            зеркало API: bootup.json, star-systems/, celestial-objects/, _index.json
  static/starmap/         движок: starmap.bundle.js (патченый), main.css, fonts, ui-images,
                          models/, sounds/, sourceimages/ + dev/
  static/fonts/           шрифты Electrolize/Orbitron (woff2)
  media/                  текстуры планет, превью, галерея (игнорируется git)
  assets/fonts.css        @font-face для страницы (генерируется build)
  rsi/static/svg/disc.svg диск (favicon + критичный ассет движка, см. ниже)
ENGINE.md                 подробный разбор движка карты и зеркала
PATCH_FOR_DCLICK.md       оба UX-патча движка (строки до/после)
```

`.gitignore`: в git НЕ идут `data/`, `web/api/`, `web/media/`, `web/static/starmap/models|sounds|sourceimages/` (перекачиваются).
В git идут: движок (`static/starmap/`), `index.html`, `index_dev.html`, `rsi/`, `assets/`.

## CLI (`php grab.php`)

Команды: `fetch` `index` `assets` `media [--КАТ]` `build` `all` `serve [порт]` `help`.
Флаг `--force` — перекачивать уже сохранённое (иначе существующие файлы пропускаются).
`CONCURRENCY = 12` (верх файла grab.php). `all` = fetch+index+assets+media+build.

- `fetch` — `POST /api/starmap/bootup` (без браузерного User-Agent API отвечает **405**; UA в `HttpClient::UA`), затем все системы и все объекты. Ретраи: 4 попытки, паузы 300–700 мс, батчами по `concurrency*2`.
- `index` — `Util::collectMediaUrls(data/)` → `data/urlindex.json` (категории: texture, model, thumbnail, media_post, media_source, media_any).
- `assets` — зеркало CDN `cdn.robertsspaceindustries.com/static/starmap/*`: bundle, main.css, fontdetect, шрифты, 22 `.dae`, 48 звуков + музыка (3 формата), `sourceimages/` (из .dae и фракций), `ui-images`/fonts из `main.css`, плюс `web/rsi/static/svg/disc.svg`.
- `media [--texture --model --thumbnail --media_post --media_source --media_any]` — картинки в `web/media/`; без аргументов = все категории.
- `build` — собрать `web/`: index.html, патченый bundle, css, api, поисковый индекс.
- `serve [port]` — `php -S 0.0.0.0:PORT server.php` (по умолчанию 8080).

## Ключевые классы и API

### Util (final, namespace `Starmap`)
Константы: `DATA_DIR`, `WEB_DIR`, `SRC_DIR`, `TMP_DIR`, `API_BASE`, `SITE_BASE`, `CDN_STARMAP`, `CDN_STATIC`.
- `dataPath(...$parts): string`, `webPath(...$parts): string` — пути.
- `saveJson($path, $data)` / `readJson($path)` — JSON (pretty, unescaped slashes).
- `apiData(?array $json, string $what): ?array` — валидирует `success===1`, отдаёт `data`.
- `collectMediaUrls($dataDir): array<string, list<string>>` — все media-URL по категориям.
- `absMediaUrl($url): ?string` — относительные `/media/...` → абсолютные.
- `rewriteUrls($text): string` — `cdn.../static/starmap/`→`/static/starmap/`, `robertsspaceindustries.com/media/`→`/media/`.
- `globRecursive($dir, array $patterns): list<string>`, `out()`, `err()`, `bytes()`.

### HttpClient (final)
- `__construct(int $concurrency = 12)`, конст. `UA`.
- `request($method, $url, ?$body=null, array $headers=[]): array{code,body,err,url}`.
- `requestJson($method, $url, ?array $json=null): array{...,json:?array}`.
- `parallelJson(array $tasks): list<array{...}>` — окно `$concurrency`, дефолт метод POST + `Content-Type: application/json`.
- `downloadFiles(array $urls, string $destRoot, bool $force=false): array{ok:list<string>, fail:list<array{url,err,code}>}` — стримит в `.part`→`rename`, пропускает существующие непустые, логирует каждый файл `✓ путь (размер)` / `✗ URL (ошибка)`.
- `destForUrl($url, $destRoot): string` — `https://host/path` → `$destRoot/path`.

### StarmapGrabber (final)
- `fetchBootup($force): ?array`; `fetchSystems($force)`; `fetchObjects($force)` — детали в `data/`.
- `indexUrls(): array`; `mirrorAssets($force): list<string>`; `downloadMedia(array $cats=[], $force): array{ok,fail}`.
- `build(): void` — вызывает приватные `buildIndexHtml`, `buildBundle`, `rewriteCss`, `buildApi`, `buildFindIndex`.
- `patchBundle($content): string` (private) — **два UX-патча двойного клика**, идемпотентные (`str_contains` guard). Подробно в `PATCH_FOR_DCLICK.md`.

### OfflineRouter
- `__construct(private string $bootupPath)` — грузит зеркало `web/api/starmap/bootup.json`, строит граф (системы, прыжки, туннели с размерами S/M/L).
- `route(array $params): array` — полный ответ в формате боевого API: `{success, code, msg, data:{shortest, leastjumps}}`; критерии `shortest` (мин. суммарная дистанция) и `leastjumps` (мин. число прыжков). Ошибки: `ErrValidationFailed` (ship_size), `ErrInvalidObject`. Дистанции округляются до 10 знаков.
- `static d3(array $a, array $b): float` — 3D-расстояние.

### StarmapLocalSearch
- `__construct(private string $webRoot)` — читает `web/api/starmap/_index.json`.
- `search(string $query): array` — подстрока по code/name/designation (case-insensitive), ответ в формате API с `wrap()` (resultset).

## Локальный сервер (server.php)

Роуты (в порядке проверки):
- страница: `/`, `/index.html`, `/en/starmap`, `/starmap` → `web/index.html`.
- dev: `/starmap-dev`, `/index_dev.html`, `/dev` → `web/index_dev.html`.
- `/favicon.ico|svg` → `web/rsi/static/svg/disc.svg`.
- `POST|GET /api/starmap/routes/find` → `OfflineRouter->route` (body JSON, fallback на GET-параметры).
- `POST|GET /api/starmap/find` → `StarmapLocalSearch->search`.
- `/api/starmap/(bookmarks|config/edit|*.saveShaderdata)` → заглушка `{"success":0,"code":"ErrNotAuthenticated",...}`.
- статика по префиксам `/static/ /media/ /rsi/ /assets/` (mime по расширению, guard от `..`).
- API-данные: `/api/starmap/(bootup|star-systems/X|celestial-objects/X)` (+ вариант с `.json`) → файлы зеркала.

## Движок карты: патчи и подводные камни

- Бандл — старый webpack, единый файл `web/static/starmap/starmap.bundle.js` (1.33 МБ). Инициализация: `new s.default({el:"#starmap-application", resourcePath:"/static/starmap", apiBaseURL:""})`. Source map на CDN отсутствует.
- `buildBundle()` патчит CDN-пути на локальные (`https://cdn.robertsspaceindustries.com/static/starmap` → `/static/starmap`).
- **disc.svg обязателен**: бандл грузит диск из `window.location.origin+"/rsi/static/svg/disc.svg"`; без него выбор системы падает `TypeError: can't access property "disc", this[S] is undefined` (диск строится в `load`-событии `<object>`). Файл качает `mirrorAssets()` в `web/rsi/static/svg/disc.svg`.
- В `starmap.bundle.js` остаются 2 внешние ссылки (googletagmanager, телеметрия cloudimperiumgames) — офлайн тихо игнорируются.
- Математика: `obj3d.position.set(x/100, z/100, -y/100)`; объекты — из `distance`(АЕ)/`latitude`/`longitude`; `size` в км.

## Распаковка бандла (dev/)

`web/static/starmap/dev/` — де-минификация для отладки (не git? — в git, кроме больших артефактов):
- `deobfuscated.js` — 65 334 строки: webcrack не смог разбить на модули, выдал единый красивый файл.
- `split.js` — ручной сплит по acorn (нужен `node_modules/acorn`); результат — `modules/0.js…482.js` (483 шт) + `runtime.js`.
- `starmap.bundle.js` — лоадер dev-сборки (загружает `runtime.js` + модули через `document.write`; jsdom это НЕ исполняет — проверять в реальном chromium).
- `annotate.js` — WIP этого сплита: переименование `t,e,n`→`module,exports,require` в модулях + заголовки-комментарии + генерация `dev/MODULES.md`. НЕ завершён (нет acorn в окружении, не прогонялся).

Каждый модуль: `function(t,e,n){...}` — фабрика webpack-модуля = `function(module,exports,require)`; `n(92)` = `require(92)`. Важные: `0.js` — вход, `121.js` — ControlAPI/состояния + three.js, `188.js` — View, `113.js` — gotoGalacticView/gotoSystemView.
`web/index_dev.html` грузит `/static/starmap/dev/starmap.bundle.js` (dev-проверка).
ВНИМАНИЕ: в server.php и документации упоминается `dev/UNPACKED.md`, но файла на диске НЕТ — при необходимости создать/синхронизировать.

## Проверка

- Линт: `make lint` (= `php -l` по всем .php). Все файлы проходят (проверено).
- PHP: 8.2.30.
- Движок в headless-chromium (jsdom не исполняет document.write):
  `chromium --headless=new --no-sandbox --disable-gpu --virtual-time-budget=9000 --enable-logging=stderr --v=0 --dump-dom URL`
  — `/en/starmap` и `/starmap-dev` инициализируются одинаково (THREE.WebGLRenderer 71, AudioContext).
- Тестов (PHPUnit и пр.) в репозитории нет.

## Демо-песочница (web_demo/)

Свой сеттинг на движке ARK: 2 системы (Alpha/Beta), туннель, фракция, текстуры планет.
- `web_demo/make_data.php` — генератор данных: сеттинг (константы `SYSTEMS`/`OBJECTS`/`TUNNELS`) →
  `web_demo/api/starmap/` (bootup.json, star-systems/, celestial-objects/, _index.json). Это прообраз конвертера.
- `web_demo/server.php` — роутер демо: `putenv('STARMAP_WEB=...')` + `require ../server.php`.
- `make demo-data` — перегенерировать данные; `make demo` — поднять сервер (по умолчанию PORT=8099).
- Ассеты симлинками на `../web/` (assets, static, rsi); текстуры планет скопированы в `web_demo/media/`
  (`planet-one.jpg`, `planet-two.jpg`) из `web/media/`.
- Проверено headless-chromium: движок стартует на демо-данных, грузит `/api/starmap/bootup`,
  рендерит галактический вид (тёмно-синее звёздное поле с цветными звёздами).

## Подводные камни старта движка (важно!)

- **Preload шрифта Electrolize обязателен**: вход `l("Electrolize").then(t.start()).catch(console.error)`
  (deobfuscated.js:50-90). `FontDetect.onFontLoaded` поллит 100 мс и РЕЖЕКТИТ через `msTimeout=2000`,
  если шрифт не измерился — `t.start()` не запустится, на экране ничего. Поэтому в head нужен
  `<link rel="preload" href="/static/fonts/electrolize/Electrolize-Regular.woff2" as="font" type="font/woff2" crossorigin>`.
  Строка добавлена в `StarmapGrabber::buildIndexHtml()` и в оба index.html. Без preload старт флуктуирует.
- **`window.RSI` обязателен**: бандл читает `window.RSI.SESSION_NAME` (и заголовок `X-Rsi-Token`);
  без `window.RSI = {}; window.RSI.SESSION_NAME='Rsi-Token'` — краш
  `Cannot read properties of undefined (reading 'SESSION_NAME')`. Сниппет есть в web/index.html.
- **Сценарии входа**: до карты 4 экрана — `.launch` («enter in window mode», появляется ~5-6 с),
  затем `model.load()` (тут грузится bootup!), `.sm-acknowledge`, `.sm-continue`.
  Обход для тестов: `localStorage.skip=1` → `onShow()` сразу делает `model.load()` и `trigger("end")`.
- bootup фетчится только после `.launch`/skip — это НЕ стартовый запрос.
- `TypeError: Failed to fetch` в консоли — внешняя телеметрия (googletagmanager и пр.), офлайн игнорируется.

## Makefile

Цели: `fetch index assets media media-all build all` (сборка), `serve PORT=8080`,
`demo PORT=8099`, `demo-data`, `lint`, `help`.
`make all` = полная сборка с нуля; `make serve` — поднять сервер.

## History (changelog)

- `986c195` "double click out" — UX-патчи движка (zoom out по двойному клику, оба пути).
- `b4ab434` "RSI Web Data" — зеркало web/ (движок, данные API, media).
- `555c1b5` "RSI Starmap Data" — скачивание данных API в data/.
- `ff27439` "PHP Grabber" — базовый граббер.
- `404d53d` "first commit".

Незакоммичено на момент написания: `.gitignore`, `ENGINE.md`, `PATCH_FOR_DCLICK.md`, `session-ses_0034.md`, `web/index_dev.html`, `web/static/starmap/dev/`, правки в `src/*` и `server.php`.
