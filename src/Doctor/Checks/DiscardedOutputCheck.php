<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Doctor\Checks;

use Zactonz\CronDoctor\Doctor\Action;
use Zactonz\CronDoctor\Doctor\Check;
use Zactonz\CronDoctor\Doctor\Finding;
use Zactonz\CronDoctor\Doctor\Inspection;
use Zactonz\CronDoctor\Doctor\Severity;

final class DiscardedOutputCheck implements Check
{
    public function inspect(Inspection $inspection): array
    {
        $findings = [];

        foreach ($inspection->jobEntries() as $entry) {
            $analysis = $entry->redirection();

            if (!$analysis->hasBalancedQuotes()) {
                $findings[] = new Finding(
                    'command.quotes',
                    Severity::WARNING,
                    'The command has an unclosed quote',
                    'This command contains a quote that is never closed. The shell may interpret it very differently '
                    . 'from what was intended, and Cron Doctor will not rewrite it automatically.',
                    $entry->line()->number(),
                    $entry->job() === null ? null : $entry->job()->id()
                );

                continue;
            }

            if (!$analysis->discardsStdout() && !$analysis->discardsStderr()) {
                continue;
            }

            if ($entry->job() === null) {
                $findings[] = new Finding(
                    'output.discarded',
                    Severity::WARNING,
                    'Throws its output away',
                    'Everything this job prints, including error messages, is sent to /dev/null. If it starts failing '
                    . 'there is nothing to see. Monitor the job and Cron Doctor will keep the output for you.',
                    $entry->line()->number(),
                    null,
                    new Action('Monitor this job', 'monitor', ['line' => $entry->line()->number()])
                );

                continue;
            }

            if (!$analysis->isRecoverable()) {
                continue;
            }

            $findings[] = new Finding(
                'output.recoverable',
                Severity::ADVICE,
                'Output is still being discarded',
                sprintf(
                    'Cron Doctor records how this job finishes, but the command still ends with a redirection to '
                    . '/dev/null, so there is no output to keep. Removing "%s" lets Cron Doctor capture it. The '
                    . 'original crontab line is kept either way.',
                    trim(substr($entry->command(), strlen((string) $analysis->withoutTrailingDiscard())))
                ),
                $entry->line()->number(),
                $entry->job()->id(),
                new Action('Capture the output', 'capture-output', ['job' => $entry->job()->id()])
            );
        }

        return $findings;
    }
}
