<?php

declare(strict_types=1);

use Zactonz\CronDoctor\Doctor\CronEntry;
use Zactonz\CronDoctor\Http\Flash;
use Zactonz\CronDoctor\Security\Token;
use Zactonz\CronDoctor\View\Html;
use Zactonz\CronDoctor\View\Page;
use Zactonz\CronDoctor\View\ScheduleDescriber;
use Zactonz\CronDoctor\View\Template;
use Zactonz\CronDoctor\Version;

[$application, $cpanel] = require __DIR__ . '/bootstrap.php';

$application->prepare();

$inspection = $application->inspection();
$entries = $inspection->jobEntries();
$now = $inspection->now();

$summary = ['total' => count($entries), 'monitored' => 0, 'failing' => 0, 'overdue' => 0];

foreach ($entries as $entry) {
    if ($entry->isMonitored()) {
        $summary['monitored']++;
    }

    $status = $entry->status($now);

    if ($status === CronEntry::STATUS_FAILING) {
        $summary['failing']++;
    }

    if ($status === CronEntry::STATUS_OVERDUE) {
        $summary['overdue']++;
    }
}

$page = new Page($cpanel, Version::NUMBER);
$page->open(Page::title());

$template = new Template(__DIR__ . '/src/View/templates');

$template->display('dashboard', [
    'entries' => $entries,
    'findings' => $application->findings(),
    'summary' => $summary,
    'presenter' => $application->presenter(),
    'now' => $now,
    'token' => $application->token()->issue(),
    'fingerprint' => $inspection->snapshot()->fingerprint(),
    'writable' => $inspection->writable(),
    'flash' => (new Flash($application->environment()))->take(),
    'timezone' => date_default_timezone_get(),
    'productName' => Version::NAME,
    'version' => Version::NUMBER,
    'homepage' => Version::HOMEPAGE,
]);

$page->close();
