<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Platform;

use Zactonz\CronDoctor\Cron\CommandTokens;

final class PhpBinaries
{
    private const EASYAPACHE_GLOB = '/opt/cpanel/ea-php*/root/usr/bin/php';

    private const ALTERNATIVE_GLOB = '/opt/alt/php*/usr/bin/php';

    private const GENERIC_NAMES = ['php', 'php-cgi', 'php5', 'php7', 'php8'];

    private const SCRIPT_OPTIONS = ['-f', '--file'];

    private const INLINE_CODE_OPTIONS = ['-r', '--run', '-B', '--process-begin', '-R', '--process-code', '-F', '--process-file', '-E', '--process-end'];

    private const VALUE_OPTIONS = ['-d', '--define', '-c', '--php-ini', '-z', '--zend-extension'];

    private CpanelApi $api;

    private string $root;

    private ?array $binaries = null;

    private ?array $versions = null;

    public function __construct(CpanelApi $api, string $root = '')
    {
        $this->api = $api;
        $this->root = rtrim($root, '/');
    }

    public function available(): array
    {
        if ($this->binaries !== null) {
            return $this->binaries;
        }

        $found = [];

        foreach ([self::EASYAPACHE_GLOB, self::ALTERNATIVE_GLOB] as $pattern) {
            $matches = @glob($this->root . $pattern, GLOB_NOSORT);

            if (!is_array($matches)) {
                continue;
            }

            foreach ($matches as $path) {
                if (!@is_executable($path)) {
                    continue;
                }

                $version = self::versionFromPath($path);

                if ($version !== null) {
                    $found[$version] = $path;
                }
            }
        }

        uksort($found, static function (string $left, string $right): int {
            return strnatcmp($right, $left);
        });

        $this->binaries = $found;

        return $found;
    }

    public function pathFor(string $version): ?string
    {
        $available = $this->available();

        return $available[$version] ?? null;
    }

    public function newestVersion(): ?string
    {
        $available = $this->available();

        return $available === [] ? null : (string) array_key_first($available);
    }

    public function documentRoots(): array
    {
        if ($this->versions === null) {
            $this->versions = $this->api->documentRootPhpVersions();
        }

        return $this->versions;
    }

    public function versionForPath(string $path): ?string
    {
        $best = null;
        $bestLength = -1;

        foreach ($this->documentRoots() as $entry) {
            $root = $entry['documentroot'];

            if ($root === '') {
                continue;
            }

            if ($path !== $root && strpos($path, $root . '/') !== 0) {
                continue;
            }

            if (strlen($root) > $bestLength) {
                $bestLength = strlen($root);
                $best = $entry['version'];
            }
        }

        return $best;
    }

    public function recommendedFor(?string $scriptPath): ?string
    {
        if ($scriptPath !== null) {
            $version = $this->versionForPath($scriptPath);

            if ($version !== null && $this->pathFor($version) !== null) {
                return $version;
            }
        }

        $roots = $this->documentRoots();

        if (count($roots) === 1 && $this->pathFor($roots[0]['version']) !== null) {
            return $roots[0]['version'];
        }

        return null;
    }

    public function inspect(string $command): ?PhpUsage
    {
        $tokens = CommandTokens::split($command);

        if (!$tokens->balanced()) {
            return null;
        }

        foreach ($tokens->all() as $index => $token) {
            $kind = self::classify($token['text']);

            if ($kind === null) {
                continue;
            }

            return new PhpUsage(
                $index,
                $token['text'],
                $kind,
                (string) (self::versionFromPath($token['text']) ?? ''),
                self::resolveAgainstWorkingDirectory($tokens, $index, self::scriptArgument($tokens, $index))
            );
        }

        return null;
    }

    public static function classify(string $token): ?string
    {
        if ($token === '' || strpos($token, '=') !== false) {
            return null;
        }

        $name = basename($token);

        if (preg_match('#/opt/cpanel/ea-php[0-9]+/#', $token) === 1 || preg_match('/^ea-php[0-9]+$/', $name) === 1) {
            return PhpUsage::KIND_EASYAPACHE;
        }

        if (preg_match('#/opt/alt/php[0-9]+/#', $token) === 1 || preg_match('/^alt-php[0-9]+$/', $name) === 1) {
            return PhpUsage::KIND_ALTERNATIVE;
        }

        if (in_array($name, self::GENERIC_NAMES, true)) {
            return PhpUsage::KIND_GENERIC;
        }

        return null;
    }

    public static function versionFromPath(string $path): ?string
    {
        if (preg_match('#/opt/cpanel/(ea-php[0-9]+)/#', $path, $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('/^(ea-php[0-9]+)$/', basename($path), $matches) === 1) {
            return $matches[1];
        }

        if (preg_match('#/opt/alt/(php[0-9]+)/#', $path, $matches) === 1) {
            return 'alt-' . $matches[1];
        }

        return null;
    }

    private static function resolveAgainstWorkingDirectory(
        CommandTokens $tokens,
        int $interpreterIndex,
        ?string $script
    ): ?string {
        if ($script === null || $script === '' || $script[0] === '/') {
            return $script;
        }

        $all = $tokens->all();

        for ($index = $interpreterIndex - 1; $index >= 0; $index--) {
            if ($all[$index]['text'] !== 'cd' || !isset($all[$index + 1])) {
                continue;
            }

            $directory = $all[$index + 1]['text'];

            if ($directory !== '' && $directory[0] === '/') {
                return rtrim($directory, '/') . '/' . $script;
            }
        }

        return $script;
    }

    private static function scriptArgument(CommandTokens $tokens, int $interpreterIndex): ?string
    {
        $all = $tokens->all();
        $count = count($all);

        for ($index = $interpreterIndex + 1; $index < $count; $index++) {
            $text = $all[$index]['text'];

            if ($text === '') {
                continue;
            }

            if ($text === '--') {
                return $all[$index + 1]['text'] ?? null;
            }

            if (in_array($text, self::SCRIPT_OPTIONS, true)) {
                return $all[$index + 1]['text'] ?? null;
            }

            if (in_array($text, self::INLINE_CODE_OPTIONS, true)) {
                return null;
            }

            if (in_array($text, self::VALUE_OPTIONS, true)) {
                $index++;
                continue;
            }

            if ($text[0] === '-') {
                continue;
            }

            return $text;
        }

        return null;
    }
}
