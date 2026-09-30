<?php

declare(strict_types=1);

namespace Zactonz\CronDoctor\View;

use Zactonz\CronDoctor\Version;

final class Page
{
    private ?object $cpanel;

    private string $assetVersion;

    public function __construct($cpanel, string $assetVersion)
    {
        $this->cpanel = $cpanel;
        $this->assetVersion = $assetVersion;
    }

    public function open(string $title): void
    {
        if (is_object($this->cpanel) && method_exists($this->cpanel, 'header')) {
            echo $this->cpanel->header($title);
        }

        printf(
            '<link rel="stylesheet" href="assets/zcd.css?v=%s">' . "\n",
            Html::attribute($this->assetVersion)
        );
    }

    public function close(): void
    {
        printf('<script src="assets/zcd.js?v=%s"></script>' . "\n", Html::attribute($this->assetVersion));

        if (is_object($this->cpanel) && method_exists($this->cpanel, 'footer')) {
            echo $this->cpanel->footer();
        }

        if (is_object($this->cpanel) && method_exists($this->cpanel, 'end')) {
            $this->cpanel->end();
        }
    }

    public static function title(string $suffix = ''): string
    {
        return $suffix === '' ? Version::NAME : Version::NAME . ' — ' . $suffix;
    }
}
