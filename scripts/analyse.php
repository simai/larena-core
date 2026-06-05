<?php

declare(strict_types=1);

$phpstan = 'vendor/bin/phpstan';

if (!is_file($phpstan)) {
    fwrite(STDERR, "PHPStan is not installed. Run composer install before static analysis.\n");
    exit(1);
}

$command = sprintf(
    '%s -d memory_limit=512M %s analyse --configuration=phpstan.neon.dist --no-progress --memory-limit=512M',
    escapeshellarg(PHP_BINARY),
    escapeshellarg($phpstan),
);

passthru($command, $exitCode);
exit($exitCode);
