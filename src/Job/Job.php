<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Job;

final class Job
{
    public const OVERLAP_SKIP = 'skip';
    public const OVERLAP_ALLOW = 'allow';

    public const EMIT_ON_FAILURE = 'on-failure';
    public const EMIT_ALWAYS = 'always';
    public const EMIT_NEVER = 'never';

    public const DEFAULT_RETAIN = 20;
    public const DEFAULT_OUTPUT_LIMIT = 262144;

    public const MAX_RETAIN = 200;
    public const MAX_OUTPUT_LIMIT = 4194304;
    public const MAX_TIMEOUT = 86400;

    private string $id;

    private string $name;

    private string $command;

    private ?string $input;

    private string $originalLine;

    private string $originalCommand;

    private string $schedule = '';

    private int $createdAt;

    private int $timeoutSeconds = 0;

    private string $onOverlap = self::OVERLAP_SKIP;

    private int $retainRuns = self::DEFAULT_RETAIN;

    private int $outputLimitBytes = self::DEFAULT_OUTPUT_LIMIT;

    private string $emit = self::EMIT_ON_FAILURE;

    private function __construct(string $id)
    {
        $this->id = $id;
        $this->name = $id;
        $this->command = '';
        $this->input = null;
        $this->originalLine = '';
        $this->originalCommand = '';
        $this->createdAt = time();
    }

    public static function create(
        string $name,
        string $command,
        ?string $input,
        string $originalLine,
        string $originalCommand,
        string $schedule
    ): self {
        $job = new self(bin2hex(random_bytes(8)));
        $job->name = self::cleanName($name);
        $job->command = $command;
        $job->input = $input;
        $job->originalLine = $originalLine;
        $job->originalCommand = $originalCommand;
        $job->schedule = self::cleanSchedule($schedule);

        return $job;
    }

    public static function fromArray(array $data): ?self
    {
        $id = (string) ($data['id'] ?? '');

        if (!self::isValidId($id)) {
            return null;
        }

        $job = new self($id);
        $job->name = self::cleanName((string) ($data['name'] ?? $id));
        $job->command = (string) ($data['command'] ?? '');
        $job->input = isset($data['input']) && is_string($data['input']) ? $data['input'] : null;
        $job->originalLine = (string) ($data['original_line'] ?? '');
        $job->originalCommand = (string) ($data['original_command'] ?? '');
        $job->schedule = self::cleanSchedule((string) ($data['schedule'] ?? ''));
        $job->createdAt = (int) ($data['created_at'] ?? time());
        $job->timeoutSeconds = self::clamp((int) ($data['timeout_seconds'] ?? 0), 0, self::MAX_TIMEOUT);
        $job->retainRuns = self::clamp((int) ($data['retain_runs'] ?? self::DEFAULT_RETAIN), 1, self::MAX_RETAIN);
        $job->outputLimitBytes = self::clamp(
            (int) ($data['output_limit_bytes'] ?? self::DEFAULT_OUTPUT_LIMIT),
            1024,
            self::MAX_OUTPUT_LIMIT
        );
        $job->onOverlap = self::validOverlap((string) ($data['on_overlap'] ?? self::OVERLAP_SKIP));
        $job->emit = self::validEmit((string) ($data['emit'] ?? self::EMIT_ON_FAILURE));

        return $job;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'command' => $this->command,
            'input' => $this->input,
            'original_line' => $this->originalLine,
            'original_command' => $this->originalCommand,
            'schedule' => $this->schedule,
            'created_at' => $this->createdAt,
            'timeout_seconds' => $this->timeoutSeconds,
            'on_overlap' => $this->onOverlap,
            'retain_runs' => $this->retainRuns,
            'output_limit_bytes' => $this->outputLimitBytes,
            'emit' => $this->emit,
        ];
    }

    public function configurationFile(): string
    {
        $settings = [
            'name' => $this->name,
            'timeout_seconds' => (string) $this->timeoutSeconds,
            'on_overlap' => $this->onOverlap,
            'retain_runs' => (string) $this->retainRuns,
            'output_limit_bytes' => (string) $this->outputLimitBytes,
            'emit' => $this->emit,
        ];

        $lines = [];

        foreach ($settings as $key => $value) {
            $lines[] = $key . '=' . str_replace(["\r", "\n"], ' ', $value);
        }

        return implode("\n", $lines) . "\n";
    }

    public static function isValidId(string $id): bool
    {
        return preg_match('/^[0-9a-f]{16}$/', $id) === 1;
    }

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function command(): string
    {
        return $this->command;
    }

    public function input(): ?string
    {
        return $this->input;
    }

    public function originalLine(): string
    {
        return $this->originalLine;
    }

    public function originalCommand(): string
    {
        return $this->originalCommand;
    }

    public function schedule(): string
    {
        return $this->schedule;
    }

    public function withSchedule(string $schedule): self
    {
        $copy = clone $this;
        $copy->schedule = self::cleanSchedule($schedule);

        return $copy;
    }

    public function createdAt(): int
    {
        return $this->createdAt;
    }

    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    public function onOverlap(): string
    {
        return $this->onOverlap;
    }

    public function retainRuns(): int
    {
        return $this->retainRuns;
    }

    public function outputLimitBytes(): int
    {
        return $this->outputLimitBytes;
    }

    public function emit(): string
    {
        return $this->emit;
    }

    public function withCommand(string $command, ?string $input): self
    {
        $copy = clone $this;
        $copy->command = $command;
        $copy->input = $input;

        return $copy;
    }

    public function withName(string $name): self
    {
        $copy = clone $this;
        $copy->name = self::cleanName($name);

        return $copy;
    }

    public function withSettings(
        int $timeoutSeconds,
        string $onOverlap,
        int $retainRuns,
        int $outputLimitBytes,
        string $emit
    ): self {
        $copy = clone $this;
        $copy->timeoutSeconds = self::clamp($timeoutSeconds, 0, self::MAX_TIMEOUT);
        $copy->onOverlap = self::validOverlap($onOverlap);
        $copy->retainRuns = self::clamp($retainRuns, 1, self::MAX_RETAIN);
        $copy->outputLimitBytes = self::clamp($outputLimitBytes, 1024, self::MAX_OUTPUT_LIMIT);
        $copy->emit = self::validEmit($emit);

        return $copy;
    }

    private static function cleanSchedule(string $schedule): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', $schedule) ?? '');

        return preg_match('/^[0-9A-Za-z@*,\/? -]{0,120}$/', $clean) === 1 ? $clean : '';
    }

    private static function cleanName(string $name): string
    {
        $clean = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name) ?? '');

        if ($clean === '') {
            return 'Cron job';
        }

        return mb_substr($clean, 0, 80);
    }

    private static function clamp(int $value, int $minimum, int $maximum): int
    {
        return max($minimum, min($maximum, $value));
    }

    private static function validOverlap(string $value): string
    {
        return $value === self::OVERLAP_ALLOW ? self::OVERLAP_ALLOW : self::OVERLAP_SKIP;
    }

    private static function validEmit(string $value): string
    {
        return in_array($value, [self::EMIT_ALWAYS, self::EMIT_NEVER, self::EMIT_ON_FAILURE], true)
            ? $value
            : self::EMIT_ON_FAILURE;
    }
}
