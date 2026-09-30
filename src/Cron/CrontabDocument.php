<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Cron;

final class CrontabDocument
{
    private array $lines;

    private bool $trailingNewline;

    private function __construct(array $lines, bool $trailingNewline)
    {
        $this->lines = $lines;
        $this->trailingNewline = $trailingNewline;
    }

    public static function parse(string $raw): self
    {
        if ($raw === '') {
            return new self([], false);
        }

        $trailingNewline = substr($raw, -1) === "\n";
        $body = $trailingNewline ? substr($raw, 0, -1) : $raw;

        $lines = [];

        foreach (explode("\n", $body) as $index => $text) {
            $lines[] = CrontabLine::parse($text, $index);
        }

        return new self($lines, $trailingNewline);
    }

    public static function fromLines(array $rawLines): self
    {
        $lines = [];

        foreach (array_values($rawLines) as $index => $text) {
            $lines[] = CrontabLine::parse((string) $text, $index);
        }

        return new self($lines, true);
    }

    public function render(): string
    {
        if ($this->lines === []) {
            return $this->trailingNewline ? "\n" : '';
        }

        $texts = [];

        foreach ($this->lines as $line) {
            $texts[] = $line->raw();
        }

        return implode("\n", $texts) . ($this->trailingNewline ? "\n" : '');
    }

    public function lines(): array
    {
        return $this->lines;
    }

    public function jobs(): array
    {
        return array_values(array_filter($this->lines, static function (CrontabLine $line): bool {
            return $line->isJob();
        }));
    }

    public function count(): int
    {
        return count($this->lines);
    }

    public function lineAt(int $number): ?CrontabLine
    {
        return $this->lines[$number] ?? null;
    }

    public function variable(string $name): ?string
    {
        $found = null;

        foreach ($this->lines as $line) {
            if ($line->isVariable() && $line->name() === $name) {
                $found = $line->value();
            }
        }

        return $found;
    }

    public function hasCarriageReturns(): bool
    {
        foreach ($this->lines as $line) {
            if ($line->hasCarriageReturn()) {
                return true;
            }
        }

        return false;
    }

    public function withLineAt(int $number, string $raw): self
    {
        if (!isset($this->lines[$number])) {
            return $this;
        }

        $lines = $this->lines;
        $lines[$number] = CrontabLine::parse($raw, $number);

        return new self($lines, $this->trailingNewline);
    }

    public function withoutLineAt(int $number): self
    {
        if (!isset($this->lines[$number])) {
            return $this;
        }

        $lines = $this->lines;
        unset($lines[$number]);

        return self::reindex(array_values($lines), $this->trailingNewline);
    }

    public function withAppendedLine(string $raw): self
    {
        $lines = $this->lines;

        if ($lines !== [] && !$this->trailingNewline) {
            $lines[] = CrontabLine::parse('', count($lines));
        }

        $lines[] = CrontabLine::parse($raw, count($lines));

        return self::reindex($lines, true);
    }

    public function fingerprint(): string
    {
        return hash('sha256', $this->render());
    }

    private static function reindex(array $lines, bool $trailingNewline): self
    {
        $rebuilt = [];

        foreach (array_values($lines) as $index => $line) {
            $rebuilt[] = CrontabLine::parse($line->raw(), $index);
        }

        return new self($rebuilt, $trailingNewline);
    }
}
