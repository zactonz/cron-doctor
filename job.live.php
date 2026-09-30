<?php

declare(strict_types=1);

use Zactonz\CronDoctor\Http\Flash;
use Zactonz\CronDoctor\Http\Request;
use Zactonz\CronDoctor\Job\JobManager;
use Zactonz\CronDoctor\Security\Token;
use Zactonz\CronDoctor\View\Html;
use Zactonz\CronDoctor\View\Page;
use Zactonz\CronDoctor\View\ScheduleDescriber;
use Zactonz\CronDoctor\View\Template;
use Zactonz\CronDoctor\Version;

[$application, $cpanel] = require __DIR__ . '/bootstrap.php';

$application->prepare();

$request = Request::capture();
$jobId = $request->jobId();
$job = $jobId === null ? null : $application->store()->find($jobId);

$page = new Page($cpanel, Version::NUMBER);

if ($job === null) {
    $page->open(Page::title());
    echo '<div class="zcd"><div class="zcd-notice zcd-notice-bad">'
        . 'That job is not known to Cron Doctor. <a href="index.live.php">Back to all cron jobs</a>.'
        . '</div></div>';
    $page->close();

    return;
}

$inspection = $application->inspection();
$snapshot = $inspection->snapshot();
$line = $application->manager()->findManagedLine($snapshot, $job->id());

$entry = null;

foreach ($inspection->jobEntries() as $candidate) {
    if (JobManager::managedJobId($candidate->line()) === $job->id()) {
        $entry = $candidate;
        break;
    }
}

$schedule = $line === null ? '' : $line->schedule();
$expression = $line === null ? null : $line->expression();
$runs = $application->history()->recent($job->id(), $job->retainRuns());

$outputs = [];

foreach ($runs as $run) {
    if ($run->hasOutput()) {
        $outputs[$run->sequence()] = (string) $application->history()->output($job->id(), $run->sequence());
    }
}

$template = new Template(__DIR__ . '/src/View/templates');

$page->open(Page::title($job->name()));

$template->display('job', [
    'job' => $job,
    'entry' => $entry,
    'status' => $entry === null ? 'unknown' : $entry->status($inspection->now()),
    'schedule' => $schedule === '' ? 'not in the crontab' : $schedule,
    'crontabLine' => $line === null ? 'This job is no longer in the crontab.' : $line->raw(),
    'commandChanged' => $job->command() !== $job->originalCommand(),
    'nextRuns' => $expression === null ? [] : $expression->nextRuns($inspection->now(), 3),
    'runs' => $runs,
    'outputs' => $outputs,
    'presenter' => $application->presenter(),
    'token' => $application->token()->issue(),
    'fingerprint' => $snapshot->fingerprint(),
    'writable' => $inspection->writable(),
    'flash' => (new Flash($application->environment()))->take(),
]);

$page->close();
