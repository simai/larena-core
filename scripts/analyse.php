<?php

declare(strict_types=1);

if (!is_dir('src') && !is_dir('tests')) {
    echo "No PHP source files to analyse in enforcement-only baseline.\n";
    exit(0);
}

if (is_file('vendor/bin/phpstan')) {
    passthru('vendor/bin/phpstan analyse src tests', $status);
    exit($status);
}

echo "PHPStan is not installed yet; static analysis is deferred until the first coding batch installs dev tooling.\n";
