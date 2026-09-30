<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Http;

final class Request
{
    private array $query;

    private array $body;

    private array $server;

    public function __construct(array $query, array $body, array $server)
    {
        $this->query = $query;
        $this->body = $body;
        $this->server = $server;
    }

    public static function capture(): self
    {
        return new self($_GET, $_POST, $_SERVER);
    }

    public function isPost(): bool
    {
        return strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET')) === 'POST';
    }

    public function server(): array
    {
        return $this->server;
    }

    public function text(string $key, string $fallback = ''): string
    {
        $value = $this->body[$key] ?? $this->query[$key] ?? null;

        if (!is_string($value)) {
            return $fallback;
        }

        return trim($value);
    }

    public function integer(string $key, int $fallback = 0): int
    {
        $value = $this->text($key, '');

        if ($value === '' || preg_match('/^-?[0-9]+$/', $value) !== 1) {
            return $fallback;
        }

        return (int) $value;
    }

    public function jobId(string $key = 'job'): ?string
    {
        $value = $this->text($key, '');

        return preg_match('/^[0-9a-f]{16}$/', $value) === 1 ? $value : null;
    }

    public function sequence(string $key = 'run'): ?string
    {
        $value = $this->text($key, '');

        return preg_match('/^[0-9]+-[0-9]+$/', $value) === 1 ? $value : null;
    }

    public function has(string $key): bool
    {
        return isset($this->body[$key]) || isset($this->query[$key]);
    }
}
