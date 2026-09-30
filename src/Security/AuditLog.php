<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Security;

use Zactonz\CronDoctor\Platform\Environment;
use Zactonz\CronDoctor\Support\Filesystem;

final class AuditLog
{
    private const FILE = 'audit.log';

    private const MAX_BYTES = 262144;

    private Environment $environment;

    public function __construct(Environment $environment)
    {
        $this->environment = $environment;
    }

    public function record(string $action, bool $successful, string $message, array $context = []): void
    {
        $path = $this->environment->path(self::FILE);

        $this->rotate($path);

        $parts = [];

        foreach ($context as $key => $value) {
            $parts[] = $key . '=' . preg_replace('/[^\x20-\x7E]/', '', (string) $value);
        }

        $line = sprintf(
            "%s\t%s\t%s\t%s\t%s\n",
            gmdate('Y-m-d\TH:i:s\Z'),
            $action,
            $successful ? 'ok' : 'failed',
            implode(' ', $parts),
            str_replace(["\r", "\n", "\t"], ' ', $message)
        );

        $handle = @fopen($path, 'ab');

        if ($handle === false) {
            return;
        }

        @chmod($path, Filesystem::FILE_MODE);

        if (@flock($handle, LOCK_EX)) {
            @fwrite($handle, $line);
            @flock($handle, LOCK_UN);
        }

        @fclose($handle);
    }

    public function recent(int $limit = 50): array
    {
        $contents = Filesystem::read($this->environment->path(self::FILE));

        if ($contents === null) {
            return [];
        }

        $lines = array_values(array_filter(explode("\n", trim($contents))));
        $entries = [];

        foreach (array_slice(array_reverse($lines), 0, $limit) as $line) {
            $parts = explode("\t", $line);

            if (count($parts) < 5) {
                continue;
            }

            $entries[] = [
                'at' => $parts[0],
                'action' => $parts[1],
                'outcome' => $parts[2],
                'context' => $parts[3],
                'message' => $parts[4],
            ];
        }

        return $entries;
    }

    private function rotate(string $path): void
    {
        if (!is_file($path) || (int) @filesize($path) < self::MAX_BYTES) {
            return;
        }

        $contents = Filesystem::read($path);

        if ($contents === null) {
            return;
        }

        Filesystem::writeAtomically($path, substr($contents, (int) (self::MAX_BYTES / 2)));
    }
}
