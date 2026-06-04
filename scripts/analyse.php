<?php

declare(strict_types=1);

$phpstan = 'vendor/bin/phpstan';

if (!is_file($phpstan)) {
    fwrite(STDERR, "PHPStan is not installed. Run composer install before static analysis.\n");
    exit(1);
}

passthru($phpstan . ' analyse --configuration=phpstan.neon.dist --no-progress', $exitCode);
exit($exitCode);
