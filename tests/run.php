<?php

declare(strict_types=1);

use Zactonz\CronDoctor\Tests\Framework\Runner;

require __DIR__ . '/bootstrap.php';

$filter = $argv[1] ?? '';

$runner = new Runner(
    [__DIR__ . '/unit', __DIR__ . '/e2e'],
    is_string($filter) ? $filter : ''
);

exit($runner->run());
