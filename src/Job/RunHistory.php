<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Job;

use Zactonz\CronDoctor\Platform\Environment;
use Zactonz\CronDoctor\Support\Filesystem;
use Zactonz\CronDoctor\Support\Json;

final class RunHistory
{
    private const OUTPUT_PREVIEW_BYTES = 262144;

    private Environment $environment;

    public function __construct(Environment $environment)
    {
        $this->environment = $environment;
    }

    public function recent(string $id, int $limit = 20): array
    {
        if (!Job::isValidId($id)) {
            return [];
        }

        $directory = $this->environment->path('runs/' . $id);
        $files = Filesystem::listFiles($directory, '*.json');
        $records = [];

        foreach ($files as $path) {
            $name = basename($path, '.json');

            if (preg_match('/^[0-9]+-[0-9]+$/', $name) !== 1) {
                continue;
            }

            $contents = Filesystem::read($path);
            $data = $contents === null ? null : Json::decode($contents);

            if (!is_array($data)) {
                continue;
            }

            $records[] = new RunRecord($data, $directory . '/' . $name . '.out');
        }

        usort($records, static function (RunRecord $left, RunRecord $right): int {
            return $right->startedAt() <=> $left->startedAt();
        });

        return array_slice($records, 0, $limit);
    }

    public function latest(string $id): ?RunRecord
    {
        $records = $this->recent($id, 1);

        return $records[0] ?? null;
    }

    public function lastCompleted(string $id, int $search = 40): ?RunRecord
    {
        foreach ($this->recent($id, $search) as $record) {
            if (!$record->skipped()) {
                return $record;
            }
        }

        return null;
    }

    public function consecutiveFailures(string $id): int
    {
        if (!Job::isValidId($id)) {
            return 0;
        }

        $contents = Filesystem::read($this->environment->path('state/' . $id . '.json'));
        $data = $contents === null ? null : Json::decode($contents);

        return is_array($data) ? max(0, (int) ($data['consecutive_failures'] ?? 0)) : 0;
    }

    public function skippedCount(string $id, int $window = 20): int
    {
        $skipped = 0;

        foreach ($this->recent($id, $window) as $record) {
            if ($record->skipped()) {
                $skipped++;
            }
        }

        return $skipped;
    }

    public function output(string $id, string $sequence): ?string
    {
        if (!Job::isValidId($id) || preg_match('/^[0-9]+-[0-9]+$/', $sequence) !== 1) {
            return null;
        }

        $path = $this->environment->path('runs/' . $id . '/' . $sequence . '.out');

        if (!is_file($path)) {
            return null;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        $contents = (string) @fread($handle, self::OUTPUT_PREVIEW_BYTES);
        @fclose($handle);

        return $contents;
    }

    public function purge(string $id): void
    {
        if (!Job::isValidId($id)) {
            return;
        }

        Filesystem::removeDirectory($this->environment->path('runs/' . $id));
        Filesystem::ensureDirectory($this->environment->path('runs/' . $id));
    }
}
