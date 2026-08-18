<?php

declare(strict_types=1);

/**
 * Обёртка для запуска original ARK engine на данных SpringGalaxy.
 *
 *   make spring   →  php -S 0.0.0.0:8090 web_spring/server.php
 */

putenv('STARMAP_WEB=' . __DIR__);
require dirname(__DIR__) . '/server.php';
