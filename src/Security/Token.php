<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Security;

use Zactonz\CronDoctor\Platform\Environment;
use Zactonz\CronDoctor\Support\Filesystem;

final class Token
{
    public const FIELD = 'zcd_token';

    private const SECRET_FILE = 'csrf.secret';

    private const SECRET_BYTES = 32;

    private Environment $environment;

    private string $session;

    public function __construct(Environment $environment, string $session)
    {
        $this->environment = $environment;
        $this->session = $session;
    }

    public static function sessionFromRequest(array $server): string
    {
        $uri = (string) ($server['REQUEST_URI'] ?? '');

        if (preg_match('#/(cpsess\d+)/#', $uri, $matches) === 1) {
            return $matches[1];
        }

        return 'no-session';
    }

    public function issue(): string
    {
        return hash_hmac('sha256', $this->session, $this->secret());
    }

    public function matches(?string $candidate): bool
    {
        if (!is_string($candidate) || $candidate === '') {
            return false;
        }

        return hash_equals($this->issue(), $candidate);
    }

    private function secret(): string
    {
        $path = $this->environment->path(self::SECRET_FILE);
        $existing = Filesystem::read($path);

        if ($existing !== null && strlen(trim($existing)) === self::SECRET_BYTES * 2) {
            return trim($existing);
        }

        $secret = bin2hex(random_bytes(self::SECRET_BYTES));

        $this->environment->ensureStateDirectories();
        Filesystem::writeAtomically($path, $secret);

        return $secret;
    }
}
