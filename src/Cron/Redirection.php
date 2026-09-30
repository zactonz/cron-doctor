<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Cron;

final class Redirection
{
    public const SINK_INHERITED = 'inherited';

    public const DISCARD = '/dev/null';

    private const PROTECTED_BYTE = "\x01";

    private const MAX_UNITS = 12;

    private const SEPARATORS = '/(?<![|&])\|(?!\|)|\|\||&&|;/';

    private string $command;

    private string $stdout;

    private string $stderr;

    private ?int $tailOffset;

    private bool $tailDiscardsOnly;

    private bool $balancedQuotes;

    private bool $compound;

    private function __construct(
        string $command,
        string $stdout,
        string $stderr,
        ?int $tailOffset,
        bool $tailDiscardsOnly,
        bool $balancedQuotes,
        bool $compound
    ) {
        $this->command = $command;
        $this->stdout = $stdout;
        $this->stderr = $stderr;
        $this->tailOffset = $tailOffset;
        $this->tailDiscardsOnly = $tailDiscardsOnly;
        $this->balancedQuotes = $balancedQuotes;
        $this->compound = $compound;
    }

    public static function analyse(string $command): self
    {
        $mask = self::mask($command);
        $masked = $mask['masked'];
        $balanced = $mask['balanced'];

        $compound = preg_match(self::SEPARATORS, $masked) === 1;

        $units = [];
        $cursor = strlen($masked);

        for ($guard = 0; $guard < self::MAX_UNITS; $guard++) {
            $unit = self::matchTrailingUnit(substr($masked, 0, $cursor), $command);

            if ($unit === null) {
                break;
            }

            array_unshift($units, $unit);
            $cursor = $unit['offset'];
        }

        [$stdout, $stderr] = self::simulate($units);

        $tailOffset = $units === [] ? null : $units[0]['offset'];
        $discardsOnly = $units !== [];

        foreach ($units as $unit) {
            if ($unit['target'] !== null && $unit['target'] !== self::DISCARD) {
                $discardsOnly = false;
            }
        }

        return new self($command, $stdout, $stderr, $tailOffset, $discardsOnly, $balanced, $compound);
    }

    public function stdout(): string
    {
        return $this->stdout;
    }

    public function stderr(): string
    {
        return $this->stderr;
    }

    public function discardsStdout(): bool
    {
        return $this->stdout === self::DISCARD;
    }

    public function discardsStderr(): bool
    {
        return $this->stderr === self::DISCARD;
    }

    public function discardsEverything(): bool
    {
        return $this->discardsStdout() && $this->discardsStderr();
    }

    public function hasBalancedQuotes(): bool
    {
        return $this->balancedQuotes;
    }

    public function isCompound(): bool
    {
        return $this->compound;
    }

    public function capturesToFile(): ?string
    {
        if ($this->stdout !== self::SINK_INHERITED && $this->stdout !== self::DISCARD) {
            return $this->stdout;
        }

        return null;
    }

    public function isRecoverable(): bool
    {
        return $this->balancedQuotes
            && $this->tailOffset !== null
            && $this->tailDiscardsOnly
            && ($this->discardsStdout() || $this->discardsStderr());
    }

    public function withoutTrailingDiscard(): ?string
    {
        if (!$this->isRecoverable()) {
            return null;
        }

        $stripped = rtrim(substr($this->command, 0, $this->tailOffset));

        return $stripped === '' ? null : $stripped;
    }

    private static function simulate(array $units): array
    {
        $stdout = self::SINK_INHERITED;
        $stderr = self::SINK_INHERITED;

        foreach ($units as $unit) {
            if ($unit['kind'] === 'both') {
                $stdout = (string) $unit['target'];
                $stderr = (string) $unit['target'];
                continue;
            }

            if ($unit['kind'] === 'file') {
                if ($unit['from'] === '2') {
                    $stderr = (string) $unit['target'];
                } else {
                    $stdout = (string) $unit['target'];
                }

                continue;
            }

            if ($unit['from'] === '2') {
                $stderr = $unit['to'] === '1' ? $stdout : $stderr;
                continue;
            }

            $stdout = $unit['to'] === '2' ? $stderr : $stdout;
        }

        return [$stdout, $stderr];
    }

    private static function matchTrailingUnit(string $masked, string $original): ?array
    {
        if (preg_match('/(?:^|\s)(?P<from>[12])>&(?P<to>[12])\s*$/', $masked, $matches, PREG_OFFSET_CAPTURE) === 1) {
            return [
                'offset' => self::unitStart($masked, $matches[0][1]),
                'target' => null,
                'kind' => 'dup',
                'from' => $matches['from'][0],
                'to' => $matches['to'][0],
            ];
        }

        if (preg_match('/(?:^|\s)&>>?\s*(?P<target>\S+)\s*$/', $masked, $matches, PREG_OFFSET_CAPTURE) === 1) {
            return [
                'offset' => self::unitStart($masked, $matches[0][1]),
                'target' => self::target($original, $matches['target']),
                'kind' => 'both',
                'from' => '',
                'to' => '',
            ];
        }

        if (preg_match('/(?:^|\s)(?P<from>[12])?>>?\s*(?P<target>\S+)\s*$/', $masked, $matches, PREG_OFFSET_CAPTURE) === 1) {
            return [
                'offset' => self::unitStart($masked, $matches[0][1]),
                'target' => self::target($original, $matches['target']),
                'kind' => 'file',
                'from' => $matches['from'][0] === '' ? '1' : $matches['from'][0],
                'to' => '',
            ];
        }

        return null;
    }

    private static function unitStart(string $masked, int $matchOffset): int
    {
        if ($matchOffset < strlen($masked) && preg_match('/\s/', $masked[$matchOffset]) === 1) {
            return $matchOffset + 1;
        }

        return $matchOffset;
    }

    private static function target(string $original, array $capture): string
    {
        return substr($original, $capture[1], strlen($capture[0]));
    }

    private static function mask(string $command): array
    {
        $length = strlen($command);
        $masked = '';
        $state = 'plain';

        for ($index = 0; $index < $length; $index++) {
            $character = $command[$index];

            if ($state === 'plain') {
                if ($character === '\\') {
                    $masked .= self::PROTECTED_BYTE;

                    if ($index + 1 < $length) {
                        $masked .= self::PROTECTED_BYTE;
                        $index++;
                    }

                    continue;
                }

                if ($character === "'" || $character === '"') {
                    $state = $character === "'" ? 'single' : 'double';
                    $masked .= self::PROTECTED_BYTE;
                    continue;
                }

                $masked .= $character;
                continue;
            }

            $masked .= self::PROTECTED_BYTE;

            if ($state === 'single') {
                if ($character === "'") {
                    $state = 'plain';
                }

                continue;
            }

            if ($character === '\\' && $index + 1 < $length) {
                $masked .= self::PROTECTED_BYTE;
                $index++;
                continue;
            }

            if ($character === '"') {
                $state = 'plain';
            }
        }

        return ['masked' => $masked, 'balanced' => $state === 'plain'];
    }
}
