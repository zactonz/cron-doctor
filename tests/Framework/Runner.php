<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Tests\Framework;

use ReflectionClass;
use ReflectionMethod;
use Throwable;

final class Runner
{
    private array $directories;

    private string $filter;

    private int $passed = 0;

    private int $failed = 0;

    private int $assertions = 0;

    private array $failures = [];

    public function __construct(array $directories, string $filter = '')
    {
        $this->directories = $directories;
        $this->filter = $filter;
    }

    public function run(): int
    {
        $start = microtime(true);

        foreach ($this->discover() as $class) {
            $this->runClass($class);
        }

        echo "\n";

        foreach ($this->failures as $failure) {
            echo $failure . "\n";
        }

        printf(
            "%s  %d passed, %d failed, %d assertions in %.2fs\n",
            $this->failed === 0 ? "\033[42;30m PASS \033[0m" : "\033[41;37m FAIL \033[0m",
            $this->passed,
            $this->failed,
            $this->assertions,
            microtime(true) - $start
        );

        return $this->failed === 0 ? 0 : 1;
    }

    private function runClass(string $class): void
    {
        $reflection = new ReflectionClass($class);

        if ($reflection->isAbstract()) {
            return;
        }

        $shortName = $reflection->getShortName();
        echo "\n" . $shortName . "\n";

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (strpos($method->getName(), 'test') !== 0) {
                continue;
            }

            if ($this->filter !== '' && stripos($shortName . '::' . $method->getName(), $this->filter) === false) {
                continue;
            }

            $this->runMethod($reflection, $method->getName(), $shortName);
        }
    }

    private function runMethod(ReflectionClass $reflection, string $method, string $shortName): void
    {
        $instance = $reflection->newInstance();

        try {
            $instance->setUp();
            $instance->{$method}();
            $instance->tearDown();

            $this->passed++;
            $this->assertions += $instance->assertionCount();
            echo "  \033[32m✔\033[0m " . $this->humanise($method) . "\n";
        } catch (Throwable $error) {
            try {
                $instance->tearDown();
            } catch (Throwable $ignored) {
                unset($ignored);
            }

            $this->failed++;
            $this->assertions += $instance->assertionCount();
            echo "  \033[31m✘\033[0m " . $this->humanise($method) . "\n";

            $this->failures[] = sprintf(
                "\033[31m%s::%s\033[0m\n    %s\n    %s:%d",
                $shortName,
                $method,
                $error->getMessage(),
                $this->relative($error->getFile()),
                $error->getLine()
            );
        }
    }

    private function humanise(string $method): string
    {
        $words = preg_replace('/(?<!^)[A-Z]/', ' $0', substr($method, 4));

        return strtolower((string) $words);
    }

    private function relative(string $path): string
    {
        $root = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR;

        return strpos($path, $root) === 0 ? substr($path, strlen($root)) : $path;
    }

    private function discover(): array
    {
        $classes = [];

        foreach ($this->directories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            $files = glob($directory . DIRECTORY_SEPARATOR . '*Test.php') ?: [];
            sort($files);

            foreach ($files as $file) {
                $before = get_declared_classes();
                require_once $file;
                $classes = array_merge($classes, array_diff(get_declared_classes(), $before));
            }
        }

        return array_values(array_filter($classes, static function (string $class): bool {
            return is_subclass_of($class, TestCase::class);
        }));
    }
}
