<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Job;

use Zactonz\CronDoctor\Platform\Environment;
use Zactonz\CronDoctor\Support\Filesystem;
use Zactonz\CronDoctor\Support\Result;
use Zactonz\CronDoctor\Version;

final class Payload
{
    private const SOURCE_NAMESPACES = ['Support', 'Security', 'Cron', 'Platform', 'Job', 'Doctor'];

    private const EXECUTABLE_MODE = 0700;

    private Environment $environment;

    private string $pluginDirectory;

    public function __construct(Environment $environment, string $pluginDirectory)
    {
        $this->environment = $environment;
        $this->pluginDirectory = rtrim($pluginDirectory, '/');
    }

    public function deployedVersion(): ?string
    {
        $contents = Filesystem::read($this->environment->path('version'));

        return $contents === null ? null : trim($contents);
    }

    public function isCurrent(): bool
    {
        return $this->deployedVersion() === Version::NUMBER
            && is_file($this->environment->runnerPath())
            && is_executable($this->environment->runnerPath());
    }

    public function deployIfNeeded(): Result
    {
        if ($this->isCurrent()) {
            return Result::ok();
        }

        return $this->deploy();
    }

    public function deploy(): Result
    {
        if (!$this->environment->ensureStateDirectories()) {
            return Result::fail('Cron Doctor could not create its folder in your home directory.');
        }

        foreach (['bin/zcd-run', 'bin/zcd-sentinel'] as $relative) {
            $source = $this->pluginDirectory . '/payload/' . $relative;

            if (!is_file($source)) {
                continue;
            }

            $contents = Filesystem::read($source);

            if ($contents === null) {
                return Result::fail('The Cron Doctor runner could not be read from the plugin folder.');
            }

            $target = $this->environment->path($relative);

            if (!Filesystem::writeAtomically($target, $contents)) {
                return Result::fail('The Cron Doctor runner could not be written to your home directory.');
            }

            @chmod($target, self::EXECUTABLE_MODE);
        }

        foreach (Filesystem::listFiles($this->pluginDirectory . '/payload/lib', '*.php') as $source) {
            $contents = Filesystem::read($source);

            if ($contents === null) {
                continue;
            }

            Filesystem::writeAtomically($this->environment->path('lib/' . basename($source)), $contents);
        }

        $copied = $this->copySources();

        if ($copied->failed()) {
            return $copied;
        }

        if (!Filesystem::writeAtomically($this->environment->path('version'), Version::NUMBER . "\n")) {
            return Result::fail('The Cron Doctor version file could not be written.');
        }

        return Result::ok('Cron Doctor installed its runner in your home directory.');
    }

    public function capabilities(): array
    {
        $runner = $this->environment->runnerPath();

        if (!is_file($runner) || !$this->environment->processes()->available()) {
            return [];
        }

        $result = $this->environment->processes()->run([$runner, '--check'], null, 10);

        if (!$result->successful()) {
            return [];
        }

        $capabilities = [];

        foreach (explode("\n", $result->stdout()) as $line) {
            $line = trim($line);

            if ($line === '' || strpos($line, '=') === false) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $capabilities[$key] = $value;
        }

        return $capabilities;
    }

    private function copySources(): Result
    {
        $root = $this->environment->path('lib/src');

        if (!Filesystem::ensureDirectory($root)) {
            return Result::fail('The Cron Doctor library folder could not be created.');
        }

        $autoloader = Filesystem::read($this->pluginDirectory . '/src/Autoload.php');

        if ($autoloader !== null) {
            Filesystem::writeAtomically($root . '/Autoload.php', $autoloader);
        }

        $version = Filesystem::read($this->pluginDirectory . '/src/Version.php');

        if ($version !== null) {
            Filesystem::writeAtomically($root . '/Version.php', $version);
        }

        foreach (self::SOURCE_NAMESPACES as $namespace) {
            $source = $this->pluginDirectory . '/src/' . $namespace;

            if (!is_dir($source)) {
                continue;
            }

            if (!$this->copyDirectory($source, $root . '/' . $namespace)) {
                return Result::fail('The Cron Doctor library could not be copied to your home directory.');
            }
        }

        return Result::ok();
    }

    private function copyDirectory(string $source, string $target): bool
    {
        if (!Filesystem::ensureDirectory($target)) {
            return false;
        }

        foreach (Filesystem::listFiles($source, '*.php') as $file) {
            $contents = Filesystem::read($file);

            if ($contents === null) {
                return false;
            }

            if (!Filesystem::writeAtomically($target . '/' . basename($file), $contents)) {
                return false;
            }
        }

        $entries = @scandir($source);

        if ($entries === false) {
            return true;
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $source . '/' . $entry;

            if (is_dir($child) && !$this->copyDirectory($child, $target . '/' . $entry)) {
                return false;
            }
        }

        return true;
    }
}
