<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Tests\Unit;

use Zactonz\CronDoctor\Cron\CrontabDocument;
use Zactonz\CronDoctor\Cron\CrontabLine;
use Zactonz\CronDoctor\Tests\Framework\TestCase;

final class CrontabDocumentTest extends TestCase
{
    private const AWKWARD = "MAILTO=\"ops@example.com\"\n"
        . "PATH=/usr/local/bin:/usr/bin:/bin\n"
        . "\n"
        . "# nightly database dump\n"
        . "15 3 * * * /usr/bin/mysqldump -u me db > /home/me/backups/db.sql 2>&1\n"
        . "\t*/5 * * * *   /usr/bin/php   /home/me/public_html/cron.php >/dev/null 2>&1\n"
        . "0 0 * * * /home/me/report.sh%first line%second line\n"
        . "30 4 * * * /usr/bin/date +\\%Y-\\%m-\\%d >> /home/me/dates.log\n"
        . "@daily /home/me/bin/cleanup\n"
        . "@reboot /home/me/bin/warmup\n"
        . "-0 6 * * * /home/me/bin/quiet\n"
        . "this line is not a cron job at all\n";

    public function testParsingAndRenderingIsByteExact(): void
    {
        $document = CrontabDocument::parse(self::AWKWARD);

        $this->assertSame(self::AWKWARD, $document->render());
    }

    public function testRoundTripSurvivesRepeatedParsing(): void
    {
        $once = CrontabDocument::parse(self::AWKWARD)->render();
        $twice = CrontabDocument::parse($once)->render();

        $this->assertSame($once, $twice);
    }

    public function testEmptyAndNewlineOnlyCrontabsRoundTrip(): void
    {
        foreach (['', "\n", "\n\n", 'no newline at end'] as $raw) {
            $this->assertSame($raw, CrontabDocument::parse($raw)->render(), 'round trip of ' . json_encode($raw));
        }
    }

    public function testLineTypesAreClassified(): void
    {
        $document = CrontabDocument::parse(self::AWKWARD);
        $types = [];

        foreach ($document->lines() as $line) {
            $types[] = $line->type();
        }

        $this->assertSame([
            CrontabLine::TYPE_VARIABLE,
            CrontabLine::TYPE_VARIABLE,
            CrontabLine::TYPE_BLANK,
            CrontabLine::TYPE_COMMENT,
            CrontabLine::TYPE_JOB,
            CrontabLine::TYPE_JOB,
            CrontabLine::TYPE_JOB,
            CrontabLine::TYPE_JOB,
            CrontabLine::TYPE_JOB,
            CrontabLine::TYPE_JOB,
            CrontabLine::TYPE_JOB,
            CrontabLine::TYPE_UNKNOWN,
        ], $types);
    }

    public function testVariablesAreReadWithQuotesRemoved(): void
    {
        $document = CrontabDocument::parse(self::AWKWARD);

        $this->assertSame('ops@example.com', $document->variable('MAILTO'));
        $this->assertSame('/usr/local/bin:/usr/bin:/bin', $document->variable('PATH'));
        $this->assertNull($document->variable('SHELL'));
    }

    public function testTheLastAssignmentOfAVariableWins(): void
    {
        $document = CrontabDocument::parse("MAILTO=first@example.com\nMAILTO=second@example.com\n");

        $this->assertSame('second@example.com', $document->variable('MAILTO'));
    }

    public function testEmptyMailtoIsDistinguishedFromAbsent(): void
    {
        $document = CrontabDocument::parse("MAILTO=\"\"\n");

        $this->assertSame('', $document->variable('MAILTO'));
    }

    public function testScheduleAndCommandAreSeparated(): void
    {
        $document = CrontabDocument::parse(self::AWKWARD);
        $jobs = $document->jobs();

        $this->assertSame('15 3 * * *', $jobs[0]->schedule());
        $this->assertSame('/usr/bin/mysqldump -u me db > /home/me/backups/db.sql 2>&1', $jobs[0]->command());

        $this->assertSame('*/5 * * * *', $jobs[1]->schedule());
        $this->assertSame('/usr/bin/php   /home/me/public_html/cron.php >/dev/null 2>&1', $jobs[1]->command());

        $this->assertSame('@daily', $jobs[4]->schedule());
        $this->assertSame('/home/me/bin/cleanup', $jobs[4]->command());
    }

    public function testPercentSignSplitsCommandFromStandardInput(): void
    {
        $job = CrontabDocument::parse(self::AWKWARD)->jobs()[2];

        $this->assertSame('/home/me/report.sh', $job->command());
        $this->assertSame("first line\nsecond line", $job->input());
    }

    public function testEscapedPercentStaysInTheCommand(): void
    {
        $job = CrontabDocument::parse(self::AWKWARD)->jobs()[3];

        $this->assertSame('/usr/bin/date +%Y-%m-%d >> /home/me/dates.log', $job->command());
        $this->assertNull($job->input());
    }

    public function testCronieLogSuppressionPrefixIsRecognised(): void
    {
        $job = CrontabDocument::parse(self::AWKWARD)->jobs()[6];

        $this->assertTrue($job->logSuppressed());
        $this->assertSame('0 6 * * *', $job->schedule());
        $this->assertSame('/home/me/bin/quiet', $job->command());
    }

    public function testRebootJobIsAJobWithNoParsableExpression(): void
    {
        $job = CrontabDocument::parse(self::AWKWARD)->jobs()[5];

        $this->assertSame('@reboot', $job->schedule());
        $this->assertNull($job->expression());
    }

    public function testEncodeIsTheInverseOfDecode(): void
    {
        $cases = [
            ['/bin/true', null],
            ['echo 100%', null],
            ['/usr/bin/date +%Y', null],
            ['/home/me/x.sh', 'plain input'],
            ['/home/me/x.sh', "two\nlines"],
            ['/home/me/x.sh', 'input with % sign'],
            ['a%b', "c%d\ne"],
            ['back\\slash x', 'more\\slashes y'],
            ['', ''],
        ];

        foreach ($cases as [$command, $input]) {
            $encoded = CrontabLine::encode($command, $input);
            [$decodedCommand, $decodedInput] = CrontabLine::decode($encoded);

            $this->assertSame($command, $decodedCommand, 'command round trip for ' . json_encode($command));
            $this->assertSame($input, $decodedInput, 'input round trip for ' . json_encode($input));
        }
    }

    public function testEncodeIsTheInverseOfDecodeForRandomPayloads(): void
    {
        $alphabet = ['a', 'Z', '0', ' ', '%', '\\', '/', '-', '"', "'", '$', '*'];

        mt_srand(20260921);

        for ($iteration = 0; $iteration < 400; $iteration++) {
            $command = $this->randomText($alphabet, mt_rand(0, 14));
            $input = mt_rand(0, 1) === 1 ? $this->randomText(array_merge($alphabet, ["\n"]), mt_rand(0, 14)) : null;

            $encodable = CrontabLine::isEncodable($command, $input);
            [$decodedCommand, $decodedInput] = CrontabLine::decode(CrontabLine::encode($command, $input));

            if (!$encodable) {
                $this->assertTrue(
                    $decodedCommand !== $command || $decodedInput !== $input,
                    'isEncodable must only reject genuinely lossy payloads'
                );
                continue;
            }

            $this->assertSame($command, $decodedCommand, 'command ' . json_encode($command));
            $this->assertSame($input, $decodedInput, 'input ' . json_encode($input));
        }
    }

    public function testPayloadsCronCannotRepresentAreRejectedRatherThanCorrupted(): void
    {
        $this->assertFalse(CrontabLine::isEncodable('/bin/x', "trailing\\\n" . 'more'));
        $this->assertFalse(CrontabLine::isEncodable('/bin/x\\', 'payload'));

        $this->assertTrue(CrontabLine::isEncodable('/bin/x', "safe\npayload"));
        $this->assertTrue(CrontabLine::isEncodable('/usr/bin/date +%Y', null));
        $this->assertTrue(CrontabLine::isEncodable('/bin/x\\', null));
    }

    public function testWithCommandRefusesUnrepresentablePayloads(): void
    {
        $job = CrontabDocument::parse("0 6 * * * /bin/old%payload\n")->jobs()[0];

        $this->assertNull($job->withCommand('/bin/new\\', 'payload'));
        $this->assertNotNull($job->withCommand('/bin/new', 'payload'));
    }

    public function testReplacingOneLineLeavesEveryOtherByteAlone(): void
    {
        $document = CrontabDocument::parse(self::AWKWARD);
        $updated = $document->withLineAt(5, '*/5 * * * * /opt/cpanel/ea-php82/root/usr/bin/php /home/me/public_html/cron.php');

        $before = explode("\n", $document->render());
        $after = explode("\n", $updated->render());

        $this->assertSame(count($before), count($after));

        foreach ($before as $index => $text) {
            if ($index === 5) {
                $this->assertNotSame($text, $after[$index]);
                continue;
            }

            $this->assertSame($text, $after[$index], 'line ' . $index . ' must not change');
        }
    }

    public function testRemovingALineKeepsTheRest(): void
    {
        $document = CrontabDocument::parse(self::AWKWARD);
        $updated = $document->withoutLineAt(3);

        $this->assertSame($document->count() - 1, $updated->count());
        $this->assertNotContainsText('# nightly database dump', $updated->render());
        $this->assertContainsText('MAILTO="ops@example.com"', $updated->render());
    }

    public function testAppendingAddsATrailingNewlineWhenOneIsMissing(): void
    {
        $document = CrontabDocument::parse('0 1 * * * /bin/true');
        $updated = $document->withAppendedLine('0 2 * * * /bin/false');

        $this->assertSame("0 1 * * * /bin/true\n\n0 2 * * * /bin/false\n", $updated->render());
    }

    public function testAppendingToATidyCrontabDoesNotAddBlankLines(): void
    {
        $document = CrontabDocument::parse("0 1 * * * /bin/true\n");
        $updated = $document->withAppendedLine('0 2 * * * /bin/false');

        $this->assertSame("0 1 * * * /bin/true\n0 2 * * * /bin/false\n", $updated->render());
    }

    public function testCarriageReturnsAreDetectedAndPreserved(): void
    {
        $raw = "MAILTO=me@example.com\r\n0 1 * * * /bin/true\r\n";
        $document = CrontabDocument::parse($raw);

        $this->assertTrue($document->hasCarriageReturns());
        $this->assertSame($raw, $document->render());
    }

    public function testFingerprintTracksContent(): void
    {
        $document = CrontabDocument::parse(self::AWKWARD);

        $this->assertSame($document->fingerprint(), CrontabDocument::parse(self::AWKWARD)->fingerprint());
        $this->assertNotSame($document->fingerprint(), $document->withoutLineAt(0)->fingerprint());
    }

    public function testWithCommandRebuildsALineWithoutTouchingTheSchedule(): void
    {
        $job = CrontabDocument::parse("*/5 * * * * /usr/bin/php /home/me/cron.php\n")->jobs()[0];
        $updated = $job->withCommand('/opt/cpanel/ea-php82/root/usr/bin/php /home/me/cron.php', null);

        $this->assertSame('*/5 * * * *', $updated->schedule());
        $this->assertSame('*/5 * * * * /opt/cpanel/ea-php82/root/usr/bin/php /home/me/cron.php', $updated->raw());
    }

    public function testWithCommandPreservesStandardInputAndLogSuppression(): void
    {
        $job = CrontabDocument::parse("-0 6 * * * /bin/old%payload\n")->jobs()[0];
        $updated = $job->withCommand('/bin/new', "payload\nsecond");

        $this->assertNotNull($updated);

        $this->assertSame('-0 6 * * * /bin/new%payload%second', $updated->raw());
        $this->assertTrue($updated->logSuppressed());
        $this->assertSame("payload\nsecond", $updated->input());
    }

    private function randomText(array $alphabet, int $length): string
    {
        $text = '';

        for ($index = 0; $index < $length; $index++) {
            $text .= $alphabet[mt_rand(0, count($alphabet) - 1)];
        }

        return $text;
    }
}
