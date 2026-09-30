<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Doctor\Checks;

use Zactonz\CronDoctor\Cron\CommandTokens;
use Zactonz\CronDoctor\Doctor\Check;
use Zactonz\CronDoctor\Doctor\Finding;
use Zactonz\CronDoctor\Doctor\Inspection;
use Zactonz\CronDoctor\Doctor\Severity;

final class MissingTargetCheck implements Check
{
    public function inspect(Inspection $inspection): array
    {
        $findings = [];
        $environment = $inspection->environment();

        foreach ($inspection->jobEntries() as $entry) {
            $tokens = CommandTokens::split($entry->command());

            if (!$tokens->balanced()) {
                continue;
            }

            foreach ($tokens->all() as $token) {
                $path = $token['text'];

                if ($path === '' || $path[0] !== '/' || !$environment->containsPath(dirname($path))) {
                    continue;
                }

                if (is_file($path) || is_dir($path)) {
                    continue;
                }

                $findings[] = new Finding(
                    'target.missing',
                    Severity::CRITICAL,
                    'Points at a file that is not there',
                    sprintf(
                        'The path %s does not exist in your account. A cron job whose script has been moved, renamed '
                        . 'or deleted fails every time it runs, usually without anyone noticing.',
                        $path
                    ),
                    $entry->line()->number(),
                    $entry->job() === null ? null : $entry->job()->id()
                );

                break;
            }
        }

        return $findings;
    }
}
