<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor;

final class Autoload
{
    public const PREFIX = 'Zactonz\\CronDoctor\\';

    private static $registered = false;

    public static function register(string $baseDir): void
    {
        if (self::$registered) {
            return;
        }

        $baseDir = rtrim($baseDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        spl_autoload_register(static function (string $class) use ($baseDir): void {
            if (strpos($class, self::PREFIX) !== 0) {
                return;
            }

            $relative = substr($class, strlen(self::PREFIX));

            if (!preg_match('/^[A-Za-z0-9_\\\\]+$/', $relative)) {
                return;
            }

            $path = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

            if (is_file($path)) {
                require $path;
            }
        });

        self::$registered = true;
    }
}
