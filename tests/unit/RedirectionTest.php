<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Tests\Unit;

use Zactonz\CronDoctor\Cron\Redirection;
use Zactonz\CronDoctor\Tests\Framework\TestCase;

final class RedirectionTest extends TestCase
{
    public function testTheCommonIdiomDiscardsBothStreams(): void
    {
        foreach (['>/dev/null 2>&1', '> /dev/null 2>&1', '1>/dev/null 2>&1', '&>/dev/null', '>/dev/null 2>/dev/null'] as $tail) {
            $analysis = Redirection::analyse('/usr/bin/php /home/me/cron.php ' . $tail);

            $this->assertTrue($analysis->discardsStdout(), $tail . ' should discard stdout');
            $this->assertTrue($analysis->discardsStderr(), $tail . ' should discard stderr');
            $this->assertTrue($analysis->isRecoverable(), $tail . ' should be recoverable');
            $this->assertSame('/usr/bin/php /home/me/cron.php', $analysis->withoutTrailingDiscard());
        }
    }

    public function testReversedIdiomLeavesErrorsOnTheInheritedStream(): void
    {
        $analysis = Redirection::analyse('/home/me/task.sh 2>&1 >/dev/null');

        $this->assertTrue($analysis->discardsStdout());
        $this->assertFalse($analysis->discardsStderr());
        $this->assertSame(Redirection::SINK_INHERITED, $analysis->stderr());
    }

    public function testDiscardingOnlyStandardErrorIsRecognised(): void
    {
        $analysis = Redirection::analyse('/home/me/task.sh 2>/dev/null');

        $this->assertFalse($analysis->discardsStdout());
        $this->assertTrue($analysis->discardsStderr());
        $this->assertTrue($analysis->isRecoverable());
        $this->assertSame('/home/me/task.sh', $analysis->withoutTrailingDiscard());
    }

    public function testRedirectionToARealFileIsReportedAndNeverStripped(): void
    {
        $analysis = Redirection::analyse('/home/me/task.sh >> /home/me/logs/cron.log 2>&1');

        $this->assertFalse($analysis->discardsStdout());
        $this->assertSame('/home/me/logs/cron.log', $analysis->stdout());
        $this->assertSame('/home/me/logs/cron.log', $analysis->capturesToFile());
        $this->assertFalse($analysis->isRecoverable());
        $this->assertNull($analysis->withoutTrailingDiscard());
    }

    public function testMixedTailWithARealFileIsNotStripped(): void
    {
        $analysis = Redirection::analyse('/home/me/task.sh >/home/me/out.log 2>/dev/null');

        $this->assertFalse($analysis->isRecoverable());
        $this->assertSame('/home/me/out.log', $analysis->stdout());
        $this->assertSame(Redirection::DISCARD, $analysis->stderr());
    }

    public function testCommandsWithNoRedirectionAreLeftAlone(): void
    {
        $analysis = Redirection::analyse('/usr/local/bin/wp cron event run --due-now');

        $this->assertFalse($analysis->discardsStdout());
        $this->assertFalse($analysis->discardsStderr());
        $this->assertFalse($analysis->isRecoverable());
        $this->assertNull($analysis->capturesToFile());
    }

    public function testRedirectionInsideQuotesIsNeverTreatedAsRedirection(): void
    {
        $quoted = [
            'echo "done > /dev/null"',
            "echo 'done > /dev/null'",
            '/bin/mail -s "report > /dev/null 2>&1" me@example.com',
            'php -r \'echo "x>/dev/null";\'',
        ];

        foreach ($quoted as $command) {
            $analysis = Redirection::analyse($command);

            $this->assertFalse($analysis->discardsStdout(), $command);
            $this->assertFalse($analysis->isRecoverable(), $command);
            $this->assertNull($analysis->withoutTrailingDiscard(), $command);
        }
    }

    public function testEscapedRedirectionIsNotTreatedAsRedirection(): void
    {
        $analysis = Redirection::analyse('/bin/echo a \\> /dev/null');

        $this->assertFalse($analysis->isRecoverable());
    }

    public function testQuotedCommandFollowedByRealRedirectionStillWorks(): void
    {
        $analysis = Redirection::analyse('/bin/mail -s "nightly > report" me@example.com >/dev/null 2>&1');

        $this->assertTrue($analysis->discardsEverything());
        $this->assertTrue($analysis->isRecoverable());
        $this->assertSame('/bin/mail -s "nightly > report" me@example.com', $analysis->withoutTrailingDiscard());
    }

    public function testUnbalancedQuotesDisableStripping(): void
    {
        $analysis = Redirection::analyse('/bin/echo "unterminated >/dev/null 2>&1');

        $this->assertFalse($analysis->hasBalancedQuotes());
        $this->assertFalse($analysis->isRecoverable());
    }

    public function testCompoundCommandsAreFlagged(): void
    {
        foreach (['a && b', 'a || b', 'a; b', 'a | b'] as $command) {
            $this->assertTrue(Redirection::analyse($command)->isCompound(), $command);
        }

        $this->assertFalse(Redirection::analyse('/usr/bin/php /home/me/cron.php')->isCompound());
        $this->assertFalse(Redirection::analyse('/bin/x 2>&1')->isCompound());
    }

    public function testRedirectionBeforeAPipelineIsNotStripped(): void
    {
        $analysis = Redirection::analyse('/bin/a >/dev/null 2>&1 && /bin/b');

        $this->assertTrue($analysis->isCompound());
        $this->assertFalse($analysis->isRecoverable());
        $this->assertNull($analysis->withoutTrailingDiscard());
    }

    public function testADanglingDupAloneIsNotConsideredRecoverable(): void
    {
        $analysis = Redirection::analyse('/bin/x 2>&1');

        $this->assertFalse($analysis->isRecoverable());
        $this->assertSame(Redirection::SINK_INHERITED, $analysis->stdout());
        $this->assertSame(Redirection::SINK_INHERITED, $analysis->stderr());
    }

    public function testStrippingNeverProducesAnEmptyCommand(): void
    {
        $this->assertNull(Redirection::analyse('>/dev/null 2>&1')->withoutTrailingDiscard());
    }
}
