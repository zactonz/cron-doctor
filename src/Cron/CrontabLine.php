<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Cron;

final class CrontabLine
{
    public const TYPE_BLANK = 'blank';
    public const TYPE_COMMENT = 'comment';
    public const TYPE_VARIABLE = 'variable';
    public const TYPE_JOB = 'job';
    public const TYPE_UNKNOWN = 'unknown';

    private const NUMERIC_FIELD = '/^[0-9*,\/?-]+$/';

    private const NAMED_FIELD = '/^[0-9A-Za-z*,\/?-]+$/';

    private string $raw;

    private string $type;

    private int $number;

    private string $schedule = '';

    private string $commandText = '';

    private string $command = '';

    private ?string $input = null;

    private string $name = '';

    private string $value = '';

    private bool $logSuppressed = false;

    private function __construct(string $raw, string $type, int $number)
    {
        $this->raw = $raw;
        $this->type = $type;
        $this->number = $number;
    }

    public static function parse(string $raw, int $number): self
    {
        $trimmed = ltrim($raw, " \t");
        $bare = rtrim($trimmed, "\r");

        if ($bare === '') {
            return new self($raw, self::TYPE_BLANK, $number);
        }

        if ($bare[0] === '#') {
            return new self($raw, self::TYPE_COMMENT, $number);
        }

        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\s*=(.*)$/', $bare, $matches) === 1) {
            $line = new self($raw, self::TYPE_VARIABLE, $number);
            $line->name = $matches[1];
            $line->value = self::unquote(trim($matches[2]));

            return $line;
        }

        $logSuppressed = false;

        if ($bare[0] === '-') {
            $logSuppressed = true;
            $bare = ltrim(substr($bare, 1), " \t");
        }

        if ($bare === '') {
            return new self($raw, self::TYPE_UNKNOWN, $number);
        }

        if ($bare[0] === '@') {
            $parts = preg_split('/\s+/', $bare, 2);

            if (!is_array($parts) || count($parts) !== 2 || trim($parts[1]) === '') {
                return new self($raw, self::TYPE_UNKNOWN, $number);
            }

            return self::job($raw, $number, $parts[0], $parts[1], $logSuppressed);
        }

        $parts = preg_split('/\s+/', $bare, 6);

        if (!is_array($parts) || count($parts) !== 6 || trim($parts[5]) === '') {
            return new self($raw, self::TYPE_UNKNOWN, $number);
        }

        for ($field = 0; $field < 5; $field++) {
            $pattern = $field < 3 ? self::NUMERIC_FIELD : self::NAMED_FIELD;

            if (preg_match($pattern, $parts[$field]) !== 1) {
                return new self($raw, self::TYPE_UNKNOWN, $number);
            }
        }

        return self::job(
            $raw,
            $number,
            implode(' ', array_slice($parts, 0, 5)),
            $parts[5],
            $logSuppressed
        );
    }

    private static function job(
        string $raw,
        int $number,
        string $schedule,
        string $commandText,
        bool $logSuppressed
    ): self {
        $line = new self($raw, self::TYPE_JOB, $number);
        $line->schedule = $schedule;
        $line->commandText = $commandText;
        $line->logSuppressed = $logSuppressed;

        [$line->command, $line->input] = self::decode($commandText);

        return $line;
    }

    public static function decode(string $text): array
    {
        $command = '';
        $length = strlen($text);

        for ($index = 0; $index < $length; $index++) {
            $character = $text[$index];

            if ($character === '\\' && $index + 1 < $length && $text[$index + 1] === '%') {
                $command .= '%';
                $index++;
                continue;
            }

            if ($character === '%') {
                return [$command, self::decodeInput(substr($text, $index + 1))];
            }

            $command .= $character;
        }

        return [$command, null];
    }

    public static function encode(string $command, ?string $input): string
    {
        $text = str_replace('%', '\\%', $command);

        if ($input === null) {
            return $text;
        }

        return $text . '%' . str_replace(['%', "\n"], ['\\%', '%'], $input);
    }

    public static function isEncodable(string $command, ?string $input): bool
    {
        [$decodedCommand, $decodedInput] = self::decode(self::encode($command, $input));

        return $decodedCommand === $command && $decodedInput === $input;
    }

    private static function decodeInput(string $text): string
    {
        $input = '';
        $length = strlen($text);

        for ($index = 0; $index < $length; $index++) {
            $character = $text[$index];

            if ($character === '\\' && $index + 1 < $length && $text[$index + 1] === '%') {
                $input .= '%';
                $index++;
                continue;
            }

            $input .= $character === '%' ? "\n" : $character;
        }

        return $input;
    }

    private static function unquote(string $value): string
    {
        $length = strlen($value);

        if ($length >= 2) {
            $first = $value[0];

            if (($first === '"' || $first === "'") && substr($value, -1) === $first) {
                return substr($value, 1, $length - 2);
            }
        }

        return $value;
    }

    public function raw(): string
    {
        return $this->raw;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function number(): int
    {
        return $this->number;
    }

    public function isJob(): bool
    {
        return $this->type === self::TYPE_JOB;
    }

    public function isVariable(): bool
    {
        return $this->type === self::TYPE_VARIABLE;
    }

    public function schedule(): string
    {
        return $this->schedule;
    }

    public function commandText(): string
    {
        return $this->commandText;
    }

    public function command(): string
    {
        return $this->command;
    }

    public function input(): ?string
    {
        return $this->input;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function logSuppressed(): bool
    {
        return $this->logSuppressed;
    }

    public function hasCarriageReturn(): bool
    {
        return strpos($this->raw, "\r") !== false;
    }

    public function expression(): ?CronExpression
    {
        if (!$this->isJob() || $this->schedule === '') {
            return null;
        }

        return CronExpression::tryParse($this->schedule);
    }

    public function withCommand(string $command, ?string $input): ?self
    {
        if (!self::isEncodable($command, $input)) {
            return null;
        }

        $rendered = sprintf(
            '%s%s %s',
            $this->logSuppressed ? '-' : '',
            $this->schedule,
            self::encode($command, $input)
        );

        return self::parse($rendered, $this->number);
    }
}
