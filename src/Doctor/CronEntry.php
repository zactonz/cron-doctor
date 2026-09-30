<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Doctor;

use DateTimeImmutable;
use Zactonz\CronDoctor\Cron\CommandName;
use Zactonz\CronDoctor\Cron\CronExpression;
use Zactonz\CronDoctor\Cron\CrontabLine;
use Zactonz\CronDoctor\Cron\Redirection;
use Zactonz\CronDoctor\Job\Job;
use Zactonz\CronDoctor\Job\RunRecord;

final class CronEntry
{
    public const STATUS_OK = 'ok';
    public const STATUS_FAILING = 'failing';
    public const STATUS_OVERDUE = 'overdue';
    public const STATUS_OVERLAPPING = 'overlapping';
    public const STATUS_WAITING = 'waiting';
    public const STATUS_UNMONITORED = 'unmonitored';
    public const STATUS_UNKNOWN = 'unknown';

    public const MINIMUM_GRACE = 120;

    public const MAXIMUM_GRACE = 3600;

    private CrontabLine $line;

    private ?Job $job;

    private ?CronExpression $expression;

    private ?RunRecord $lastRun;

    private ?RunRecord $lastCompleted;

    private int $consecutiveFailures;

    private int $skippedRuns;

    private array $findings = [];

    public function __construct(
        CrontabLine $line,
        ?Job $job,
        ?RunRecord $lastRun,
        ?RunRecord $lastCompleted,
        int $consecutiveFailures,
        int $skippedRuns
    ) {
        $this->line = $line;
        $this->job = $job;
        $this->expression = $line->expression();
        $this->lastRun = $lastRun;
        $this->lastCompleted = $lastCompleted;
        $this->consecutiveFailures = $consecutiveFailures;
        $this->skippedRuns = $skippedRuns;
    }

    public function line(): CrontabLine
    {
        return $this->line;
    }

    public function job(): ?Job
    {
        return $this->job;
    }

    public function isMonitored(): bool
    {
        return $this->job !== null;
    }

    public function expression(): ?CronExpression
    {
        return $this->expression;
    }

    public function isReboot(): bool
    {
        return CronExpression::isReboot($this->line->schedule());
    }

    public function command(): string
    {
        return $this->job !== null ? $this->job->command() : $this->line->command();
    }

    public function displayName(): string
    {
        return $this->job !== null ? $this->job->name() : CommandName::describe($this->line->command());
    }

    public function lastRun(): ?RunRecord
    {
        return $this->lastRun;
    }

    public function lastCompleted(): ?RunRecord
    {
        return $this->lastCompleted;
    }

    public function consecutiveFailures(): int
    {
        return $this->consecutiveFailures;
    }

    public function skippedRuns(): int
    {
        return $this->skippedRuns;
    }

    public function redirection(): Redirection
    {
        return Redirection::analyse($this->command());
    }

    public function intervalSeconds(DateTimeImmutable $now): ?int
    {
        return $this->expression === null ? null : $this->expression->intervalSeconds($now);
    }

    public function graceSeconds(DateTimeImmutable $now): int
    {
        $interval = $this->intervalSeconds($now);

        if ($interval === null) {
            return self::MINIMUM_GRACE;
        }

        return (int) max(self::MINIMUM_GRACE, min(self::MAXIMUM_GRACE, (int) round($interval * 0.2)));
    }

    public function expectedPreviousRun(DateTimeImmutable $now): ?DateTimeImmutable
    {
        return $this->expression === null ? null : $this->expression->previousRun($now);
    }

    public function nextRun(DateTimeImmutable $now): ?DateTimeImmutable
    {
        return $this->expression === null ? null : $this->expression->nextRun($now);
    }

    public function isOverdue(DateTimeImmutable $now): bool
    {
        if ($this->job === null || $this->expression === null) {
            return false;
        }

        $expected = $this->expectedPreviousRun($now);

        if ($expected === null) {
            return false;
        }

        if ($now->getTimestamp() - $expected->getTimestamp() < $this->graceSeconds($now)) {
            return false;
        }

        if ($this->job->createdAt() > $expected->getTimestamp()) {
            return false;
        }

        $lastStart = $this->lastRun === null ? 0 : $this->lastRun->startedAt();

        return $lastStart < $expected->getTimestamp();
    }

    public function status(DateTimeImmutable $now): string
    {
        if (!$this->line->isJob()) {
            return self::STATUS_UNKNOWN;
        }

        if ($this->job === null) {
            return self::STATUS_UNMONITORED;
        }

        if ($this->consecutiveFailures > 0) {
            return self::STATUS_FAILING;
        }

        if ($this->isOverdue($now)) {
            return self::STATUS_OVERDUE;
        }

        if ($this->skippedRuns > 0) {
            return self::STATUS_OVERLAPPING;
        }

        if ($this->lastCompleted === null) {
            return self::STATUS_WAITING;
        }

        return self::STATUS_OK;
    }

    public function addFinding(Finding $finding): void
    {
        $this->findings[] = $finding;
    }

    public function findings(): array
    {
        return Finding::sort($this->findings);
    }

    public function worstSeverity(): ?string
    {
        $worst = null;

        foreach ($this->findings as $finding) {
            if ($worst === null || Severity::rank($finding->severity()) < Severity::rank($worst)) {
                $worst = $finding->severity();
            }
        }

        return $worst;
    }

}
