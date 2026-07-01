<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Larena\Core\Starter\InstallerDbSchemaApply;

$tables = InstallerDbSchemaApply::plannedTables();

if (count($tables) !== 2) {
    fwrite(STDERR, 'Installer DB schema apply must stay narrow in this batch.' . PHP_EOL);
    exit(1);
}

$names = array_column($tables, 'name');
foreach (['larena_package_registry', 'larena_install_state'] as $expectedTable) {
    if (!in_array($expectedTable, $names, true)) {
        fwrite(STDERR, "Missing planned installer DB table: {$expectedTable}" . PHP_EOL);
        exit(1);
    }
}

foreach ($tables as $table) {
    if (($table['owner'] ?? null) !== 'larena/core') {
        fwrite(STDERR, 'Installer DB schema apply batch must only expose core-owned tables.' . PHP_EOL);
        exit(1);
    }
}

if (!is_dir(InstallerDbSchemaApply::migrationPath())) {
    fwrite(STDERR, 'Installer DB schema migration path must exist.' . PHP_EOL);
    exit(1);
}

echo 'InstallerDbSchemaApplyTest passed.' . PHP_EOL;
