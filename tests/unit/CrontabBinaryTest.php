<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Tests\Unit;

use Zactonz\CronDoctor\Platform\Environment;
use Zactonz\CronDoctor\Platform\ProcessRunner;
use Zactonz\CronDoctor\Support\Filesystem;
use Zactonz\CronDoctor\Tests\Framework\TestCase;

final class CrontabBinaryTest extends TestCase
{
    private string $root = '';

    public function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/zcd-crontab-' . bin2hex(random_bytes(6));
        Filesystem::ensureDirectory($this->root);
    }

    public function tearDown(): void
    {
        if ($this->root !== '') {
            Filesystem::removeDirectory($this->root);
        }
    }

    public function testAJailedCrontabThatCannotReadIsPassedOverForOneThatCan(): void
    {
        $broken = $this->binary('broken', "echo \"/var/spool/cron/demo: Permission denied\" >&2\nexit 1\n");
        $working = $this->binary('working', "echo '0 3 * * * /bin/true'\nexit 0\n");

        $environment = $this->environmentWith([$broken, $working]);

        $this->assertSame($working, $environment->crontabBinary());
        $this->assertTrue($environment->canModifyCrontab());
    }

    public function testACrontabThatReportsNoCrontabIsAccepted(): void
    {
        $empty = $this->binary('empty', "echo \"no crontab for demo\" >&2\nexit 1\n");
        $working = $this->binary('working', "echo '0 3 * * * /bin/true'\nexit 0\n");

        $this->assertSame($empty, $this->environmentWith([$empty, $working])->crontabBinary());
    }

    public function testTheFirstWorkingCandidateWins(): void
    {
        $working = $this->binary('working', "exit 0\n");
        $other = $this->binary('other', "exit 0\n");

        $this->assertSame($working, $this->environmentWith([$working, $other])->crontabBinary());
    }

    public function testWhenNoCandidateWorksTheFirstInstalledOneIsStillReturned(): void
    {
        $broken = $this->binary('broken', "echo 'Permission denied' >&2\nexit 1\n");
        $alsoBroken = $this->binary('also', "echo 'Permission denied' >&2\nexit 1\n");

        $this->assertSame($broken, $this->environmentWith([$broken, $alsoBroken])->crontabBinary());
    }

    public function testMissingCandidatesAreSkipped(): void
    {
        $working = $this->binary('working', "exit 0\n");

        $this->assertSame(
            $working,
            $this->environmentWith([$this->root . '/not-installed', $working])->crontabBinary()
        );
    }

    public function testNoCandidatesMeansNoBinary(): void
    {
        $environment = $this->environmentWith([$this->root . '/nothing-here']);

        $this->assertNull($environment->crontabBinary());
        $this->assertFalse($environment->canModifyCrontab());
    }

    public function testTheProbeRunsOnlyOnce(): void
    {
        $counter = $this->root . '/probe-count';
        $working = $this->binary('counting', "echo x >> '$counter'\nexit 0\n");

        $environment = $this->environmentWith([$working]);

        $environment->crontabBinary();
        $environment->crontabBinary();
        $environment->crontabBinary();

        $this->assertSame(1, count(file($counter) ?: []));
    }

    public function testAnExplicitBinaryIsUsedWithoutProbing(): void
    {
        $broken = $this->binary('broken', "exit 1\n");
        $environment = new Environment('demo', $this->root, new ProcessRunner(), $broken);

        $this->assertSame($broken, $environment->crontabBinary());
    }

    public function testCpanelsJailSafeWrapperIsPreferredOverTheRawBinary(): void
    {
        $this->assertSame(
            ['/usr/local/bin/crontab', '/usr/local/cpanel/bin/jail_safe_crontab', '/usr/bin/crontab', '/bin/crontab'],
            Environment::CRONTAB_CANDIDATES,
            'cPanel jail-safe wrappers must be tried before the raw crontab binary'
        );
    }

    private function binary(string $name, string $body): string
    {
        $path = $this->root . '/' . $name;

        file_put_contents($path, "#!/bin/sh\n" . $body);
        chmod($path, 0755);

        return $path;
    }

    private function environmentWith(array $candidates): Environment
    {
        return new Environment('demo', $this->root, new ProcessRunner(), null, $candidates);
    }
}
