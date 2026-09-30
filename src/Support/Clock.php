<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Support;

use DateTimeImmutable;
use DateTimeZone;

class Clock
{
    private DateTimeZone $zone;

    public function __construct(?DateTimeZone $zone = null)
    {
        $this->zone = $zone ?: new DateTimeZone(date_default_timezone_get());
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->zone);
    }

    public function at(int $timestamp): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $timestamp))->setTimezone($this->zone);
    }

    public function zone(): DateTimeZone
    {
        return $this->zone;
    }
}
