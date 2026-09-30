<?php

declare(strict_types=1);

use Zactonz\CronDoctor\Http\Flash;
use Zactonz\CronDoctor\Job\JobManager;
use Zactonz\CronDoctor\Security\Token;
use Zactonz\CronDoctor\View\Html;
use Zactonz\CronDoctor\View\Page;
use Zactonz\CronDoctor\View\ScheduleDescriber;
use Zactonz\CronDoctor\View\Template;
use Zactonz\CronDoctor\Version;

[$application, $cpanel] = require __DIR__ . '/bootstrap.php';

$application->prepare();

$inspection = $application->inspection();

$template = new Template(__DIR__ . '/src/View/templates');

$page = new Page($cpanel, Version::NUMBER);
$page->open(Page::title('Settings'));

$template->display('settings', [
    'writable' => $inspection->writable(),
    'crontabBinary' => (string) $application->environment()->crontabBinary(),
    'crontabSource' => $inspection->snapshot()->source(),
    'baseDirectory' => $application->environment()->baseDirectory(),
    'runnerVersion' => $application->payload()->deployedVersion(),
    'runnerCurrent' => $application->payload()->isCurrent(),
    'capabilities' => $inspection->capabilities(),
    'phpVersions' => $application->phpBinaries()->available(),
    'backups' => $application->gateway()->backups(),
    'audit' => $application->audit()->recent(25),
    'notifyAddress' => $application->settings()->notificationAddress(),
    'repeatHours' => $application->settings()->repeatHours(),
    'sentinelInstalled' => $application->manager()->sentinelInstalled($inspection->snapshot()),
    'sentinelSchedule' => JobManager::SENTINEL_SCHEDULE,
    'sentinelPath' => $application->environment()->sentinelPath(),
    'presenter' => $application->presenter(),
    'token' => $application->token()->issue(),
    'fingerprint' => $inspection->snapshot()->fingerprint(),
    'flash' => (new Flash($application->environment()))->take(),
    'timezone' => date_default_timezone_get(),
    'productName' => Version::NAME,
    'version' => Version::NUMBER,
    'homepage' => Version::HOMEPAGE,
]);

$page->close();
