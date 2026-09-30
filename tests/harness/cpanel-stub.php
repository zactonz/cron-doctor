<?php

declare(strict_types=1);

class CPANEL
{
    public function header(string $title = ''): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . htmlspecialchars($title, ENT_QUOTES) . '</title>'
            . '<link rel="stylesheet" href="/harness/jupiter.css">'
            . '</head><body><div class="jupiter-chrome">'
            . '<div class="jupiter-bar">cPanel<span>' . htmlspecialchars($title, ENT_QUOTES) . '</span></div>'
            . '<main class="jupiter-body">';
    }

    public function footer(): string
    {
        return '</main><div class="jupiter-foot">cPanel demonstration chrome</div></div></body></html>';
    }

    public function end(): void
    {
    }

    public function api2(string $module, string $function, array $arguments = []): array
    {
        return ['cpanelresult' => ['data' => [], 'event' => ['result' => 1]]];
    }

    public function uapi(string $module, string $function, array $arguments = []): array
    {
        if ($module === 'LangPHP' && $function === 'php_get_vhost_versions') {
            return [
                'cpanelresult' => [
                    'result' => [
                        'status' => 1,
                        'data' => [
                            [
                                'vhost' => 'example.test',
                                'documentroot' => getenv('HOME') . '/public_html',
                                'version' => 'ea-php83',
                                'account' => 'demo',
                            ],
                            [
                                'vhost' => 'shop.example.test',
                                'documentroot' => getenv('HOME') . '/public_html/shop',
                                'version' => 'ea-php74',
                                'account' => 'demo',
                            ],
                        ],
                    ],
                ],
            ];
        }

        return ['cpanelresult' => ['result' => ['status' => 1, 'data' => []]]];
    }
}
