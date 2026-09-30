<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Support;

final class Filesystem
{
    public const DIRECTORY_MODE = 0700;

    public const FILE_MODE = 0600;

    public static function ensureDirectory(string $path): bool
    {
        if (is_dir($path)) {
            @chmod($path, self::DIRECTORY_MODE);

            return true;
        }

        if (!@mkdir($path, self::DIRECTORY_MODE, true) && !is_dir($path)) {
            return false;
        }

        @chmod($path, self::DIRECTORY_MODE);

        return is_dir($path);
    }

    public static function writeAtomically(string $path, string $contents): bool
    {
        $directory = dirname($path);

        if (!self::ensureDirectory($directory)) {
            return false;
        }

        $temporary = $directory . DIRECTORY_SEPARATOR . '.' . basename($path) . '.' . getmypid() . '.tmp';
        $handle = @fopen($temporary, 'wb');

        if ($handle === false) {
            return false;
        }

        @chmod($temporary, self::FILE_MODE);

        $written = @fwrite($handle, $contents);
        @fflush($handle);
        @fclose($handle);

        if ($written === false || $written !== strlen($contents)) {
            @unlink($temporary);

            return false;
        }

        if (!@rename($temporary, $path)) {
            @unlink($temporary);

            return false;
        }

        @chmod($path, self::FILE_MODE);

        return true;
    }

    public static function read(string $path): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    public static function withLock(string $lockPath, callable $callback)
    {
        if (!self::ensureDirectory(dirname($lockPath))) {
            return $callback();
        }

        $handle = @fopen($lockPath, 'c');

        if ($handle === false) {
            return $callback();
        }

        @chmod($lockPath, self::FILE_MODE);

        $locked = @flock($handle, LOCK_EX);

        try {
            return $callback();
        } finally {
            if ($locked) {
                @flock($handle, LOCK_UN);
            }

            @fclose($handle);
        }
    }

    public static function isSharedWithOtherUsers(string $path): bool
    {
        $permissions = @fileperms($path);

        if ($permissions === false) {
            return false;
        }

        return ($permissions & 0077) !== 0;
    }

    public static function isWritableByOtherUsers(string $path): bool
    {
        $permissions = @fileperms($path);

        if ($permissions === false) {
            return false;
        }

        return ($permissions & 0022) !== 0;
    }

    public static function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $entries = @scandir($path);

        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($child) && !is_link($child)) {
                self::removeDirectory($child);
                continue;
            }

            @unlink($child);
        }

        @rmdir($path);
    }

    public static function listFiles(string $directory, string $pattern): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        $matches = @glob($directory . DIRECTORY_SEPARATOR . $pattern, GLOB_NOSORT);

        if (!is_array($matches)) {
            return [];
        }

        $files = array_values(array_filter($matches, static function (string $path): bool {
            return is_file($path);
        }));

        sort($files);

        return $files;
    }
}
