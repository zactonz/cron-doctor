<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Job;

use Zactonz\CronDoctor\Cron\CrontabLine;
use Zactonz\CronDoctor\Doctor\CronEntry;
use Zactonz\CronDoctor\Platform\CrontabGateway;
use Zactonz\CronDoctor\Platform\Environment;
use Zactonz\CronDoctor\Support\Clock;
use Zactonz\CronDoctor\Support\Filesystem;
use Zactonz\CronDoctor\Support\Json;
use Zactonz\CronDoctor\Support\Result;
use Zactonz\CronDoctor\Version;

final class Sentinel
{
    public const STATE_FILE = 'state/alerts.json';

    private const OUTPUT_EXCERPT = 1200;

    private CrontabGateway $gateway;

    private JobStore $store;

    private RunHistory $history;

    private Settings $settings;

    private Clock $clock;

    private Mailer $mailer;

    private Environment $environment;

    public function __construct(
        Environment $environment,
        CrontabGateway $gateway,
        JobStore $store,
        RunHistory $history,
        Settings $settings,
        Clock $clock,
        Mailer $mailer
    ) {
        $this->environment = $environment;
        $this->gateway = $gateway;
        $this->store = $store;
        $this->history = $history;
        $this->settings = $settings;
        $this->clock = $clock;
        $this->mailer = $mailer;
    }

    public function run(): Result
    {
        $address = $this->settings->notificationAddress();

        if ($address === '') {
            return Result::ok('No notification address is configured.');
        }

        $now = $this->clock->now();
        $problems = [];

        foreach ($this->currentLines() as $jobId => $line) {
            $job = $this->store->find((string) $jobId);

            if ($job === null) {
                continue;
            }

            $entry = new CronEntry(
                $line,
                $job,
                $this->history->latest((string) $jobId),
                $this->history->lastCompleted((string) $jobId),
                $this->history->consecutiveFailures((string) $jobId),
                $this->history->skippedCount((string) $jobId)
            );

            $status = $entry->status($now);

            if ($status === CronEntry::STATUS_FAILING || $status === CronEntry::STATUS_OVERDUE) {
                $problems[$jobId] = ['status' => $status, 'entry' => $entry, 'job' => $job];
            }
        }

        $previous = $this->readState();
        $repeatSeconds = $this->settings->repeatHours() * 3600;
        $timestamp = $now->getTimestamp();

        $newOrDue = [];
        $recovered = [];

        foreach ($problems as $jobId => $problem) {
            $before = $previous[$jobId] ?? null;

            if ($before === null
                || ($before['status'] ?? '') !== $problem['status']
                || $timestamp - (int) ($before['notified_at'] ?? 0) >= $repeatSeconds
            ) {
                $newOrDue[$jobId] = $problem;
            }
        }

        foreach ($previous as $jobId => $before) {
            if (isset($problems[$jobId])) {
                continue;
            }

            $job = $this->store->find((string) $jobId);

            if ($job !== null) {
                $recovered[$jobId] = $job;
            }
        }

        if ($newOrDue === [] && $recovered === []) {
            $this->writeState($problems, $previous, $timestamp, []);

            return Result::ok('Nothing to report.');
        }

        $sent = $this->mailer->send(
            $address,
            $this->subject($newOrDue, $recovered),
            $this->body($newOrDue, $recovered, $now->format('j M Y, H:i T'))
        );

        $this->writeState($problems, $previous, $timestamp, array_keys($newOrDue));

        if (!$sent) {
            return Result::fail('The alert email could not be sent.');
        }

        return Result::ok(sprintf(
            'Reported %d problem(s) and %d recovery(ies).',
            count($newOrDue),
            count($recovered)
        ));
    }

    private function currentLines(): array
    {
        $snapshot = $this->gateway->read();
        $lines = [];

        if ($snapshot->readable()) {
            foreach ($snapshot->document()->lines() as $line) {
                $jobId = JobManager::managedJobId($line);

                if ($jobId !== null) {
                    $lines[$jobId] = $line;
                }
            }

            return $lines;
        }

        foreach ($this->store->all() as $job) {
            if ($job->schedule() === '') {
                continue;
            }

            $lines[$job->id()] = CrontabLine::parse(
                $job->schedule() . ' ' . $this->environment->runnerPath() . ' ' . $job->id(),
                0
            );
        }

        return $lines;
    }

    private function subject(array $problems, array $recovered): string
    {
        if ($problems === []) {
            return sprintf('[Cron Doctor] %s recovered', $this->describeCount(count($recovered), 'cron job'));
        }

        $first = reset($problems);

        if (count($problems) === 1) {
            return sprintf(
                '[Cron Doctor] %s is %s',
                $first['job']->name(),
                $first['status'] === CronEntry::STATUS_OVERDUE ? 'overdue' : 'failing'
            );
        }

        return sprintf('[Cron Doctor] %s need attention', $this->describeCount(count($problems), 'cron job'));
    }

    private function body(array $problems, array $recovered, string $when): string
    {
        $lines = [];
        $lines[] = sprintf('Cron Doctor checked this account at %s.', $when);
        $lines[] = '';

        foreach ($problems as $problem) {
            $entry = $problem['entry'];
            $job = $problem['job'];

            $lines[] = sprintf('%s  (%s)', $job->name(), $entry->line()->schedule());
            $lines[] = str_repeat('-', 60);
            $lines[] = 'Command: ' . $job->command();

            if ($problem['status'] === CronEntry::STATUS_OVERDUE) {
                $lines[] = 'Problem: it has not run when it should have.';
                $expected = $entry->expectedPreviousRun($this->clock->now());

                if ($expected !== null) {
                    $lines[] = 'Expected: ' . $expected->format('j M Y, H:i');
                }
            } else {
                $last = $entry->lastCompleted();
                $lines[] = sprintf(
                    'Problem: the last %s failed.',
                    $entry->consecutiveFailures() === 1 ? 'run' : $entry->consecutiveFailures() . ' runs'
                );

                if ($last !== null) {
                    $lines[] = 'Exit code: ' . $last->exitCode();
                    $lines[] = 'Finished: ' . date('j M Y, H:i', $last->finishedAt());

                    $output = $this->history->output($job->id(), $last->sequence());

                    if ($output !== null && trim($output) !== '') {
                        $lines[] = '';
                        $lines[] = 'Output:';
                        $lines[] = substr($output, 0, self::OUTPUT_EXCERPT);
                    }
                }
            }

            $lines[] = '';
        }

        foreach ($recovered as $job) {
            $lines[] = sprintf('%s is running normally again.', $job->name());
        }

        if ($recovered !== []) {
            $lines[] = '';
        }

        $lines[] = sprintf('Sent by %s %s on this account.', Version::NAME, Version::NUMBER);
        $lines[] = 'Turn these messages off by clearing the address in the Cron Doctor settings page.';

        return implode("\n", $lines) . "\n";
    }

    private function describeCount(int $value, string $noun): string
    {
        return $value . ' ' . $noun . ($value === 1 ? '' : 's');
    }

    private function readState(): array
    {
        $contents = Filesystem::read($this->environment->path(self::STATE_FILE));
        $decoded = $contents === null ? null : Json::decode($contents);

        return is_array($decoded) && isset($decoded['jobs']) && is_array($decoded['jobs'])
            ? $decoded['jobs']
            : [];
    }

    private function writeState(array $problems, array $previous, int $timestamp, array $notified): void
    {
        $jobs = [];

        foreach ($problems as $jobId => $problem) {
            $jobs[$jobId] = [
                'status' => $problem['status'],
                'notified_at' => in_array($jobId, $notified, true)
                    ? $timestamp
                    : (int) ($previous[$jobId]['notified_at'] ?? $timestamp),
            ];
        }

        Filesystem::writeAtomically(
            $this->environment->path(self::STATE_FILE),
            Json::encode(['checked_at' => $timestamp, 'jobs' => $jobs])
        );
    }
}
