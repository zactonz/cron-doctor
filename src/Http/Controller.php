<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Http;

use Zactonz\CronDoctor\Application;
use Zactonz\CronDoctor\Job\Job;
use Zactonz\CronDoctor\Security\Token;
use Zactonz\CronDoctor\Support\Filesystem;
use Zactonz\CronDoctor\Support\Result;

final class Controller
{
    private const ACTIONS = [
        'monitor',
        'release',
        'capture-output',
        'restore-command',
        'fix-interpreter-job',
        'fix-interpreter-line',
        'save-settings',
        'run-now',
        'repair-runner',
        'restore-backup',
        'purge-output',
        'save-alerts',
    ];

    private Application $application;

    private Flash $flash;

    public function __construct(Application $application)
    {
        $this->application = $application;
        $this->flash = new Flash($application->environment());
    }

    public function handle(Request $request): string
    {
        if (!$request->isPost()) {
            return $this->redirect('index');
        }

        if (!$this->application->token()->matches($request->text(Token::FIELD))) {
            $this->flash->store(false, 'That request could not be verified. Reload the page and try again.');

            return $this->redirect('index');
        }

        $action = $request->text('action');

        if (!in_array($action, self::ACTIONS, true)) {
            $this->flash->store(false, 'That action is not recognised.');

            return $this->redirect('index');
        }

        $result = $this->dispatch($action, $request);

        $this->application->audit()->record($action, $result->successful(), $result->message(), [
            'job' => $request->text('job', '-'),
            'line' => $request->text('line', '-'),
        ]);

        $this->flash->store($result->successful(), $result->message());

        return $this->destination($action, $request, $result);
    }

    private function dispatch(string $action, Request $request): Result
    {
        $manager = $this->application->manager();
        $fingerprint = $request->text('fingerprint');

        if ($action === 'monitor') {
            return $manager->monitor($request->integer('line', -1), $fingerprint);
        }

        if ($action === 'release') {
            $job = $request->jobId();

            return $job === null
                ? Result::fail('That job identifier is not valid.')
                : $manager->release($job, $fingerprint);
        }

        if ($action === 'capture-output') {
            $job = $request->jobId();

            return $job === null
                ? Result::fail('That job identifier is not valid.')
                : $manager->captureOutput($job);
        }

        if ($action === 'restore-command') {
            $job = $request->jobId();

            return $job === null
                ? Result::fail('That job identifier is not valid.')
                : $manager->restoreCommand($job);
        }

        if ($action === 'fix-interpreter-job' || $action === 'fix-interpreter-line') {
            return $this->fixInterpreter($action, $request, $fingerprint);
        }

        if ($action === 'save-settings') {
            return $this->saveSettings($request);
        }

        if ($action === 'run-now') {
            $job = $request->jobId();

            return $job === null
                ? Result::fail('That job identifier is not valid.')
                : $manager->runNow($job);
        }

        if ($action === 'repair-runner') {
            return $this->application->payload()->deploy();
        }

        if ($action === 'restore-backup') {
            return $this->application->gateway()->restore($request->text('name'));
        }

        if ($action === 'save-alerts') {
            return $this->saveAlerts($request, $fingerprint);
        }

        return $this->purgeOutput($request);
    }

    private function fixInterpreter(string $action, Request $request, string $fingerprint): Result
    {
        $binary = $request->text('binary');

        if (!$this->isKnownInterpreter($binary)) {
            return Result::fail('That PHP binary is not one this server offers, so nothing was changed.');
        }

        $token = $request->integer('token', -1);

        if ($token < 0) {
            return Result::fail('That request was incomplete, so nothing was changed.');
        }

        if ($action === 'fix-interpreter-job') {
            $job = $request->jobId();

            return $job === null
                ? Result::fail('That job identifier is not valid.')
                : $this->application->manager()->applyInterpreterToJob($job, $token, $binary);
        }

        return $this->application->manager()->applyInterpreterToLine(
            $request->integer('line', -1),
            $token,
            $binary,
            $fingerprint
        );
    }

    private function saveSettings(Request $request): Result
    {
        $job = $request->jobId();

        if ($job === null) {
            return Result::fail('That job identifier is not valid.');
        }

        return $this->application->manager()->updateSettings(
            $job,
            $request->integer('timeout_seconds', 0),
            $request->text('on_overlap', Job::OVERLAP_SKIP),
            $request->integer('retain_runs', Job::DEFAULT_RETAIN),
            $request->integer('output_limit_bytes', Job::DEFAULT_OUTPUT_LIMIT),
            $request->text('emit', Job::EMIT_ON_FAILURE),
            $request->text('name', '')
        );
    }

    private function saveAlerts(Request $request, string $fingerprint): Result
    {
        $address = $request->text('notify_email');

        $saved = $this->application->settings()->save($address, $request->integer('repeat_hours', 24));

        if ($saved->failed()) {
            return $saved;
        }

        $manager = $this->application->manager();

        if ($address === '') {
            $removed = $manager->removeSentinel($fingerprint);

            return $removed->failed()
                ? Result::fail('The address was cleared, but the alert check could not be removed: ' . $removed->message())
                : Result::ok('Alerts are switched off and the alert check was removed from the crontab.');
        }

        $installed = $manager->installSentinel($fingerprint);

        if ($installed->failed()) {
            return Result::fail('The address was saved, but the alert check could not be added: ' . $installed->message());
        }

        return Result::ok(sprintf('Alerts will be sent to %s. Cron Doctor checks every fifteen minutes.', $address));
    }

    private function purgeOutput(Request $request): Result
    {
        $job = $request->jobId();

        if ($job !== null) {
            $this->application->history()->purge($job);

            return Result::ok('The stored output for this job was deleted.');
        }

        if ($request->text('scope') !== 'all') {
            return Result::fail('That request did not say what to delete, so nothing was deleted.');
        }

        $purged = 0;

        foreach ($this->application->store()->all() as $stored) {
            $this->application->history()->purge($stored->id());
            $purged++;
        }

        return Result::ok(sprintf('Stored output was deleted for %d monitored job(s).', $purged));
    }

    private function isKnownInterpreter(string $binary): bool
    {
        if ($binary === '') {
            return false;
        }

        foreach ($this->application->phpBinaries()->available() as $path) {
            if (hash_equals($path, $binary)) {
                return true;
            }
        }

        return false;
    }

    private function destination(string $action, Request $request, Result $result): string
    {
        $job = $request->jobId();

        if ($action === 'restore-backup' || $action === 'repair-runner' || $action === 'save-alerts') {
            return $this->redirect('settings');
        }

        if ($job !== null && $action !== 'release') {
            return $this->redirect('job', $job);
        }

        return $this->redirect('index');
    }

    private function redirect(string $page, ?string $job = null): string
    {
        $pages = [
            'index' => 'index.live.php',
            'job' => 'job.live.php',
            'settings' => 'settings.live.php',
        ];

        $target = $pages[$page] ?? $pages['index'];

        if ($page === 'job' && $job !== null) {
            $target .= '?job=' . rawurlencode($job);
        }

        return $target;
    }

    public function flash(): Flash
    {
        return $this->flash;
    }

    public static function directoryIsWritable(string $path): bool
    {
        return Filesystem::ensureDirectory($path);
    }
}
