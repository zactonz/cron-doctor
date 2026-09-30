<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Doctor\Checks;

use Zactonz\CronDoctor\Doctor\Check;
use Zactonz\CronDoctor\Doctor\Finding;
use Zactonz\CronDoctor\Doctor\Inspection;
use Zactonz\CronDoctor\Doctor\Severity;
use Zactonz\CronDoctor\Doctor\Words;

final class MonitoringCheck implements Check
{
    public function inspect(Inspection $inspection): array
    {
        $unmonitored = 0;

        foreach ($inspection->jobEntries() as $entry) {
            if (!$entry->isMonitored()) {
                $unmonitored++;
            }
        }

        if ($unmonitored === 0) {
            return [];
        }

        return [
            new Finding(
                'job.unmonitored',
                Severity::ADVICE,
                $unmonitored === 1 ? 'One job is not monitored yet' : Words::count($unmonitored, 'job') . ' are not monitored yet',
                'Cron Doctor is not recording these jobs, so there is nothing to show about whether they run, how '
                . 'long they take or whether they fail. Use Monitor in the table below to start recording one. The '
                . 'original crontab line is kept, so monitoring can always be undone.'
            ),
        ];
    }
}
