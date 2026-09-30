<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Doctor\Checks;

use Zactonz\CronDoctor\Doctor\Check;
use Zactonz\CronDoctor\Doctor\Finding;
use Zactonz\CronDoctor\Doctor\Inspection;
use Zactonz\CronDoctor\Doctor\Severity;

final class SecretsCheck implements Check
{
    private const PATTERNS = [
        '/(?:^|\s)-p(?!\s)\S+/' => 'a database password given with -p',
        '/--password=\S+/i' => 'a password given with --password',
        '/PGPASSWORD=\S+/' => 'a PostgreSQL password in an environment variable',
        '/(?:api[_-]?key|access[_-]?token|secret)=\S+/i' => 'an API key or token',
        '/Authorization:\s*(?:Bearer|Basic)\s+\S+/i' => 'an authorisation header',
    ];

    public function inspect(Inspection $inspection): array
    {
        $findings = [];

        foreach ($inspection->jobEntries() as $entry) {
            foreach (self::PATTERNS as $pattern => $description) {
                if (preg_match($pattern, $entry->command()) !== 1) {
                    continue;
                }

                $findings[] = new Finding(
                    'command.secret',
                    Severity::ADVICE,
                    'The command contains a credential',
                    sprintf(
                        'This command appears to include %s. Anyone who can list processes on this server can read a '
                        . 'command line while it runs, and it is stored in the crontab in plain text. A configuration '
                        . 'file the command reads, such as ~/.my.cnf, keeps it out of sight.',
                        $description
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
