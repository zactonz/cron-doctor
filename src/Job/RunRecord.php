<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Job;

final class RunRecord
{
    private string $sequence;

    private int $startedAt;

    private int $finishedAt;

    private int $durationMs;

    private int $exitCode;

    private bool $timedOut;

    private bool $skipped;

    private bool $truncated;

    private bool $orphansPossible;

    private bool $staleLockBroken;

    private int $outputBytes;

    private string $outputPath;

    public function __construct(array $data, string $outputPath)
    {
        $this->sequence = (string) ($data['sequence'] ?? '');
        $this->startedAt = (int) ($data['started_at'] ?? 0);
        $this->finishedAt = (int) ($data['finished_at'] ?? 0);
        $this->durationMs = max(0, (int) ($data['duration_ms'] ?? 0));
        $this->exitCode = (int) ($data['exit_code'] ?? 0);
        $this->timedOut = (int) ($data['timed_out'] ?? 0) === 1;
        $this->skipped = (int) ($data['skipped'] ?? 0) === 1;
        $this->truncated = (int) ($data['truncated'] ?? 0) === 1;
        $this->orphansPossible = (int) ($data['orphans_possible'] ?? 0) === 1;
        $this->staleLockBroken = (int) ($data['stale_lock_broken'] ?? 0) === 1;
        $this->outputBytes = max(0, (int) ($data['output_bytes'] ?? 0));
        $this->outputPath = $outputPath;
    }

    public function sequence(): string
    {
        return $this->sequence;
    }

    public function startedAt(): int
    {
        return $this->startedAt;
    }

    public function finishedAt(): int
    {
        return $this->finishedAt;
    }

    public function durationMs(): int
    {
        return $this->durationMs;
    }

    public function exitCode(): int
    {
        return $this->exitCode;
    }

    public function timedOut(): bool
    {
        return $this->timedOut;
    }

    public function skipped(): bool
    {
        return $this->skipped;
    }

    public function truncated(): bool
    {
        return $this->truncated;
    }

    public function orphansPossible(): bool
    {
        return $this->orphansPossible;
    }

    public function staleLockBroken(): bool
    {
        return $this->staleLockBroken;
    }

    public function outputBytes(): int
    {
        return $this->outputBytes;
    }

    public function succeeded(): bool
    {
        return !$this->skipped && $this->exitCode === 0;
    }

    public function failed(): bool
    {
        return !$this->skipped && $this->exitCode !== 0;
    }

    public function outputPath(): string
    {
        return $this->outputPath;
    }

    public function hasOutput(): bool
    {
        return is_file($this->outputPath) && (int) @filesize($this->outputPath) > 0;
    }
}
