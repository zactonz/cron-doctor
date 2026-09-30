<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Doctor\Checks;

use Zactonz\CronDoctor\Cron\CrontabLine;
use Zactonz\CronDoctor\Doctor\Check;
use Zactonz\CronDoctor\Doctor\Finding;
use Zactonz\CronDoctor\Doctor\Inspection;
use Zactonz\CronDoctor\Doctor\Severity;

final class HygieneCheck implements Check
{
    public function inspect(Inspection $inspection): array
    {
        $findings = [];
        $document = $inspection->snapshot()->document();

        if ($document->hasCarriageReturns()) {
            $findings[] = new Finding(
                'crontab.carriage-returns',
                Severity::CRITICAL,
                'The crontab has Windows line endings',
                'One or more lines end with a carriage return. Cron passes that character to the shell, which usually '
                . 'makes the command fail with a confusing "command not found" or "No such file or directory" error.'
            );
        }

        foreach ($document->lines() as $line) {
            if ($line->type() !== CrontabLine::TYPE_UNKNOWN) {
                continue;
            }

            $findings[] = new Finding(
                'crontab.unreadable-line',
                Severity::CRITICAL,
                'A line in the crontab is not a cron job',
                sprintf(
                    'Line %d is neither a comment, a setting nor a cron job: "%s". Cron will usually refuse to load '
                    . 'the whole crontab, which silently stops every job in it.',
                    $line->number() + 1,
                    self::shorten(trim($line->raw()))
                ),
                $line->number()
            );
        }

        $seen = [];

        foreach ($inspection->jobEntries() as $entry) {
            $key = $entry->line()->schedule() . "\n" . $entry->command();

            if (isset($seen[$key])) {
                $findings[] = new Finding(
                    'crontab.duplicate',
                    Severity::WARNING,
                    'The same job appears twice',
                    sprintf(
                        'This line repeats line %d exactly, so the command runs twice on every schedule.',
                        $seen[$key] + 1
                    ),
                    $entry->line()->number(),
                    $entry->job() === null ? null : $entry->job()->id()
                );

                continue;
            }

            $seen[$key] = $entry->line()->number();
        }

        return $findings;
    }

    private static function shorten(string $text): string
    {
        return mb_strlen($text) > 70 ? mb_substr($text, 0, 70) . '…' : $text;
    }
}
