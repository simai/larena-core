<?php

declare(strict_types=1);

$roots = ['src', 'tests', 'config'];
$files = [];

foreach ($roots as $root) {
    if (!is_dir($root)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
    foreach ($iterator as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
}

foreach ($files as $file) {
    $command = sprintf('php -l %s', escapeshellarg($file));
    passthru($command, $status);
    if ($status !== 0) {
        exit($status);
    }
}

echo count($files) === 0
    ? "No PHP source files to lint in enforcement-only baseline.\n"
    : sprintf("Linted %d PHP file(s).\n", count($files));
