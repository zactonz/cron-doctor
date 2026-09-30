<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Tests\Framework;

use Zactonz\CronDoctor\Platform\CpanelApi;

final class FakeCpanelApi implements CpanelApi
{
    private bool $available;

    private ?array $lines;

    private array $versions;

    public function __construct(bool $available = true, ?array $lines = null, array $versions = [])
    {
        $this->available = $available;
        $this->lines = $lines;
        $this->versions = $versions;
    }

    public function available(): bool
    {
        return $this->available;
    }

    public function fetchCrontabLines(): ?array
    {
        return $this->lines;
    }

    public function documentRootPhpVersions(): array
    {
        return $this->versions;
    }
}
