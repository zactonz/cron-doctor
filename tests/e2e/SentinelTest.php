<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Tests\E2e;

use DateTimeImmutable;
use DateTimeZone;
use Zactonz\CronDoctor\Job\JobManager;
use Zactonz\CronDoctor\Job\JobStore;
use Zactonz\CronDoctor\Job\Payload;
use Zactonz\CronDoctor\Job\RunHistory;
use Zactonz\CronDoctor\Job\Sentinel;
use Zactonz\CronDoctor\Job\Settings;
use Zactonz\CronDoctor\Platform\CrontabGateway;
use Zactonz\CronDoctor\Tests\Framework\FakeCpanelApi;
use Zactonz\CronDoctor\Tests\Framework\FakeMailer;
use Zactonz\CronDoctor\Tests\Framework\FrozenClock;
use Zactonz\CronDoctor\Tests\Framework\TemporaryAccount;
use Zactonz\CronDoctor\Tests\Framework\TestCase;

final class SentinelTest extends TestCase
{
    private ?TemporaryAccount $account = null;

    private ?JobManager $manager = null;

    private ?JobStore $store = null;

    private ?CrontabGateway $gateway = null;

    private ?FakeMailer $mailer = null;

    private ?FrozenClock $clock = null;

    private ?Settings $settings = null;

    public function setUp(): void
    {
        $this->account = new TemporaryAccount();
        $environment = $this->account->environment();

        $this->gateway = new CrontabGateway($environment, new FakeCpanelApi(false, null));
        $this->store = new JobStore($environment);
        $this->mailer = new FakeMailer();
        $this->clock = new FrozenClock(new DateTimeImmutable('now', new DateTimeZone('UTC')));
        $this->settings = new Settings($environment);

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

    public function testNothingIsSentWithoutAnAddress(): void
    {
        $this->monitorFailingJob();

        $this->assertTrue($this->sentinel()->run()->successful());
        $this->assertSame(0, $this->mailer->count());
    }

    public function testAFailingJobProducesOneAlert(): void
    {
        $this->settings->save('ops@example.com', 24);
        $job = $this->monitorFailingJob();

        $result = $this->sentinel()->run();

        $this->assertTrue($result->successful(), $result->message());
        $this->assertSame(1, $this->mailer->count());

        $message = $this->mailer->last();
        $this->assertSame('ops@example.com', $message['address']);
        $this->assertSame('sh', $job->name());
        $this->assertContainsText('is failing', $message['subject']);
        $this->assertContainsText('Exit code: 3', $message['body']);
        $this->assertContainsText('deliberately broken', $message['body']);
    }

    public function testTheSameProblemIsNotReportedAgainWithinTheRepeatWindow(): void
    {
        $this->settings->save('ops@example.com', 24);
        $this->monitorFailingJob();

        $this->sentinel()->run();
        $this->assertSame(1, $this->mailer->count());

        $this->clock->advance(3600);
        $this->sentinel()->run();
        $this->assertSame(1, $this->mailer->count(), 'a second alert within the window would be spam');

        $this->clock->advance(3600 * 23);
        $this->sentinel()->run();
        $this->assertSame(2, $this->mailer->count(), 'the reminder should arrive once the window passes');
    }

    public function testRecoveryIsReportedOnce(): void
    {
        $this->settings->save('ops@example.com', 24);
        $job = $this->monitorFailingJob();

        $this->sentinel()->run();
        $this->mailer->forget();

        $this->rewriteCommand($job->id(), 'exit 0');
        $this->runJobNow($job->id());

        $this->sentinel()->run();

        $this->assertSame(1, $this->mailer->count());
        $this->assertContainsText('recovered', $this->mailer->last()['subject']);
        $this->assertContainsText('running normally again', $this->mailer->last()['body']);

        $this->mailer->forget();
        $this->sentinel()->run();

        $this->assertSame(0, $this->mailer->count(), 'recovery is announced once, not repeatedly');
    }

    public function testAHealthyAccountSendsNothing(): void
    {
        $this->settings->save('ops@example.com', 24);

        $this->account->setCrontab("*/5 * * * * exit 0\n");
        $snapshot = $this->gateway->read();
        $this->manager->monitor(0, $snapshot->fingerprint());

        $job = array_values($this->store->all())[0];
        $this->runJobNow($job->id());

        $this->assertTrue($this->sentinel()->run()->successful());
        $this->assertSame(0, $this->mailer->count());
    }

    public function testAnOverdueJobIsReported(): void
    {
        $this->settings->save('ops@example.com', 24);

        $this->account->setCrontab("*/5 * * * * exit 0\n");
        $snapshot = $this->gateway->read();
        $this->manager->monitor(0, $snapshot->fingerprint());

        $job = array_values($this->store->all())[0];
        $this->runJobNow($job->id());

        $this->clock->advance(7200);
        $this->settleClockClearOfTheGracePeriod();

        $result = $this->sentinel()->run();

        $this->assertTrue($result->successful(), $result->message());
        $this->assertSame(1, $this->mailer->count());
        $this->assertContainsText('is overdue', $this->mailer->last()['subject']);
        $this->assertContainsText('has not run when it should have', $this->mailer->last()['body']);
    }

    public function testAFailedDeliveryIsReportedAsAFailure(): void
    {
        $this->settings->save('ops@example.com', 24);
        $this->monitorFailingJob();
        $this->mailer->breakDelivery();

        $result = $this->sentinel()->run();

        $this->assertTrue($result->failed());
        $this->assertContainsText('could not be sent', $result->message());
    }

    public function testTheAlertCheckIsAddedAndRemovedCleanly(): void
    {
        $this->account->setCrontab("MAILTO=\"ops@example.com\"\n0 3 * * * exit 0\n");

        $installed = $this->manager->installSentinel($this->gateway->read()->fingerprint());
        $this->assertTrue($installed->successful(), $installed->message());

        $crontab = (string) $this->account->crontab();
        $this->assertContainsText('*/15 * * * *', $crontab);
        $this->assertContainsText('bin/zcd-sentinel', $crontab);
        $this->assertContainsText('0 3 * * * exit 0', $crontab);
        $this->assertTrue($this->manager->sentinelInstalled($this->gateway->read()));

        $removed = $this->manager->removeSentinel($this->gateway->read()->fingerprint());
        $this->assertTrue($removed->successful(), $removed->message());
        $this->assertNotContainsText('zcd-sentinel', (string) $this->account->crontab());
        $this->assertContainsText('0 3 * * * exit 0', (string) $this->account->crontab());
    }

    public function testInstallingTheAlertCheckTwiceAddsOneLine(): void
    {
        $this->account->setCrontab("0 3 * * * exit 0\n");

        $this->manager->installSentinel($this->gateway->read()->fingerprint());
        $this->manager->installSentinel($this->gateway->read()->fingerprint());

        $this->assertSame(1, substr_count((string) $this->account->crontab(), 'zcd-sentinel'));
    }

    public function testTheScheduleIsRememberedSoAlertsSurviveAnUnreadableCrontab(): void
    {
        $this->settings->save('ops@example.com', 24);
        $job = $this->monitorFailingJob();

        $this->assertSame('*/5 * * * *', $job->schedule(), 'the schedule is stored with the job');

        $environment = $this->account->environment();
        $blind = new Sentinel(
            $environment,
            new CrontabGateway($environment, new FakeCpanelApi(false, null)),
            new JobStore($environment),
            new RunHistory($environment),
            new Settings($environment),
            $this->clock,
            $this->mailer
        );

        $this->account->removeCrontab();
        $this->account->setMode('unreadable');

        $result = $blind->run();
        $this->account->setMode('normal');

        $this->assertTrue($result->successful(), $result->message());
        $this->assertSame(1, $this->mailer->count(), 'the failing job is still reported');
        $this->assertContainsText('is failing', $this->mailer->last()['subject']);
    }

    private function settleClockClearOfTheGracePeriod(): void
    {
        $interval = 300;
        $clearance = 150;
        $offset = $this->clock->now()->getTimestamp() % $interval;

        $this->clock->advance((($interval - $offset) % $interval) + $clearance);
    }

    private function sentinel(): Sentinel
    {
        $environment = $this->account->environment();

        return new Sentinel(
            $environment,
            $this->gateway,
            new JobStore($environment),
            new RunHistory($environment),
            new Settings($environment),
            $this->clock,
            $this->mailer
        );
    }

    private function monitorFailingJob(): \Zactonz\CronDoctor\Job\Job
    {
        $this->account->setCrontab("*/5 * * * * /bin/sh -c 'echo deliberately broken >&2; exit 3'\n");

        $snapshot = $this->gateway->read();
        $this->manager->monitor(0, $snapshot->fingerprint());

        $job = array_values($this->store->all())[0];
        $this->runJobNow($job->id());

        return $job;
    }

    private function rewriteCommand(string $jobId, string $command): void
    {
        $job = $this->store->find($jobId);
        $this->store->save($job->withCommand($command, null));
    }

    private function runJobNow(string $jobId): void
    {
        $environment = $this->account->environment();

        $environment->processes()->run([$environment->runnerPath(), $jobId], null, 30);
    }
}
