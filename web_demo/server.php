<?php

declare(strict_types=1);

/**
 * Роутер демо-версии: тот же server.php, но корень зеркала — web_demo/.
 *
 * Запуск:
 *   php -S 0.0.0.0:8099 web_demo/server.php
 *   → http://localhost:8099/en/starmap
 */

putenv('STARMAP_WEB=' . __DIR__);

require __DIR__ . '/../server.php';
