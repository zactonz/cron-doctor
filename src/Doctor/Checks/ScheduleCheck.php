<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Doctor\Checks;

use Zactonz\CronDoctor\Doctor\Check;
use Zactonz\CronDoctor\Doctor\Finding;
use Zactonz\CronDoctor\Doctor\Inspection;
use Zactonz\CronDoctor\Doctor\Severity;

final class ScheduleCheck implements Check
{
    public function inspect(Inspection $inspection): array
    {
        $findings = [];

        foreach ($inspection->jobEntries() as $entry) {
            if ($entry->isReboot()) {
                $findings[] = new Finding(
                    'schedule.reboot',
                    Severity::ADVICE,
                    'Runs only when the server restarts',
                    'A job scheduled with @reboot runs once when the server boots. Cron Doctor cannot tell whether it '
                    . 'is overdue, because there is no next scheduled time to compare against.',
                    $entry->line()->number(),
                    $entry->job() === null ? null : $entry->job()->id()
                );

                continue;
            }

            if ($entry->expression() === null) {
                $findings[] = new Finding(
                    'schedule.unreadable',
                    Severity::WARNING,
                    'The schedule cannot be read',
                    sprintf(
                        'Cron Doctor does not understand the schedule "%s", so it cannot work out when this job '
                        . 'should run. The job is still listed, and monitoring still records every run.',
                        $entry->line()->schedule()
                    ),
                    $entry->line()->number(),
                    $entry->job() === null ? null : $entry->job()->id()
                );
            }
        }

        return $findings;
    }
}
