<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Http;

use Zactonz\CronDoctor\Platform\Environment;
use Zactonz\CronDoctor\Support\Filesystem;
use Zactonz\CronDoctor\Support\Json;

final class Flash
{
    private const FILE = 'flash.json';

    private const LIFETIME = 120;

    private Environment $environment;

    public function __construct(Environment $environment)
    {
        $this->environment = $environment;
    }

    public function store(bool $successful, string $message): void
    {
        Filesystem::writeAtomically($this->environment->path(self::FILE), Json::encode([
            'successful' => $successful,
            'message' => $message,
            'at' => time(),
        ]));
    }

    public function take(): ?array
    {
        $path = $this->environment->path(self::FILE);
        $contents = Filesystem::read($path);

        @unlink($path);

        if ($contents === null) {
            return null;
        }

        $data = Json::decode($contents);

        if (!is_array($data) || !isset($data['message'])) {
            return null;
        }

        if (time() - (int) ($data['at'] ?? 0) > self::LIFETIME) {
            return null;
        }

        return [
            'successful' => (bool) ($data['successful'] ?? false),
            'message' => (string) $data['message'],
        ];
    }
}
