<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Doctor;

use Zactonz\CronDoctor\Doctor\Checks\DiscardedOutputCheck;
use Zactonz\CronDoctor\Doctor\Checks\EnvironmentCheck;
use Zactonz\CronDoctor\Doctor\Checks\ExecutionCheck;
use Zactonz\CronDoctor\Doctor\Checks\HygieneCheck;
use Zactonz\CronDoctor\Doctor\Checks\InterpreterCheck;
use Zactonz\CronDoctor\Doctor\Checks\MailRoutingCheck;
use Zactonz\CronDoctor\Doctor\Checks\MissingTargetCheck;
use Zactonz\CronDoctor\Doctor\Checks\MonitoringCheck;
use Zactonz\CronDoctor\Doctor\Checks\ScheduleCheck;
use Zactonz\CronDoctor\Doctor\Checks\SecretsCheck;
use Zactonz\CronDoctor\Doctor\Checks\WordPressCheck;
use Throwable;

final class Checkup
{
    private array $checks;

    public function __construct(array $checks)
    {
        $this->checks = $checks;
    }

    public static function withDefaultChecks(): self
    {
        return new self([
            new EnvironmentCheck(),
            new HygieneCheck(),
            new ExecutionCheck(),
            new MissingTargetCheck(),
            new InterpreterCheck(),
            new WordPressCheck(),
            new DiscardedOutputCheck(),
            new MailRoutingCheck(),
            new ScheduleCheck(),
            new SecretsCheck(),
            new MonitoringCheck(),
        ]);
    }

    public function run(Inspection $inspection): array
    {
        $everything = [];

        foreach ($this->checks as $check) {
            try {
                $produced = $check->inspect($inspection);
            } catch (Throwable $error) {
                $produced = [
                    new Finding(
                        'check.failed',
                        Severity::ADVICE,
                        'One of the checks could not finish',
                        sprintf('The %s check stopped early and its results are missing.', self::name($check))
                    ),
                ];
            }

            foreach ($produced as $finding) {
                $everything[] = $finding;

                $lineNumber = $finding->lineNumber();

                if ($lineNumber === null) {
                    continue;
                }

                $entry = $inspection->entryFor($lineNumber);

                if ($entry !== null) {
                    $entry->addFinding($finding);
                }
            }
        }

        return Finding::sort($everything);
    }

    private static function name(Check $check): string
    {
        $parts = explode('\\', get_class($check));

        return (string) end($parts);
    }
}
