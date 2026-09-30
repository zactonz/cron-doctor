<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$source = $root . '/assets/zcd.un-compressed.css';
$target = $root . '/assets/zcd.css';

$css = file_get_contents($source);

if ($css === false) {
    fwrite(STDERR, "The stylesheet source could not be read.\n");
    exit(1);
}

$minified = preg_replace('#/\*.*?\*/#s', '', $css);
$minified = preg_replace('/\s+/', ' ', (string) $minified);
$minified = preg_replace('/\s*([{}:;,>])\s*/', '$1', (string) $minified);
$minified = preg_replace('/;}/', '}', (string) $minified);
$minified = str_replace(['( ', ' )'], ['(', ')'], (string) $minified);
$minified = trim((string) $minified);

if (file_put_contents($target, $minified . "\n") === false) {
    fwrite(STDERR, "The stylesheet could not be written.\n");
    exit(1);
}

printf(
    "%s: %d bytes from %d (%d%% smaller)\n",
    basename($target),
    strlen($minified),
    strlen($css),
    (int) round((1 - strlen($minified) / strlen($css)) * 100)
);
