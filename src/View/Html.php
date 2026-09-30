<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\View;

final class Html
{
    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    public static function text(string $value): string
    {
        return self::escape(self::printable($value));
    }

    public static function printable(string $value): string
    {
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);

        if ($clean === null) {
            $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
        }

        return (string) $clean;
    }

    public static function output(string $value): string
    {
        $printable = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value);

        if ($printable === null) {
            $printable = $value;
        }

        if (!mb_check_encoding($printable, 'UTF-8')) {
            $printable = mb_convert_encoding($printable, 'UTF-8', 'UTF-8');
        }

        return self::escape($printable);
    }

    public static function attribute(string $value): string
    {
        return self::escape(self::printable($value));
    }

    public static function shorten(string $value, int $length): string
    {
        $printable = self::printable($value);

        if (mb_strlen($printable) <= $length) {
            return $printable;
        }

        return mb_substr($printable, 0, $length - 1) . '…';
    }

    public static function maskSecrets(string $command): string
    {
        $masked = preg_replace("/(^|\s)(-p)(['\"]?)([^\s'\"]+)/", '$1$2$3••••••', $command);
        $masked = preg_replace("/(^|\s)(--password=)(['\"]?)([^\s'\"]+)/i", '$1$2$3••••••', (string) $masked);
        $masked = preg_replace("/(^|\s)(PGPASSWORD=)(['\"]?)([^\s'\"]+)/", '$1$2$3••••••', (string) $masked);
        $masked = preg_replace(
            "/(^|\s)((?:api[_-]?key|access[_-]?token|secret)=)(['\"]?)([^\s'\"]+)/i",
            '$1$2$3••••••',
            (string) $masked
        );
        $masked = preg_replace(
            "/(Authorization:\s*(?:Bearer|Basic)\s+)(['\"]?)([^\s'\"]+)/i",
            '$1$2••••••',
            (string) $masked
        );

        return (string) $masked;
    }

    public static function containsSecret(string $command): bool
    {
        return self::maskSecrets($command) !== $command;
    }
}
