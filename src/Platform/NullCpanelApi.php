<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Platform;

final class NullCpanelApi implements CpanelApi
{
    public function available(): bool
    {
        return false;
    }

    public function fetchCrontabLines(): ?array
    {
        return null;
    }

    public function documentRootPhpVersions(): array
    {
        return [];
    }
}
