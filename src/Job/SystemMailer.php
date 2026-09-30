<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Job;

use Zactonz\CronDoctor\Version;

final class SystemMailer implements Mailer
{
    public function send(string $address, string $subject, string $body): bool
    {
        if (!function_exists('mail')) {
            return false;
        }

        $headers = implode("\r\n", [
            'Content-Type: text/plain; charset=UTF-8',
            'Auto-Submitted: auto-generated',
            'X-Mailer: ' . Version::NAME . ' ' . Version::NUMBER,
        ]);

        return @mail($address, $subject, $body, $headers);
    }
}
