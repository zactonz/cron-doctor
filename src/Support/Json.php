<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Support;

final class Json
{
    public static function encode($value): string
    {
        $encoded = json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE
        );

        return $encoded === false ? '{}' : $encoded;
    }

    public static function decode(string $json): ?array
    {
        if (trim($json) === '') {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }
}
