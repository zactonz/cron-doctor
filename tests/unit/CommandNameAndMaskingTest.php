<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Tests\Unit;

use Zactonz\CronDoctor\Cron\CommandName;
use Zactonz\CronDoctor\Tests\Framework\TestCase;
use Zactonz\CronDoctor\View\Html;

final class CommandNameAndMaskingTest extends TestCase
{
    public function testAJobIsNamedAfterWhatItActuallyRuns(): void
    {
        $cases = [
            '/usr/bin/php /home/me/public_html/cron.php' => 'cron.php',
            '/home/me/bin/backup.sh' => 'backup.sh',
            'cd /home/me/public_html && php wp-cron.php >/dev/null 2>&1' => 'wp-cron.php',
            '/opt/cpanel/ea-php74/root/usr/bin/php /home/me/bin/newsletter.php' => 'newsletter.php',
            '/usr/bin/mysqldump -u me -psecret db > /home/me/db.sql' => 'mysqldump',
            '/usr/local/bin/wp cron event run --due-now --path=/home/me/public_html' => 'wp',
            'nice -n 10 /home/me/bin/heavy.sh' => 'heavy.sh',
            'MAILTO=me@example.com /home/me/bin/task.sh' => 'task.sh',
        ];

        foreach ($cases as $command => $expected) {
            $this->assertSame($expected, CommandName::describe($command), $command);
        }
    }

    public function testACommandThatIsOnlyAWrapperFallsBackToTheWrapper(): void
    {
        $this->assertSame('sh', CommandName::describe("/bin/sh -c 'echo hi; exit 1'"));
        $this->assertSame('php', CommandName::describe("/usr/bin/php -r 'echo 1;'"));
        $this->assertSame(CommandName::FALLBACK, CommandName::describe(''));
        $this->assertSame(CommandName::FALLBACK, CommandName::describe('--flag --other'));
    }

    public function testCredentialsAreMaskedWhereTheyAppear(): void
    {
        $this->assertSame(
            '/usr/bin/mysqldump -u me -p•••••• db',
            Html::maskSecrets('/usr/bin/mysqldump -u me -phunter2 db')
        );

        $this->assertSame(
            'curl -H "Authorization: Bearer ••••••" https://example.com',
            Html::maskSecrets('curl -H "Authorization: Bearer abc123xyz" https://example.com')
        );

        $this->assertSame(
            '/usr/bin/mysql --password=•••••• db',
            Html::maskSecrets('/usr/bin/mysql --password=hunter2 db')
        );

        $this->assertSame(
            'PGPASSWORD=•••••• /usr/bin/psql db',
            Html::maskSecrets('PGPASSWORD=hunter2 /usr/bin/psql db')
        );
    }

    public function testMaskingNeverDamagesAPathThatMerelyContainsDashP(): void
    {
        $untouched = [
            '/opt/cpanel/ea-php74/root/usr/bin/php /home/me/cron.php',
            '/opt/cpanel/ea-php83/root/usr/bin/php -d memory_limit=256M /home/me/a.php',
            '/home/me/my-project/run.sh',
            '/usr/bin/wget https://example.com/x?a-page=1',
            '/usr/local/bin/wp cron event run --path=/home/me/public_html',
        ];

        foreach ($untouched as $command) {
            $this->assertSame($command, Html::maskSecrets($command), $command);
            $this->assertFalse(Html::containsSecret($command), $command);
        }
    }

    public function testSecretDetectionAgreesWithMasking(): void
    {
        $this->assertTrue(Html::containsSecret('/usr/bin/mysqldump -u me -phunter2 db'));
        $this->assertFalse(Html::containsSecret('/usr/bin/mysqldump -u me db'));
    }
}
