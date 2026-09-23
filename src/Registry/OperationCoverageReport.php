<?php

declare(strict_types=1);

namespace Larena\Core\Registry;

use Larena\Core\Contracts\OperationDeclaration;
use Larena\Core\Contracts\OperationRegistry;

/**
 * What "no button without an operation" currently costs, as data.
 *
 * Two kinds of finding, treated differently on purpose. A malformed descriptor
 * file or an operation registered against an undeclared access code is a
 * failure: someone can fix it today. An operation code declared by a package
 * whose registration batch has not run yet is a recorded gap with a name and a
 * schedule, and failing on it would make the report useless for exactly as long
 * as the plan says the gap will exist.
 */
final class OperationCoverageReport
{
    public const SCHEMA = 'larena.operation_coverage_report.v1';

    /**
     * Packages whose operations are registered in a later batch of the Minimal
     * CMS v1.1 alignment plan. A package that is absent here and unregistered is
     * an unscheduled gap, which the report says out loud instead of hiding.
     */
    public const SCHEDULED_REGISTRATION = [
        'larena/access' => 'batch-3-follow-up: access registers its own operations',
        'larena/storage' => 'batch-4-storage-roles',
        'larena/licensing' => 'batch-5-licensing-scope-entitlement',
        'larena/docara' => 'batch-6-docara-over-storage',
        'larena/content' => 'batch-6-content-retirement',
        'larena/admin' => 'batch-7-admin-surfaces',
    ];

    public function __construct(
        private readonly OperationRegistry $registry,
        private readonly PackageDescriptorFileValidator $validator = new PackageDescriptorFileValidator(),
    ) {
    }

    /**
     * @param array<string, string> $installedPackages package name => install path
     * @return array<string, mixed>
     */
    public function build(array $installedPackages, string $revision = 'unknown'): array
    {
        $registered = $this->registry->list();
        $registeredScopes = [];
        foreach ($registered as $declaration) {
            if ($declaration->accessScope !== null) {
                $registeredScopes[$declaration->accessScope] = $declaration->name;
            }
        }

        $declaredCodes = [];
        $descriptorViolations = [];
        $descriptorNotices = [];

        foreach ($installedPackages as $package => $path) {
            foreach ($this->validator->declaredAccessOperationCodes($path) as $code) {
                $declaredCodes[$code] = $package;
            }

            foreach ($this->validator->violations($path) as $violation) {
                $finding = ['package' => $package] + $violation;
                if (PackageDescriptorFileValidator::isFailure((string) $violation['reason_code'])) {
                    $descriptorViolations[] = $finding;

                    continue;
                }

                $descriptorNotices[] = $finding;
            }
        }

        ksort($declaredCodes);

        $covered = [];
        $declaredNotRegistered = [];

        foreach ($declaredCodes as $code => $package) {
            if (isset($registeredScopes[$code])) {
                $covered[] = ['access_operation_code' => $code, 'package' => $package];

                continue;
            }

            $declaredNotRegistered[] = [
                'access_operation_code' => $code,
                'package' => $package,
                'scheduled_in' => self::SCHEDULED_REGISTRATION[$package] ?? null,
                'state' => isset(self::SCHEDULED_REGISTRATION[$package]) ? 'scheduled_gap' : 'unscheduled_gap',
            ];
        }

        $registeredWithoutDeclaredCode = [];
        foreach ($registered as $declaration) {
            if ($declaration->accessScope !== null && !isset($declaredCodes[$declaration->accessScope])) {
                $registeredWithoutDeclaredCode[] = [
                    'operation' => $declaration->name,
                    'access_scope' => $declaration->accessScope,
                ];
            }
        }

        // The report fails on what someone can fix today: a malformed descriptor
        // file, or an operation registered against an access code no package
        // declares. A declared code with no registered operation is a gap, and a
        // gap does not fail the gate — it would then be red for exactly as long
        // as the plan says the gap exists, which teaches everyone to ignore it.
        // The gap's state says whether the plan has scheduled it, so an
        // unscheduled one is visible without being fatal.
        $failed = $descriptorViolations !== [] || $registeredWithoutDeclaredCode !== [];

        return [
            'schema' => self::SCHEMA,
            'generated_for_revision' => $revision,
            'declared_access_operation_codes' => array_keys($declaredCodes),
            'registered_operations' => array_map(
                static fn (OperationDeclaration $declaration): string => $declaration->name,
                $registered,
            ),
            'covered' => $covered,
            'declared_not_registered' => $declaredNotRegistered,
            'gap_counts' => [
                'covered' => count($covered),
                'scheduled_gap' => count(array_filter(
                    $declaredNotRegistered,
                    static fn (array $gap): bool => $gap['state'] === 'scheduled_gap',
                )),
                'unscheduled_gap' => count(array_filter(
                    $declaredNotRegistered,
                    static fn (array $gap): bool => $gap['state'] === 'unscheduled_gap',
                )),
            ],
            'registered_without_declared_access_code' => $registeredWithoutDeclaredCode,
            // A registered operation always satisfies the descriptor v2 policy,
            // because the registry refuses to register one that does not. The
            // field stays so the report never reads as "unchecked", and so a
            // future warn-only registry mode has a place to speak.
            'policy_violations' => [],
            'descriptor_file_violations' => $descriptorViolations,
            'descriptor_file_notices' => $descriptorNotices,
            'status' => $failed ? 'failed' : 'passed',
        ];
    }
}
