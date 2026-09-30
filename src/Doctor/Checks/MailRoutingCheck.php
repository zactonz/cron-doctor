<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Doctor\Checks;

use Zactonz\CronDoctor\Doctor\Check;
use Zactonz\CronDoctor\Doctor\Finding;
use Zactonz\CronDoctor\Doctor\Inspection;
use Zactonz\CronDoctor\Doctor\Severity;

final class MailRoutingCheck implements Check
{
    public function inspect(Inspection $inspection): array
    {
        $mailto = $inspection->snapshot()->document()->variable('MAILTO');

        if ($mailto === null || trim($mailto) !== '') {
            return [];
        }

        $monitored = 0;

        foreach ($inspection->jobEntries() as $entry) {
            if ($entry->isMonitored()) {
                $monitored++;
            }
        }

        return [
            new Finding(
                'mail.disabled',
                $monitored > 0 ? Severity::WARNING : Severity::CRITICAL,
                'Cron email is switched off',
                'The crontab sets MAILTO to an empty value, which tells cron never to send mail. Failures will not '
                . 'reach anyone by email. Cron Doctor still records every run it monitors, but nothing will be sent '
                . 'to your inbox while MAILTO is empty.'
            ),
        ];
    }
}
