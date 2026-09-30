<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$home = sys_get_temp_dir() . '/zcd-demo-account';

putenv('HOME=' . $home);
putenv('USER=demo');
putenv('ZCD_FAKE_CRONTAB_STORE=' . $home . '/crontab.store');
putenv('ZCD_FAKE_CRONTAB_MODE=normal');

$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

if (!is_dir($home)) {
    require __DIR__ . '/seed.php';
    zcd_seed_account($home);
}

define('ZCD_PLUGIN_DIR', $root);
define('ZCD_CPANEL_LIVEAPI', __DIR__ . '/cpanel-stub.php');
define('ZCD_CRONTAB_BINARY', $root . '/tests/fixtures/fake-crontab');
define('ZCD_PHP_ROOT', $home . '/phproot');

$path = parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = is_string($path) ? $path : '/';

if ($path === '/harness/jupiter.css') {
    header('Content-Type: text/css');
    readfile(__DIR__ . '/jupiter.css');

    return true;
}

if ($path === '/harness/reset') {
    require __DIR__ . '/seed.php';
    zcd_seed_account($home);
    header('Location: /index.live.php', true, 303);

    return true;
}

if (preg_match('#^/assets/(zcd\.css|zcd\.js)$#', $path, $matches) === 1) {
    header('Content-Type: ' . ($matches[1] === 'zcd.css' ? 'text/css' : 'application/javascript'));
    readfile($root . '/assets/' . $matches[1]);

    return true;
}

$pages = [
    '/' => 'index.live.php',
    '/index.live.php' => 'index.live.php',
    '/job.live.php' => 'job.live.php',
    '/settings.live.php' => 'settings.live.php',
    '/actions.php' => 'actions.php',
];

if (!isset($pages[$path])) {
    http_response_code(404);
    echo 'Not found';

    return true;
}

require $root . '/' . $pages[$path];

return true;
