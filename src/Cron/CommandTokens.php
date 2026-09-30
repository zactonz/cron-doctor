<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Cron;

final class CommandTokens
{
    private array $tokens;

    private bool $balanced;

    private function __construct(array $tokens, bool $balanced)
    {
        $this->tokens = $tokens;
        $this->balanced = $balanced;
    }

    public static function split(string $command): self
    {
        $tokens = [];
        $length = strlen($command);
        $index = 0;
        $state = 'plain';

        while ($index < $length) {
            while ($index < $length && ($command[$index] === ' ' || $command[$index] === "\t")) {
                $index++;
            }

            if ($index >= $length) {
                break;
            }

            $start = $index;
            $text = '';
            $state = 'plain';

            while ($index < $length) {
                $character = $command[$index];

                if ($state === 'plain') {
                    if ($character === ' ' || $character === "\t") {
                        break;
                    }

                    if ($character === '\\' && $index + 1 < $length) {
                        $text .= $command[$index + 1];
                        $index += 2;
                        continue;
                    }

                    if ($character === "'") {
                        $state = 'single';
                        $index++;
                        continue;
                    }

                    if ($character === '"') {
                        $state = 'double';
                        $index++;
                        continue;
                    }

                    $text .= $character;
                    $index++;
                    continue;
                }

                if ($state === 'single') {
                    if ($character === "'") {
                        $state = 'plain';
                        $index++;
                        continue;
                    }

                    $text .= $character;
                    $index++;
                    continue;
                }

                if ($character === '\\' && $index + 1 < $length) {
                    $next = $command[$index + 1];

                    if ($next === '"' || $next === '\\' || $next === '$' || $next === '`') {
                        $text .= $next;
                        $index += 2;
                        continue;
                    }

                    $text .= $character;
                    $index++;
                    continue;
                }

                if ($character === '"') {
                    $state = 'plain';
                    $index++;
                    continue;
                }

                $text .= $character;
                $index++;
            }

            $tokens[] = [
                'text' => $text,
                'offset' => $start,
                'length' => $index - $start,
            ];
        }

        return new self($tokens, $state === 'plain');
    }

    public function all(): array
    {
        return $this->tokens;
    }

    public function count(): int
    {
        return count($this->tokens);
    }

    public function balanced(): bool
    {
        return $this->balanced;
    }

    public function textAt(int $index): ?string
    {
        return $this->tokens[$index]['text'] ?? null;
    }

    public function replace(string $command, int $index, string $replacement): ?string
    {
        if (!isset($this->tokens[$index])) {
            return null;
        }

        $token = $this->tokens[$index];

        return substr($command, 0, $token['offset'])
            . $replacement
            . substr($command, $token['offset'] + $token['length']);
    }
}
