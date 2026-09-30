<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Doctor\Checks;

use Zactonz\CronDoctor\Doctor\Action;
use Zactonz\CronDoctor\Doctor\Check;
use Zactonz\CronDoctor\Doctor\CronEntry;
use Zactonz\CronDoctor\Doctor\Finding;
use Zactonz\CronDoctor\Doctor\Inspection;
use Zactonz\CronDoctor\Doctor\Severity;
use Zactonz\CronDoctor\Platform\PhpUsage;

final class InterpreterCheck implements Check
{
    public function inspect(Inspection $inspection): array
    {
        $findings = [];
        $binaries = $inspection->phpBinaries();

        foreach ($inspection->jobEntries() as $entry) {
            $usage = $binaries->inspect($entry->command());

            if ($usage === null) {
                continue;
            }

            $recommended = $binaries->recommendedFor($usage->scriptPath());
            $target = $recommended === null ? null : $binaries->pathFor($recommended);

            if ($usage->isGeneric()) {
                $findings[] = $this->genericInterpreter($entry, $usage, $recommended, $target);
                continue;
            }

            if ($recommended !== null && $usage->version() !== '' && $usage->version() !== $recommended && $target !== null) {
                $findings[] = $this->versionMismatch($entry, $usage, $recommended, $target);
            }
        }

        return $findings;
    }

    private function genericInterpreter(
        CronEntry $entry,
        PhpUsage $usage,
        ?string $recommended,
        ?string $target
    ): Finding {
        $detail = sprintf(
            'This job runs PHP as "%s". In a cron job that resolves to the server default rather than the PHP '
            . 'version your site is set to use, which is a common cause of jobs that fail silently or behave '
            . 'differently from the website.',
            $usage->binary()
        );

        if ($target === null) {
            return new Finding(
                'interpreter.generic',
                Severity::WARNING,
                'Runs PHP from a generic path',
                $detail . ' Cron Doctor could not tell which site this job belongs to, so it cannot suggest a '
                . 'version. Check the site in MultiPHP Manager and use that version\'s full path here.',
                $entry->line()->number(),
                $entry->job() === null ? null : $entry->job()->id()
            );
        }

        return new Finding(
            'interpreter.generic',
            Severity::WARNING,
            'Runs PHP from a generic path',
            $detail . sprintf(' Your site uses %s, so this job should run %s.', $recommended, $target),
            $entry->line()->number(),
            $entry->job() === null ? null : $entry->job()->id(),
            $this->action($entry, $usage, $target)
        );
    }

    private function versionMismatch(
        CronEntry $entry,
        PhpUsage $usage,
        string $recommended,
        string $target
    ): Finding {
        return new Finding(
            'interpreter.mismatch',
            Severity::ADVICE,
            'Uses a different PHP version from the site',
            sprintf(
                'This job runs %s, but the site it belongs to is set to %s. Running a different version can produce '
                . 'errors that never appear when the site is visited in a browser.',
                $usage->version(),
                $recommended
            ),
            $entry->line()->number(),
            $entry->job() === null ? null : $entry->job()->id(),
            $this->action($entry, $usage, $target)
        );
    }

    private function action(CronEntry $entry, PhpUsage $usage, string $target): Action
    {
        if ($entry->job() !== null) {
            return new Action('Use the right PHP', 'fix-interpreter-job', [
                'job' => $entry->job()->id(),
                'token' => $usage->tokenIndex(),
                'binary' => $target,
            ]);
        }

        return new Action('Use the right PHP', 'fix-interpreter-line', [
            'line' => $entry->line()->number(),
            'token' => $usage->tokenIndex(),
            'binary' => $target,
        ]);
    }
}
