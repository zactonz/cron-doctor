<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Platform;

final class PhpUsage
{
    public const KIND_GENERIC = 'generic';
    public const KIND_EASYAPACHE = 'easyapache';
    public const KIND_ALTERNATIVE = 'alternative';

    private int $tokenIndex;

    private string $binary;

    private string $kind;

    private string $version;

    private ?string $scriptPath;

    public function __construct(int $tokenIndex, string $binary, string $kind, string $version, ?string $scriptPath)
    {
        $this->tokenIndex = $tokenIndex;
        $this->binary = $binary;
        $this->kind = $kind;
        $this->version = $version;
        $this->scriptPath = $scriptPath;
    }

    public function tokenIndex(): int
    {
        return $this->tokenIndex;
    }

    public function binary(): string
    {
        return $this->binary;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function version(): string
    {
        return $this->version;
    }

    public function scriptPath(): ?string
    {
        return $this->scriptPath;
    }

    public function isGeneric(): bool
    {
        return $this->kind === self::KIND_GENERIC;
    }
}
