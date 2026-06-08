<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Larena\Core\Starter\InstallReadinessContract;

$doctor = [
    'status' => 'passed',
    'checks' => [
        'required_packages' => ['status' => 'passed'],
        'runtime_security_smoke' => [
            'status' => 'passed',
            'evidence_path' => '/tmp/runtime-security.json',
        ],
        'write_paths' => ['status' => 'passed'],
        'database_connection' => [
            'status' => 'degraded',
            'reason' => 'database_credentials_rejected',
            'action' => 'Check DB_CONNECTION and DB_USERNAME in .env.',
        ],
    ],
];

$plan = [
    ['step' => 'environment_preflight', 'status' => 'ready'],
    ['step' => 'package_registry_seed', 'status' => 'planned'],
    ['step' => 'installer_db_schema_apply', 'status' => 'planned_after_installer_persistence_foundation'],
    ['step' => 'runtime_security_verification', 'status' => 'ready'],
];

$contract = InstallReadinessContract::fromDryRunPlan($doctor, $plan, '/tmp/doctor.json');

if (($contract['schema'] ?? null) !== 'larena.install_readiness_contract.v1') {
    fwrite(STDERR, 'Unexpected install readiness contract schema.' . PHP_EOL);
    exit(1);
}

if (($contract['status'] ?? null) !== 'ready_for_guarded_install_planning') {
    fwrite(STDERR, 'Install readiness contract should be ready for guarded planning.' . PHP_EOL);
    exit(1);
}

if (($contract['actual_install_allowed'] ?? null) !== false) {
    fwrite(STDERR, 'Install readiness contract must not allow actual install mutations.' . PHP_EOL);
    exit(1);
}

if (($contract['mutation_policy']['apply_without_launch_record'] ?? null) !== 'blocked') {
    fwrite(STDERR, 'Install readiness contract must block apply without launch record.' . PHP_EOL);
    exit(1);
}

$databaseGate = null;
foreach ($contract['gates'] ?? [] as $gate) {
    if (($gate['id'] ?? null) === 'database_environment') {
        $databaseGate = $gate;
        break;
    }
}

if (!is_array($databaseGate)) {
    fwrite(STDERR, 'Install readiness contract must include database environment gate.' . PHP_EOL);
    exit(1);
}

if (($databaseGate['status'] ?? null) !== 'degraded') {
    fwrite(STDERR, 'Database environment gate should expose degraded future-install readiness.' . PHP_EOL);
    exit(1);
}

if (($databaseGate['required_for_current_preview'] ?? null) !== false) {
    fwrite(STDERR, 'Database environment gate must not block the current developer preview.' . PHP_EOL);
    exit(1);
}

if (($databaseGate['required_for_future_install'] ?? null) !== true) {
    fwrite(STDERR, 'Database environment gate must be required for future install.' . PHP_EOL);
    exit(1);
}

foreach (['launch_record', 'allowed_scope', 'backup_evidence', 'rollback_plan', 'evidence_path'] as $requiredGate) {
    if (!in_array($requiredGate, $contract['required_before_mutation'] ?? [], true)) {
        fwrite(STDERR, "Missing required gate: {$requiredGate}" . PHP_EOL);
        exit(1);
    }
}

if (!in_array('installer_db_schema_apply', $contract['eligible_first_mutations_after_launch_record'] ?? [], true)) {
    fwrite(STDERR, 'Install readiness contract must expose installer DB schema apply as a guarded mutation.' . PHP_EOL);
    exit(1);
}

if (!in_array('package_registry_db_seed', $contract['eligible_first_mutations_after_launch_record'] ?? [], true)) {
    fwrite(STDERR, 'Install readiness contract must expose package registry DB seed as a guarded mutation.' . PHP_EOL);
    exit(1);
}

$dbSchemaApply = null;
foreach ($contract['eligible_guarded_mutations_after_launch_record'] ?? [] as $mutation) {
    if (is_array($mutation) && ($mutation['step'] ?? null) === 'installer_db_schema_apply') {
        $dbSchemaApply = $mutation;
        break;
    }
}

if (!is_array($dbSchemaApply)) {
    fwrite(STDERR, 'Install readiness contract must include installer DB schema apply mutation details.' . PHP_EOL);
    exit(1);
}

if (($dbSchemaApply['applies_database_migrations'] ?? null) !== true) {
    fwrite(STDERR, 'Installer DB schema apply must be marked as database migration apply.' . PHP_EOL);
    exit(1);
}

if (($dbSchemaApply['creates_database'] ?? null) !== false) {
    fwrite(STDERR, 'Installer DB schema apply must not create the database.' . PHP_EOL);
    exit(1);
}

if (($dbSchemaApply['writes_environment'] ?? null) !== false) {
    fwrite(STDERR, 'Installer DB schema apply must not write environment files.' . PHP_EOL);
    exit(1);
}

$packageRegistryDbSeed = null;
foreach ($contract['eligible_guarded_mutations_after_launch_record'] ?? [] as $mutation) {
    if (is_array($mutation) && ($mutation['step'] ?? null) === 'package_registry_db_seed') {
        $packageRegistryDbSeed = $mutation;
        break;
    }
}

if (!is_array($packageRegistryDbSeed)) {
    fwrite(STDERR, 'Install readiness contract must include package registry DB seed mutation details.' . PHP_EOL);
    exit(1);
}

if (($packageRegistryDbSeed['requires_existing_table'] ?? null) !== 'larena_package_registry') {
    fwrite(STDERR, 'Package registry DB seed must require the package registry table.' . PHP_EOL);
    exit(1);
}

if (($packageRegistryDbSeed['applies_database_migrations'] ?? null) !== false) {
    fwrite(STDERR, 'Package registry DB seed must not apply database migrations.' . PHP_EOL);
    exit(1);
}

echo 'InstallReadinessContractTest passed.' . PHP_EOL;
