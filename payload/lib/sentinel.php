<?php

declare(strict_types=1);

use Zactonz\CronDoctor\Autoload;
use Zactonz\CronDoctor\Job\JobStore;
use Zactonz\CronDoctor\Job\RunHistory;
use Zactonz\CronDoctor\Job\Sentinel;
use Zactonz\CronDoctor\Job\Settings;
use Zactonz\CronDoctor\Job\SystemMailer;
use Zactonz\CronDoctor\Platform\CrontabGateway;
use Zactonz\CronDoctor\Platform\Environment;
use Zactonz\CronDoctor\Platform\NullCpanelApi;
use Zactonz\CronDoctor\Platform\ProcessRunner;
use Zactonz\CronDoctor\Support\Clock;

$base = dirname(__DIR__);
$autoloader = $base . '/lib/src/Autoload.php';

if (!is_file($autoloader)) {
    fwrite(STDERR, "zcd-sentinel: the Cron Doctor library is missing, reinstall the runner from settings\n");
    exit(78);
}

require $autoloader;

Autoload::register($base . '/lib/src');

$errorThrottle = $base . '/state/sentinel-error';

try {
    $home = dirname($base, 2);
    $user = (string) (getenv('USER') ?: getenv('LOGNAME') ?: '');

    if ($user === '' && function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $user = (string) (posix_getpwuid(posix_geteuid())['name'] ?? '');
    }

    $environment = new Environment($user, $home, new ProcessRunner());
    $settings = new Settings($environment);

    $sentinel = new Sentinel(
        $environment,
        new CrontabGateway($environment, new NullCpanelApi()),
        new JobStore($environment),
        new RunHistory($environment),
        $settings,
        new Clock(),
        new SystemMailer()
    );

    $result = $sentinel->run();

    if ($result->failed()) {
        throw new RuntimeException($result->message());
    }

    @unlink($errorThrottle);

    if (in_array('--verbose', $argv, true)) {
        fwrite(STDOUT, $result->message() . "\n");
    }

    exit(0);
} catch (Throwable $error) {
    $lastReported = is_file($errorThrottle) ? (int) @filemtime($errorThrottle) : 0;

    if (time() - $lastReported > 21600) {
        @touch($errorThrottle);
        fwrite(STDERR, 'zcd-sentinel: ' . $error->getMessage() . "\n");
        fwrite(STDERR, "This message is repeated at most once every six hours.\n");
    }

    exit(0);
}
