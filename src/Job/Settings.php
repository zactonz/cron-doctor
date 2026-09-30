<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Job;

use Zactonz\CronDoctor\Platform\Environment;
use Zactonz\CronDoctor\Support\Filesystem;
use Zactonz\CronDoctor\Support\Json;
use Zactonz\CronDoctor\Support\Result;

final class Settings
{
    public const FILE = 'config.json';

    public const DEFAULT_REPEAT_HOURS = 24;

    public const MINIMUM_REPEAT_HOURS = 1;

    public const MAXIMUM_REPEAT_HOURS = 336;

    private Environment $environment;

    private ?array $values = null;

    public function __construct(Environment $environment)
    {
        $this->environment = $environment;
    }

    public function notificationAddress(): string
    {
        return (string) $this->value('notify_email', '');
    }

    public function repeatHours(): int
    {
        $hours = (int) $this->value('repeat_hours', self::DEFAULT_REPEAT_HOURS);

        return max(self::MINIMUM_REPEAT_HOURS, min(self::MAXIMUM_REPEAT_HOURS, $hours));
    }

    public function alertsEnabled(): bool
    {
        return $this->notificationAddress() !== '';
    }

    public function save(string $address, int $repeatHours): Result
    {
        $address = trim($address);

        if ($address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return Result::fail('That does not look like an email address, so nothing was saved.');
        }

        if ($address !== '' && strlen($address) > 254) {
            return Result::fail('That email address is too long.');
        }

        $this->environment->ensureStateDirectories();

        $written = Filesystem::writeAtomically($this->environment->path(self::FILE), Json::encode([
            'notify_email' => $address,
            'repeat_hours' => max(self::MINIMUM_REPEAT_HOURS, min(self::MAXIMUM_REPEAT_HOURS, $repeatHours)),
        ]));

        $this->values = null;

        if (!$written) {
            return Result::fail('The alert settings could not be saved.');
        }

        return Result::ok('The alert settings were saved.');
    }

    private function value(string $key, $fallback)
    {
        if ($this->values === null) {
            $contents = Filesystem::read($this->environment->path(self::FILE));
            $decoded = $contents === null ? null : Json::decode($contents);
            $this->values = is_array($decoded) ? $decoded : [];
        }

        return $this->values[$key] ?? $fallback;
    }
}
