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
    ],
];

$plan = [
    ['step' => 'environment_preflight', 'status' => 'ready'],
    ['step' => 'package_registry_seed', 'status' => 'planned'],
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

foreach (['launch_record', 'allowed_scope', 'backup_evidence', 'rollback_plan', 'evidence_path'] as $requiredGate) {
    if (!in_array($requiredGate, $contract['required_before_mutation'] ?? [], true)) {
        fwrite(STDERR, "Missing required gate: {$requiredGate}" . PHP_EOL);
        exit(1);
    }
}

echo 'InstallReadinessContractTest passed.' . PHP_EOL;
