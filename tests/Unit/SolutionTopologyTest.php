<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/solution-fixtures.php';

use Larena\Core\Enums\CapabilityPresence;
use Larena\Core\Enums\EnvironmentCapability;
use Larena\Core\Runtime\DeclaredEnvironmentProfile;
use Larena\Core\Runtime\SolutionManifestValidator;
use Larena\Core\Runtime\SolutionPlanner;

$validator = new SolutionManifestValidator();

$node = static fn (string $id, bool $headless = false): array => [
    'node_id' => $id,
    'packages' => ['larena/core'],
    'roles' => [],
    'headless' => $headless,
];

$single = $validator->validate(solution_manifest(['topology' => [$node('web')]]));
$pair = $validator->validate(solution_manifest(['topology' => [$node('web'), $node('worker', true)]]));
$cluster = $validator->validate(solution_manifest([
    'topology' => [$node('web'), $node('worker', true), $node('search', true)],
]));

// One node needs nothing at all — no entitlement resolver is bound here, and the
// single-node topology is still allowed. That is the open-source boundary.
$free = new SolutionPlanner();
$singleReport = $free->validateTopology($single);
solution_assert($singleReport['allowed'] === true, 'a single node needs no entitlement');
solution_assert($singleReport['reason_codes'] === []);
solution_assert($singleReport['offline'] === true);

// A second node needs remote operations, and without it the refusal names the
// capability rather than just saying no.
$pairReport = $free->validateTopology($pair);
solution_assert($pairReport['allowed'] === false);
solution_assert(in_array('multi_node_without_entitlement', $pairReport['reason_codes'], true));
solution_assert($pairReport['missing_capabilities'] === [SolutionPlanner::REMOTE_OPERATIONS]);

$remote = new SolutionPlanner(static fn (string $key): bool => $key === SolutionPlanner::REMOTE_OPERATIONS);
solution_assert($remote->validateTopology($pair)['allowed'] === true, 'remote operations admit a pair');

// Three nodes need the cluster capability on top of remote operations.
$clusterReport = $remote->validateTopology($cluster);
solution_assert($clusterReport['allowed'] === false);
solution_assert($clusterReport['reason_codes'] === ['cluster_without_entitlement']);
solution_assert($clusterReport['missing_capabilities'] === [SolutionPlanner::CLUSTER]);

$entitled = new SolutionPlanner(static fn (): bool => true);
solution_assert($entitled->validateTopology($cluster)['allowed'] === true);

// The headless marker is read from the declaration, not derived from what the node
// happens to carry: both nodes here hold the same package.
solution_assert($free->validateTopology($pair)['headless_nodes'] === ['worker']);
solution_assert($free->validateTopology($single)['headless_nodes'] === []);

// A topology whose requirement the host does not meet is refused naming the
// capability. Ordinary hosting has no queue worker.
$ordinary = new SolutionPlanner(
    static fn (): bool => true,
    DeclaredEnvironmentProfile::ordinaryHosting(),
);

$needsWorker = $validator->validate(solution_manifest([
    'topology' => [$node('web'), $node('worker', true)],
    'environment_requirements' => ['queue_worker', 'writable_storage_path'],
]));

$workerReport = $ordinary->validateTopology($needsWorker);
solution_assert($workerReport['allowed'] === false);
solution_assert($workerReport['reason_codes'] === ['environment_requirement_unmet']);
solution_assert($workerReport['missing_environment_capabilities'] === ['queue_worker']);

// And `unknown` is `absent` here too: a requirement nobody could confirm is not met.
$unknownProfile = DeclaredEnvironmentProfile::declare(
    'undeclared_host',
    1,
    [EnvironmentCapability::QueueWorker->value => CapabilityPresence::Unknown],
    'test',
);
$unknown = new SolutionPlanner(static fn (): bool => true, $unknownProfile);
solution_assert(
    $unknown->validateTopology($needsWorker)['missing_environment_capabilities'] === ['queue_worker', 'writable_storage_path'],
    'an unknown capability is not a met requirement',
);

// The worker profile admits it.
$withWorker = DeclaredEnvironmentProfile::declare(
    'worker_host',
    1,
    [
        EnvironmentCapability::QueueWorker->value => CapabilityPresence::Present,
        EnvironmentCapability::WritableStoragePath->value => CapabilityPresence::Present,
    ],
    'test',
);
solution_assert((new SolutionPlanner(static fn (): bool => true, $withWorker))->validateTopology($needsWorker)['allowed'] === true);

// The tracker fixture is the two-node case end to end.
$tracker = $validator->validate(solution_fixture('tracker'));
solution_assert($tracker->nodeCount() === 2 && !$tracker->isCluster());
solution_assert($free->validateTopology($tracker)['allowed'] === false, 'two nodes are gated by default');
solution_assert((new SolutionPlanner(static fn (): bool => true, $withWorker))->validateTopology($tracker)['allowed'] === true);

echo "Solution topology validation passed.\n";
