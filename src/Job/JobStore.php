<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Job;

use Zactonz\CronDoctor\Platform\Environment;
use Zactonz\CronDoctor\Support\Filesystem;
use Zactonz\CronDoctor\Support\Json;
use Zactonz\CronDoctor\Support\Result;

final class JobStore
{
    private const REGISTRY = 'jobs.json';

    private const LOCK = 'locks/jobs.lock';

    private Environment $environment;

    private ?array $cache = null;

    public function __construct(Environment $environment)
    {
        $this->environment = $environment;
    }

    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $contents = Filesystem::read($this->environment->path(self::REGISTRY));
        $decoded = $contents === null ? null : Json::decode($contents);
        $jobs = [];

        if (is_array($decoded) && isset($decoded['jobs']) && is_array($decoded['jobs'])) {
            foreach ($decoded['jobs'] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }

                $job = Job::fromArray($entry);

                if ($job !== null) {
                    $jobs[$job->id()] = $job;
                }
            }
        }

        $this->cache = $jobs;

        return $jobs;
    }

    public function find(string $id): ?Job
    {
        if (!Job::isValidId($id)) {
            return null;
        }

        $jobs = $this->all();

        return $jobs[$id] ?? null;
    }

    public function save(Job $job): Result
    {
        $this->environment->ensureStateDirectories();

        $written = $this->writeJobFiles($job);

        if ($written->failed()) {
            return $written;
        }

        return $this->mutate(static function (array $jobs) use ($job): array {
            $jobs[$job->id()] = $job;

            return $jobs;
        });
    }

    public function remove(string $id): Result
    {
        if (!Job::isValidId($id)) {
            return Result::fail('That job identifier is not valid.');
        }

        $result = $this->mutate(static function (array $jobs) use ($id): array {
            unset($jobs[$id]);

            return $jobs;
        });

        if ($result->failed()) {
            return $result;
        }

        foreach (['jobs/' . $id . '.cmd', 'jobs/' . $id . '.conf', 'jobs/' . $id . '.in', 'state/' . $id . '.json'] as $relative) {
            @unlink($this->environment->path($relative));
        }

        Filesystem::removeDirectory($this->environment->path('runs/' . $id));
        @unlink($this->environment->path('locks/' . $id . '.lock'));
        Filesystem::removeDirectory($this->environment->path('locks/' . $id . '.lockdir'));

        return Result::ok('The job was removed from Cron Doctor.');
    }

    public function writeJobFiles(Job $job): Result
    {
        $this->environment->ensureStateDirectories();

        $commandPath = $this->environment->path('jobs/' . $job->id() . '.cmd');
        $configurationPath = $this->environment->path('jobs/' . $job->id() . '.conf');
        $inputPath = $this->environment->path('jobs/' . $job->id() . '.in');

        if (!Filesystem::writeAtomically($commandPath, rtrim($job->command(), "\n") . "\n")) {
            return Result::fail('The job command could not be saved.');
        }

        if (!Filesystem::writeAtomically($configurationPath, $job->configurationFile())) {
            return Result::fail('The job settings could not be saved.');
        }

        if ($job->input() === null) {
            @unlink($inputPath);

            return Result::ok();
        }

        if (!Filesystem::writeAtomically($inputPath, $job->input())) {
            return Result::fail('The job input could not be saved.');
        }

        return Result::ok();
    }

    public function commandPath(string $id): string
    {
        return $this->environment->path('jobs/' . $id . '.cmd');
    }

    public function jobFilesArePresent(Job $job): bool
    {
        return is_file($this->commandPath($job->id()))
            && is_file($this->environment->path('jobs/' . $job->id() . '.conf'));
    }

    private function mutate(callable $change): Result
    {
        $path = $this->environment->path(self::REGISTRY);
        $lock = $this->environment->path(self::LOCK);

        $outcome = Filesystem::withLock($lock, static function () use ($path, $change): bool {
            $contents = Filesystem::read($path);
            $decoded = $contents === null ? null : Json::decode($contents);
            $jobs = [];

            if (is_array($decoded) && isset($decoded['jobs']) && is_array($decoded['jobs'])) {
                foreach ($decoded['jobs'] as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }

                    $job = Job::fromArray($entry);

                    if ($job !== null) {
                        $jobs[$job->id()] = $job;
                    }
                }
            }

            $jobs = $change($jobs);
            $payload = ['version' => 1, 'jobs' => []];

            foreach ($jobs as $job) {
                $payload['jobs'][] = $job->toArray();
            }

            return Filesystem::writeAtomically($path, Json::encode($payload));
        });

        $this->cache = null;

        return $outcome === true
            ? Result::ok()
            : Result::fail('The job list could not be saved. Check that your home directory is writable.');
    }
}
