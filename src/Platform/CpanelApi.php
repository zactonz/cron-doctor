<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Platform;

interface CpanelApi
{
    public function available(): bool;

    public function fetchCrontabLines(): ?array;

    public function documentRootPhpVersions(): array;
}
