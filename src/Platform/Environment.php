<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Platform;

use Zactonz\CronDoctor\Support\Filesystem;

final class Environment
{
    public const STATE_DIRECTORY = '.zactonz/zcd';

    public const CRONTAB_CANDIDATES = [
        '/usr/local/bin/crontab',
        '/usr/local/cpanel/bin/jail_safe_crontab',
        '/usr/bin/crontab',
        '/bin/crontab',
    ];

    private string $username;

    private string $homeDirectory;

    private string $baseDirectory;

    private ProcessRunner $processes;

    private ?string $crontabBinary;

    private array $crontabCandidates;

    private ?string $resolvedCrontab = null;

    public function __construct(
        string $username,
        string $homeDirectory,
        ProcessRunner $processes,
        ?string $crontabBinary = null,
        array $crontabCandidates = []
    ) {
        $this->username = $username;
        $this->homeDirectory = rtrim($homeDirectory, '/');
        $this->baseDirectory = $this->homeDirectory . '/' . self::STATE_DIRECTORY;
        $this->processes = $processes;
        $this->crontabBinary = $crontabBinary;
        $this->crontabCandidates = $crontabCandidates === [] ? self::CRONTAB_CANDIDATES : $crontabCandidates;
    }

    public static function discover(?ProcessRunner $processes = null, ?string $crontabBinary = null): self
    {
        $username = (string) (getenv('USER') ?: getenv('REMOTE_USER') ?: '');

        if ($username === '') {
            $username = function_exists('posix_getpwuid') && function_exists('posix_geteuid')
                ? (string) (posix_getpwuid(posix_geteuid())['name'] ?? '')
                : '';
        }

        $home = (string) (getenv('HOME') ?: '');

        if ($home === '' && $username !== '') {
            $home = '/home/' . $username;
        }

        return new self($username, $home, $processes ?: new ProcessRunner(), $crontabBinary);
    }

    public function username(): string
    {
        return $this->username;
    }

    public function homeDirectory(): string
    {
        return $this->homeDirectory;
    }

    public function baseDirectory(): string
    {
        return $this->baseDirectory;
    }

    public function path(string $relative): string
    {
        return $this->baseDirectory . '/' . ltrim($relative, '/');
    }

    public function runnerPath(): string
    {
        return $this->path('bin/zcd-run');
    }

    public function sentinelPath(): string
    {
        return $this->path('bin/zcd-sentinel');
    }

    public function processes(): ProcessRunner
    {
        return $this->processes;
    }

    public function isUsable(): bool
    {
        return $this->username !== '' && $this->homeDirectory !== '' && is_dir($this->homeDirectory);
    }

    public function canModifyCrontab(): bool
    {
        return $this->processes->available() && $this->crontabBinary() !== null;
    }

    public function crontabBinary(): ?string
    {
        if ($this->crontabBinary !== null) {
            return @is_executable($this->crontabBinary) ? $this->crontabBinary : null;
        }

        if ($this->resolvedCrontab !== null) {
            return $this->resolvedCrontab === '' ? null : $this->resolvedCrontab;
        }

        $this->resolvedCrontab = $this->probeCrontabBinary() ?? '';

        return $this->resolvedCrontab === '' ? null : $this->resolvedCrontab;
    }

    private function probeCrontabBinary(): ?string
    {
        $firstInstalled = null;

        foreach ($this->crontabCandidates as $candidate) {
            if (!@is_executable($candidate)) {
                continue;
            }

            $firstInstalled = $firstInstalled ?? $candidate;

            if (!$this->processes->available()) {
                continue;
            }

            $result = $this->processes->run([$candidate, '-l'], null, 10);

            if ($result->successful() || self::reportsAnEmptyCrontab($result)) {
                return $candidate;
            }
        }

        return $firstInstalled;
    }

    private static function reportsAnEmptyCrontab(ProcessResult $result): bool
    {
        return stripos($result->stderr() . ' ' . $result->stdout(), 'no crontab') !== false;
    }

    public function ensureStateDirectories(): bool
    {
        $created = Filesystem::ensureDirectory($this->baseDirectory);

        foreach (['bin', 'lib', 'jobs', 'runs', 'state', 'locks', 'backups', 'tmp'] as $child) {
            $created = Filesystem::ensureDirectory($this->path($child)) && $created;
        }

        return $created;
    }

    public function stateDirectoryIsShared(): bool
    {
        return is_dir($this->baseDirectory) && Filesystem::isSharedWithOtherUsers($this->baseDirectory);
    }

    public function containsPath(string $candidate): bool
    {
        $real = @realpath($candidate);

        if ($real === false) {
            return false;
        }

        $home = @realpath($this->homeDirectory);

        if ($home === false) {
            return false;
        }

        return $real === $home || strpos($real, rtrim($home, '/') . '/') === 0;
    }
}
