<?php

declare(strict_types=1);

$packageRoot = dirname(__DIR__);
$entryAppRoot = getenv('LARENA_ENTRY_APP_ROOT');

$candidates = [
    $packageRoot . '/vendor/autoload.php',
    dirname($packageRoot, 2) . '/vendor/autoload.php',
    dirname($packageRoot, 3) . '/larena/vendor/autoload.php',
];

if (is_string($entryAppRoot) && $entryAppRoot !== '') {
    $candidates[] = rtrim($entryAppRoot, '/') . '/vendor/autoload.php';
}

foreach (array_unique($candidates) as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;

        return;
    }
}

fwrite(STDERR, "Larena Core test bootstrap could not find Composer autoload.\n");
fwrite(STDERR, "Run composer install in the package, workspace, or entry app, or set LARENA_ENTRY_APP_ROOT to the Larena entry app path.\n");
exit(1);
