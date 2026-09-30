<?php

declare(strict_types=1);

use Zactonz\CronDoctor\Support\Filesystem;

require __DIR__ . '/../bootstrap.php';

function zcd_seed_account(string $home): void
{
    Filesystem::removeDirectory($home);
    Filesystem::ensureDirectory($home);
    Filesystem::ensureDirectory($home . '/public_html');
    Filesystem::ensureDirectory($home . '/public_html/shop');
    Filesystem::ensureDirectory($home . '/bin');

    file_put_contents($home . '/public_html/wp-cron.php', "<?php\n");
    file_put_contents($home . '/public_html/wp-config.php', "<?php\ndefine('DB_NAME', 'demo');\n");
    file_put_contents($home . '/public_html/shop/wp-cron.php', "<?php\n");
    file_put_contents($home . '/public_html/shop/wp-config.php', "<?php\ndefine('DISABLE_WP_CRON', true);\n");
    file_put_contents($home . '/bin/backup.sh', "#!/bin/sh\necho backing up\n");
    chmod($home . '/bin/backup.sh', 0755);
    file_put_contents($home . '/bin/newsletter.php', "<?php\n");
    file_put_contents($home . '/bin/warm-cache.sh', "#!/bin/sh\necho warming\n");
    chmod($home . '/bin/warm-cache.sh', 0755);

    $crontab = implode("\n", [
        'MAILTO=""',
        'PATH=/usr/local/bin:/usr/bin:/bin',
        '',
        '# WordPress scheduled tasks',
        '*/5 * * * * /usr/bin/php ' . $home . '/public_html/wp-cron.php >/dev/null 2>&1',
        '*/5 * * * * cd ' . $home . '/public_html && php wp-cron.php >/dev/null 2>&1',
        '',
        '# housekeeping',
        '0 3 * * * ' . $home . '/bin/backup.sh',
        '30 2 * * 1 /opt/cpanel/ea-php74/root/usr/bin/php ' . $home . '/bin/newsletter.php',
        '0 1 * * * /usr/bin/mysqldump -u demo -phunter2 demo_db > ' . $home . '/backups/demo.sql',
        '*/10 * * * * ' . $home . '/bin/removed-last-month.sh',
        '@reboot ' . $home . '/bin/warm-cache.sh',
        '',
    ]);

    file_put_contents($home . '/crontab.store', $crontab);

    foreach (['ea-php74', 'ea-php83'] as $version) {
        $directory = $home . '/phproot/opt/cpanel/' . $version . '/root/usr/bin';
        Filesystem::ensureDirectory($directory);
        file_put_contents($directory . '/php', "#!/bin/sh\n");
        chmod($directory . '/php', 0755);
    }
}
