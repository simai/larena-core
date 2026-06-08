<?php

declare(strict_types=1);

namespace Larena\Core\Starter;

final class InstallReadinessContract
{
    /**
     * @param array<string, mixed> $doctor
     * @param list<array<string, mixed>> $plan
     *
     * @return array<string, mixed>
     */
    public static function fromDryRunPlan(array $doctor, array $plan, string $doctorEvidencePath): array
    {
        $blockedSteps = self::blockedSteps($plan);
        $environmentReady = ($doctor['status'] ?? null) === 'passed' && $blockedSteps === [];

        return [
            'schema' => 'larena.install_readiness_contract.v1',
            'status' => $environmentReady ? 'ready_for_guarded_install_planning' : 'blocked',
            'generated_at' => gmdate('c'),
            'mutates_state' => false,
            'actual_install_allowed' => false,
            'transition_required' => 'install_apply_launch_record',
            'required_before_mutation' => [
                'launch_record',
                'allowed_scope',
                'backup_evidence',
                'rollback_plan',
                'evidence_path',
                'operator_approval',
            ],
            'gates' => [
                [
                    'id' => 'environment_preflight',
                    'status' => ($doctor['status'] ?? null) === 'passed' ? 'passed' : 'failed',
                    'evidence' => $doctorEvidencePath,
                ],
                [
                    'id' => 'required_packages',
                    'status' => self::doctorCheckStatus($doctor, 'required_packages'),
                    'packages' => [
                        'larena/core',
                        'larena/access',
                        'larena/audit',
                        'larena/licensing',
                    ],
                ],
                [
                    'id' => 'runtime_security_smoke',
                    'status' => self::doctorCheckStatus($doctor, 'runtime_security_smoke'),
                    'evidence' => self::doctorCheckValue($doctor, 'runtime_security_smoke', 'evidence_path'),
                ],
                [
                    'id' => 'write_paths',
                    'status' => self::doctorCheckStatus($doctor, 'write_paths'),
                ],
                [
                    'id' => 'database_environment',
                    'status' => self::doctorCheckStatus($doctor, 'database_connection'),
                    'required_for_current_preview' => false,
                    'required_for_future_install' => true,
                    'reason' => self::doctorCheckValue($doctor, 'database_connection', 'reason'),
                    'action' => self::doctorCheckValue($doctor, 'database_connection', 'action'),
                ],
            ],
            'mutation_policy' => [
                'default' => 'fail_closed',
                'dry_run' => 'allowed',
                'apply_without_launch_record' => 'blocked',
                'unknown_step' => 'blocked',
                'deferred_step' => 'blocked_until_owner_package_ready',
            ],
            'blocked_steps' => $blockedSteps,
            'eligible_first_mutations_after_launch_record' => [
                'package_registry_seed',
                'installer_persistence_foundation',
                'installer_db_schema_apply',
                'package_registry_db_seed',
            ],
            'eligible_guarded_mutations_after_launch_record' => [
                [
                    'step' => 'package_registry_seed',
                    'requires_command_confirmation' => 'package_registry_seed',
                    'mutates_state' => true,
                    'creates_database' => false,
                    'applies_database_migrations' => false,
                    'writes_environment' => false,
                ],
                [
                    'step' => 'installer_persistence_foundation',
                    'requires_command_confirmation' => 'installer_persistence_foundation',
                    'mutates_state' => true,
                    'creates_database' => false,
                    'applies_database_migrations' => false,
                    'writes_environment' => false,
                ],
                [
                    'step' => 'installer_db_schema_apply',
                    'requires_command_confirmation' => 'installer_db_schema_apply',
                    'mutates_state' => true,
                    'creates_database' => false,
                    'applies_database_migrations' => true,
                    'writes_environment' => false,
                    'planned_tables' => InstallerDbSchemaApply::plannedTables(),
                    'deferred_tables' => [
                        [
                            'name' => 'larena_install_events',
                            'owner' => 'larena/audit',
                            'reason' => 'audit-owned installer event persistence requires its own guarded batch',
                        ],
                    ],
                ],
                [
                    'step' => 'package_registry_db_seed',
                    'requires_command_confirmation' => 'package_registry_db_seed',
                    'mutates_state' => true,
                    'creates_database' => false,
                    'applies_database_migrations' => false,
                    'writes_environment' => false,
                    'requires_existing_table' => 'larena_package_registry',
                ],
            ],
            'read_only_steps' => [
                'environment_preflight',
                'runtime_security_verification',
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $plan
     *
     * @return list<array<string, mixed>>
     */
    private static function blockedSteps(array $plan): array
    {
        return array_values(array_filter(
            $plan,
            static fn (array $step): bool => ($step['status'] ?? null) === 'blocked',
        ));
    }

    /**
     * @param array<string, mixed> $doctor
     */
    private static function doctorCheckStatus(array $doctor, string $check): string
    {
        $checks = $doctor['checks'] ?? [];

        if (!is_array($checks)) {
            return 'failed';
        }

        $checkData = $checks[$check] ?? [];

        if (!is_array($checkData)) {
            return 'failed';
        }

        $status = $checkData['status'] ?? 'failed';

        return is_string($status) ? $status : 'failed';
    }

    /**
     * @param array<string, mixed> $doctor
     */
    private static function doctorCheckValue(array $doctor, string $check, string $key): mixed
    {
        $checks = $doctor['checks'] ?? [];

        if (!is_array($checks)) {
            return null;
        }

        $checkData = $checks[$check] ?? [];

        if (!is_array($checkData)) {
            return null;
        }

        return $checkData[$key] ?? null;
    }
}
