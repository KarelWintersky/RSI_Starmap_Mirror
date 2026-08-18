#!/usr/bin/env php
<?php

declare(strict_types=1);

use Starmap\StarmapGrabber;
use Starmap\Util;

require __DIR__ . '/vendor/autoload.php';

const CONCURRENCY = 12;

$args = array_slice($argv, 1);
$cmd = array_shift($args) ?? 'help';

$force = in_array('--force', $args, true);
$args = array_values(array_filter($args, static fn (string $a): bool => $a !== '--force'));

$grab = new StarmapGrabber(CONCURRENCY);

try {
    switch ($cmd) {
        case 'fetch':
            $grab->fetchBootup($force);
            $grab->fetchSystems($force);
            $grab->fetchObjects($force);
            Util::out('fetch: готово');
            break;

        case 'index':
            $grab->indexUrls();
            break;

        case 'assets':
            $grab->mirrorAssets($force);
            Util::out('assets: готово');
            break;

        case 'media':
            $cats = [];
            foreach ($args as $a) {
                if (str_starts_with($a, '--')) {
                    $cats[] = substr($a, 2);
                }
            }
            $grab->downloadMedia($cats, $force);
            break;

        case 'build':
            $grab->build();
            break;

        case 'all':
            $grab->fetchBootup($force);
            $grab->fetchSystems($force);
            $grab->fetchObjects($force);
            $grab->indexUrls();
            $grab->mirrorAssets($force);
            $grab->downloadMedia([], $force);
            $grab->build();
            Util::out('all: полностью готово');
            break;

        case 'serve':
            $port = (string) ($args[0] ?? '8080');
            $doc = Util::WEB_DIR;
            if (!is_file($doc . '/index.html')) {
                Util::err('Нет web/index.html — сначала выполните "php grab.php all"');
                exit(1);
            }
            Util::out("Сервер: http://localhost:$port/en/starmap");
            passthru(PHP_BINARY . ' -S 0.0.0.0:' . $port . ' ' . escapeshellarg(__DIR__ . '/server.php'), $code);
            exit($code === null ? 0 : (int) $code);

        case 'help':
        case '--help':
        case '-h':
        default:
            echo <<<TXT
Граббер ARK Starmap (Star Citizen)

Команды:
  php grab.php fetch            загрузить JSON: bootup + системы + объекты
  php grab.php index            собрать список media-URL из data/
  php grab.php assets           зеркалировать статику CDN (models, sounds, css, js, fonts)
  php grab.php media [--КАТ]    скачать media-файлы (--texture --model --thumbnail --media_post --media_source --media_any)
  php grab.php build            собрать web/ (index.html, патченный bundle, локальный API)
  php grab.php all              fetch + index + assets + media + build
  php grab.php serve [порт]     поднять локальный сервер (по умолчанию 8080)
  php grab.php help             эта справка

Флаги: --force  повторно качать уже сохранённое

Каталоги:
  data/   сырые JSON с API (источник истины)
  web/    офлайн-зеркало для запуска (web/index.html + web/static + web/api + web/media)

TXT;
            break;
    }
} catch (Throwable $e) {
    Util::err('Ошибка: ' . $e->getMessage());
    exit(1);
}
