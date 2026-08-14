<?php

declare(strict_types=1);

/**
 * Роутер локального офлайн-сервера (php -S 0.0.0.0:8080 server.php).
 *
 * Отдаёт зеркало из web/: страницу, статику, локальный API starmap.
 */

use Starmap\OfflineRouter;
use Starmap\Util;

require __DIR__ . '/src/Util.php';
require __DIR__ . '/src/HttpClient.php';
require __DIR__ . '/src/StarmapGrabber.php';
require __DIR__ . '/src/OfflineRouter.php';
require __DIR__ . '/src/StarmapLocalSearch.php';

$web = realpath(__DIR__ . '/web') ?: (__DIR__ . '/web');
$uri = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ------------------------------------------------ статические файлы
$safe = static function (string $path): bool {
    return !str_contains($path, '..');
};

$serveFile = static function (string $file, string $contentType): bool {
    if (!is_file($file)) {
        return false;
    }
    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . filesize($file));
    readfile($file);
    return true;
};

$mime = static function (string $ext): string {
    return match (strtolower($ext)) {
        'html', 'htm' => 'text/html; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'eot' => 'application/vnd.ms-fontobject',
        'dae' => 'application/xml; charset=utf-8',
        'wav' => 'audio/wav',
        'ogg' => 'audio/ogg',
        'mp3' => 'audio/mpeg',
        default => 'application/octet-stream',
    };
};

// страница
if ($uri === '/en/starmap' || $uri === '/starmap' || $uri === '/' || $uri === '/index.html') {
    header('Content-Type: text/html; charset=utf-8');
    $f = $web . '/index.html';
    if (is_file($f)) {
        readfile($f);
    } else {
        http_response_code(404);
        echo 'Нет web/index.html. Запустите: php grab.php all';
    }
    exit;
}

// favicon — диск с сайта
if ($uri === '/favicon.ico' || $uri === '/favicon.svg') {
    $f = $web . '/rsi/static/svg/disc.svg';
    if (is_file($f)) {
        header('Content-Type: image/svg+xml');
        readfile($f);
        exit;
    }
}

// -------------------------------------------------- локальный API starmap
if (preg_match('#^/api/starmap/routes/find$#', $uri)) {
    $body = file_get_contents('php://input');
    $params = [];
    if ($body !== '' && $body !== false) {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $params = $decoded;
        }
    }
    if (!isset($params['departure'])) {
        $params['departure'] = $_GET['departure'] ?? null;
    }
    if (!isset($params['destination'])) {
        $params['destination'] = $_GET['destination'] ?? null;
    }
    if (!isset($params['ship_size'])) {
        $params['ship_size'] = $_GET['ship_size'] ?? null;
    }
    $router = new OfflineRouter($web . '/api/starmap/bootup.json');
    $json = $router->route($params);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (preg_match('#^/api/starmap/find$#', $uri)) {
    $body = file_get_contents('php://input');
    $query = null;
    if ($body !== '' && $body !== false) {
        $decoded = json_decode($body, true);
        if (is_array($decoded) && isset($decoded['query'])) {
            $query = $decoded['query'];
        }
    }
    if ($query === null) {
        $query = $_GET['query'] ?? $_POST['query'] ?? '';
    }
    $result = (new \Starmap\StarmapLocalSearch($web))->search((string) $query);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// bookmarks и прочие требующие авторизации эндпоинты — заглушка
if (preg_match('#^/api/starmap/(bookmarks(?:/[^/]+)?|config/edit|.*saveShaderdata)$#', $uri)) {
    header('Content-Type: application/json; charset=utf-8');
    echo '{"success":0,"code":"ErrNotAuthenticated","msg":"You must be authenticated to reach this area.","data":null}';
    exit;
}

// статические файлы зеркала
$prefixes = ['/static/', '/media/', '/rsi/', '/assets/'];
foreach ($prefixes as $prefix) {
    if (str_starts_with($uri, $prefix) && $safe($uri)) {
        $file = $web . $uri;
        if (is_dir($file)) {
            $file = rtrim($file, '/') . '/index.html';
        }
        if (is_file($file)) {
            $ext = pathinfo($file, PATHINFO_EXTENSION);
            header('Content-Type: ' . $mime($ext));
            readfile($file);
            exit;
        }
        http_response_code(404);
        echo '404';
        exit;
    }
}

// API-данные зеркала (bootup, star-systems, celestial-objects)
// GET с .json, POST без расширения — оба отдаём из web/api/starmap/
if (preg_match('#^/api/starmap/([a-z-]+)(/[^/]+)?\.json$#', $uri, $m) && $safe($uri)) {
    $file = $web . $uri;
    if (is_file($file)) {
        header('Content-Type: application/json; charset=utf-8');
        readfile($file);
        exit;
    }
}
if (preg_match('#^/api/starmap/(star-systems/[^/]+|celestial-objects/[^/]+|bootup)$#', $uri) && $safe($uri)) {
    $file = $web . $uri . '.json';
    if (is_file($file)) {
        header('Content-Type: application/json; charset=utf-8');
        readfile($file);
        exit;
    }
}

http_response_code(404);
echo '404 Not Found';
