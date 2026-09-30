<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Tests\Unit;

use Zactonz\CronDoctor\Cron\CommandTokens;
use Zactonz\CronDoctor\Tests\Framework\TestCase;

final class CommandTokensTest extends TestCase
{
    public function testAPlainCommandSplitsOnWhitespace(): void
    {
        $tokens = CommandTokens::split('/usr/bin/php  /home/me/cron.php   --quiet');

        $this->assertSame(3, $tokens->count());
        $this->assertSame('/usr/bin/php', $tokens->textAt(0));
        $this->assertSame('/home/me/cron.php', $tokens->textAt(1));
        $this->assertSame('--quiet', $tokens->textAt(2));
    }

    public function testQuotedArgumentsStayWhole(): void
    {
        $tokens = CommandTokens::split('/bin/mail -s "nightly report" me@example.com');

        $this->assertSame(4, $tokens->count());
        $this->assertSame('nightly report', $tokens->textAt(2));
    }

    public function testSingleQuotesKeepEverythingLiteral(): void
    {
        $tokens = CommandTokens::split("php -r 'echo \"a b\";'");

        $this->assertSame(3, $tokens->count());
        $this->assertSame('echo "a b";', $tokens->textAt(2));
    }

    public function testEscapedSpacesDoNotSplitAToken(): void
    {
        $tokens = CommandTokens::split('/home/me/my\\ scripts/run.sh now');

        $this->assertSame(2, $tokens->count());
        $this->assertSame('/home/me/my scripts/run.sh', $tokens->textAt(0));
    }

    public function testUnbalancedQuotesAreReported(): void
    {
        $this->assertFalse(CommandTokens::split('/bin/echo "unterminated')->balanced());
        $this->assertTrue(CommandTokens::split('/bin/echo "fine"')->balanced());
    }

    public function testReplaceRewritesOnlyTheChosenToken(): void
    {
        $command = '/usr/bin/php  /home/me/cron.php --quiet';
        $tokens = CommandTokens::split($command);

        $this->assertSame(
            '/opt/cpanel/ea-php82/root/usr/bin/php  /home/me/cron.php --quiet',
            $tokens->replace($command, 0, '/opt/cpanel/ea-php82/root/usr/bin/php')
        );
    }

    public function testReplacePreservesQuotingElsewhere(): void
    {
        $command = 'cd /home/me && php -f "my script.php"';
        $tokens = CommandTokens::split($command);

        $this->assertSame(
            'cd /home/me && /opt/cpanel/ea-php82/root/usr/bin/php -f "my script.php"',
            $tokens->replace($command, 3, '/opt/cpanel/ea-php82/root/usr/bin/php')
        );
    }

    public function testReplacingAnUnknownTokenReturnsNothing(): void
    {
        $tokens = CommandTokens::split('/bin/true');

        $this->assertNull($tokens->replace('/bin/true', 9, 'x'));
    }
}
