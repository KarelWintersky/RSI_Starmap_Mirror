# ARK Starmap — офлайн-зеркало (Star Citizen)

Скачивает все данные интерактивной карты вселенной Star Citizen
(`robertsspaceindustries.com/en/starmap`) и поднимает локальную копию,
где оригинальный движок карты работает без интернета.

## Требования

- PHP >= 8.1 (CLI) с расширениями: `curl`, `json`, `mbstring`
- Доступ в интернет только на время скачивания

## Быстрый старт

```bash
# 1. Скачать всё: данные API + статика CDN + медиа + собрать зеркало
php grab.php all

# 2. Поднять локальный сервер
php grab.php serve
# сервер: http://localhost:8080/en/starmap
```

Откройте `http://localhost:8080/en/starmap` в браузере.

Или вручную, по шагам:

```bash
php grab.php fetch     # JSON: bootup + 90 систем + детали объектов (data/)
php grab.php index     # список media-URL (data/urlindex.json)
php grab.php assets    # статика CDN: модели, звуки, css/js, шрифты (web/static)
php grab.php media     # медиа: текстуры, 3D-модели, превью (web/media)
php grab.php build     # web/index.html + патченный bundle + локальный API (web/api)
```

## Команды

| Команда | Что делает |
|---|---|
| `php grab.php fetch` | Скачать JSON: bootup, системы, объекты |
| `php grab.php index` | Собрать список media-URL из `data/` |
| `php grab.php assets` | Зеркалировать статику CDN (models, sounds, css, js, fonts) |
| `php grab.php media [--КАТ]` | Скачать медиа. Категории: `--texture --model --thumbnail --media_post --media_source --media_any`. По умолчанию — `texture, model, thumbnail, media_post` |
| `php grab.php build` | Собрать `web/`: index.html, патченный bundle, локальный API |
| `php grab.php all` | `fetch + index + assets + media + build` |
| `php grab.php serve [порт]` | Локальный сервер (по умолчанию порт 8080) |
| `php grab.php help` | Справка |

Флаги:

- `--force` — повторно скачивать уже сохранённые файлы (иначе существующие пропускаются)

Примеры:

```bash
php grab.php fetch --force   # перекачать JSON заново
php grab.php media --media_any   # докачать все размеры галереи (большой объём)
php grab.php serve 8899      # сервер на порту 8899
```

## Структура

```
grab.php                 CLI-вход (все команды)
server.php               роутер локального сервера (php -S)
src/
  HttpClient.php         параллельный HTTP-клиент (curl_multi)
  Util.php               константы, пути, хелперы (media-URL, rewrite)
  StarmapGrabber.php     граббер: fetch/index/assets/media/build
  OfflineRouter.php      локальный /api/starmap/routes/find (Dijkstra)
  StarmapLocalSearch.php локальный /api/starmap/find
data/                    сырые JSON с API (источник истины)
  bootup.json            90 систем, 135 туннелей, фракции, виды
  star-systems/          детали систем + объекты верхнего уровня
  celestial-objects/     детали объектов (деревья, текстуры, модели)
  urlindex.json          список media-URL по категориям
  page_starmap.html      исходная страница /en/starmap
web/                     офлайн-зеркало (запускается)
  index.html             страница приложения
  static/starmap/        bundle, css, модели .dae, звуки, ui-images
  static/fonts/          шрифты Electrolize/Orbitron
  api/starmap/           JSON-зеркало API + _index.json (поиск)
  media/                 текстуры планет, превью, галерея
  assets/fonts.css       @font-face для страницы
```

## Что работает офлайн

- Загрузка и рендер 3D-карты, систем, объектов, туннелей
- `POST /api/starmap/routes/find` — построение маршрута (совпадает с боевым API:
  те же поля, дистанции до 10 знаков; фильтрация туннелей по размеру корабля S/M/L)
- `POST /api/starmap/find` — поиск по системам и объектам
- `POST /api/starmap/bootup`, `star-systems/{CODE}`, `celestial-objects/{CODE}`
- Текстуры планет, 3D-модели, звуки, превью — всё локально

## Известные особенности

- `POST /api/starmap/bookmarks/*` возвращает заглушку `ErrNotAuthenticated`
  (реальные закладки требуют авторизации на сайте).
- Два битых URL текстур (`ArcCorp1.jpg`, `Earthjp-Nyc.png`) отдают 404 даже на
  живом CDN — не скачиваются, на работу карты не влияют.
- Размеры галереи (`slideshow`, `hub_*`, `product_thumb_*` и т.п.) по умолчанию
  не скачиваются — карте не нужны. Докачиваются: `php grab.php media --media_any`.
- В `starmap.bundle.js` остаются 2 безобидные внешние ссылки (аналитика
  googletagmanager и телеметрия cloudimperiumgames) — офлайн тихо игнорируются.

## Как это работает

1. `fetch` — `POST /api/starmap/bootup` (без User-Agent API отвечает 405),
   затем системы и объекты. Кэш: повторный запуск пропускает уже скачанные файлы.
2. `assets` — зеркалирует `cdn.robertsspaceindustries.com/static/starmap/*`
   и шрифты; список URL извлекается из `main.css`.
3. `build` — копирует JSON в `web/api/starmap/` с переписыванием медиа-путей
   (`robertsspaceindustries.com/media/...` → `/media/...`), правит bundle
   (`resourcePath` → `/static/starmap`) и css (CDN → локальные пути).
4. `serve` / `server.php` — отдаёт зеркало и отвечает на локальный API.
