<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Platform;

final class ProcessResult
{
    private int $exitCode;

    private string $stdout;

    private string $stderr;

    private bool $timedOut;

    public function __construct(int $exitCode, string $stdout, string $stderr, bool $timedOut = false)
    {
        $this->exitCode = $exitCode;
        $this->stdout = $stdout;
        $this->stderr = $stderr;
        $this->timedOut = $timedOut;
    }

    public function successful(): bool
    {
        return $this->exitCode === 0 && !$this->timedOut;
    }

    public function exitCode(): int
    {
        return $this->exitCode;
    }

    public function stdout(): string
    {
        return $this->stdout;
    }

    public function stderr(): string
    {
        return $this->stderr;
    }

    public function timedOut(): bool
    {
        return $this->timedOut;
    }

    public function errorSummary(): string
    {
        if ($this->timedOut) {
            return 'the command did not finish in time';
        }

        $message = trim($this->stderr) !== '' ? trim($this->stderr) : trim($this->stdout);

        if ($message === '') {
            return sprintf('the command exited with status %d', $this->exitCode);
        }

        return $message;
    }
}
