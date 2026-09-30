<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\View;

use Throwable;

final class Template
{
    private string $directory;

    private array $shared;

    public function __construct(string $directory, array $shared = [])
    {
        $this->directory = rtrim($directory, '/');
        $this->shared = $shared;
    }

    public function render(string $name, array $data = []): string
    {
        if (preg_match('/^[a-z0-9-]+$/', $name) !== 1) {
            return '';
        }

        $path = $this->directory . '/' . $name . '.php';

        if (!is_file($path)) {
            return '';
        }

        $template = $this;
        $variables = array_merge($this->shared, $data, ['template' => $this]);

        ob_start();

        try {
            (static function (string $path, array $variables): void {
                extract($variables, EXTR_SKIP);

                require $path;
            })($path, $variables);
        } catch (Throwable $error) {
            ob_end_clean();

            throw $error;
        }

        unset($template);

        return (string) ob_get_clean();
    }

    public function display(string $name, array $data = []): void
    {
        echo $this->render($name, $data);
    }
}
