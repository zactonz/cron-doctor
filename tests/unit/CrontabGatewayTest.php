<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Tests\Unit;

use Zactonz\CronDoctor\Cron\CrontabDocument;
use Zactonz\CronDoctor\Platform\CrontabGateway;
use Zactonz\CronDoctor\Platform\CrontabSnapshot;
use Zactonz\CronDoctor\Platform\Environment;
use Zactonz\CronDoctor\Platform\ProcessRunner;
use Zactonz\CronDoctor\Tests\Framework\FakeCpanelApi;
use Zactonz\CronDoctor\Tests\Framework\TemporaryAccount;
use Zactonz\CronDoctor\Tests\Framework\TestCase;

final class CrontabGatewayTest extends TestCase
{
    private const SAMPLE = "MAILTO=\"ops@example.com\"\n"
        . "\n"
        . "# nightly work\n"
        . "15 3 * * * /usr/bin/mysqldump db > /home/me/db.sql\n"
        . "*/5 * * * * /usr/bin/php /home/me/cron.php >/dev/null 2>&1\n";

    private ?TemporaryAccount $account = null;

    public function tearDown(): void
    {
        if ($this->account !== null) {
            $this->account->destroy();
            $this->account = null;
        }
    }

    public function testAnAbsentCrontabReadsAsEmptyRatherThanFailing(): void
    {
        $gateway = $this->gateway();
        $this->account->removeCrontab();

        $snapshot = $gateway->read();

        $this->assertTrue($snapshot->readable());
        $this->assertSame(CrontabSnapshot::SOURCE_BINARY, $snapshot->source());
        $this->assertSame(0, count($snapshot->document()->jobs()));
    }

    public function testTheCrontabIsReadVerbatim(): void
    {
        $gateway = $this->gateway();
        $this->account->setCrontab(self::SAMPLE);

        $snapshot = $gateway->read();

        $this->assertTrue($snapshot->isVerbatim());
        $this->assertSame(self::SAMPLE, $snapshot->document()->render());
    }

    public function testAChangeIsWrittenAndEverythingElseIsPreserved(): void
    {
        $gateway = $this->gateway();
        $this->account->setCrontab(self::SAMPLE);

        $snapshot = $gateway->read();
        $updated = $snapshot->document()->withLineAt(4, '*/5 * * * * /home/me/.zactonz/zcd/bin/zcd-run 0123456789abcdef');

        $result = $gateway->write($updated, $snapshot->fingerprint());

        $this->assertTrue($result->successful(), $result->message());

        $written = $this->account->crontab();
        $this->assertNotNull($written);
        $this->assertContainsText('zcd-run 0123456789abcdef', $written);
        $this->assertContainsText('MAILTO="ops@example.com"', $written);
        $this->assertContainsText('# nightly work', $written);
        $this->assertContainsText('mysqldump', $written);
        $this->assertNotContainsText('/usr/bin/php /home/me/cron.php', $written);
    }

    public function testAStaleFingerprintStopsTheWrite(): void
    {
        $gateway = $this->gateway();
        $this->account->setCrontab(self::SAMPLE);

        $snapshot = $gateway->read();
        $updated = $snapshot->document()->withAppendedLine('0 4 * * * /bin/new');

        $this->account->setCrontab(self::SAMPLE . "0 9 * * * /bin/elsewhere\n");

        $result = $gateway->write($updated, $snapshot->fingerprint());

        $this->assertTrue($result->failed());
        $this->assertContainsText('changed since this page was loaded', $result->message());
        $this->assertContainsText('/bin/elsewhere', (string) $this->account->crontab());
        $this->assertNotContainsText('/bin/new', (string) $this->account->crontab());
    }

    public function testEmptyingTheCrontabIsRefusedUnlessRequested(): void
    {
        $gateway = $this->gateway();
        $this->account->setCrontab(self::SAMPLE);

        $snapshot = $gateway->read();
        $emptied = CrontabDocument::parse("MAILTO=\"ops@example.com\"\n");

        $result = $gateway->write($emptied, $snapshot->fingerprint());

        $this->assertTrue($result->failed());
        $this->assertContainsText('remove every cron job', $result->message());
        $this->assertContainsText('mysqldump', (string) $this->account->crontab());

        $allowed = $gateway->write($emptied, $snapshot->fingerprint(), true);
        $this->assertTrue($allowed->successful(), $allowed->message());
    }

    public function testAWriteThatDoesNotVerifyIsRolledBack(): void
    {
        $gateway = $this->gateway();
        $this->account->setCrontab(self::SAMPLE);

        $snapshot = $gateway->read();
        $updated = $snapshot->document()->withAppendedLine('0 4 * * * /bin/new');

        $this->account->setMode('sabotage');
        $result = $gateway->write($updated, $snapshot->fingerprint());
        $this->account->setMode('normal');

        $this->assertTrue($result->failed());
        $this->assertContainsText('previous version was restored', $result->message());

        $this->assertSame(self::SAMPLE, (string) $this->account->crontab());
    }

    public function testAFailedRollbackNamesTheBackupInsteadOfStayingSilent(): void
    {
        $gateway = $this->gateway();
        $this->account->setCrontab(self::SAMPLE);

        $snapshot = $gateway->read();
        $updated = $snapshot->document()->withAppendedLine('0 4 * * * /bin/new');

        $this->account->setMode('sabotage-always');
        $result = $gateway->write($updated, $snapshot->fingerprint());
        $this->account->setMode('normal');

        $this->assertTrue($result->failed());
        $this->assertContainsText('could not be restored automatically', $result->message());
        $this->assertMatches('/crontab-[0-9]{8}-[0-9]{6}-[0-9a-f]{6}\.txt/', $result->message());

        $context = $result->context();
        $this->assertTrue(isset($context['urgent']));
    }

    public function testACrontabCommandThatRefusesTheFileChangesNothing(): void
    {
        $gateway = $this->gateway();
        $this->account->setCrontab(self::SAMPLE);

        $snapshot = $gateway->read();
        $updated = $snapshot->document()->withAppendedLine('0 4 * * * /bin/new');

        $this->account->setMode('reject');
        $result = $gateway->write($updated, $snapshot->fingerprint());
        $this->account->setMode('normal');

        $this->assertTrue($result->failed());
        $this->assertContainsText('refused the change', $result->message());
        $this->assertSame(self::SAMPLE, (string) $this->account->crontab());
    }

    public function testAHeaderAddedByCrontabDoesNotTriggerARollback(): void
    {
        $gateway = $this->gateway();
        $this->account->setCrontab(self::SAMPLE);

        $snapshot = $gateway->read();
        $updated = $snapshot->document()->withAppendedLine('0 4 * * * /bin/new');

        $this->account->setMode('header');
        $result = $gateway->write($updated, $snapshot->fingerprint());
        $this->account->setMode('normal');

        $this->assertTrue($result->successful(), $result->message());
        $this->assertContainsText('/bin/new', (string) $this->account->crontab());
        $this->assertContainsText('DO NOT EDIT', (string) $this->account->crontab());
    }

    public function testEveryWriteLeavesARestorableBackup(): void
    {
        $gateway = $this->gateway();
        $this->account->setCrontab(self::SAMPLE);

        $snapshot = $gateway->read();
        $updated = $snapshot->document()->withAppendedLine('0 4 * * * /bin/new');
        $gateway->write($updated, $snapshot->fingerprint());

        $backups = $gateway->backups();
        $this->assertSame(1, count($backups));

        $restored = $gateway->restore($backups[0]['name']);
        $this->assertTrue($restored->successful(), $restored->message());
        $this->assertSame(self::SAMPLE, (string) $this->account->crontab());
    }

    public function testBackupNamesAreValidated(): void
    {
        $gateway = $this->gateway();

        foreach (['../../etc/passwd', 'crontab-../x.txt', 'other.txt', ''] as $name) {
            $result = $gateway->restore($name);
            $this->assertTrue($result->failed(), 'should refuse ' . $name);
        }
    }

    public function testTheCpanelApiIsUsedWhenTheBinaryIsMissing(): void
    {
        $this->account = new TemporaryAccount();

        $environment = new Environment(
            'testuser',
            $this->account->home(),
            new ProcessRunner(),
            '/nonexistent/crontab'
        );

        $api = new FakeCpanelApi(true, [
            ['type' => 'variable', 'line' => 'MAILTO=ops@example.com'],
            ['type' => 'command', 'line' => '*/5 * * * * /usr/bin/php /home/me/cron.php'],
        ]);

        $gateway = new CrontabGateway($environment, $api);
        $snapshot = $gateway->read();

        $this->assertTrue($snapshot->readable());
        $this->assertFalse($snapshot->isVerbatim());
        $this->assertSame(CrontabSnapshot::SOURCE_API, $snapshot->source());
        $this->assertSame(1, count($snapshot->document()->jobs()));
        $this->assertFalse($gateway->writable());
    }

    public function testWritingIsRefusedWhenNothingCanReadTheCrontab(): void
    {
        $this->account = new TemporaryAccount();

        $environment = new Environment(
            'testuser',
            $this->account->home(),
            new ProcessRunner(),
            '/nonexistent/crontab'
        );

        $gateway = new CrontabGateway($environment, new FakeCpanelApi(false, null));
        $snapshot = $gateway->read();

        $this->assertFalse($snapshot->readable());
        $this->assertSame(CrontabSnapshot::SOURCE_NONE, $snapshot->source());

        $result = $gateway->write(CrontabDocument::parse("0 1 * * * /bin/x\n"), $snapshot->fingerprint());
        $this->assertTrue($result->failed());
    }

    private function gateway(): CrontabGateway
    {
        $this->account = new TemporaryAccount();

        return new CrontabGateway($this->account->environment(), new FakeCpanelApi(false, null));
    }
}
