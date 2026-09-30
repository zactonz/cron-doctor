<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Platform;

use Zactonz\CronDoctor\Cron\CrontabDocument;

final class CrontabSnapshot
{
    public const SOURCE_BINARY = 'crontab';
    public const SOURCE_API = 'cpanel';
    public const SOURCE_NONE = 'none';

    private CrontabDocument $document;

    private string $source;

    private bool $readable;

    private string $message;

    public function __construct(CrontabDocument $document, string $source, bool $readable, string $message = '')
    {
        $this->document = $document;
        $this->source = $source;
        $this->readable = $readable;
        $this->message = $message;
    }

    public function document(): CrontabDocument
    {
        return $this->document;
    }

    public function fingerprint(): string
    {
        return $this->document->fingerprint();
    }

    public function source(): string
    {
        return $this->source;
    }

    public function readable(): bool
    {
        return $this->readable;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function isVerbatim(): bool
    {
        return $this->source === self::SOURCE_BINARY;
    }
}
