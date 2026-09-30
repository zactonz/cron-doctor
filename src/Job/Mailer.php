<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Job;

interface Mailer
{
    public function send(string $address, string $subject, string $body): bool;
}
