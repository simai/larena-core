<?php

declare(strict_types=1);

if (!is_file('vendor/bin/phpstan')) {
    fwrite(STDERR, "PHPStan is required. Run composer install before composer run analyse.\n");
    exit(1);
}

passthru('vendor/bin/phpstan analyse --configuration=phpstan.neon.dist', $status);
exit($status);
