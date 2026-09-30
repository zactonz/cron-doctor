<?php

declare(strict_types=1);

use Zactonz\CronDoctor\Http\Controller;
use Zactonz\CronDoctor\Http\Request;

[$application] = require __DIR__ . '/bootstrap.php';

$application->environment()->ensureStateDirectories();

$controller = new Controller($application);
$destination = $controller->handle(Request::capture());

if (!headers_sent()) {
    header('Location: ' . $destination, true, 303);
}

echo '<!doctype html><meta charset="utf-8"><title>Redirecting</title>';
printf(
    '<p>Continue to <a href="%s">Cron Doctor</a>.</p>',
    htmlspecialchars($destination, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
);
