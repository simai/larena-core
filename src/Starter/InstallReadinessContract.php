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
