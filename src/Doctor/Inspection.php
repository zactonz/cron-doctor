<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Doctor;

use DateTimeImmutable;
use Zactonz\CronDoctor\Platform\CrontabSnapshot;
use Zactonz\CronDoctor\Platform\Environment;
use Zactonz\CronDoctor\Platform\PhpBinaries;

final class Inspection
{
    private CrontabSnapshot $snapshot;

    private array $entries;

    private Environment $environment;

    private PhpBinaries $phpBinaries;

    private DateTimeImmutable $now;

    private array $capabilities;

    private bool $writable;

    private bool $runnerCurrent;

    public function __construct(
        CrontabSnapshot $snapshot,
        array $entries,
        Environment $environment,
        PhpBinaries $phpBinaries,
        DateTimeImmutable $now,
        array $capabilities,
        bool $writable,
        bool $runnerCurrent
    ) {
        $this->snapshot = $snapshot;
        $this->entries = $entries;
        $this->environment = $environment;
        $this->phpBinaries = $phpBinaries;
        $this->now = $now;
        $this->capabilities = $capabilities;
        $this->writable = $writable;
        $this->runnerCurrent = $runnerCurrent;
    }

    public function snapshot(): CrontabSnapshot
    {
        return $this->snapshot;
    }

    public function entries(): array
    {
        return $this->entries;
    }

    public function jobEntries(): array
    {
        return array_values(array_filter($this->entries, static function (CronEntry $entry): bool {
            return $entry->line()->isJob();
        }));
    }

    public function environment(): Environment
    {
        return $this->environment;
    }

    public function phpBinaries(): PhpBinaries
    {
        return $this->phpBinaries;
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    public function capabilities(): array
    {
        return $this->capabilities;
    }

    public function capability(string $key, string $fallback = ''): string
    {
        return $this->capabilities[$key] ?? $fallback;
    }

    public function writable(): bool
    {
        return $this->writable;
    }

    public function runnerCurrent(): bool
    {
        return $this->runnerCurrent;
    }

    public function entryFor(int $lineNumber): ?CronEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->line()->number() === $lineNumber) {
                return $entry;
            }
        }

        return null;
    }
}
