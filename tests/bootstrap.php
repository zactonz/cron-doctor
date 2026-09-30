<?php

declare(strict_types=1);

use Zactonz\CronDoctor\Autoload;

require __DIR__ . '/../src/Autoload.php';

Autoload::register(__DIR__ . '/../src');

spl_autoload_register(static function (string $class): void {
    $prefix = 'Zactonz\\CronDoctor\\Tests\\';

    if (strpos($class, $prefix) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));

    if (!preg_match('/^[A-Za-z0-9_\\\\]+$/', $relative)) {
        return;
    }

    $path = __DIR__ . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

    if (is_file($path)) {
        require $path;
    }
});

date_default_timezone_set(getenv('ZCD_TEST_TZ') ?: 'UTC');
