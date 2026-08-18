<?php

declare(strict_types=1);

/**
 * Роутер локального офлайн-сервера (php -S 0.0.0.0:8080 server.php).
 *
 * Отдаёт зеркало из web/: страницу, статику, локальный API starmap.
 * Рутизация — Arris AppRouter.
 */

use Arris\AppRouter;
use Arris\Exceptions\{
    AppRouterHandlerError,
    AppRouterMethodNotAllowedException,
    AppRouterNotFoundException
};
use Starmap\OfflineRouter;
use Starmap\StarmapAPI;
use Starmap\StarmapStaticAPI;

require __DIR__ . '/vendor/autoload.php';

// ─── Конфигурация ───────────────────────────────────────────────

$webRoot = getenv('STARMAP_WEB') ?: __DIR__ . '/web';
$web = realpath($webRoot) ?: $webRoot;

$starmapApi = null;
if (getenv('STARMAP_DB') === '1') {
    $dbDsn  = getenv('STARMAP_DB_DSN')  ?: 'mysql:host=localhost;dbname=rsi_starmap;charset=utf8mb4';
    $dbUser = getenv('STARMAP_DB_USER') ?: 'rsi_starmap';
    $dbPass = getenv('STARMAP_DB_PASS') ?: 'password';
    $starmapApi = new StarmapAPI(new PDO($dbDsn, $dbUser, $dbPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]));
} else {
    $starmapApi = new StarmapStaticAPI($web);
}

// ─── Отладочный лог (STARMAP_LOG=/path/log.txt) ─────────────────

$debugLog = getenv('STARMAP_LOG');
if ($debugLog) {
    $srv = $_SERVER['SERVER_ADDR'] ?? '-';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    $line = sprintf("[%s] %s %s (%s:%d)\n", date('H:i:s'), $method, $uri, $srv, $_SERVER['REMOTE_PORT'] ?? 0);
    @file_put_contents($debugLog, $line, FILE_APPEND);
}

// ─── MIME-карта ─────────────────────────────────────────────────

$mimeMap = [
    'html' => 'text/html; charset=utf-8',
    'htm'  => 'text/html; charset=utf-8',
    'css'  => 'text/css; charset=utf-8',
    'js'   => 'application/javascript; charset=utf-8',
    'json' => 'application/json; charset=utf-8',
    'svg'  => 'image/svg+xml',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif'  => 'image/gif',
    'webp' => 'image/webp',
    'woff' => 'font/woff',
    'woff2'=> 'font/woff2',
    'ttf'  => 'font/ttf',
    'eot'  => 'application/vnd.ms-fontobject',
    'dae'  => 'application/xml; charset=utf-8',
    'wav'  => 'audio/wav',
    'ogg'  => 'audio/ogg',
    'mp3'  => 'audio/mpeg',
];

$mime = static function (string $ext) use ($mimeMap): string {
    return $mimeMap[strtolower($ext)] ?? 'application/octet-stream';
};

// ─── Горячий путь: статические файлы (без AppRouter) ────────────

$uri = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

$prefixes = ['/static/', '/media/', '/rsi/', '/assets/'];
foreach ($prefixes as $prefix) {
    if (str_starts_with($uri, $prefix) && !str_contains($uri, '..')) {
        $file = $web . $uri;
        if (is_dir($file)) {
            $file = rtrim($file, '/') . '/index.html';
        }
        if (is_file($file)) {
            $ext = pathinfo($file, PATHINFO_EXTENSION);
            header('Content-Type: ' . $mime($ext));
            header('Content-Length: ' . filesize($file));
            readfile($file);
            exit;
        }
        http_response_code(404);
        echo '404';
        exit;
    }
}

// ─── AppRouter ──────────────────────────────────────────────────

try {
    AppRouter::init();

    // --- Страницы ---

    AppRouter::get('/', function () use ($web) {
        header('Content-Type: text/html; charset=utf-8');
        $f = $web . '/index.html';
        is_file($f) ? readfile($f) : (http_response_code(404) && print 'Нет web/index.html. Запустите: php grab.php all');
    }, 'page.index');

    AppRouter::get('/en/starmap', function () use ($web) {
        header('Content-Type: text/html; charset=utf-8');
        $f = $web . '/index.html';
        is_file($f) ? readfile($f) : (http_response_code(404) && print 'Нет web/index.html');
    }, 'page.starmap');

    AppRouter::get('/starmap', function () use ($web) {
        header('Content-Type: text/html; charset=utf-8');
        $f = $web . '/index.html';
        is_file($f) ? readfile($f) : (http_response_code(404) && print 'Нет web/index.html');
    }, 'page.starmap');

    AppRouter::get('/starmap-dev', function () use ($web) {
        header('Content-Type: text/html; charset=utf-8');
        $f = $web . '/index_dev.html';
        is_file($f) ? readfile($f) : (http_response_code(404) && print 'Нет web/index_dev.html');
    }, 'page.dev');

    AppRouter::get('/index_dev.html', function () use ($web) {
        header('Content-Type: text/html; charset=utf-8');
        $f = $web . '/index_dev.html';
        is_file($f) ? readfile($f) : (http_response_code(404) && print 'Нет web/index_dev.html');
    }, 'page.dev.html');

    AppRouter::get('/dev', function () use ($web) {
        header('Content-Type: text/html; charset=utf-8');
        $f = $web . '/index_dev.html';
        is_file($f) ? readfile($f) : (http_response_code(404) && print 'Нет web/index_dev.html');
    }, 'page.dev.short');

    // --- Favicon ---

    $faviconHandler = function () use ($web) {
        $f = $web . '/rsi/static/svg/disc.svg';
        if (is_file($f)) {
            header('Content-Type: image/svg+xml');
            readfile($f);
            exit;
        }
    };
    AppRouter::get('/favicon.ico', $faviconHandler, 'favicon.ico');
    AppRouter::get('/favicon.svg', $faviconHandler, 'favicon.svg');

    // --- API: bootup (POST) ---

    AppRouter::post('/api/starmap/bootup', function () use ($starmapApi) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($starmapApi->bootup(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }, 'api.bootup');

    // --- API: star-systems ---

    AppRouter::get('/api/starmap/star-systems/{code}', function (string $code) use ($starmapApi) {
        $code = preg_replace('#\.json$#', '', $code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($starmapApi->starSystem($code), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }, 'api.star-system');

    // --- API: celestial-objects ---

    AppRouter::get('/api/starmap/celestial-objects/{code}', function (string $code) use ($starmapApi) {
        $code = preg_replace('#\.json$#', '', $code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($starmapApi->celestialObject($code), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }, 'api.celestial-object');

    // --- API: routes/find (POST, Dijkstra) ---

    AppRouter::post('/api/starmap/routes/find', function () use ($web) {
        $body = file_get_contents('php://input');
        $params = ($body !== '' && $body !== false) ? (json_decode($body, true) ?? []) : [];
        $params['departure']  = $params['departure']  ?? $_GET['departure']  ?? null;
        $params['destination'] = $params['destination'] ?? $_GET['destination'] ?? null;
        $params['ship_size']  = $params['ship_size']  ?? $_GET['ship_size']  ?? null;

        $router = new OfflineRouter($web . '/api/starmap/bootup.json');
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($router->route($params), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }, 'api.routes.find');

    // --- API: find (поиск) ---

    $findHandler = function () use ($starmapApi) {
        $body = file_get_contents('php://input');
        $query = null;
        if ($body !== '' && $body !== false) {
            $decoded = json_decode($body, true);
            if (is_array($decoded) && isset($decoded['query'])) {
                $query = $decoded['query'];
            }
        }
        $query = $query ?? $_GET['query'] ?? $_POST['query'] ?? '';

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($starmapApi->search((string) $query), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    };
    AppRouter::post('/api/starmap/find', $findHandler, 'api.find');
    AppRouter::get('/api/starmap/find', $findHandler, 'api.find.get');

    // --- API: auth stubs (bookmarks, config, shaderdata) ---

    $authStub = static function () {
        header('Content-Type: application/json; charset=utf-8');
        echo '{"success":0,"code":"ErrNotAuthenticated","msg":"You must be authenticated to reach this area.","data":null}';
        exit;
    };

    AppRouter::group(
        prefix: '/api/starmap',
        before: $authStub,
        callback: function () {
            AppRouter::any('/bookmarks', function () {}, 'api.auth.bookmarks');
            AppRouter::any('/bookmarks/{id}', function () {}, 'api.auth.bookmarks.item');
            AppRouter::post('/config/edit', function () {}, 'api.auth.config');
            AppRouter::post('/{anything}/saveShaderdata', function () {}, 'api.auth.shader');
        }
    );

    AppRouter::dispatch();

} catch (AppRouterNotFoundException $e) {
    http_response_code(404);
    echo '404 Not Found';
} catch (AppRouterMethodNotAllowedException $e) {
    http_response_code(405);
    echo '405 Method Not Allowed';
} catch (AppRouterHandlerError $e) {
    http_response_code(500);
    echo '500 Handler Error: ' . $e->getMessage();
} catch (\Throwable $e) {
    http_response_code(500);
    echo '500 Internal Server Error';
}
