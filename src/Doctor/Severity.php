<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Doctor;

final class Severity
{
    public const CRITICAL = 'critical';
    public const WARNING = 'warning';
    public const ADVICE = 'advice';

    private const ORDER = [
        self::CRITICAL => 0,
        self::WARNING => 1,
        self::ADVICE => 2,
    ];

    public static function rank(string $severity): int
    {
        return self::ORDER[$severity] ?? 99;
    }

    public static function label(string $severity): string
    {
        if ($severity === self::CRITICAL) {
            return 'Needs attention';
        }

        return $severity === self::WARNING ? 'Worth fixing' : 'Suggestion';
    }
}
