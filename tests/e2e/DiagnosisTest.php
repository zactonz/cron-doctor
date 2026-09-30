<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Tests\E2e;

use DateTimeImmutable;
use DateTimeZone;
use Zactonz\CronDoctor\Doctor\Checkup;
use Zactonz\CronDoctor\Doctor\Finding;
use Zactonz\CronDoctor\Doctor\Inspection;
use Zactonz\CronDoctor\Doctor\InspectionBuilder;
use Zactonz\CronDoctor\Doctor\Severity;
use Zactonz\CronDoctor\Job\JobStore;
use Zactonz\CronDoctor\Job\Payload;
use Zactonz\CronDoctor\Job\RunHistory;
use Zactonz\CronDoctor\Platform\CrontabGateway;
use Zactonz\CronDoctor\Platform\PhpBinaries;
use Zactonz\CronDoctor\Support\Filesystem;
use Zactonz\CronDoctor\Tests\Framework\FakeCpanelApi;
use Zactonz\CronDoctor\Tests\Framework\FrozenClock;
use Zactonz\CronDoctor\Tests\Framework\TemporaryAccount;
use Zactonz\CronDoctor\Tests\Framework\TestCase;

final class DiagnosisTest extends TestCase
{
    private ?TemporaryAccount $account = null;

    private ?Inspection $inspection = null;

    private array $findings = [];

    public function setUp(): void
    {
        $this->account = new TemporaryAccount();
        $home = $this->account->home();

        Filesystem::ensureDirectory($home . '/public_html');
        file_put_contents($home . '/public_html/wp-cron.php', "<?php\n");
        file_put_contents($home . '/public_html/wp-config.php', "<?php\ndefine('DB_NAME', 'x');\n");
        file_put_contents($home . '/public_html/task.php', "<?php\n");
        file_put_contents($home . '/backup.sh', "#!/bin/sh\n");

        $this->account->setCrontab(implode("\n", [
            'MAILTO=""',
            '# site maintenance',
            '*/5 * * * * /usr/bin/php ' . $home . '/public_html/wp-cron.php >/dev/null 2>&1',
            '*/5 * * * * /usr/bin/php ' . $home . '/public_html/wp-cron.php >/dev/null 2>&1',
            '0 3 * * * ' . $home . '/gone-missing.sh',
            '30 2 * * * /opt/cpanel/ea-php74/root/usr/bin/php ' . $home . '/public_html/task.php',
            '0 1 * * * /usr/bin/mysqldump -u me -psecret db > ' . $home . '/db.sql',
            'this line is broken',
            '@reboot ' . $home . '/backup.sh',
            '',
        ]));

        $this->rebuild();
    }

    public function tearDown(): void
    {
        if ($this->account !== null) {
            $this->account->destroy();
            $this->account = null;
        }
    }

    public function testEmptyMailtoIsReported(): void
    {
        $this->assertHasFinding('mail.disabled');
    }

    public function testABrokenCrontabLineIsReported(): void
    {
        $finding = $this->finding('crontab.unreadable-line');

        $this->assertNotNull($finding);
        $this->assertSame(Severity::CRITICAL, $finding->severity());
        $this->assertContainsText('this line is broken', $finding->detail());
    }

    public function testAnIdenticalRepeatedJobIsReported(): void
    {
        $this->assertHasFinding('crontab.duplicate');
    }

    public function testDuplicatedWordPressCronIsReported(): void
    {
        $finding = $this->finding('wordpress.duplicate');

        $this->assertNotNull($finding);
        $this->assertContainsText('Lines 3 and 4 drive WordPress cron', $finding->detail());
    }

    public function testWordPressStillRunningItsOwnCronIsReported(): void
    {
        $this->assertHasFinding('wordpress.internal-cron');
    }

    public function testDisablingInternalCronClearsThatFinding(): void
    {
        file_put_contents(
            $this->account->home() . '/public_html/wp-config.php',
            "<?php\ndefine('DISABLE_WP_CRON', true);\n"
        );

        $this->rebuild();

        $this->assertNull($this->finding('wordpress.internal-cron'));
    }

    public function testAGenericPhpPathIsReportedWithAFix(): void
    {
        $finding = $this->finding('interpreter.generic');

        $this->assertNotNull($finding);
        $this->assertNotNull($finding->action());
        $this->assertSame('fix-interpreter-line', $finding->action()->name());
        $this->assertContainsText('ea-php83', $finding->detail());
    }

    public function testAVersionMismatchIsReported(): void
    {
        $finding = $this->finding('interpreter.mismatch');

        $this->assertNotNull($finding);
        $this->assertContainsText('ea-php74', $finding->detail());
        $this->assertContainsText('ea-php83', $finding->detail());
    }

    public function testAMissingScriptIsReported(): void
    {
        $finding = $this->finding('target.missing');

        $this->assertNotNull($finding);
        $this->assertSame(Severity::CRITICAL, $finding->severity());
        $this->assertContainsText('gone-missing.sh', $finding->detail());
    }

    public function testAnExistingScriptIsNotReportedAsMissing(): void
    {
        foreach ($this->findings as $finding) {
            if ($finding->code() !== 'target.missing') {
                continue;
            }

            $this->assertNotContainsText('backup.sh', $finding->detail());
            $this->assertNotContainsText('task.php', $finding->detail());
        }

        $this->assertTrue(true);
    }

    public function testDiscardedOutputIsReported(): void
    {
        $this->assertHasFinding('output.discarded');
    }

    public function testAPasswordOnTheCommandLineIsReported(): void
    {
        $finding = $this->finding('command.secret');

        $this->assertNotNull($finding);
        $this->assertSame(Severity::ADVICE, $finding->severity());
    }

    public function testRebootJobsAreExplainedRatherThanFlagged(): void
    {
        $finding = $this->finding('schedule.reboot');

        $this->assertNotNull($finding);
        $this->assertSame(Severity::ADVICE, $finding->severity());
    }

    public function testUnmonitoredJobsAreSummarisedInOneFinding(): void
    {
        $unmonitored = [];

        foreach ($this->findings as $finding) {
            if ($finding->code() === 'job.unmonitored') {
                $unmonitored[] = $finding;
            }
        }

        $this->assertSame(1, count($unmonitored));
        $this->assertSame('6 jobs are not monitored yet', $unmonitored[0]->title());
        $this->assertNull($unmonitored[0]->lineNumber());
    }

    public function testFindingsAreOrderedBySeverity(): void
    {
        $previous = -1;

        foreach ($this->findings as $finding) {
            $rank = Severity::rank($finding->severity());
            $this->assertTrue($rank >= $previous, 'findings must be ordered by severity');
            $previous = $rank;
        }
    }

    public function testFindingsAreAttachedToTheirOwnCronLine(): void
    {
        $entry = $this->inspection->entryFor(4);

        $this->assertNotNull($entry);

        $codes = [];

        foreach ($entry->findings() as $finding) {
            $codes[] = $finding->code();
        }

        $this->assertTrue(in_array('target.missing', $codes, true), 'the missing script belongs to line 4');
        $this->assertFalse(in_array('mail.disabled', $codes, true), 'a crontab wide finding is not attached to a line');
    }

    public function testAHealthyCrontabProducesNoSeriousFindings(): void
    {
        $home = $this->account->home();
        $this->account->setCrontab(
            "MAILTO=\"ops@example.com\"\n"
            . '30 2 * * * /opt/cpanel/ea-php83/root/usr/bin/php ' . $home . "/public_html/task.php\n"
        );

        $this->rebuild();

        foreach ($this->findings as $finding) {
            $this->assertNotSame(
                Severity::CRITICAL,
                $finding->severity(),
                'unexpected critical finding: ' . $finding->code() . ' - ' . $finding->title()
            );
        }
    }

    private function rebuild(): void
    {
        $environment = $this->account->environment();
        $api = new FakeCpanelApi(true, null, [
            [
                'vhost' => 'example.com',
                'documentroot' => $this->account->home() . '/public_html',
                'version' => 'ea-php83',
                'source' => '',
            ],
        ]);

        $gateway = new CrontabGateway($environment, new FakeCpanelApi(false, null));
        $store = new JobStore($environment);
        $payload = new Payload($environment, dirname(__DIR__, 2));

        $builder = new InspectionBuilder(
            $environment,
            $gateway,
            $store,
            new RunHistory($environment),
            new PhpBinaries($api, $this->phpRoot()),
            $payload,
            new FrozenClock(new DateTimeImmutable('2026-09-21 12:00:00', new DateTimeZone('UTC')))
        );

        $this->inspection = $builder->build();
        $this->findings = Checkup::withDefaultChecks()->run($this->inspection);
    }

    private function phpRoot(): string
    {
        $root = $this->account->home() . '/phproot';

        foreach (['ea-php74', 'ea-php83'] as $version) {
            $directory = $root . '/opt/cpanel/' . $version . '/root/usr/bin';
            Filesystem::ensureDirectory($directory);
            file_put_contents($directory . '/php', "#!/bin/sh\n");
            chmod($directory . '/php', 0755);
        }

        return $root;
    }

    private function finding(string $code): ?Finding
    {
        foreach ($this->findings as $finding) {
            if ($finding->code() === $code) {
                return $finding;
            }
        }

        return null;
    }

    private function assertHasFinding(string $code): void
    {
        $this->assertNotNull($this->finding($code), 'expected a ' . $code . ' finding');
    }
}
