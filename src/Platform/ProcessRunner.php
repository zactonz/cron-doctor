<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Platform;

class ProcessRunner
{
    private const READ_CHUNK = 8192;

    private const OUTPUT_CEILING = 1048576;

    private bool $available;

    public function __construct(?bool $available = null)
    {
        $this->available = $available ?? self::detectAvailability();
    }

    public function available(): bool
    {
        return $this->available;
    }

    public static function detectAvailability(): bool
    {
        if (!function_exists('proc_open') || !function_exists('proc_get_status')) {
            return false;
        }

        if (PHP_VERSION_ID < 70400) {
            return false;
        }

        $disabled = (string) ini_get('disable_functions');

        foreach (explode(',', $disabled) as $name) {
            if (strtolower(trim($name)) === 'proc_open') {
                return false;
            }
        }

        return true;
    }

    public function run(array $command, ?string $standardInput = null, int $timeoutSeconds = 20): ProcessResult
    {
        if (!$this->available) {
            return new ProcessResult(-1, '', 'running external commands is disabled on this server');
        }

        foreach ($command as $argument) {
            if (!is_string($argument) || $argument === '') {
                return new ProcessResult(-1, '', 'the command was not well formed');
            }
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes = [];
        $process = @proc_open($command, $descriptors, $pipes, null, null);

        if (!is_resource($process)) {
            return new ProcessResult(-1, '', 'the command could not be started');
        }

        if ($standardInput !== null && $standardInput !== '') {
            @fwrite($pipes[0], $standardInput);
        }

        @fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeoutSeconds;
        $timedOut = false;

        while (true) {
            $open = [];

            foreach ([1, 2] as $index) {
                if (isset($pipes[$index]) && is_resource($pipes[$index]) && !feof($pipes[$index])) {
                    $open[$index] = $pipes[$index];
                }
            }

            if ($open === []) {
                break;
            }

            $remaining = $deadline - microtime(true);

            if ($remaining <= 0) {
                $timedOut = true;
                break;
            }

            $read = array_values($open);
            $write = null;
            $except = null;
            $seconds = (int) floor($remaining);
            $microseconds = (int) (($remaining - $seconds) * 1000000);

            if (@stream_select($read, $write, $except, $seconds, $microseconds) === false) {
                break;
            }

            foreach ($read as $stream) {
                $chunk = @fread($stream, self::READ_CHUNK);

                if ($chunk === false || $chunk === '') {
                    continue;
                }

                if ($stream === $pipes[1]) {
                    $stdout .= $chunk;
                } else {
                    $stderr .= $chunk;
                }
            }

            if (strlen($stdout) + strlen($stderr) > self::OUTPUT_CEILING) {
                break;
            }
        }

        foreach ([1, 2] as $index) {
            if (isset($pipes[$index]) && is_resource($pipes[$index])) {
                @fclose($pipes[$index]);
            }
        }

        if ($timedOut) {
            @proc_terminate($process, 15);
        }

        $exitCode = @proc_close($process);

        return new ProcessResult($timedOut ? -1 : (int) $exitCode, $stdout, $stderr, $timedOut);
    }
}
