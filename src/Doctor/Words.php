<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Doctor;

final class Words
{
    public static function list(array $items): string
    {
        $items = array_values(array_map('strval', $items));

        if ($items === []) {
            return '';
        }

        if (count($items) === 1) {
            return $items[0];
        }

        $last = array_pop($items);

        return implode(', ', $items) . ' and ' . $last;
    }

    public static function duration(int $seconds): string
    {
        if ($seconds < 60) {
            return self::count($seconds, 'second');
        }

        if ($seconds < 3600) {
            return self::count((int) round($seconds / 60), 'minute');
        }

        if ($seconds < 86400) {
            return self::count((int) round($seconds / 3600), 'hour');
        }

        return self::count((int) round($seconds / 86400), 'day');
    }

    public static function count(int $value, string $noun): string
    {
        return $value . ' ' . $noun . ($value === 1 ? '' : 's');
    }
}
