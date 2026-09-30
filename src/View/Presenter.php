<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\View;

use DateTimeImmutable;
use Zactonz\CronDoctor\Doctor\CronEntry;

final class Presenter
{
    private DateTimeImmutable $now;

    public function __construct(DateTimeImmutable $now)
    {
        $this->now = $now;
    }

    public function moment(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return 'never';
        }

        return date('j M Y, H:i', $timestamp);
    }

    public function relative(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return 'never';
        }

        $difference = $this->now->getTimestamp() - $timestamp;
        $future = $difference < 0;
        $difference = abs($difference);

        $text = $this->span($difference);

        return $future ? 'in ' . $text : $text . ' ago';
    }

    public function span(int $seconds): string
    {
        if ($seconds < 45) {
            return 'moments';
        }

        if ($seconds < 3600) {
            return $this->unit((int) round($seconds / 60), 'minute');
        }

        if ($seconds < 86400) {
            return $this->unit((int) round($seconds / 3600), 'hour');
        }

        if ($seconds < 2592000) {
            return $this->unit((int) round($seconds / 86400), 'day');
        }

        return $this->unit((int) round($seconds / 2592000), 'month');
    }

    public function duration(int $milliseconds): string
    {
        if ($milliseconds < 1000) {
            return $milliseconds . ' ms';
        }

        if ($milliseconds < 60000) {
            return rtrim(rtrim(number_format($milliseconds / 1000, 1), '0'), '.') . ' s';
        }

        $seconds = (int) round($milliseconds / 1000);
        $minutes = intdiv($seconds, 60);

        if ($minutes < 60) {
            return $minutes . 'm ' . ($seconds % 60) . 's';
        }

        return intdiv($minutes, 60) . 'h ' . ($minutes % 60) . 'm';
    }

    public function bytes(int $value): string
    {
        if ($value < 1024) {
            return $value . ' B';
        }

        if ($value < 1048576) {
            return round($value / 1024, 1) . ' KB';
        }

        return round($value / 1048576, 1) . ' MB';
    }

    public function statusLabel(string $status): string
    {
        $labels = [
            CronEntry::STATUS_OK => 'Healthy',
            CronEntry::STATUS_FAILING => 'Failing',
            CronEntry::STATUS_OVERDUE => 'Overdue',
            CronEntry::STATUS_OVERLAPPING => 'Overlapping',
            CronEntry::STATUS_WAITING => 'Waiting for first run',
            CronEntry::STATUS_UNMONITORED => 'Not monitored',
            CronEntry::STATUS_UNKNOWN => 'Unknown',
        ];

        return $labels[$status] ?? 'Unknown';
    }

    public function statusTone(string $status): string
    {
        $tones = [
            CronEntry::STATUS_OK => 'good',
            CronEntry::STATUS_FAILING => 'bad',
            CronEntry::STATUS_OVERDUE => 'bad',
            CronEntry::STATUS_OVERLAPPING => 'warn',
            CronEntry::STATUS_WAITING => 'idle',
            CronEntry::STATUS_UNMONITORED => 'idle',
            CronEntry::STATUS_UNKNOWN => 'idle',
        ];

        return $tones[$status] ?? 'idle';
    }

    public function exitCode(int $code, bool $timedOut, bool $skipped): string
    {
        if ($skipped) {
            return 'skipped';
        }

        if ($timedOut) {
            return 'timed out';
        }

        return $code === 0 ? '0' : (string) $code;
    }

    private function unit(int $count, string $name): string
    {
        return $count . ' ' . $name . ($count === 1 ? '' : 's');
    }
}
