<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Cron;

use DateTimeImmutable;
use DateTimeZone;

final class CronExpression
{
    public const MACROS = [
        '@yearly' => '0 0 1 1 *',
        '@annually' => '0 0 1 1 *',
        '@monthly' => '0 0 1 * *',
        '@weekly' => '0 0 * * 0',
        '@daily' => '0 0 * * *',
        '@midnight' => '0 0 * * *',
        '@hourly' => '0 * * * *',
    ];

    private const MONTH_NAMES = [
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
        'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    private const DAY_NAMES = [
        'sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6,
    ];

    private const SEARCH_LIMIT = 6000;

    private const HORIZON_YEARS = 8;

    private const DAYS_IN_MONTH = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

    private const WEEKDAY_OFFSETS = [0, 3, 2, 5, 0, 3, 5, 1, 4, 6, 2, 4];

    private array $minutes;

    private array $hours;

    private array $daysOfMonth;

    private array $months;

    private array $daysOfWeek;

    private bool $dayOfMonthIsStar;

    private bool $dayOfWeekIsStar;

    private string $normalised;

    private function __construct(
        array $minutes,
        array $hours,
        array $daysOfMonth,
        array $months,
        array $daysOfWeek,
        bool $dayOfMonthIsStar,
        bool $dayOfWeekIsStar,
        string $normalised
    ) {
        $this->minutes = $minutes;
        $this->hours = $hours;
        $this->daysOfMonth = $daysOfMonth;
        $this->months = $months;
        $this->daysOfWeek = $daysOfWeek;
        $this->dayOfMonthIsStar = $dayOfMonthIsStar;
        $this->dayOfWeekIsStar = $dayOfWeekIsStar;
        $this->normalised = $normalised;
    }

    public static function parse(string $expression): self
    {
        $trimmed = trim($expression);

        if ($trimmed === '') {
            throw new InvalidExpression('The schedule is empty.');
        }

        if ($trimmed[0] === '@') {
            $macro = strtolower($trimmed);

            if ($macro === '@reboot') {
                throw new InvalidExpression('@reboot has no predictable schedule.');
            }

            if (!isset(self::MACROS[$macro])) {
                throw new InvalidExpression(sprintf('Unknown schedule macro "%s".', $trimmed));
            }

            $trimmed = self::MACROS[$macro];
        }

        $fields = preg_split('/\s+/', $trimmed);

        if (!is_array($fields) || count($fields) !== 5) {
            throw new InvalidExpression('A schedule needs exactly five fields.');
        }

        $dayOfMonthIsStar = $fields[2][0] === '*';
        $dayOfWeekIsStar = $fields[4][0] === '*';

        return new self(
            self::expand($fields[0], 0, 59, [], 'minute'),
            self::expand($fields[1], 0, 23, [], 'hour'),
            self::expand($fields[2], 1, 31, [], 'day of month'),
            self::expand($fields[3], 1, 12, self::MONTH_NAMES, 'month'),
            self::foldSunday(self::expand($fields[4], 0, 7, self::DAY_NAMES, 'day of week')),
            $dayOfMonthIsStar,
            $dayOfWeekIsStar,
            implode(' ', $fields)
        );
    }

    public static function tryParse(string $expression): ?self
    {
        try {
            return self::parse($expression);
        } catch (InvalidExpression $e) {
            return null;
        }
    }

    public static function isReboot(string $expression): bool
    {
        return strtolower(trim($expression)) === '@reboot';
    }

    public function expression(): string
    {
        return $this->normalised;
    }

    public function matches(DateTimeImmutable $moment): bool
    {
        return $this->matchesParts(
            (int) $moment->format('Y'),
            (int) $moment->format('n'),
            (int) $moment->format('j'),
            (int) $moment->format('G'),
            (int) $moment->format('i')
        );
    }

    public function nextRun(DateTimeImmutable $after): ?DateTimeImmutable
    {
        return $this->search($after, true);
    }

    public function previousRun(DateTimeImmutable $before): ?DateTimeImmutable
    {
        return $this->search($before, false);
    }

    public function nextRuns(DateTimeImmutable $after, int $count): array
    {
        $runs = [];
        $cursor = $after;

        for ($i = 0; $i < $count; $i++) {
            $cursor = $this->nextRun($cursor);

            if ($cursor === null) {
                break;
            }

            $runs[] = $cursor;
        }

        return $runs;
    }

    public function intervalSeconds(DateTimeImmutable $reference): ?int
    {
        $first = $this->nextRun($reference);

        if ($first === null) {
            return null;
        }

        $second = $this->nextRun($first);

        if ($second === null) {
            return null;
        }

        return $second->getTimestamp() - $first->getTimestamp();
    }

    private function matchesParts(int $year, int $month, int $day, int $hour, int $minute): bool
    {
        if (!isset($this->minutes[$minute]) || !isset($this->hours[$hour]) || !isset($this->months[$month])) {
            return false;
        }

        return $this->dayMatches($year, $month, $day);
    }

    private function dayMatches(int $year, int $month, int $day): bool
    {
        $byDayOfMonth = isset($this->daysOfMonth[$day]);
        $byDayOfWeek = isset($this->daysOfWeek[self::weekday($year, $month, $day)]);

        if ($this->dayOfMonthIsStar || $this->dayOfWeekIsStar) {
            return $byDayOfMonth && $byDayOfWeek;
        }

        return $byDayOfMonth || $byDayOfWeek;
    }

    private function search(DateTimeImmutable $origin, bool $forward): ?DateTimeImmutable
    {
        $zone = $origin->getTimezone();
        $step = $forward ? 1 : -1;

        $year = (int) $origin->format('Y');
        $month = (int) $origin->format('n');
        $day = (int) $origin->format('j');
        $hour = (int) $origin->format('G');
        $minute = (int) $origin->format('i') + $step;

        [$year, $month, $day, $hour, $minute] = self::normalise($year, $month, $day, $hour, $minute);

        $horizon = $year + ($forward ? self::HORIZON_YEARS : -self::HORIZON_YEARS);

        for ($iteration = 0; $iteration < self::SEARCH_LIMIT; $iteration++) {
            if ($forward ? $year > $horizon : $year < $horizon) {
                return null;
            }

            if (!isset($this->months[$month])) {
                [$year, $month, $day, $hour, $minute] = $forward
                    ? self::normalise($year, $month + 1, 1, 0, 0)
                    : self::endOfPreviousMonth($year, $month);
                continue;
            }

            if (!$this->dayMatches($year, $month, $day)) {
                [$year, $month, $day, $hour, $minute] = $forward
                    ? self::normalise($year, $month, $day + 1, 0, 0)
                    : self::normalise($year, $month, $day - 1, 23, 59);
                continue;
            }

            if (!isset($this->hours[$hour])) {
                [$year, $month, $day, $hour, $minute] = $forward
                    ? self::normalise($year, $month, $day, $hour + 1, 0)
                    : self::normalise($year, $month, $day, $hour - 1, 59);
                continue;
            }

            if (!isset($this->minutes[$minute])) {
                [$year, $month, $day, $hour, $minute] = $forward
                    ? self::normalise($year, $month, $day, $hour, $minute + 1)
                    : self::normalise($year, $month, $day, $hour, $minute - 1);
                continue;
            }

            return self::materialise($year, $month, $day, $hour, $minute, $zone);
        }

        return null;
    }

    private static function materialise(
        int $year,
        int $month,
        int $day,
        int $hour,
        int $minute,
        DateTimeZone $zone
    ): DateTimeImmutable {
        $stamp = sprintf('%04d-%02d-%02d %02d:%02d:00', $year, $month, $day, $hour, $minute);

        return new DateTimeImmutable($stamp, $zone);
    }

    private static function endOfPreviousMonth(int $year, int $month): array
    {
        $month--;

        if ($month < 1) {
            $month = 12;
            $year--;
        }

        return [$year, $month, self::daysInMonth($year, $month), 23, 59];
    }

    private static function normalise(int $year, int $month, int $day, int $hour, int $minute): array
    {
        while ($minute > 59) {
            $minute -= 60;
            $hour++;
        }

        while ($minute < 0) {
            $minute += 60;
            $hour--;
        }

        while ($hour > 23) {
            $hour -= 24;
            $day++;
        }

        while ($hour < 0) {
            $hour += 24;
            $day--;
        }

        while ($month > 12) {
            $month -= 12;
            $year++;
        }

        while ($month < 1) {
            $month += 12;
            $year--;
        }

        while ($day > self::daysInMonth($year, $month)) {
            $day -= self::daysInMonth($year, $month);
            $month++;

            if ($month > 12) {
                $month = 1;
                $year++;
            }
        }

        while ($day < 1) {
            $month--;

            if ($month < 1) {
                $month = 12;
                $year--;
            }

            $day += self::daysInMonth($year, $month);
        }

        return [$year, $month, $day, $hour, $minute];
    }

    private static function weekday(int $year, int $month, int $day): int
    {
        $shifted = $month < 3 ? $year - 1 : $year;

        $sum = $shifted
            + intdiv($shifted, 4)
            - intdiv($shifted, 100)
            + intdiv($shifted, 400)
            + self::WEEKDAY_OFFSETS[$month - 1]
            + $day;

        return $sum % 7;
    }

    private static function daysInMonth(int $year, int $month): int
    {
        if ($month === 2 && self::isLeapYear($year)) {
            return 29;
        }

        return self::DAYS_IN_MONTH[$month - 1];
    }

    private static function isLeapYear(int $year): bool
    {
        return ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;
    }

    private static function foldSunday(array $values): array
    {
        if (isset($values[7])) {
            unset($values[7]);
            $values[0] = true;
        }

        return $values;
    }

    private static function expand(string $field, int $min, int $max, array $names, string $label): array
    {
        if ($field === '') {
            throw new InvalidExpression(sprintf('The %s field is empty.', $label));
        }

        $selected = [];

        foreach (explode(',', $field) as $term) {
            if ($term === '') {
                throw new InvalidExpression(sprintf('The %s field has an empty list entry.', $label));
            }

            $step = 1;
            $slash = strpos($term, '/');

            if ($slash !== false) {
                $stepText = substr($term, $slash + 1);
                $term = substr($term, 0, $slash);

                if ($stepText === '' || !ctype_digit($stepText)) {
                    throw new InvalidExpression(sprintf('The %s field has an invalid step.', $label));
                }

                $step = (int) $stepText;

                if ($step < 1 || $step > $max - $min + 1) {
                    throw new InvalidExpression(sprintf('The %s field has an out of range step.', $label));
                }
            }

            if ($term === '*') {
                $from = $min;
                $to = $max;
            } else {
                $dash = strpos($term, '-', 1);

                if ($dash !== false) {
                    $from = self::value(substr($term, 0, $dash), $min, $max, $names, $label);
                    $to = self::value(substr($term, $dash + 1), $min, $max, $names, $label);

                    if ($to < $from) {
                        throw new InvalidExpression(
                            sprintf('The %s field has a reversed range.', $label)
                        );
                    }
                } else {
                    $from = self::value($term, $min, $max, $names, $label);
                    $to = $slash === false ? $from : $max;
                }
            }

            for ($value = $from; $value <= $to; $value += $step) {
                $selected[$value] = true;
            }
        }

        ksort($selected);

        return $selected;
    }

    private static function value(string $token, int $min, int $max, array $names, string $label): int
    {
        $token = trim($token);

        if ($token === '') {
            throw new InvalidExpression(sprintf('The %s field has an empty value.', $label));
        }

        if ($names !== [] && !ctype_digit($token)) {
            $key = strtolower($token);

            if (!isset($names[$key])) {
                throw new InvalidExpression(sprintf('"%s" is not a valid %s.', $token, $label));
            }

            return $names[$key];
        }

        if (!ctype_digit($token)) {
            throw new InvalidExpression(sprintf('"%s" is not a valid %s.', $token, $label));
        }

        $value = (int) $token;

        if ($value < $min || $value > $max) {
            throw new InvalidExpression(
                sprintf('%s must be between %d and %d.', ucfirst($label), $min, $max)
            );
        }

        return $value;
    }
}
