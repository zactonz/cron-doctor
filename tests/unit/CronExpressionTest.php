<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Tests\Unit;

use DateTimeImmutable;
use DateTimeZone;
use Zactonz\CronDoctor\Cron\CronExpression;
use Zactonz\CronDoctor\Cron\InvalidExpression;
use Zactonz\CronDoctor\Tests\Framework\TestCase;

final class CronExpressionTest extends TestCase
{
    public function testEveryMinuteMatchesAnyMoment(): void
    {
        $expression = CronExpression::parse('* * * * *');

        $this->assertTrue($expression->matches($this->at('2026-09-21 13:47:00')));
        $this->assertTrue($expression->matches($this->at('2026-02-29 00:00:00')));
    }

    public function testStepValuesSelectEveryNthMinute(): void
    {
        $expression = CronExpression::parse('*/15 * * * *');

        foreach ([0, 15, 30, 45] as $minute) {
            $this->assertTrue($expression->matches($this->at(sprintf('2026-09-21 10:%02d:00', $minute))));
        }

        foreach ([1, 14, 16, 44, 59] as $minute) {
            $this->assertFalse($expression->matches($this->at(sprintf('2026-09-21 10:%02d:00', $minute))));
        }
    }

    public function testRangesListsAndNamesAreUnderstood(): void
    {
        $expression = CronExpression::parse('0 9-17 * JAN,jul MON-fri');

        $this->assertTrue($expression->matches($this->at('2026-01-05 09:00:00')));
        $this->assertTrue($expression->matches($this->at('2026-07-31 17:00:00')));
        $this->assertFalse($expression->matches($this->at('2026-01-03 09:00:00')));
        $this->assertFalse($expression->matches($this->at('2026-01-05 08:00:00')));
        $this->assertFalse($expression->matches($this->at('2026-03-05 09:00:00')));
    }

    public function testSundayIsAcceptedAsBothZeroAndSeven(): void
    {
        $zero = CronExpression::parse('0 0 * * 0');
        $seven = CronExpression::parse('0 0 * * 7');
        $sunday = $this->at('2026-09-20 00:00:00');

        $this->assertTrue($zero->matches($sunday));
        $this->assertTrue($seven->matches($sunday));
        $this->assertTrue(CronExpression::parse('0 0 * * 0-7')->matches($this->at('2026-09-23 00:00:00')));
    }

    public function testRestrictedDayFieldsAreCombinedWithOr(): void
    {
        $expression = CronExpression::parse('0 0 1 * 1');

        $this->assertTrue($expression->matches($this->at('2026-09-01 00:00:00')));
        $this->assertTrue($expression->matches($this->at('2026-09-07 00:00:00')));
        $this->assertFalse($expression->matches($this->at('2026-09-08 00:00:00')));
    }

    public function testStarredDayFieldsAreCombinedWithAnd(): void
    {
        $dayOfMonthOnly = CronExpression::parse('0 0 1 * *');
        $this->assertTrue($dayOfMonthOnly->matches($this->at('2026-09-01 00:00:00')));
        $this->assertFalse($dayOfMonthOnly->matches($this->at('2026-09-07 00:00:00')));

        $dayOfWeekOnly = CronExpression::parse('0 0 * * 1');
        $this->assertTrue($dayOfWeekOnly->matches($this->at('2026-09-07 00:00:00')));
        $this->assertFalse($dayOfWeekOnly->matches($this->at('2026-09-01 00:00:00')));

        $steppedDayOfMonth = CronExpression::parse('0 0 */2 * 1');
        $this->assertTrue($steppedDayOfMonth->matches($this->at('2026-09-07 00:00:00')));
        $this->assertFalse($steppedDayOfMonth->matches($this->at('2026-09-14 00:00:00')));
    }

    public function testMacrosExpandToEquivalentExpressions(): void
    {
        $this->assertSame('0 0 * * *', CronExpression::parse('@daily')->expression());
        $this->assertSame('0 0 * * *', CronExpression::parse('@MIDNIGHT')->expression());
        $this->assertSame('0 * * * *', CronExpression::parse('@hourly')->expression());
        $this->assertSame('0 0 1 1 *', CronExpression::parse('@annually')->expression());
        $this->assertSame('0 0 * * 0', CronExpression::parse('@weekly')->expression());
    }

    public function testRebootIsReportedButNotParsed(): void
    {
        $this->assertTrue(CronExpression::isReboot('@reboot'));
        $this->assertTrue(CronExpression::isReboot('  @REBOOT '));
        $this->assertFalse(CronExpression::isReboot('@daily'));
        $this->assertNull(CronExpression::tryParse('@reboot'));
    }

    public function testMalformedExpressionsAreRejected(): void
    {
        $rejected = [
            '',
            '* * * *',
            '* * * * * *',
            '60 * * * *',
            '* 24 * * *',
            '* * 0 * *',
            '* * 32 * *',
            '* * * 13 *',
            '* * * * 8',
            '5-1 * * * *',
            '*/0 * * * *',
            '*/61 * * * *',
            '*/x * * * *',
            'abc * * * *',
            '* * * xyz *',
            '1,,2 * * * *',
            '@nonsense',
        ];

        foreach ($rejected as $expression) {
            $this->assertNull(
                CronExpression::tryParse($expression),
                sprintf('"%s" should be rejected', $expression)
            );
        }
    }

    public function testParseThrowsWithAReadableMessage(): void
    {
        $error = $this->assertThrows(
            InvalidExpression::class,
            static function (): void {
                CronExpression::parse('* * * * 9');
            }
        );

        $this->assertSame('Day of week must be between 0 and 7.', $error->getMessage());
    }

    public function testNextRunWalksForwardFromTheGivenMoment(): void
    {
        $expression = CronExpression::parse('*/5 * * * *');
        $next = $expression->nextRun($this->at('2026-09-21 10:02:30'));

        $this->assertNotNull($next);
        $this->assertSame('2026-09-21 10:05:00', $next->format('Y-m-d H:i:s'));
    }

    public function testNextRunNeverReturnsTheStartingMinute(): void
    {
        $expression = CronExpression::parse('*/5 * * * *');
        $next = $expression->nextRun($this->at('2026-09-21 10:05:00'));

        $this->assertNotNull($next);
        $this->assertSame('2026-09-21 10:10:00', $next->format('Y-m-d H:i:s'));
    }

    public function testPreviousRunWalksBackwards(): void
    {
        $expression = CronExpression::parse('30 3 * * *');
        $previous = $expression->previousRun($this->at('2026-09-21 01:00:00'));

        $this->assertNotNull($previous);
        $this->assertSame('2026-09-20 03:30:00', $previous->format('Y-m-d H:i:s'));
    }

    public function testPreviousRunCrossesYearBoundaries(): void
    {
        $expression = CronExpression::parse('0 0 1 1 *');
        $previous = $expression->previousRun($this->at('2026-09-21 12:00:00'));

        $this->assertNotNull($previous);
        $this->assertSame('2026-01-01 00:00:00', $previous->format('Y-m-d H:i:s'));
    }

    public function testLeapDayScheduleSkipsNonLeapYears(): void
    {
        $expression = CronExpression::parse('0 0 29 2 *');
        $next = $expression->nextRun($this->at('2026-03-01 00:00:00'));

        $this->assertNotNull($next);
        $this->assertSame('2028-02-29 00:00:00', $next->format('Y-m-d H:i:s'));
    }

    public function testImpossibleDateReturnsNullInsteadOfLooping(): void
    {
        $expression = CronExpression::parse('0 0 30 2 *');

        $this->assertNull($expression->nextRun($this->at('2026-09-21 00:00:00')));
        $this->assertNull($expression->previousRun($this->at('2026-09-21 00:00:00')));
    }

    public function testNextRunsReturnsAnOrderedSeries(): void
    {
        $expression = CronExpression::parse('0 */6 * * *');
        $runs = $expression->nextRuns($this->at('2026-09-21 07:00:00'), 4);

        $formatted = array_map(static function (DateTimeImmutable $run): string {
            return $run->format('Y-m-d H:i');
        }, $runs);

        $this->assertSame(
            ['2026-09-21 12:00', '2026-09-21 18:00', '2026-09-22 00:00', '2026-09-22 06:00'],
            $formatted
        );
    }

    public function testIntervalSecondsMeasuresTheGapBetweenRuns(): void
    {
        $this->assertSame(300, CronExpression::parse('*/5 * * * *')->intervalSeconds($this->at('2026-09-21 00:00:00')));
        $this->assertSame(86400, CronExpression::parse('0 3 * * *')->intervalSeconds($this->at('2026-09-21 00:00:00')));
    }

    public function testDaylightSavingSpringForwardDoesNotStall(): void
    {
        $zone = new DateTimeZone('America/New_York');
        $expression = CronExpression::parse('30 2 * * *');
        $next = $expression->nextRun(new DateTimeImmutable('2026-03-07 12:00:00', $zone));

        $this->assertNotNull($next);
        $this->assertSame('2026-03-08 03:30', $next->format('Y-m-d H:i'));

        $following = $expression->nextRun($next);
        $this->assertNotNull($following);
        $this->assertSame('2026-03-09 02:30', $following->format('Y-m-d H:i'));
    }

    public function testDaylightSavingFallBackResolvesToASingleRun(): void
    {
        $zone = new DateTimeZone('America/New_York');
        $expression = CronExpression::parse('30 1 * * *');
        $next = $expression->nextRun(new DateTimeImmutable('2026-10-31 12:00:00', $zone));

        $this->assertNotNull($next);
        $this->assertSame('2026-11-01 01:30 -0400', $next->format('Y-m-d H:i O'));

        $following = $expression->nextRun($next);
        $this->assertNotNull($following);
        $this->assertSame('2026-11-02 01:30 -0500', $following->format('Y-m-d H:i O'));
    }

    public function testSearchTerminatesForEveryValidExpression(): void
    {
        $expressions = ['* * * * *', '0 0 1 1 0', '59 23 31 12 *', '0 0 29 2 6', '0 0 1-7 * 1'];

        foreach ($expressions as $text) {
            $expression = CronExpression::parse($text);
            $next = $expression->nextRun($this->at('2026-09-21 00:00:00'));

            $this->assertNotNull($next, $text . ' should resolve');
            $this->assertTrue($expression->matches($next), $text . ' should match its own next run');
        }
    }

    private function at(string $moment): DateTimeImmutable
    {
        return new DateTimeImmutable($moment, new DateTimeZone('UTC'));
    }
}
