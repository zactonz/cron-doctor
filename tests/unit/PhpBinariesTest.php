<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Tests\Unit;

use Zactonz\CronDoctor\Platform\PhpBinaries;
use Zactonz\CronDoctor\Platform\PhpUsage;
use Zactonz\CronDoctor\Support\Filesystem;
use Zactonz\CronDoctor\Tests\Framework\FakeCpanelApi;
use Zactonz\CronDoctor\Tests\Framework\TestCase;

final class PhpBinariesTest extends TestCase
{
    private string $root = '';

    public function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/zcd-php-' . bin2hex(random_bytes(6));

        foreach (['ea-php74', 'ea-php82', 'ea-php83'] as $version) {
            $directory = $this->root . '/opt/cpanel/' . $version . '/root/usr/bin';
            Filesystem::ensureDirectory($directory);
            file_put_contents($directory . '/php', "#!/bin/sh\n");
            chmod($directory . '/php', 0755);
        }

        $alternative = $this->root . '/opt/alt/php81/usr/bin';
        Filesystem::ensureDirectory($alternative);
        file_put_contents($alternative . '/php', "#!/bin/sh\n");
        chmod($alternative . '/php', 0755);
    }

    public function tearDown(): void
    {
        if ($this->root !== '') {
            Filesystem::removeDirectory($this->root);
        }
    }

    public function testInstalledInterpretersAreFoundNewestFirst(): void
    {
        $binaries = new PhpBinaries(new FakeCpanelApi(), $this->root);
        $available = $binaries->available();

        $this->assertSame(['ea-php83', 'ea-php82', 'ea-php74', 'alt-php81'], array_keys($available));
        $this->assertSame($this->root . '/opt/cpanel/ea-php82/root/usr/bin/php', $binaries->pathFor('ea-php82'));
        $this->assertSame('ea-php83', $binaries->newestVersion());
        $this->assertNull($binaries->pathFor('ea-php99'));
    }

    public function testInterpretersAreClassified(): void
    {
        $this->assertSame(PhpUsage::KIND_GENERIC, PhpBinaries::classify('/usr/bin/php'));
        $this->assertSame(PhpUsage::KIND_GENERIC, PhpBinaries::classify('php'));
        $this->assertSame(PhpUsage::KIND_GENERIC, PhpBinaries::classify('/usr/local/bin/php'));
        $this->assertSame(PhpUsage::KIND_GENERIC, PhpBinaries::classify('/usr/bin/php-cgi'));
        $this->assertSame(PhpUsage::KIND_EASYAPACHE, PhpBinaries::classify('/opt/cpanel/ea-php82/root/usr/bin/php'));
        $this->assertSame(PhpUsage::KIND_EASYAPACHE, PhpBinaries::classify('ea-php82'));
        $this->assertSame(PhpUsage::KIND_ALTERNATIVE, PhpBinaries::classify('/opt/alt/php81/usr/bin/php'));

        $this->assertNull(PhpBinaries::classify('/usr/bin/mysqldump'));
        $this->assertNull(PhpBinaries::classify('/home/me/phpsomething.sh'));
        $this->assertNull(PhpBinaries::classify('PHPRC=/etc'));
    }

    public function testTheInterpreterAndScriptAreLocatedInACommand(): void
    {
        $binaries = new PhpBinaries(new FakeCpanelApi(), $this->root);
        $usage = $binaries->inspect('/usr/bin/php -d memory_limit=256M /home/me/public_html/cron.php --now');

        $this->assertNotNull($usage);
        $this->assertSame(0, $usage->tokenIndex());
        $this->assertTrue($usage->isGeneric());
        $this->assertSame('/home/me/public_html/cron.php', $usage->scriptPath());
    }

    public function testPhpCommandLineOptionsAreUnderstoodWhenFindingTheScript(): void
    {
        $binaries = new PhpBinaries(new FakeCpanelApi(), $this->root);

        $cases = [
            '/usr/bin/php -q /home/me/a.php' => '/home/me/a.php',
            '/usr/bin/php -d memory_limit=256M -d max_execution_time=0 /home/me/b.php' => '/home/me/b.php',
            '/usr/bin/php -dmemory_limit=256M /home/me/c.php' => '/home/me/c.php',
            '/usr/bin/php -c /etc/php.ini /home/me/d.php' => '/home/me/d.php',
            '/usr/bin/php -f /home/me/e.php' => '/home/me/e.php',
            '/usr/bin/php --file /home/me/f.php' => '/home/me/f.php',
            '/usr/bin/php -- /home/me/g.php' => '/home/me/g.php',
            '/usr/bin/php --define memory_limit=1G /home/me/h.php' => '/home/me/h.php',
        ];

        foreach ($cases as $command => $expected) {
            $usage = $binaries->inspect($command);

            $this->assertNotNull($usage, $command);
            $this->assertSame($expected, $usage->scriptPath(), $command);
        }
    }

    public function testInlineCodeHasNoScriptPath(): void
    {
        $binaries = new PhpBinaries(new FakeCpanelApi(), $this->root);
        $usage = $binaries->inspect("/usr/bin/php -r 'echo 1;'");

        $this->assertNotNull($usage);
        $this->assertNull($usage->scriptPath());
    }

    public function testAnInterpreterLaterInTheCommandIsStillFound(): void
    {
        $binaries = new PhpBinaries(new FakeCpanelApi(), $this->root);
        $usage = $binaries->inspect('cd /home/me/public_html && php wp-cron.php');

        $this->assertNotNull($usage);
        $this->assertSame(3, $usage->tokenIndex());
        $this->assertSame('/home/me/public_html/wp-cron.php', $usage->scriptPath());
    }

    public function testARelativeScriptIsResolvedAgainstAPrecedingChangeOfDirectory(): void
    {
        $binaries = new PhpBinaries($this->apiWithRoots(), $this->root);

        $usage = $binaries->inspect('cd /home/me/public_html/shop && /usr/bin/php cron.php');
        $this->assertNotNull($usage);
        $this->assertSame('/home/me/public_html/shop/cron.php', $usage->scriptPath());
        $this->assertSame('ea-php83', $binaries->recommendedFor($usage->scriptPath()));
    }

    public function testARelativeScriptWithNoChangeOfDirectoryIsLeftAlone(): void
    {
        $binaries = new PhpBinaries(new FakeCpanelApi(), $this->root);
        $usage = $binaries->inspect('/usr/bin/php cron.php');

        $this->assertNotNull($usage);
        $this->assertSame('cron.php', $usage->scriptPath());
    }

    public function testNonPhpCommandsAreIgnored(): void
    {
        $binaries = new PhpBinaries(new FakeCpanelApi(), $this->root);

        $this->assertNull($binaries->inspect('/usr/bin/mysqldump -u me db'));
        $this->assertNull($binaries->inspect('/usr/local/bin/wp cron event run --due-now'));
        $this->assertNull($binaries->inspect('curl -s https://example.com/cron.php'));
    }

    public function testUnbalancedQuotesPreventInspection(): void
    {
        $binaries = new PhpBinaries(new FakeCpanelApi(), $this->root);

        $this->assertNull($binaries->inspect('/usr/bin/php "unterminated'));
    }

    public function testTheVersionForAPathUsesTheLongestMatchingDocumentRoot(): void
    {
        $binaries = new PhpBinaries($this->apiWithRoots(), $this->root);

        $this->assertSame('ea-php74', $binaries->versionForPath('/home/me/public_html/cron.php'));
        $this->assertSame('ea-php83', $binaries->versionForPath('/home/me/public_html/shop/cron.php'));
        $this->assertNull($binaries->versionForPath('/home/me/outside/cron.php'));
    }

    public function testADocumentRootMatchIsRecommended(): void
    {
        $binaries = new PhpBinaries($this->apiWithRoots(), $this->root);

        $this->assertSame('ea-php83', $binaries->recommendedFor('/home/me/public_html/shop/cron.php'));
        $this->assertSame('ea-php74', $binaries->recommendedFor('/home/me/public_html/cron.php'));
    }

    public function testASingleDocumentRootIsRecommendedForScriptsOutsideIt(): void
    {
        $api = new FakeCpanelApi(true, null, [
            ['vhost' => 'example.com', 'documentroot' => '/home/me/public_html', 'version' => 'ea-php82', 'source' => ''],
        ]);

        $binaries = new PhpBinaries($api, $this->root);

        $this->assertSame('ea-php82', $binaries->recommendedFor('/home/me/scripts/task.php'));
    }

    public function testNothingIsRecommendedWhenTheChoiceIsAmbiguous(): void
    {
        $binaries = new PhpBinaries($this->apiWithRoots(), $this->root);

        $this->assertNull($binaries->recommendedFor('/home/me/scripts/task.php'));
        $this->assertNull($binaries->recommendedFor(null));
    }

    private function apiWithRoots(): FakeCpanelApi
    {
        return new FakeCpanelApi(true, null, [
            ['vhost' => 'example.com', 'documentroot' => '/home/me/public_html', 'version' => 'ea-php74', 'source' => ''],
            ['vhost' => 'shop.example.com', 'documentroot' => '/home/me/public_html/shop', 'version' => 'ea-php83', 'source' => ''],
        ]);
    }
}
