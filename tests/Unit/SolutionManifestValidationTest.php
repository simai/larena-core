<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/solution-fixtures.php';

use Larena\Core\Contracts\SolutionManifest;
use Larena\Core\Enums\SolutionDistribution;
use Larena\Core\Runtime\SolutionManifestValidator;

$validator = new SolutionManifestValidator();

// A minimal manifest validates, and it validates offline: this whole file runs
// with no service, no database and no entitlement.
$manifest = $validator->validate(solution_manifest());
solution_assert($manifest->solutionId === 'example_solution');
solution_assert($manifest->distribution === SolutionDistribution::SimaiProduct);
solution_assert($manifest->isSingleNode(), 'no declared topology is one node');
solution_assert($manifest->toArray()['schema'] === SolutionManifest::SCHEMA);

// The one schema serves all three distributions. A partner solution and a local
// customization differ from a SIMAI product in author and distribution only.
foreach (['partner_marketplace', 'local_customization'] as $distribution) {
    $other = $validator->validate(solution_manifest([
        'distribution' => $distribution,
        'author' => 'Some Partner',
    ]));
    solution_assert($other->distribution->value === $distribution);
    solution_assert($other->packages === $manifest->packages, 'the shape does not change with the distribution');
}

// The top-level key set is closed.
solution_assert(
    solution_refusal(static fn () => $validator->validate(solution_manifest(['runtime_patch' => ['x']])))
        === 'unknown_manifest_key',
);

foreach (SolutionManifest::REQUIRED_KEYS as $required) {
    $document = solution_manifest();
    unset($document[$required]);
    solution_assert(
        solution_refusal(static fn () => $validator->validate($document)) !== null,
        'a manifest without ' . $required . ' must be refused',
    );
}

// An edition names a capability set and nothing else.
$editioned = $validator->validate(solution_manifest([
    'editions' => ['free' => ['capabilities' => []], 'pro' => ['capabilities' => ['larena.capability.x.v1']]],
    'edition' => 'pro',
]));
solution_assert($editioned->enabledCapabilities() === ['larena.capability.x.v1']);
solution_assert($editioned->editions['free']->capabilities === []);

foreach ([
    ['capabilities' => [], 'packages' => ['larena/extra']],
    ['capabilities' => [], 'scopes' => ['team']],
    ['capabilities' => [], 'planes' => ['org_chart']],
    ['capabilities' => [], 'structure_roles' => ['page']],
] as $body) {
    solution_assert(
        solution_refusal(static fn () => $validator->validate(solution_manifest(['editions' => ['pro' => $body]])))
            === 'edition_changes_more_than_capabilities',
        'an edition that changes more than capabilities must be refused',
    );
}

// Seed data enters through SitePack.
$seeded = $validator->validate(solution_manifest(['seed' => ['sitepack' => 'example/starter', 'dry_run' => true]]));
solution_assert($seeded->seed === ['sitepack' => 'example/starter', 'dry_run' => true]);

foreach (['sql', 'raw_sql', 'statements', 'tables', 'table_writes', 'inserts'] as $rawKey) {
    solution_assert(
        solution_refusal(static fn () => $validator->validate(solution_manifest([
            'seed' => ['sitepack' => 'example/starter', $rawKey => ['INSERT INTO larena_pages VALUES (1)']],
        ]))) === 'raw_seed_forbidden',
        'a manifest that writes rows itself must be refused: ' . $rawKey,
    );
}

// An assistant profile may only narrow.
$available = ['core.solution.explain', 'core.scope.create'];
$narrow = $validator->validate(solution_manifest([
    'assistant_profile' => ['operations' => ['core.solution.explain'], 'max_risk' => 'read'],
]));
$validator->verifyAssistantProfile($narrow, $available);

$broadOperation = $validator->validate(solution_manifest([
    'assistant_profile' => ['operations' => ['core.plane.membership.assign'], 'max_risk' => 'read'],
]));
solution_assert(
    solution_refusal(static fn () => $validator->verifyAssistantProfile($broadOperation, $available))
        === 'assistant_profile_broadens',
);

$broadRisk = $validator->validate(solution_manifest([
    'assistant_profile' => ['operations' => ['core.solution.explain'], 'max_risk' => 'irreversible'],
]));
solution_assert(
    solution_refusal(static fn () => $validator->verifyAssistantProfile($broadRisk, $available))
        === 'assistant_profile_broadens',
);

// A manifest with no assistant profile asks nothing of the assistant, which is not
// the same as asking for everything.
$validator->verifyAssistantProfile($manifest, []);

// Both first manifests validate.
foreach (['docara', 'tracker'] as $name) {
    $fixture = $validator->validate(solution_fixture($name));
    solution_assert($fixture->solutionId === $name);
    solution_assert($fixture->packages !== []);
}

echo "Solution manifest validation passed.\n";
