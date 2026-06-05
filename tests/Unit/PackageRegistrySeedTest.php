<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Larena\Core\Starter\PackageRegistrySeed;

$basePath = sys_get_temp_dir() . '/larena-core-package-registry-seed-test-' . bin2hex(random_bytes(4));
$record = [
    'id' => 'package-registry-seed-test',
    '_relative_path' => 'docs/project-management/launch-records/package-registry-seed.json',
    'evidence_path' => 'docs/project-management/evidence/package-registry-seed',
    'backup' => [
        'target' => 'storage/app/larena/package-registry.json',
        'path' => 'docs/project-management/evidence/package-registry-seed/backup/package-registry.before.json',
    ],
    'rollback_plan' => [
        'type' => 'restore_backup_or_delete_if_absent',
    ],
];
$installed = [
    'larena/core' => [
        'version' => 'dev-main',
        'install_path' => '../larena/core',
    ],
    'larena/storage' => [
        'version' => 'dev-main',
        'install_path' => '../larena/storage',
    ],
];

$requiredPackages = ['larena/core', 'larena/storage'];

$first = PackageRegistrySeed::apply($basePath, $record, $installed, $requiredPackages);

if (($first['status'] ?? null) !== 'passed') {
    fwrite(STDERR, 'Expected first package registry seed to pass.' . PHP_EOL);
    exit(1);
}

if (($first['mutates_state'] ?? null) !== true) {
    fwrite(STDERR, 'Expected first package registry seed to mutate state.' . PHP_EOL);
    exit(1);
}

if (!is_file($basePath . '/storage/app/larena/package-registry.json')) {
    fwrite(STDERR, 'Expected package registry target to exist.' . PHP_EOL);
    exit(1);
}

$registry = json_decode((string) file_get_contents($basePath . '/storage/app/larena/package-registry.json'), true);
$packages = array_column($registry['packages'] ?? [], 'status', 'name');

if (($packages['larena/storage'] ?? null) !== 'installed') {
    fwrite(STDERR, 'Expected foundation package to be written to package registry.' . PHP_EOL);
    exit(1);
}

if (!is_file($basePath . '/docs/project-management/evidence/package-registry-seed/backup/package-registry.before.json')) {
    fwrite(STDERR, 'Expected backup marker to exist.' . PHP_EOL);
    exit(1);
}

$second = PackageRegistrySeed::apply($basePath, $record, $installed, $requiredPackages);

if (($second['status'] ?? null) !== 'passed') {
    fwrite(STDERR, 'Expected second package registry seed to pass.' . PHP_EOL);
    exit(1);
}

if (($second['mutates_state'] ?? null) !== false) {
    fwrite(STDERR, 'Expected second package registry seed to be idempotent.' . PHP_EOL);
    exit(1);
}

echo 'PackageRegistrySeedTest passed.' . PHP_EOL;
