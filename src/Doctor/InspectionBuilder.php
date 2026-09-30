<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Doctor;

use Zactonz\CronDoctor\Job\JobManager;
use Zactonz\CronDoctor\Job\JobStore;
use Zactonz\CronDoctor\Job\Payload;
use Zactonz\CronDoctor\Job\RunHistory;
use Zactonz\CronDoctor\Platform\CrontabGateway;
use Zactonz\CronDoctor\Platform\CrontabSnapshot;
use Zactonz\CronDoctor\Platform\Environment;
use Zactonz\CronDoctor\Platform\PhpBinaries;
use Zactonz\CronDoctor\Support\Clock;

final class InspectionBuilder
{
    private Environment $environment;

    private CrontabGateway $gateway;

    private JobStore $store;

    private RunHistory $history;

    private PhpBinaries $phpBinaries;

    private Payload $payload;

    private Clock $clock;

    public function __construct(
        Environment $environment,
        CrontabGateway $gateway,
        JobStore $store,
        RunHistory $history,
        PhpBinaries $phpBinaries,
        Payload $payload,
        Clock $clock
    ) {
        $this->environment = $environment;
        $this->gateway = $gateway;
        $this->store = $store;
        $this->history = $history;
        $this->phpBinaries = $phpBinaries;
        $this->payload = $payload;
        $this->clock = $clock;
    }

    public function build(?CrontabSnapshot $snapshot = null): Inspection
    {
        $snapshot = $snapshot ?: $this->gateway->read();
        $entries = [];

        foreach ($snapshot->document()->lines() as $line) {
            if (!$line->isJob() || JobManager::isSentinelLine($line)) {
                continue;
            }

            $jobId = JobManager::managedJobId($line);
            $job = $jobId === null ? null : $this->store->find($jobId);

            if ($job !== null && $job->schedule() !== $line->schedule()) {
                $job = $job->withSchedule($line->schedule());
                $this->store->save($job);
            }

            $entries[] = new CronEntry(
                $line,
                $job,
                $job === null ? null : $this->history->latest($job->id()),
                $job === null ? null : $this->history->lastCompleted($job->id()),
                $job === null ? 0 : $this->history->consecutiveFailures($job->id()),
                $job === null ? 0 : $this->history->skippedCount($job->id())
            );
        }

        return new Inspection(
            $snapshot,
            $entries,
            $this->environment,
            $this->phpBinaries,
            $this->clock->now(),
            $this->payload->capabilities(),
            $this->gateway->writable(),
            $this->payload->isCurrent()
        );
    }
}
