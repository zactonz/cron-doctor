<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Tests\Framework;

use Zactonz\CronDoctor\Platform\Environment;
use Zactonz\CronDoctor\Platform\ProcessRunner;
use Zactonz\CronDoctor\Support\Filesystem;

final class TemporaryAccount
{
    private string $home;

    private string $store;

    private Environment $environment;

    public function __construct(string $mode = 'normal')
    {
        $this->home = sys_get_temp_dir() . '/zcd-test-' . bin2hex(random_bytes(6));

        Filesystem::ensureDirectory($this->home);

        $this->store = $this->home . '/crontab.store';

        putenv('ZCD_FAKE_CRONTAB_STORE=' . $this->store);
        putenv('ZCD_FAKE_CRONTAB_MODE=' . $mode);

        $this->environment = new Environment(
            'testuser',
            $this->home,
            new ProcessRunner(),
            dirname(__DIR__) . '/fixtures/fake-crontab'
        );

        $this->environment->ensureStateDirectories();
    }

    public function environment(): Environment
    {
        return $this->environment;
    }

    public function home(): string
    {
        return $this->home;
    }

    public function setCrontab(string $contents): void
    {
        file_put_contents($this->store, $contents);
    }

    public function crontab(): ?string
    {
        return Filesystem::read($this->store);
    }

    public function removeCrontab(): void
    {
        @unlink($this->store);
    }

    public function setMode(string $mode): void
    {
        putenv('ZCD_FAKE_CRONTAB_MODE=' . $mode);
    }

    public function destroy(): void
    {
        Filesystem::removeDirectory($this->home);
        putenv('ZCD_FAKE_CRONTAB_STORE');
        putenv('ZCD_FAKE_CRONTAB_MODE');
    }
}
