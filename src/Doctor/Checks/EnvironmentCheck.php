<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Doctor\Checks;

use Zactonz\CronDoctor\Doctor\Action;
use Zactonz\CronDoctor\Doctor\Check;
use Zactonz\CronDoctor\Doctor\Finding;
use Zactonz\CronDoctor\Doctor\Inspection;
use Zactonz\CronDoctor\Doctor\Severity;
use Zactonz\CronDoctor\Platform\CrontabSnapshot;

final class EnvironmentCheck implements Check
{
    public function inspect(Inspection $inspection): array
    {
        $findings = [];

        if (!$inspection->snapshot()->readable()) {
            return [
                new Finding(
                    'environment.unreadable',
                    Severity::CRITICAL,
                    'The crontab could not be read',
                    'Cron Doctor could not read this account\'s crontab, either through the crontab command or the '
                    . 'cPanel API. Nothing can be diagnosed until that is resolved.'
                ),
            ];
        }

        if (!$inspection->writable()) {
            $findings[] = new Finding(
                'environment.read-only',
                Severity::WARNING,
                'Cron Doctor is in read-only mode',
                $inspection->snapshot()->source() === CrontabSnapshot::SOURCE_API
                    ? 'This account cannot run the crontab command, so Cron Doctor reads the crontab through the '
                    . 'cPanel API and will not change it. Every check still runs, and each fix explains what to '
                    . 'change by hand in the Cron Jobs page.'
                    : 'Running commands is disabled for this account, so Cron Doctor will not change the crontab. '
                    . 'Every check still runs, and each fix explains what to change by hand.'
            );
        }

        if (!$inspection->runnerCurrent() && $inspection->writable()) {
            $findings[] = new Finding(
                'environment.runner-stale',
                Severity::WARNING,
                'The runner in your home directory is out of date',
                'Cron Doctor keeps a small runner script in your home directory, and the copy there does not match '
                . 'the installed version of the plugin. Repairing it replaces the script without touching your jobs.',
                null,
                null,
                new Action('Repair the runner', 'repair-runner', [])
            );
        }

        if ($inspection->environment()->stateDirectoryIsShared()) {
            $findings[] = new Finding(
                'environment.permissions',
                Severity::CRITICAL,
                'Other users can read the Cron Doctor folder',
                sprintf(
                    'The folder %s can be read by other users on this server. Captured output often contains '
                    . 'information you would not publish. Repairing the runner also resets the permissions.',
                    $inspection->environment()->baseDirectory()
                ),
                null,
                null,
                new Action('Repair permissions', 'repair-runner', [])
            );
        }

        $capabilities = $inspection->capabilities();

        if ($capabilities !== [] && ($capabilities['timeout_is_reliable'] ?? '1') === '0') {
            $findings[] = new Finding(
                'environment.timeout',
                Severity::ADVICE,
                'Time limits cannot be enforced cleanly here',
                'Neither the timeout command nor setsid is available to this account. Cron Doctor can still stop a '
                . 'job that runs too long, but any background work the job started may keep running. Time limits are '
                . 'off by default, so this only matters if you turn one on.'
            );
        }

        return $findings;
    }
}
