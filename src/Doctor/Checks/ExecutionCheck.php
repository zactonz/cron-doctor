<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Doctor\Checks;

use Zactonz\CronDoctor\Doctor\Action;
use Zactonz\CronDoctor\Doctor\Check;
use Zactonz\CronDoctor\Doctor\Finding;
use Zactonz\CronDoctor\Doctor\Inspection;
use Zactonz\CronDoctor\Doctor\Severity;
use Zactonz\CronDoctor\Doctor\Words;

final class ExecutionCheck implements Check
{
    private const LONG_RUN_RATIO = 0.8;

    public function inspect(Inspection $inspection): array
    {
        $findings = [];
        $now = $inspection->now();

        foreach ($inspection->jobEntries() as $entry) {
            $job = $entry->job();

            if ($job === null) {
                continue;
            }

            $failures = $entry->consecutiveFailures();
            $last = $entry->lastCompleted();

            if ($failures > 0 && $last !== null) {
                $findings[] = new Finding(
                    'run.failing',
                    Severity::CRITICAL,
                    $failures === 1 ? 'The last run failed' : sprintf('The last %d runs failed', $failures),
                    sprintf(
                        'This job last finished with exit code %d. Anything other than 0 means the command reported '
                        . 'a failure.%s',
                        $last->exitCode(),
                        $last->hasOutput() ? ' The captured output is on the job page.' : ''
                    ),
                    $entry->line()->number(),
                    $job->id(),
                    new Action('Open the job', 'view-job', ['job' => $job->id()], false)
                );
            }

            if ($entry->isOverdue($now)) {
                $expected = $entry->expectedPreviousRun($now);

                $findings[] = new Finding(
                    'run.overdue',
                    Severity::CRITICAL,
                    'It has not run when it should have',
                    sprintf(
                        'This job was due at %s but Cron Doctor has no record of it running. Either cron is not '
                        . 'running the line at all, or the runner could not start.',
                        $expected === null ? 'its last scheduled time' : $expected->format('j M Y, H:i')
                    ),
                    $entry->line()->number(),
                    $job->id()
                );
            }

            if ($entry->skippedRuns() > 0) {
                $findings[] = new Finding(
                    'run.overlapping',
                    Severity::WARNING,
                    'Runs are overlapping',
                    sprintf(
                        '%d of the recent runs were skipped because the previous run was still going. The job is '
                        . 'taking longer than the gap between runs. Cron Doctor skipped them rather than letting two '
                        . 'copies run at once.',
                        $entry->skippedRuns()
                    ),
                    $entry->line()->number(),
                    $job->id()
                );
            }

            if ($last !== null && $last->timedOut()) {
                $findings[] = new Finding(
                    'run.timeout',
                    Severity::WARNING,
                    'The last run was stopped at its time limit',
                    sprintf('This job was stopped after %d seconds because it reached its time limit.', $job->timeoutSeconds()),
                    $entry->line()->number(),
                    $job->id()
                );
            }

            if ($last !== null && $last->orphansPossible()) {
                $findings[] = new Finding(
                    'run.orphans',
                    Severity::WARNING,
                    'A stopped job may have left work running',
                    'This server has neither the timeout nor the setsid command available, so when a job is stopped '
                    . 'at its time limit any background work it started can keep running. Ask your host to install '
                    . 'coreutils and util-linux, or avoid relying on the time limit.',
                    $entry->line()->number(),
                    $job->id()
                );
            }

            $interval = $entry->intervalSeconds($now);

            if ($last !== null && $interval !== null && $interval > 0 && $job->timeoutSeconds() === 0) {
                $ratio = ($last->durationMs() / 1000) / $interval;

                if ($ratio >= self::LONG_RUN_RATIO) {
                    $findings[] = new Finding(
                        'run.slow',
                        Severity::ADVICE,
                        'It nearly takes as long as the gap between runs',
                        sprintf(
                            'The last run took %s, and this job runs every %s. Runs will start overlapping if it '
                            . 'gets any slower. Setting a time limit would stop a stuck run from piling up.',
                            Words::duration((int) round($last->durationMs() / 1000)),
                            Words::duration($interval)
                        ),
                        $entry->line()->number(),
                        $job->id()
                    );
                }
            }
        }

        return $findings;
    }
}
