<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Tests\E2e;

use Zactonz\CronDoctor\Job\JobManager;
use Zactonz\CronDoctor\Job\JobStore;
use Zactonz\CronDoctor\Job\Payload;
use Zactonz\CronDoctor\Job\RunHistory;
use Zactonz\CronDoctor\Platform\CrontabGateway;
use Zactonz\CronDoctor\Tests\Framework\FakeCpanelApi;
use Zactonz\CronDoctor\Tests\Framework\TemporaryAccount;
use Zactonz\CronDoctor\Tests\Framework\TestCase;

final class MonitorJobTest extends TestCase
{
    private const CRONTAB = "MAILTO=\"ops@example.com\"\n"
        . "# keep the site ticking\n"
        . "*/5 * * * * /usr/bin/php /home/me/public_html/cron.php >/dev/null 2>&1\n"
        . "0 3 * * * /home/me/backup.sh\n";

    private ?TemporaryAccount $account = null;

    private ?JobManager $manager = null;

    private ?JobStore $store = null;

    private ?CrontabGateway $gateway = null;

    public function setUp(): void
    {
        $this->account = new TemporaryAccount();
        $this->account->setCrontab(self::CRONTAB);

        $environment = $this->account->environment();
        $this->gateway = new CrontabGateway($environment, new FakeCpanelApi(false, null));
        $this->store = new JobStore($environment);

        $this->manager = new JobManager(
            $environment,
            $this->gateway,
            $this->store,
            new Payload($environment, dirname(__DIR__, 2))
        );
    }

    public function tearDown(): void
    {
        if ($this->account !== null) {
            $this->account->destroy();
            $this->account = null;
        }
    }

    public function testMonitoringReplacesOnlyTheCommandAndKeepsTheSchedule(): void
    {
        $snapshot = $this->gateway->read();
        $result = $this->manager->monitor(2, $snapshot->fingerprint());

        $this->assertTrue($result->successful(), $result->message());

        $written = (string) $this->account->crontab();

        $this->assertContainsText('MAILTO="ops@example.com"', $written);
        $this->assertContainsText('# keep the site ticking', $written);
        $this->assertContainsText('0 3 * * * /home/me/backup.sh', $written);
        $this->assertMatches('#\*/5 \* \* \* \* \S+/bin/zcd-run [0-9a-f]{16}#', $written);
        $this->assertNotContainsText('/usr/bin/php /home/me/public_html/cron.php', $written);
    }

    public function testTheOriginalCommandIsStoredVerbatimForUnwrapping(): void
    {
        $snapshot = $this->gateway->read();
        $this->manager->monitor(2, $snapshot->fingerprint());

        $jobs = $this->store->all();
        $this->assertSame(1, count($jobs));

        $job = array_values($jobs)[0];

        $this->assertSame('/usr/bin/php /home/me/public_html/cron.php >/dev/null 2>&1', $job->command());
        $this->assertSame('cron.php', $job->name());
        $this->assertContainsText('*/5 * * * * /usr/bin/php', $job->originalLine());
        $this->assertTrue($this->store->jobFilesArePresent($job));
    }

    public function testReleasingRestoresTheOriginalCommand(): void
    {
        $snapshot = $this->gateway->read();
        $this->manager->monitor(2, $snapshot->fingerprint());

        $job = array_values($this->store->all())[0];

        $released = $this->manager->release($job->id(), $this->gateway->read()->fingerprint());

        $this->assertTrue($released->successful(), $released->message());
        $this->assertSame(self::CRONTAB, (string) $this->account->crontab());
        $this->assertSame(0, count($this->store->all()));
    }

    public function testReleasingKeepsAScheduleChangedElsewhere(): void
    {
        $snapshot = $this->gateway->read();
        $this->manager->monitor(2, $snapshot->fingerprint());

        $job = array_values($this->store->all())[0];
        $current = (string) $this->account->crontab();
        $this->account->setCrontab(str_replace('*/5 * * * *', '*/30 * * * *', $current));

        $released = $this->manager->release($job->id(), $this->gateway->read()->fingerprint());

        $this->assertTrue($released->successful(), $released->message());
        $this->assertContainsText(
            '*/30 * * * * /usr/bin/php /home/me/public_html/cron.php >/dev/null 2>&1',
            (string) $this->account->crontab()
        );
    }

    public function testAMonitoredJobActuallyRunsAndIsRecorded(): void
    {
        $this->account->setCrontab("*/5 * * * * /bin/echo monitored-run-worked\n");

        $snapshot = $this->gateway->read();
        $result = $this->manager->monitor(0, $snapshot->fingerprint());
        $this->assertTrue($result->successful(), $result->message());

        $job = array_values($this->store->all())[0];
        $started = $this->manager->runNow($job->id());
        $this->assertTrue($started->successful(), $started->message());

        $history = new RunHistory($this->account->environment());
        $record = $this->waitForRun($history, $job->id());

        $this->assertNotNull($record, 'the run should have been recorded');
        $this->assertSame(0, $record->exitCode());
        $this->assertFalse($record->skipped());
        $this->assertContainsText('monitored-run-worked', (string) $history->output($job->id(), $record->sequence()));
    }

    public function testAFailingMonitoredJobRecordsItsExitCodeAndOutput(): void
    {
        $this->account->setCrontab("*/5 * * * * /bin/sh -c 'echo broken >&2; exit 4'\n");

        $snapshot = $this->gateway->read();
        $this->manager->monitor(0, $snapshot->fingerprint());

        $job = array_values($this->store->all())[0];
        $this->manager->runNow($job->id());

        $history = new RunHistory($this->account->environment());
        $record = $this->waitForRun($history, $job->id());

        $this->assertNotNull($record);
        $this->assertSame(4, $record->exitCode());
        $this->assertTrue($record->failed());
        $this->assertSame(1, $history->consecutiveFailures($job->id()));
        $this->assertContainsText('broken', (string) $history->output($job->id(), $record->sequence()));
    }

    public function testCapturingOutputRewritesOnlyTheStoredCommand(): void
    {
        $snapshot = $this->gateway->read();
        $this->manager->monitor(2, $snapshot->fingerprint());

        $job = array_values($this->store->all())[0];
        $before = (string) $this->account->crontab();

        $captured = $this->manager->captureOutput($job->id());
        $this->assertTrue($captured->successful(), $captured->message());

        $updated = $this->store->find($job->id());
        $this->assertNotNull($updated);
        $this->assertSame('/usr/bin/php /home/me/public_html/cron.php', $updated->command());
        $this->assertSame($before, (string) $this->account->crontab());

        $stored = file_get_contents($this->store->commandPath($job->id()));
        $this->assertSame("/usr/bin/php /home/me/public_html/cron.php\n", $stored);
    }

    public function testTheInterpreterFixForAMonitoredJobNeverTouchesTheCrontab(): void
    {
        $snapshot = $this->gateway->read();
        $this->manager->monitor(2, $snapshot->fingerprint());

        $job = array_values($this->store->all())[0];
        $before = (string) $this->account->crontab();

        $applied = $this->manager->applyInterpreterToJob(
            $job->id(),
            0,
            '/opt/cpanel/ea-php82/root/usr/bin/php'
        );

        $this->assertTrue($applied->successful(), $applied->message());
        $this->assertSame($before, (string) $this->account->crontab());

        $updated = $this->store->find($job->id());
        $this->assertNotNull($updated);
        $this->assertSame(
            '/opt/cpanel/ea-php82/root/usr/bin/php /home/me/public_html/cron.php >/dev/null 2>&1',
            $updated->command()
        );
    }

    public function testTheInterpreterFixForAnUnmonitoredJobRewritesTheCrontabLine(): void
    {
        $snapshot = $this->gateway->read();

        $applied = $this->manager->applyInterpreterToLine(
            2,
            0,
            '/opt/cpanel/ea-php82/root/usr/bin/php',
            $snapshot->fingerprint()
        );

        $this->assertTrue($applied->successful(), $applied->message());
        $this->assertContainsText(
            '*/5 * * * * /opt/cpanel/ea-php82/root/usr/bin/php /home/me/public_html/cron.php >/dev/null 2>&1',
            (string) $this->account->crontab()
        );
    }

    public function testRewritingALineKeepsItsStandardInputPayloadIntact(): void
    {
        $this->account->setCrontab("0 4 * * * /usr/bin/php /home/me/report.php%first line%second line\n");

        $snapshot = $this->gateway->read();
        $applied = $this->manager->applyInterpreterToLine(
            0,
            0,
            '/opt/cpanel/ea-php82/root/usr/bin/php',
            $snapshot->fingerprint()
        );

        $this->assertTrue($applied->successful(), $applied->message());
        $this->assertSame(
            "0 4 * * * /opt/cpanel/ea-php82/root/usr/bin/php /home/me/report.php%first line%second line\n",
            (string) $this->account->crontab()
        );

        $line = $this->gateway->read()->document()->jobs()[0];
        $this->assertSame('/opt/cpanel/ea-php82/root/usr/bin/php /home/me/report.php', $line->command());
        $this->assertSame("first line\nsecond line", $line->input());
    }

    public function testMonitoringAJobCarriesItsStandardInputPayloadAcross(): void
    {
        $this->account->setCrontab("0 4 * * * /bin/cat%hello from stdin\n");

        $snapshot = $this->gateway->read();
        $this->assertTrue($this->manager->monitor(0, $snapshot->fingerprint())->successful());

        $job = array_values($this->store->all())[0];
        $this->assertSame('/bin/cat', $job->command());
        $this->assertSame('hello from stdin', $job->input());

        $this->manager->runNow($job->id());

        $history = new RunHistory($this->account->environment());
        $record = $this->waitForRun($history, $job->id());

        $this->assertNotNull($record);
        $this->assertContainsText('hello from stdin', (string) $history->output($job->id(), $record->sequence()));
    }

    public function testAStaleFingerprintBlocksMonitoring(): void
    {
        $snapshot = $this->gateway->read();
        $this->account->setCrontab(self::CRONTAB . "0 9 * * * /bin/elsewhere\n");

        $result = $this->manager->monitor(2, $snapshot->fingerprint());

        $this->assertTrue($result->failed());
        $this->assertContainsText('changed since this page was loaded', $result->message());
        $this->assertSame(0, count($this->store->all()));
    }

    public function testMonitoringIsRolledBackWhenTheCrontabWriteFails(): void
    {
        $snapshot = $this->gateway->read();
        $this->account->setMode('reject');

        $result = $this->manager->monitor(2, $snapshot->fingerprint());
        $this->account->setMode('normal');

        $this->assertTrue($result->failed());
        $this->assertSame(0, count($this->store->all()), 'no job record should survive a failed write');
        $this->assertSame(self::CRONTAB, (string) $this->account->crontab());
    }

    public function testManagedLinesAreRecognised(): void
    {
        $snapshot = $this->gateway->read();
        $this->manager->monitor(2, $snapshot->fingerprint());

        $after = $this->gateway->read();
        $job = array_values($this->store->all())[0];

        $line = $this->manager->findManagedLine($after, $job->id());

        $this->assertNotNull($line);
        $this->assertSame('*/5 * * * *', $line->schedule());
    }

    private function waitForRun(RunHistory $history, string $jobId): ?object
    {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $record = $history->latest($jobId);

            if ($record !== null) {
                return $record;
            }

            usleep(100000);
        }

        return null;
    }
}
