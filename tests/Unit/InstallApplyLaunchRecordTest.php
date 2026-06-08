<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Larena\Core\Starter\InstallApplyLaunchRecord;

$basePath = sys_get_temp_dir() . '/larena-core-launch-record-test-' . bin2hex(random_bytes(4));
mkdir($basePath . '/docs/project-management/launch-records', 0775, true);

$recordPath = 'docs/project-management/launch-records/package-registry-seed.json';
file_put_contents($basePath . '/' . $recordPath, json_encode([
    'schema' => 'larena.install_apply_launch_record.v1',
    'id' => 'package-registry-seed-test',
    'status' => 'ready_to_apply',
    'transition' => 'install_apply_launch_record',
    'target_step' => 'package_registry_seed',
    'allowed_scope' => ['package_registry_seed'],
    'evidence_path' => 'docs/project-management/evidence/package-registry-seed',
    'backup' => [
        'target' => 'storage/app/larena/package-registry.json',
        'path' => 'docs/project-management/evidence/package-registry-seed/backup.json',
    ],
    'rollback_plan' => [
        'type' => 'restore_backup_or_delete_if_absent',
    ],
    'limits' => [
        'requires_command_confirmation' => 'package_registry_seed',
    ],
    'operator_approval' => [
        'status' => 'approved',
    ],
], JSON_PRETTY_PRINT) . PHP_EOL);

$loaded = InstallApplyLaunchRecord::load($basePath, $recordPath);

if (($loaded['status'] ?? null) !== 'passed') {
    fwrite(STDERR, 'Expected valid launch record to pass.' . PHP_EOL);
    exit(1);
}

file_put_contents($basePath . '/' . $recordPath, json_encode([
    'schema' => 'larena.install_apply_launch_record.v1',
    'id' => 'installer-persistence-foundation-test',
    'status' => 'ready_to_apply',
    'transition' => 'install_apply_launch_record',
    'target_step' => 'installer_persistence_foundation',
    'allowed_scope' => ['installer_persistence_foundation'],
    'evidence_path' => 'docs/project-management/evidence/installer-persistence-foundation',
    'backup' => [
        'target' => 'storage/app/larena/installer-persistence-foundation.json',
        'path' => 'docs/project-management/evidence/installer-persistence-foundation/backup.json',
    ],
    'rollback_plan' => [
        'type' => 'restore_backup_or_delete_if_absent',
    ],
    'limits' => [
        'requires_command_confirmation' => 'installer_persistence_foundation',
    ],
    'operator_approval' => [
        'status' => 'approved',
    ],
], JSON_PRETTY_PRINT) . PHP_EOL);

$loadedPersistence = InstallApplyLaunchRecord::load($basePath, $recordPath);

if (($loadedPersistence['status'] ?? null) !== 'passed') {
    fwrite(STDERR, 'Expected valid installer persistence launch record to pass.' . PHP_EOL);
    exit(1);
}

file_put_contents($basePath . '/' . $recordPath, json_encode([
    'schema' => 'larena.install_apply_launch_record.v1',
    'id' => 'package-registry-seed-test',
    'status' => 'ready_to_apply',
    'transition' => 'install_apply_launch_record',
    'target_step' => 'admin_bootstrap',
    'allowed_scope' => ['admin_bootstrap'],
    'evidence_path' => 'docs/project-management/evidence/package-registry-seed',
    'backup' => [
        'target' => 'storage/app/larena/package-registry.json',
        'path' => 'docs/project-management/evidence/package-registry-seed/backup.json',
    ],
    'rollback_plan' => [
        'type' => 'restore_backup_or_delete_if_absent',
    ],
    'limits' => [
        'requires_command_confirmation' => 'package_registry_seed',
    ],
    'operator_approval' => [
        'status' => 'approved',
    ],
], JSON_PRETTY_PRINT) . PHP_EOL);

$blocked = InstallApplyLaunchRecord::load($basePath, $recordPath);

if (($blocked['status'] ?? null) !== 'blocked') {
    fwrite(STDERR, 'Expected unsupported target step to be blocked.' . PHP_EOL);
    exit(1);
}

file_put_contents($basePath . '/' . $recordPath, json_encode([
    'schema' => 'larena.install_apply_launch_record.v1',
    'id' => 'package-registry-seed-test',
    'status' => 'ready_to_apply',
    'transition' => 'install_apply_launch_record',
    'target_step' => 'package_registry_seed',
    'allowed_scope' => ['package_registry_seed'],
    'evidence_path' => 'docs/project-management/evidence/package-registry-seed',
    'backup' => [
        'target' => 'storage/app/larena/package-registry.json',
        'path' => 'docs/project-management/evidence/package-registry-seed/backup.json',
    ],
    'rollback_plan' => [
        'type' => 'restore_backup_or_delete_if_absent',
    ],
    'operator_approval' => [
        'status' => 'approved',
    ],
], JSON_PRETTY_PRINT) . PHP_EOL);

$missingConfirmationPolicy = InstallApplyLaunchRecord::load($basePath, $recordPath);

if (($missingConfirmationPolicy['status'] ?? null) !== 'blocked') {
    fwrite(STDERR, 'Expected launch record without command confirmation policy to be blocked.' . PHP_EOL);
    exit(1);
}

file_put_contents($basePath . '/' . $recordPath, json_encode([
    'schema' => 'larena.install_apply_launch_record.v1',
    'id' => 'installer-persistence-foundation-test',
    'status' => 'ready_to_apply',
    'transition' => 'install_apply_launch_record',
    'target_step' => 'installer_persistence_foundation',
    'allowed_scope' => ['installer_persistence_foundation'],
    'evidence_path' => 'docs/project-management/evidence/installer-persistence-foundation',
    'backup' => [
        'target' => 'storage/app/larena/installer-persistence-foundation.json',
        'path' => 'docs/project-management/evidence/installer-persistence-foundation/backup.json',
    ],
    'rollback_plan' => [
        'type' => 'restore_backup_or_delete_if_absent',
    ],
    'limits' => [
        'requires_command_confirmation' => 'package_registry_seed',
    ],
    'operator_approval' => [
        'status' => 'approved',
    ],
], JSON_PRETTY_PRINT) . PHP_EOL);

$wrongConfirmationPolicy = InstallApplyLaunchRecord::load($basePath, $recordPath);

if (($wrongConfirmationPolicy['status'] ?? null) !== 'blocked') {
    fwrite(STDERR, 'Expected launch record with mismatched confirmation policy to be blocked.' . PHP_EOL);
    exit(1);
}

echo 'InstallApplyLaunchRecordTest passed.' . PHP_EOL;
