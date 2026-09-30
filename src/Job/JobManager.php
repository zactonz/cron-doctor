<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Job;

use Zactonz\CronDoctor\Cron\CommandName;
use Zactonz\CronDoctor\Cron\CommandTokens;
use Zactonz\CronDoctor\Cron\CrontabLine;
use Zactonz\CronDoctor\Cron\Redirection;
use Zactonz\CronDoctor\Platform\CrontabGateway;
use Zactonz\CronDoctor\Platform\CrontabSnapshot;
use Zactonz\CronDoctor\Platform\Environment;
use Zactonz\CronDoctor\Support\Result;

final class JobManager
{
    private const MANAGED_PATTERN = '#^(?:\S*/)?zcd-run\s+([0-9a-f]{16})$#';

    private const SENTINEL_PATTERN = '#^(?:\S*/)?zcd-sentinel(?:\s|$)#';

    public const SENTINEL_SCHEDULE = '*/15 * * * *';



    private Environment $environment;

    private CrontabGateway $gateway;

    private JobStore $store;

    private Payload $payload;

    public function __construct(
        Environment $environment,
        CrontabGateway $gateway,
        JobStore $store,
        Payload $payload
    ) {
        $this->environment = $environment;
        $this->gateway = $gateway;
        $this->store = $store;
        $this->payload = $payload;
    }

    public static function managedJobId(CrontabLine $line): ?string
    {
        if (!$line->isJob()) {
            return null;
        }

        if (preg_match(self::MANAGED_PATTERN, trim($line->command()), $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    public static function isSentinelLine(CrontabLine $line): bool
    {
        return $line->isJob() && preg_match(self::SENTINEL_PATTERN, trim($line->command())) === 1;
    }

    public function wrappedCommand(string $id): string
    {
        return $this->environment->runnerPath() . ' ' . $id;
    }

    public function sentinelInstalled(CrontabSnapshot $snapshot): bool
    {
        foreach ($snapshot->document()->lines() as $line) {
            if (self::isSentinelLine($line)) {
                return true;
            }
        }

        return false;
    }

    public function installSentinel(string $fingerprint): Result
    {
        $snapshot = $this->gateway->read();

        $guard = $this->guard($snapshot, $fingerprint);

        if ($guard !== null) {
            return $guard;
        }

        if ($this->sentinelInstalled($snapshot)) {
            return Result::ok();
        }

        $deployed = $this->payload->deployIfNeeded();

        if ($deployed->failed()) {
            return $deployed;
        }

        return $this->gateway->write(
            $snapshot->document()->withAppendedLine(
                self::SENTINEL_SCHEDULE . ' ' . $this->environment->sentinelPath()
            ),
            $fingerprint
        );
    }

    public function removeSentinel(string $fingerprint): Result
    {
        $snapshot = $this->gateway->read();

        $guard = $this->guard($snapshot, $fingerprint);

        if ($guard !== null) {
            return $guard;
        }

        $document = $snapshot->document();
        $target = null;

        foreach ($document->lines() as $line) {
            if (self::isSentinelLine($line)) {
                $target = $line->number();
                break;
            }
        }

        if ($target === null) {
            return Result::ok();
        }

        return $this->gateway->write($document->withoutLineAt($target), $fingerprint, true);
    }

    public function monitor(int $lineNumber, string $fingerprint): Result
    {
        $snapshot = $this->gateway->read();

        $guard = $this->guard($snapshot, $fingerprint);

        if ($guard !== null) {
            return $guard;
        }

        $line = $snapshot->document()->lineAt($lineNumber);

        if ($line === null || !$line->isJob()) {
            return Result::fail('That crontab line is not a cron job.');
        }

        if (self::managedJobId($line) !== null) {
            return Result::fail('That job is already monitored.');
        }

        $deployed = $this->payload->deployIfNeeded();

        if ($deployed->failed()) {
            return $deployed;
        }

        $job = Job::create(
            self::deriveName($line->command()),
            $line->command(),
            $line->input(),
            $line->raw(),
            $line->commandText(),
            $line->schedule()
        );

        $saved = $this->store->save($job);

        if ($saved->failed()) {
            return $saved;
        }

        $replacement = sprintf(
            '%s%s %s',
            $line->logSuppressed() ? '-' : '',
            $line->schedule(),
            $this->wrappedCommand($job->id())
        );

        $written = $this->gateway->write(
            $snapshot->document()->withLineAt($lineNumber, $replacement),
            $fingerprint
        );

        if ($written->failed()) {
            $this->store->remove($job->id());

            return $written;
        }

        return Result::ok(
            sprintf('%s is now monitored. Its next run will be recorded.', $job->name()),
            ['job' => $job]
        );
    }

    public function release(string $jobId, string $fingerprint): Result
    {
        $job = $this->store->find($jobId);

        if ($job === null) {
            return Result::fail('That job is not known to Cron Doctor.');
        }

        $snapshot = $this->gateway->read();

        $guard = $this->guard($snapshot, $fingerprint);

        if ($guard !== null) {
            return $guard;
        }

        $line = $this->findManagedLine($snapshot, $jobId);

        if ($line === null) {
            $this->store->remove($jobId);

            return Result::ok('That job was no longer in the crontab, so Cron Doctor forgot about it.');
        }

        $replacement = sprintf(
            '%s%s %s',
            $line->logSuppressed() ? '-' : '',
            $line->schedule(),
            $job->originalCommand()
        );

        $written = $this->gateway->write(
            $snapshot->document()->withLineAt($line->number(), $replacement),
            $fingerprint
        );

        if ($written->failed()) {
            return $written;
        }

        $this->store->remove($jobId);

        return Result::ok(sprintf('%s is back to its original crontab line.', $job->name()));
    }

    public function captureOutput(string $jobId): Result
    {
        $job = $this->store->find($jobId);

        if ($job === null) {
            return Result::fail('That job is not known to Cron Doctor.');
        }

        $analysis = Redirection::analyse($job->command());

        if (!$analysis->isRecoverable()) {
            return Result::fail('This command does not end with a redirection that Cron Doctor can safely remove.');
        }

        $stripped = $analysis->withoutTrailingDiscard();

        if ($stripped === null) {
            return Result::fail('Removing the redirection would leave no command to run.');
        }

        $updated = $job->withCommand($stripped, $job->input());
        $saved = $this->store->save($updated);

        if ($saved->failed()) {
            return $saved;
        }

        return Result::ok('Output from this job will now be captured.');
    }

    public function restoreCommand(string $jobId): Result
    {
        $job = $this->store->find($jobId);

        if ($job === null) {
            return Result::fail('That job is not known to Cron Doctor.');
        }

        [$command, $input] = CrontabLine::decode($job->originalCommand());

        $saved = $this->store->save($job->withCommand($command, $input));

        if ($saved->failed()) {
            return $saved;
        }

        return Result::ok('The command was restored to the one from the original crontab line.');
    }

    public function applyInterpreterToJob(string $jobId, int $tokenIndex, string $binary): Result
    {
        $job = $this->store->find($jobId);

        if ($job === null) {
            return Result::fail('That job is not known to Cron Doctor.');
        }

        $tokens = CommandTokens::split($job->command());
        $rewritten = $tokens->replace($job->command(), $tokenIndex, $binary);

        if ($rewritten === null) {
            return Result::fail('The command could not be rewritten safely, so nothing was changed.');
        }

        $saved = $this->store->save($job->withCommand($rewritten, $job->input()));

        if ($saved->failed()) {
            return $saved;
        }

        return Result::ok(sprintf('This job now runs with %s.', $binary));
    }

    public function applyInterpreterToLine(
        int $lineNumber,
        int $tokenIndex,
        string $binary,
        string $fingerprint
    ): Result {
        $snapshot = $this->gateway->read();

        $guard = $this->guard($snapshot, $fingerprint);

        if ($guard !== null) {
            return $guard;
        }

        $line = $snapshot->document()->lineAt($lineNumber);

        if ($line === null || !$line->isJob()) {
            return Result::fail('That crontab line is not a cron job.');
        }

        $tokens = CommandTokens::split($line->command());
        $rewritten = $tokens->replace($line->command(), $tokenIndex, $binary);

        if ($rewritten === null) {
            return Result::fail('The command could not be rewritten safely, so nothing was changed.');
        }

        $updated = $line->withCommand($rewritten, $line->input());

        if ($updated === null) {
            return Result::fail(
                'This command uses a percent sign that cannot be rewritten without changing its meaning. '
                . 'Monitor the job first, then apply the fix.'
            );
        }

        return $this->gateway->write(
            $snapshot->document()->withLineAt($lineNumber, $updated->raw()),
            $fingerprint
        );
    }

    public function updateSettings(
        string $jobId,
        int $timeoutSeconds,
        string $onOverlap,
        int $retainRuns,
        int $outputLimitBytes,
        string $emit,
        string $name
    ): Result {
        $job = $this->store->find($jobId);

        if ($job === null) {
            return Result::fail('That job is not known to Cron Doctor.');
        }

        $updated = $job
            ->withName($name)
            ->withSettings($timeoutSeconds, $onOverlap, $retainRuns, $outputLimitBytes, $emit);

        $saved = $this->store->save($updated);

        if ($saved->failed()) {
            return $saved;
        }

        return Result::ok('The settings for this job were saved.');
    }

    public function runNow(string $jobId): Result
    {
        $job = $this->store->find($jobId);

        if ($job === null) {
            return Result::fail('That job is not known to Cron Doctor.');
        }

        if (!$this->environment->processes()->available()) {
            return Result::fail('Cron Doctor cannot start a job because running commands is disabled for this account.');
        }

        $runner = $this->environment->runnerPath();

        if (!is_file($runner)) {
            return Result::fail('The Cron Doctor runner is not installed. Repair it from Settings.');
        }

        $result = $this->environment->processes()->run([$runner, '--detach', $jobId], null, 15);

        if (!$result->successful()) {
            return Result::fail('The job could not be started: ' . $result->errorSummary());
        }

        return Result::ok(sprintf('%s was started. Refresh in a moment to see the result.', $job->name()));
    }

    public function findManagedLine(CrontabSnapshot $snapshot, string $jobId): ?CrontabLine
    {
        foreach ($snapshot->document()->lines() as $line) {
            if (self::managedJobId($line) === $jobId) {
                return $line;
            }
        }

        return null;
    }

    public static function deriveName(string $command): string
    {
        return CommandName::describe($command);
    }

    private function guard(CrontabSnapshot $snapshot, string $fingerprint): ?Result
    {
        if (!$snapshot->readable()) {
            return Result::fail('The crontab could not be read, so nothing was changed.');
        }

        if (!$this->gateway->writable()) {
            return Result::fail(
                'Cron Doctor is running in read-only mode because this account cannot run commands. '
                . 'Findings are still shown, but changes must be made in the Cron Jobs page.'
            );
        }

        if ($snapshot->fingerprint() !== $fingerprint) {
            return Result::fail('The crontab changed since this page was loaded. Reload and try again.');
        }

        return null;
    }
}
