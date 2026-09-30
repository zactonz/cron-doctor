<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Platform;

use Zactonz\CronDoctor\Cron\CrontabDocument;
use Zactonz\CronDoctor\Cron\CrontabLine;
use Zactonz\CronDoctor\Support\Filesystem;
use Zactonz\CronDoctor\Support\Result;

final class CrontabGateway
{
    private const BACKUP_LIMIT = 30;

    private const BACKUP_PATTERN = 'crontab-*.txt';

    private Environment $environment;

    private CpanelApi $api;

    public function __construct(Environment $environment, CpanelApi $api)
    {
        $this->environment = $environment;
        $this->api = $api;
    }

    public function read(): CrontabSnapshot
    {
        $binary = $this->environment->crontabBinary();

        if ($binary !== null && $this->environment->processes()->available()) {
            $result = $this->environment->processes()->run([$binary, '-l'], null, 15);

            if ($result->successful()) {
                return new CrontabSnapshot(
                    CrontabDocument::parse($result->stdout()),
                    CrontabSnapshot::SOURCE_BINARY,
                    true
                );
            }

            if ($this->looksLikeNoCrontab($result)) {
                return new CrontabSnapshot(
                    CrontabDocument::parse(''),
                    CrontabSnapshot::SOURCE_BINARY,
                    true
                );
            }
        }

        $lines = $this->api->fetchCrontabLines();

        if ($lines !== null) {
            return new CrontabSnapshot(
                self::rebuildFromApi($lines),
                CrontabSnapshot::SOURCE_API,
                true
            );
        }

        return new CrontabSnapshot(
            CrontabDocument::parse(''),
            CrontabSnapshot::SOURCE_NONE,
            false,
            'The crontab could not be read on this server.'
        );
    }

    public function writable(): bool
    {
        return $this->environment->canModifyCrontab();
    }

    public function write(
        CrontabDocument $intended,
        string $expectedFingerprint,
        bool $allowEmptying = false
    ): Result {
        if (!$this->writable()) {
            return Result::fail(
                'Cron Doctor cannot change this crontab because running commands is disabled for this account.'
            );
        }

        $current = $this->read();

        if (!$current->readable()) {
            return Result::fail('The current crontab could not be read, so nothing was changed.');
        }

        if ($current->fingerprint() !== $expectedFingerprint) {
            return Result::fail(
                'The crontab changed since this page was loaded, so nothing was changed. Reload and try again.'
            );
        }

        if (!$allowEmptying && $intended->jobs() === [] && $current->document()->jobs() !== []) {
            return Result::fail('That change would remove every cron job, so it was not applied.');
        }

        $backup = $this->storeBackup($current->document()->render());

        $installed = $this->install($intended->render());

        if ($installed->failed()) {
            return $installed;
        }

        $verification = $this->read();

        if (!$verification->readable()) {
            return $this->rollBack($current->document(), $backup, 'The crontab could not be verified after writing.');
        }

        if (!self::significantLinesMatch($intended, $verification->document())) {
            return $this->rollBack(
                $current->document(),
                $backup,
                'The crontab did not match what Cron Doctor wrote.'
            );
        }

        $this->pruneBackups();

        return Result::ok('The crontab was updated.', ['backup' => $backup]);
    }

    public function restore(string $name): Result
    {
        if (preg_match('/^crontab-[0-9A-Za-z_-]+\.txt$/', $name) !== 1) {
            return Result::fail('That backup name is not valid.');
        }

        $path = $this->environment->path('backups/' . $name);
        $contents = Filesystem::read($path);

        if ($contents === null) {
            return Result::fail('That backup could not be read.');
        }

        $current = $this->read();

        if ($current->readable()) {
            $this->storeBackup($current->document()->render());
        }

        $installed = $this->install($contents);

        if ($installed->failed()) {
            return $installed;
        }

        return Result::ok('The crontab was restored from ' . $name . '.');
    }

    public function backups(): array
    {
        $files = Filesystem::listFiles($this->environment->path('backups'), self::BACKUP_PATTERN);
        $backups = [];

        foreach ($files as $path) {
            $backups[] = [
                'name' => basename($path),
                'time' => (int) @filemtime($path),
                'bytes' => (int) @filesize($path),
            ];
        }

        usort($backups, static function (array $left, array $right): int {
            return $right['time'] <=> $left['time'];
        });

        return $backups;
    }

    public function storeBackup(string $contents): string
    {
        $name = sprintf('crontab-%s-%s.txt', date('Ymd-His'), substr(bin2hex(random_bytes(4)), 0, 6));

        Filesystem::writeAtomically($this->environment->path('backups/' . $name), $contents);

        return $name;
    }

    private function rollBack(CrontabDocument $previous, string $backup, string $reason): Result
    {
        $restored = $this->install($previous->render());

        if ($restored->successful()) {
            $check = $this->read();

            if ($check->readable() && self::significantLinesMatch($previous, $check->document())) {
                return Result::fail($reason . ' The previous version was restored.');
            }
        }

        return Result::fail(sprintf(
            '%s The previous version could not be restored automatically. A copy of it is saved as %s and can be '
            . 'restored from the Backups section.',
            $reason,
            $backup
        ), ['backup' => $backup, 'urgent' => true]);
    }

    private function install(string $contents): Result
    {
        $binary = $this->environment->crontabBinary();

        if ($binary === null) {
            return Result::fail('The crontab command is not available on this server.');
        }

        $path = $this->environment->path('tmp/crontab.install');

        if (!Filesystem::writeAtomically($path, $contents)) {
            return Result::fail('A temporary file could not be written, so nothing was changed.');
        }

        $result = $this->environment->processes()->run([$binary, $path], null, 20);

        @unlink($path);

        if (!$result->successful()) {
            return Result::fail('The crontab command refused the change: ' . $result->errorSummary());
        }

        return Result::ok();
    }

    private function looksLikeNoCrontab(ProcessResult $result): bool
    {
        $text = strtolower($result->stderr() . ' ' . $result->stdout());

        return strpos($text, 'no crontab') !== false;
    }

    private function pruneBackups(): void
    {
        $backups = $this->backups();

        if (count($backups) <= self::BACKUP_LIMIT) {
            return;
        }

        foreach (array_slice($backups, self::BACKUP_LIMIT) as $backup) {
            @unlink($this->environment->path('backups/' . $backup['name']));
        }
    }

    private static function significantLinesMatch(CrontabDocument $intended, CrontabDocument $actual): bool
    {
        return self::significantLines($intended) === self::significantLines($actual);
    }

    private static function significantLines(CrontabDocument $document): array
    {
        $lines = [];

        foreach ($document->lines() as $line) {
            if ($line->type() === CrontabLine::TYPE_BLANK || $line->type() === CrontabLine::TYPE_COMMENT) {
                continue;
            }

            $lines[] = trim($line->raw());
        }

        return $lines;
    }

    private static function rebuildFromApi(array $entries): CrontabDocument
    {
        $lines = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (isset($entry['line']) && is_string($entry['line'])) {
                $lines[] = $entry['line'];
                continue;
            }

            $command = isset($entry['command']) ? (string) $entry['command'] : '';

            if ($command === '') {
                continue;
            }

            $lines[] = sprintf(
                '%s %s %s %s %s %s',
                (string) ($entry['minute'] ?? '*'),
                (string) ($entry['hour'] ?? '*'),
                (string) ($entry['day'] ?? '*'),
                (string) ($entry['month'] ?? '*'),
                (string) ($entry['weekday'] ?? '*'),
                $command
            );
        }

        return CrontabDocument::fromLines($lines);
    }
}
