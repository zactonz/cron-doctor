<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Doctor;

final class Finding
{
    private string $code;

    private string $severity;

    private string $title;

    private string $detail;

    private ?int $lineNumber;

    private ?string $jobId;

    private ?Action $action;

    public function __construct(
        string $code,
        string $severity,
        string $title,
        string $detail,
        ?int $lineNumber = null,
        ?string $jobId = null,
        ?Action $action = null
    ) {
        $this->code = $code;
        $this->severity = $severity;
        $this->title = $title;
        $this->detail = $detail;
        $this->lineNumber = $lineNumber;
        $this->jobId = $jobId;
        $this->action = $action;
    }

    public function code(): string
    {
        return $this->code;
    }

    public function severity(): string
    {
        return $this->severity;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function detail(): string
    {
        return $this->detail;
    }

    public function lineNumber(): ?int
    {
        return $this->lineNumber;
    }

    public function jobId(): ?string
    {
        return $this->jobId;
    }

    public function action(): ?Action
    {
        return $this->action;
    }

    public function isCritical(): bool
    {
        return $this->severity === Severity::CRITICAL;
    }

    public static function sort(array $findings): array
    {
        usort($findings, static function (Finding $left, Finding $right): int {
            $bySeverity = Severity::rank($left->severity()) <=> Severity::rank($right->severity());

            if ($bySeverity !== 0) {
                return $bySeverity;
            }

            return ($left->lineNumber() ?? -1) <=> ($right->lineNumber() ?? -1);
        });

        return $findings;
    }
}
