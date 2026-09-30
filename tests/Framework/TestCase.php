<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Tests\Framework;

use Throwable;

abstract class TestCase
{
    private int $assertions = 0;

    public function setUp(): void
    {
    }

    public function tearDown(): void
    {
    }

    public function assertionCount(): int
    {
        return $this->assertions;
    }

    protected function assertTrue($actual, string $message = ''): void
    {
        $this->assertions++;

        if ($actual !== true) {
            throw new AssertionFailed(
                $this->describe($message, 'expected true, got ' . $this->export($actual))
            );
        }
    }

    protected function assertFalse($actual, string $message = ''): void
    {
        $this->assertions++;

        if ($actual !== false) {
            throw new AssertionFailed(
                $this->describe($message, 'expected false, got ' . $this->export($actual))
            );
        }
    }

    protected function assertSame($expected, $actual, string $message = ''): void
    {
        $this->assertions++;

        if ($expected !== $actual) {
            throw new AssertionFailed($this->describe(
                $message,
                'expected ' . $this->export($expected) . ', got ' . $this->export($actual)
            ));
        }
    }

    protected function assertNotSame($unexpected, $actual, string $message = ''): void
    {
        $this->assertions++;

        if ($unexpected === $actual) {
            throw new AssertionFailed($this->describe(
                $message,
                'expected a value other than ' . $this->export($unexpected)
            ));
        }
    }

    protected function assertNull($actual, string $message = ''): void
    {
        $this->assertions++;

        if ($actual !== null) {
            throw new AssertionFailed(
                $this->describe($message, 'expected null, got ' . $this->export($actual))
            );
        }
    }

    protected function assertNotNull($actual, string $message = ''): void
    {
        $this->assertions++;

        if ($actual === null) {
            throw new AssertionFailed($this->describe($message, 'expected a value, got null'));
        }
    }

    protected function assertContainsText(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertions++;

        if (strpos($haystack, $needle) === false) {
            throw new AssertionFailed($this->describe(
                $message,
                'expected to find ' . $this->export($needle) . ' in ' . $this->export($haystack)
            ));
        }
    }

    protected function assertNotContainsText(string $needle, string $haystack, string $message = ''): void
    {
        $this->assertions++;

        if (strpos($haystack, $needle) !== false) {
            throw new AssertionFailed($this->describe(
                $message,
                'did not expect to find ' . $this->export($needle) . ' in ' . $this->export($haystack)
            ));
        }
    }

    protected function assertMatches(string $pattern, string $subject, string $message = ''): void
    {
        $this->assertions++;

        if (preg_match($pattern, $subject) !== 1) {
            throw new AssertionFailed($this->describe(
                $message,
                'expected ' . $this->export($subject) . ' to match ' . $pattern
            ));
        }
    }

    protected function assertThrows(string $class, callable $callback, string $message = ''): Throwable
    {
        $this->assertions++;

        try {
            $callback();
        } catch (Throwable $caught) {
            if ($caught instanceof $class) {
                return $caught;
            }

            throw new AssertionFailed($this->describe(
                $message,
                'expected ' . $class . ', got ' . get_class($caught) . ': ' . $caught->getMessage()
            ));
        }

        throw new AssertionFailed($this->describe($message, 'expected ' . $class . ', nothing thrown'));
    }

    private function describe(string $message, string $detail): string
    {
        return $message === '' ? $detail : $message . ' (' . $detail . ')';
    }

    private function export($value): string
    {
        if (is_string($value)) {
            return '"' . (strlen($value) > 400 ? substr($value, 0, 400) . '...' : $value) . '"';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_array($value)) {
            return 'array(' . count($value) . ')' . json_encode(
                array_slice($value, 0, 12),
                JSON_UNESCAPED_SLASHES
            );
        }

        if (is_object($value)) {
            return get_class($value);
        }

        return (string) $value;
    }
}
