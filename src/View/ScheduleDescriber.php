<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\View;

use Zactonz\CronDoctor\Cron\CronExpression;

final class ScheduleDescriber
{
    private const DAY_NAMES = [
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ];

    private const MONTH_NAMES = [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
        5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
        9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
    ];

    public static function describe(string $schedule): string
    {
        $trimmed = trim($schedule);

        if (strtolower($trimmed) === '@reboot') {
            return 'When the server restarts';
        }

        if ($trimmed !== '' && $trimmed[0] === '@') {
            $expanded = CronExpression::MACROS[strtolower($trimmed)] ?? null;

            if ($expanded === null) {
                return $trimmed;
            }

            $trimmed = $expanded;
        }

        $fields = preg_split('/\s+/', $trimmed);

        if (!is_array($fields) || count($fields) !== 5) {
            return $trimmed;
        }

        if (CronExpression::tryParse($trimmed) === null) {
            return $trimmed;
        }

        [$minute, $hour, $dayOfMonth, $month, $dayOfWeek] = $fields;

        $time = self::timePart($minute, $hour);

        if ($time === null) {
            return $trimmed;
        }

        $day = self::dayPart($dayOfMonth, $month, $dayOfWeek);

        if ($day === null) {
            return $trimmed;
        }

        return trim($time . ' ' . $day);
    }

    private static function timePart(string $minute, string $hour): ?string
    {
        if ($minute === '*' && $hour === '*') {
            return 'Every minute';
        }

        if ($hour === '*' && preg_match('#^\*/([0-9]+)$#', $minute, $matches) === 1) {
            return 'Every ' . self::plural((int) $matches[1], 'minute');
        }

        if ($hour === '*' && preg_match('/^[0-9]+$/', $minute) === 1) {
            return sprintf('Hourly at %02d minutes past', (int) $minute);
        }

        if (preg_match('/^[0-9]+$/', $minute) === 1 && preg_match('#^\*/([0-9]+)$#', $hour, $matches) === 1) {
            return sprintf('Every %s at %02d minutes past', self::plural((int) $matches[1], 'hour'), (int) $minute);
        }

        if (preg_match('/^[0-9]+$/', $minute) === 1 && preg_match('/^[0-9]+$/', $hour) === 1) {
            return sprintf('At %02d:%02d', (int) $hour, (int) $minute);
        }

        if (preg_match('/^[0-9]+$/', $minute) === 1 && preg_match('/^[0-9,]+$/', $hour) === 1) {
            $hours = array_map('intval', explode(',', $hour));
            $formatted = [];

            foreach ($hours as $value) {
                $formatted[] = sprintf('%02d:%02d', $value, (int) $minute);
            }

            return 'At ' . self::join($formatted);
        }

        return null;
    }

    private static function dayPart(string $dayOfMonth, string $month, string $dayOfWeek): ?string
    {
        $everyDayOfMonth = $dayOfMonth === '*';
        $everyMonth = $month === '*';
        $everyDayOfWeek = $dayOfWeek === '*';

        if ($everyDayOfMonth && $everyMonth && $everyDayOfWeek) {
            return '';
        }

        if ($everyDayOfMonth && $everyMonth && preg_match('/^[0-7](,[0-7])*$/', $dayOfWeek) === 1) {
            $names = [];

            foreach (explode(',', $dayOfWeek) as $value) {
                $index = (int) $value === 7 ? 0 : (int) $value;
                $names[] = self::DAY_NAMES[$index];
            }

            return 'on ' . self::join($names);
        }

        if ($everyDayOfWeek && $everyMonth && preg_match('/^[0-9]+$/', $dayOfMonth) === 1) {
            return 'on the ' . self::ordinal((int) $dayOfMonth) . ' of the month';
        }

        if ($everyDayOfWeek && preg_match('/^[0-9]+$/', $dayOfMonth) === 1 && preg_match('/^[0-9]+$/', $month) === 1) {
            return 'on ' . self::ordinal((int) $dayOfMonth) . ' ' . (self::MONTH_NAMES[(int) $month] ?? $month);
        }

        return null;
    }

    private static function plural(int $count, string $unit): string
    {
        return $count === 1 ? $unit : $count . ' ' . $unit . 's';
    }

    private static function ordinal(int $value): string
    {
        if ($value % 100 >= 11 && $value % 100 <= 13) {
            return $value . 'th';
        }

        $suffixes = [1 => 'st', 2 => 'nd', 3 => 'rd'];

        return $value . ($suffixes[$value % 10] ?? 'th');
    }

    private static function join(array $items): string
    {
        if (count($items) === 1) {
            return (string) $items[0];
        }

        $last = array_pop($items);

        return implode(', ', $items) . ' and ' . $last;
    }
}
