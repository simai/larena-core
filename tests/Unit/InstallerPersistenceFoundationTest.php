<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Larena\Core\Starter\InstallerPersistenceFoundation;

$basePath = sys_get_temp_dir() . '/larena-core-installer-persistence-foundation-test-' . bin2hex(random_bytes(4));
$record = [
    'id' => 'installer-persistence-foundation-test',
    '_relative_path' => 'docs/project-management/launch-records/installer-persistence-foundation.json',
    'evidence_path' => 'docs/project-management/evidence/installer-persistence-foundation',
    'backup' => [
        'target' => 'storage/app/larena/installer-persistence-foundation.json',
        'path' => 'docs/project-management/evidence/installer-persistence-foundation/backup/installer-persistence-foundation.before.json',
    ],
    'rollback_plan' => [
        'type' => 'restore_backup_or_delete_if_absent',
        'target' => 'storage/app/larena/installer-persistence-foundation.json',
        'backup_path' => 'docs/project-management/evidence/installer-persistence-foundation/backup/installer-persistence-foundation.before.json',
    ],
];
$applicationContext = [
    'database_connection' => [
        'status' => 'passed',
        'driver' => 'mysql',
        'default_connection' => 'mysql',
    ],
];

$first = InstallerPersistenceFoundation::apply($basePath, $record, $applicationContext);

if (($first['status'] ?? null) !== 'passed') {
    fwrite(STDERR, 'Expected first installer persistence foundation apply to pass.' . PHP_EOL);
    exit(1);
}

if (($first['mutation'] ?? null) !== 'installer_persistence_foundation') {
    fwrite(STDERR, 'Expected installer persistence foundation mutation.' . PHP_EOL);
    exit(1);
}

if (($first['state'] ?? null) !== 'applied') {
    fwrite(STDERR, 'Expected first installer persistence foundation state to be applied.' . PHP_EOL);
    exit(1);
}

if (($first['mutates_state'] ?? null) !== true) {
    fwrite(STDERR, 'Expected first installer persistence foundation apply to mutate state.' . PHP_EOL);
    exit(1);
}

if (($first['persistence_manifest']['schema'] ?? null) !== 'larena.installer_persistence_foundation.v1') {
    fwrite(STDERR, 'Expected installer persistence manifest schema.' . PHP_EOL);
    exit(1);
}

if (($first['persistence_manifest']['blocked_changes'][0] ?? null) !== 'database_schema_mutation') {
    fwrite(STDERR, 'Expected database schema mutation to remain blocked.' . PHP_EOL);
    exit(1);
}

if (($first['recovery_guidance']['status'] ?? null) !== 'available') {
    fwrite(STDERR, 'Expected installer persistence foundation recovery guidance.' . PHP_EOL);
    exit(1);
}

if (!is_file($basePath . '/storage/app/larena/installer-persistence-foundation.json')) {
    fwrite(STDERR, 'Expected installer persistence foundation target to exist.' . PHP_EOL);
    exit(1);
}

if (!is_file($basePath . '/docs/project-management/evidence/installer-persistence-foundation/backup/installer-persistence-foundation.before.json')) {
    fwrite(STDERR, 'Expected installer persistence foundation backup marker to exist.' . PHP_EOL);
    exit(1);
}

$second = InstallerPersistenceFoundation::apply($basePath, $record, $applicationContext);

if (($second['status'] ?? null) !== 'passed') {
    fwrite(STDERR, 'Expected second installer persistence foundation apply to pass.' . PHP_EOL);
    exit(1);
}

if (($second['state'] ?? null) !== 'already_current') {
    fwrite(STDERR, 'Expected second installer persistence foundation state to be already current.' . PHP_EOL);
    exit(1);
}

if (($second['mutates_state'] ?? null) !== false) {
    fwrite(STDERR, 'Expected second installer persistence foundation apply to be idempotent.' . PHP_EOL);
    exit(1);
}

echo 'InstallerPersistenceFoundationTest passed.' . PHP_EOL;
