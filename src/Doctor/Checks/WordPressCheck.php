<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Doctor\Checks;

use Zactonz\CronDoctor\Cron\CommandTokens;
use Zactonz\CronDoctor\Doctor\Check;
use Zactonz\CronDoctor\Doctor\CronEntry;
use Zactonz\CronDoctor\Doctor\Finding;
use Zactonz\CronDoctor\Doctor\Inspection;
use Zactonz\CronDoctor\Doctor\Severity;
use Zactonz\CronDoctor\Doctor\Words;
use Zactonz\CronDoctor\Platform\Environment;

final class WordPressCheck implements Check
{
    private const CONFIG_READ_LIMIT = 131072;

    private const FREQUENT_THRESHOLD = 300;

    public function inspect(Inspection $inspection): array
    {
        $findings = [];
        $installations = [];

        foreach ($inspection->jobEntries() as $entry) {
            $root = self::installationRoot($entry, $inspection->environment());

            if ($root === null) {
                continue;
            }

            $installations[$root][] = $entry;
        }

        foreach ($installations as $root => $entries) {
            $findings = array_merge($findings, $this->inspectInstallation((string) $root, $entries, $inspection));
        }

        return $findings;
    }

    private function inspectInstallation(string $root, array $entries, Inspection $inspection): array
    {
        $findings = [];
        $first = $entries[0];

        if (count($entries) > 1) {
            $lines = [];

            foreach ($entries as $entry) {
                $lines[] = (string) ($entry->line()->number() + 1);
            }

            $findings[] = new Finding(
                'wordpress.duplicate',
                Severity::WARNING,
                'WordPress cron is triggered more than once',
                sprintf(
                    'Lines %s drive WordPress cron for %s. Duplicate triggers make scheduled posts, backups and '
                    . 'email run more often than intended, and can leave overlapping tasks half finished. Keep one '
                    . 'and remove the rest.',
                    Words::list($lines),
                    $root
                ),
                $first->line()->number(),
                $first->job() === null ? null : $first->job()->id()
            );
        }

        $configured = self::disablesInternalCron($root, $inspection->environment());

        if ($configured === false) {
            $findings[] = new Finding(
                'wordpress.internal-cron',
                Severity::WARNING,
                'WordPress is still running its own cron as well',
                sprintf(
                    'A real cron job drives WordPress cron for %s, but DISABLE_WP_CRON is not set to true in '
                    . 'wp-config.php. WordPress will keep firing its own scheduler on page loads too, so tasks run '
                    . 'twice and busy pages get slower. Cron Doctor only reads the file; it does not change it.',
                    $root
                ),
                $first->line()->number(),
                $first->job() === null ? null : $first->job()->id()
            );
        }

        $interval = $first->intervalSeconds($inspection->now());

        if ($interval !== null && $interval > 0 && $interval < self::FREQUENT_THRESHOLD) {
            $findings[] = new Finding(
                'wordpress.frequent',
                Severity::ADVICE,
                'WordPress cron runs very often',
                sprintf(
                    'This job triggers WordPress cron every %s. Most sites are fine with every five or fifteen '
                    . 'minutes, and a shorter gap mostly adds load.',
                    Words::duration($interval)
                ),
                $first->line()->number(),
                $first->job() === null ? null : $first->job()->id()
            );
        }

        return $findings;
    }

    private static function installationRoot(CronEntry $entry, Environment $environment): ?string
    {
        $tokens = CommandTokens::split($entry->command());

        if (!$tokens->balanced()) {
            return null;
        }

        $all = $tokens->all();
        $sawWpCli = false;
        $sawCronVerb = false;
        $directory = null;

        foreach ($all as $index => $token) {
            $text = $token['text'];

            if ($text === '') {
                continue;
            }

            if (substr($text, -12) === '/wp-cron.php') {
                return self::normalise(dirname($text), $environment);
            }

            if ($text === 'wp-cron.php' && $directory !== null) {
                return self::normalise($directory, $environment);
            }

            if ($text === 'cd' && isset($all[$index + 1])) {
                $directory = $all[$index + 1]['text'];
            }

            if (basename($text) === 'wp') {
                $sawWpCli = true;
            }

            if ($sawWpCli && $text === 'cron') {
                $sawCronVerb = true;
            }

            if (strpos($text, '--path=') === 0) {
                $directory = substr($text, 7);
            }
        }

        if ($sawWpCli && $sawCronVerb && $directory !== null) {
            return self::normalise($directory, $environment);
        }

        return null;
    }

    private static function normalise(string $path, Environment $environment): ?string
    {
        $trimmed = rtrim($path, '/');

        if ($trimmed === '' || $trimmed[0] !== '/') {
            return null;
        }

        return $environment->containsPath($trimmed) ? $trimmed : null;
    }

    private static function disablesInternalCron(string $root, Environment $environment): ?bool
    {
        foreach ([$root . '/wp-config.php', dirname($root) . '/wp-config.php'] as $candidate) {
            if (!$environment->containsPath($candidate) || !is_file($candidate) || !is_readable($candidate)) {
                continue;
            }

            $handle = @fopen($candidate, 'rb');

            if ($handle === false) {
                continue;
            }

            $contents = (string) @fread($handle, self::CONFIG_READ_LIMIT);
            @fclose($handle);

            if (preg_match('/DISABLE_WP_CRON/', $contents) !== 1) {
                return false;
            }

            $enabled = preg_match(
                '/(?:define\s*\(\s*([\'"])DISABLE_WP_CRON\1\s*,\s*|const\s+DISABLE_WP_CRON\s*=\s*)(true|1|([\'"])1\3)/i',
                $contents
            ) === 1;

            return $enabled ? true : false;
        }

        return null;
    }
}
