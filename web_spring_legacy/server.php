<?php

declare(strict_types=1);

/**
 * Роутер локального сервера SpringGalaxy (php -S 0.0.0.0:8090 web_spring/server.php).
 *
 * Отдаёт страницу, статику (js/, three/, media/) и данные api/starmap/.
 */

$web = __DIR__;
$uri = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

$mime = static function (string $ext): string {
    return match (strtolower($ext)) {
        'html', 'htm' => 'text/html; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'js', 'mjs' => 'application/javascript; charset=utf-8',
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

$serve = static function (string $file) use ($mime): bool {
    if (!is_file($file)) {
        return false;
    }
    header('Content-Type: ' . $mime(pathinfo($file, PATHINFO_EXTENSION)));
    header('Content-Length: ' . filesize($file));
    readfile($file);
    return true;
};

// favicon: данных нет, отдаём пустой ответ, чтобы не шумел 404
if ($uri === '/favicon.ico') {
    http_response_code(204);
    exit;
}

// страница
if (in_array($uri, ['/', '/index.html', '/spring'], true)) {
    $f = $web . '/index.html';
    header('Content-Type: text/html; charset=utf-8');
    if (is_file($f)) {
        readfile($f);
    } else {
        http_response_code(404);
        echo 'Нет web_spring/index.html. Запустите: php web_spring/make_data.php';
    }
    exit;
}

// статика движка и медиа
foreach (['/js/', '/three/', '/media/', '/fonts/'] as $prefix) {
    if (str_starts_with($uri, $prefix) && !str_contains($uri, '..')) {
        if ($serve($web . $uri)) {
            exit;
        }
        http_response_code(404);
        echo '404';
        exit;
    }
}

// DAE-модели и текстуры из оригинального стarmap (../web/static/starmap/)
if (preg_match('#^/static/starmap/(models|sourceimages)/#', $uri) && !str_contains($uri, '..')) {
    $base = realpath(__DIR__ . '/../web/static/starmap');
    $real = realpath(__DIR__ . '/../web' . $uri);
    if ($real && $base && str_starts_with($real, $base) && $serve($real)) {
        exit;
    }
}

// API-данные: GET с .json, POST/GET без расширения — из api/starmap/
if (preg_match('#^/api/starmap/([a-z-]+)(/[^/]+)?\.json$#', $uri, $m) && !str_contains($uri, '..')) {
    if ($serve($web . $uri)) {
        exit;
    }
}
if (preg_match('#^/api/starmap/(star-systems/[^/]+|bootup)$#', $uri) && !str_contains($uri, '..')) {
    if ($serve($web . $uri . '.json')) {
        exit;
    }
}

http_response_code(404);
echo '404 Not Found';
