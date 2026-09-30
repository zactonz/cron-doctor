<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Support;

final class Result
{
    private bool $successful;

    private string $message;

    private array $context;

    private function __construct(bool $successful, string $message, array $context)
    {
        $this->successful = $successful;
        $this->message = $message;
        $this->context = $context;
    }

    public static function ok(string $message = '', array $context = []): self
    {
        return new self(true, $message, $context);
    }

    public static function fail(string $message, array $context = []): self
    {
        return new self(false, $message, $context);
    }

    public function successful(): bool
    {
        return $this->successful;
    }

    public function failed(): bool
    {
        return !$this->successful;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function context(): array
    {
        return $this->context;
    }
}
