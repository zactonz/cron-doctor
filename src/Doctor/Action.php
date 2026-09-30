<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\Doctor;

final class Action
{
    private string $label;

    private string $name;

    private array $parameters;

    private bool $primary;

    public function __construct(string $label, string $name, array $parameters, bool $primary = true)
    {
        $this->label = $label;
        $this->name = $name;
        $this->parameters = array_map('strval', $parameters);
        $this->primary = $primary;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function parameters(): array
    {
        return $this->parameters;
    }

    public function primary(): bool
    {
        return $this->primary;
    }
}
