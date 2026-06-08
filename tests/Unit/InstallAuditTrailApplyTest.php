<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Larena\Audit\Install\InstallAuditTrail;

if (!class_exists(InstallAuditTrail::class)) {
    fwrite(STDERR, 'InstallAuditTrail contract must be available from larena/audit.' . PHP_EOL);
    exit(1);
}

$tables = InstallAuditTrail::plannedTables();

if (($tables[0]['name'] ?? null) !== 'larena_install_events') {
    fwrite(STDERR, 'Install audit trail apply must use larena_install_events.' . PHP_EOL);
    exit(1);
}

if (($tables[0]['owner'] ?? null) !== 'larena/audit') {
    fwrite(STDERR, 'Install audit trail table must be owned by larena/audit.' . PHP_EOL);
    exit(1);
}

if (!is_dir(InstallAuditTrail::migrationPath())) {
    fwrite(STDERR, 'Install audit trail migration path must exist.' . PHP_EOL);
    exit(1);
}

echo 'InstallAuditTrailApplyTest passed.' . PHP_EOL;
