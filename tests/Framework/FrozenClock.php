<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Tests\Framework;

use DateTimeImmutable;
use Zactonz\CronDoctor\Support\Clock;

final class FrozenClock extends Clock
{
    private DateTimeImmutable $moment;

    public function __construct(DateTimeImmutable $moment)
    {
        parent::__construct($moment->getTimezone());

        $this->moment = $moment;
    }

    public function now(): DateTimeImmutable
    {
        return $this->moment;
    }

    public function advance(int $seconds): void
    {
        $this->moment = $this->moment->modify(sprintf('%+d seconds', $seconds));
    }
}
