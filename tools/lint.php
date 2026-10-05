<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

$forbiddenCalls = [
    'eval', 'assert', 'unserialize', 'shell_exec', 'passthru', 'system', 'popen',
    'exec', 'pcntl_exec', 'create_function', 'extract', 'putenv',
];

$allowedExtract = ['src/View/Template.php'];
$allowedPutenv = [];

$phpFiles = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $file) {
    $path = $file->getPathname();
    $relative = substr($path, strlen($root) + 1);

    if (strpos($relative, '.git/') === 0 || strpos($relative, 'tests/') === 0 || strpos($relative, 'tools/') === 0) {
        continue;
    }

    if (substr($path, -4) === '.php') {
        $phpFiles[] = $relative;
    }
}

sort($phpFiles);

foreach ($phpFiles as $relative) {
    $source = (string) file_get_contents($root . '/' . $relative);
    $tokens = token_get_all($source);

    foreach ($tokens as $index => $token) {
        if (!is_array($token)) {
            continue;
        }

        [$id, $text, $line] = $token;

        if ($id === T_COMMENT || $id === T_DOC_COMMENT) {
            $failures[] = sprintf('%s:%d comment left in source: %s', $relative, $line, trim(substr($text, 0, 60)));
        }

        if ($id === T_STRING && in_array(strtolower($text), $forbiddenCalls, true)) {
            $previous = $tokens[$index - 1] ?? null;
            $isMethodCall = is_array($previous)
                ? ($previous[0] === T_OBJECT_OPERATOR || $previous[0] === T_DOUBLE_COLON)
                : false;

            if ($isMethodCall) {
                continue;
            }

            $next = $tokens[$index + 1] ?? null;
            $isCall = $next === '(' || (is_array($next) && $next[0] === T_WHITESPACE && ($tokens[$index + 2] ?? null) === '(');

            if (!$isCall) {
                continue;
            }

            if (strtolower($text) === 'extract' && in_array($relative, $allowedExtract, true)) {
                continue;
            }

            if (strtolower($text) === 'putenv' && in_array($relative, $allowedPutenv, true)) {
                continue;
            }

            $failures[] = sprintf('%s:%d forbidden call: %s()', $relative, $line, $text);
        }
    }

    if (strpos($source, '`') !== false && preg_match('/`[^`\n]*`/', $source) === 1) {
        $failures[] = $relative . ' contains a shell backtick expression';
    }

    if (preg_match('/\bstyle\s*=\s*"/', $source) === 1) {
        $failures[] = $relative . ' contains an inline style attribute';
    }

    if (preg_match('/<!--/', $source) === 1) {
        $failures[] = $relative . ' contains an HTML comment';
    }
}

$shellFiles = [
    'install.sh', 'uninstall.sh', 'payload/bin/zcd-run', 'payload/bin/zcd-sentinel',
];

foreach ($shellFiles as $relative) {
    $path = $root . '/' . $relative;

    if (!is_file($path)) {
        continue;
    }

    foreach (explode("\n", (string) file_get_contents($path)) as $number => $line) {
        if ($number === 0 && strpos($line, '#!') === 0) {
            continue;
        }

        if (preg_match('/^\s*#/', $line) === 1) {
            $failures[] = sprintf('%s:%d comment left in shell script: %s', $relative, $number + 1, trim($line));
        }
    }
}

foreach (['install.sh' => 'install_plugin', 'uninstall.sh' => 'uninstall_plugin'] as $relative => $script) {
    $path = $root . '/' . $relative;

    if (!is_file($path)) {
        continue;
    }

    foreach (explode("\n", (string) file_get_contents($path)) as $number => $line) {
        if (strpos(ltrim($line), '/usr/local/cpanel/scripts/' . $script . ' ') !== 0) {
            continue;
        }

        if (preg_match('/\.json("|\s|$)/', $line) === 1) {
            $failures[] = sprintf(
                '%s:%d %s takes a directory or an archive, never a path to install.json',
                $relative,
                $number + 1,
                $script
            );
        }

        if (strpos($line, '--theme ') === false) {
            $failures[] = sprintf(
                '%s:%d %s needs an explicit --theme, otherwise it uses the server default',
                $relative,
                $number + 1,
                $script
            );
        }
    }
}

foreach (['assets/zcd.js', 'assets/zcd.un-compressed.css'] as $relative) {
    $contents = (string) file_get_contents($root . '/' . $relative);

    if (preg_match('#(^|\s)//[^\n]*#', $contents) === 1 || strpos($contents, '/*') !== false) {
        $failures[] = $relative . ' contains a comment';
    }
}

$css = (string) file_get_contents($root . '/assets/zcd.un-compressed.css');
$built = (string) file_get_contents($root . '/assets/zcd.css');

$expected = preg_replace('#/\*.*?\*/#s', '', $css);
$expected = preg_replace('/\s+/', ' ', (string) $expected);
$expected = preg_replace('/\s*([{}:;,>])\s*/', '$1', (string) $expected);
$expected = preg_replace('/;}/', '}', (string) $expected);
$expected = str_replace(['( ', ' )'], ['(', ')'], (string) $expected);

if (trim($built) !== trim((string) $expected)) {
    $failures[] = 'assets/zcd.css is out of date, run tools/build-css.php';
}

$attribution = ['claude', 'anthropic', 'copilot', 'chatgpt', 'openai', 'co-authored-by'];
$auditTargets = array_merge($phpFiles, [
    'README.md', 'CHANGELOG.md', 'SECURITY.md', 'install.sh', 'uninstall.sh',
    'install.json', '_plugin.yaml', 'assets/zcd.js', 'assets/zcd.un-compressed.css',
    'payload/bin/zcd-run', 'payload/bin/zcd-sentinel', 'zcd.svg',
]);

foreach ($auditTargets as $relative) {
    $path = $root . '/' . $relative;

    if (!is_file($path)) {
        continue;
    }

    $contents = strtolower((string) file_get_contents($path));

    foreach ($attribution as $needle) {
        if (strpos($contents, $needle) !== false) {
            $failures[] = $relative . ' mentions "' . $needle . '"';
        }
    }
}

if ($failures === []) {
    printf("%d PHP files checked, no findings\n", count($phpFiles));
    exit(0);
}

foreach ($failures as $failure) {
    fwrite(STDERR, $failure . "\n");
}

fwrite(STDERR, sprintf("\n%d finding(s)\n", count($failures)));
exit(1);
