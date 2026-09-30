<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Cron;

final class CommandName
{
    public const FALLBACK = 'Cron job';

    private const WRAPPERS = [
        'php', 'php-cgi', 'sh', 'bash', 'dash', 'zsh', 'ksh',
        'cd', 'env', 'nice', 'ionice', 'nohup', 'flock', 'timeout', 'setsid', 'exec',
    ];

    private const PLAIN = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    public static function describe(string $command): string
    {
        $tokens = CommandTokens::split($command);
        $pathLike = null;
        $plain = null;
        $wrapper = null;
        $skipNext = false;

        foreach ($tokens->all() as $token) {
            $text = $token['text'];

            if ($skipNext) {
                $skipNext = false;
                continue;
            }

            if ($text === '' || strpos($text, '=') !== false || $text[0] === '-') {
                continue;
            }

            if (strpbrk($text, '<>|;&') !== false) {
                continue;
            }

            $name = basename($text);

            if (preg_match(self::PLAIN, $name) !== 1 || preg_match('/[A-Za-z]/', $name) !== 1) {
                continue;
            }

            if ($name === 'cd') {
                $wrapper = $wrapper ?? $name;
                $skipNext = true;
                continue;
            }

            if (in_array($name, self::WRAPPERS, true)) {
                $wrapper = $wrapper ?? $name;
                continue;
            }

            if (strpos($text, '/') !== false) {
                $pathLike = $pathLike ?? $name;
                continue;
            }

            $plain = $plain ?? $name;
        }

        return $pathLike ?? $plain ?? $wrapper ?? self::FALLBACK;
    }
}
