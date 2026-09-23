<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/solution-fixtures.php';

use Larena\Core\Contracts\OperationContext;
use Larena\Core\Enums\OperationRiskClass;
use Larena\Core\Runtime\SolutionManifestValidator;
use Larena\Core\Runtime\SolutionOperationHandlers;
use Larena\Core\Runtime\SolutionPlanner;

$validator = new SolutionManifestValidator();

$node = static fn (array $overrides = []): array => [
    'node_id' => 'web',
    'packages' => ['larena/core'],
    ...$overrides,
];

$refusals = [
    'schema_mismatch' => ['schema' => 'larena.solution_manifest.v2'],
    'invalid_solution_id' => ['solution_id' => 'Example Solution'],
    'invalid_version' => ['version' => '1.0'],
    'unknown_distribution' => ['distribution' => 'internal_only'],
    'empty_package_list' => ['packages' => []],
    'unknown_edition' => ['edition' => 'enterprise', 'editions' => ['free' => ['capabilities' => []]]],
    'unknown_environment_capability' => ['environment_requirements' => ['gpu']],
    'unknown_topology_key' => ['topology' => [$node(['replicas' => 3])]],
    'node_without_packages' => ['topology' => [$node(['packages' => []])]],
    'unknown_package_in_node' => ['topology' => [$node(['packages' => ['larena/absent']])]],
    'invalid_headless_marker' => ['topology' => [$node(['headless' => 'yes'])]],
    'duplicate_node_id' => ['topology' => [$node(), $node()]],
    'invalid_base_solution' => ['base_solution' => ['solution_id' => 'minimal_cms', 'min_version' => '2.0.0', 'max_version' => '1.0.0']],
    'unknown_base_solution_key' => ['base_solution' => [
        'solution_id' => 'minimal_cms',
        'min_version' => '1.0.0',
        'max_version' => '2.0.0',
        'branch' => 'main',
    ]],
];

foreach ($refusals as $reasonCode => $overrides) {
    solution_assert(
        solution_refusal(static fn () => $validator->validate(solution_manifest($overrides))) === $reasonCode,
        'expected ' . $reasonCode,
    );
}

// Every solution operation is a read: no audit event, no transaction, no
// irreversible class. An operation that plans must not be able to write.
foreach (SolutionOperationHandlers::descriptors() as $name => $descriptor) {
    solution_assert($descriptor->effectiveRiskClass() === OperationRiskClass::Read, $name . ' must be a read');
    solution_assert($descriptor->auditEvent === null, $name . ' records no audit event');
    solution_assert(!$descriptor->requiresTransactionBoundary(), $name . ' opens no transaction');
    solution_assert($descriptor->accessScope === 'core.solution.read');
    solution_assert(!$descriptor->alwaysRequiresConfirmation(), $name . ' changes nothing to confirm');
}

$handlers = new SolutionOperationHandlers($validator, new SolutionPlanner());
$descriptors = SolutionOperationHandlers::descriptors();

$context = static fn (array $metadata): OperationContext => new OperationContext(
    actorId: 'tester',
    correlationId: 'solution-fails-closed',
    metadata: $metadata,
);

// A manifest is required, and a missing one is a refusal rather than an empty plan.
foreach (['core.solution.validate', 'core.solution.plan_install', 'core.solution.plan_upgrade', 'core.solution.validate_topology'] as $name) {
    solution_assert(
        solution_refusal(static fn () => $handlers->handle($descriptors[$name], $context([]))) === 'invalid_input',
        $name . ' requires a manifest',
    );
}

solution_assert(
    solution_refusal(static fn () => $handlers->handle(
        $descriptors['core.solution.plan_install'],
        $context(['manifest' => solution_manifest(), 'installed' => 'everything']),
    )) === 'invalid_input',
);

// The contract explains itself, and says plainly that it installs nothing.
$explained = $handlers->handle($descriptors['core.solution.explain'], $context([]));
solution_assert($explained['installs'] === false);
solution_assert($explained['offline'] === true);
solution_assert($explained['conflict_classes'] === ['scope', 'plane', 'structure_role', 'package']);
solution_assert(count($explained['top_level_keys']) === 17);

// Planning through the operation path reaches the same verdict as planning
// directly, and the default composition gates a second node.
$plan = $handlers->handle($descriptors['core.solution.plan_install'], $context(['manifest' => solution_fixture('docara')]));
solution_assert($plan['actionable'] === true && $plan['planning_only'] === true);

$topology = $handlers->handle($descriptors['core.solution.validate_topology'], $context(['manifest' => solution_fixture('tracker')]));
solution_assert($topology['allowed'] === false && $topology['reason_codes'] === ['multi_node_without_entitlement']);

echo "Solution fail-closed boundaries passed.\n";
