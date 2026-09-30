<?php

declare(strict_types=1);

use Zactonz\CronDoctor\Application;
use Zactonz\CronDoctor\Autoload;

if (!defined('ZCD_PLUGIN_DIR')) {
    define('ZCD_PLUGIN_DIR', __DIR__);
}

if (!defined('ZCD_CPANEL_LIVEAPI')) {
    define('ZCD_CPANEL_LIVEAPI', '/usr/local/cpanel/php/cpanel.php');
}

require_once ZCD_PLUGIN_DIR . '/src/Autoload.php';

Autoload::register(ZCD_PLUGIN_DIR . '/src');

if (is_file(ZCD_CPANEL_LIVEAPI)) {
    require_once ZCD_CPANEL_LIVEAPI;
}

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store, private');
}

$zcdCpanel = class_exists('CPANEL') ? new CPANEL() : null;

$zcdApplication = Application::boot(
    ZCD_PLUGIN_DIR,
    $_SERVER,
    $zcdCpanel,
    defined('ZCD_CRONTAB_BINARY') ? (string) constant('ZCD_CRONTAB_BINARY') : null,
    defined('ZCD_PHP_ROOT') ? (string) constant('ZCD_PHP_ROOT') : ''
);

return [$zcdApplication, $zcdCpanel];
