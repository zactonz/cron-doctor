<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Tests\Framework;

use Zactonz\CronDoctor\Job\Mailer;

final class FakeMailer implements Mailer
{
    private array $sent = [];

    private bool $deliverable = true;

    public function send(string $address, string $subject, string $body): bool
    {
        $this->sent[] = ['address' => $address, 'subject' => $subject, 'body' => $body];

        return $this->deliverable;
    }

    public function breakDelivery(): void
    {
        $this->deliverable = false;
    }

    public function count(): int
    {
        return count($this->sent);
    }

    public function last(): ?array
    {
        return $this->sent === [] ? null : $this->sent[count($this->sent) - 1];
    }

    public function forget(): void
    {
        $this->sent = [];
    }
}
