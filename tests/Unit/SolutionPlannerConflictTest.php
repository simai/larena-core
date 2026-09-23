<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/solution-fixtures.php';

use Larena\Core\Enums\SolutionConflictClass;
use Larena\Core\Runtime\SolutionManifestValidator;
use Larena\Core\Runtime\SolutionPlanner;

$validator = new SolutionManifestValidator();
$planner = new SolutionPlanner();

// A clean installation plans, and the plan names what it would do.
$manifest = $validator->validate(solution_manifest([
    'scopes' => ['site'],
    'planes' => ['org_chart'],
    'structure_roles' => ['page'],
    'seed' => ['sitepack' => 'example/starter', 'dry_run' => true],
]));

$plan = $planner->planInstall($manifest);
solution_assert($plan->isActionable(), 'an unoccupied installation plans');
solution_assert($plan->fromVersion === null && $plan->toVersion === '1.0.0');
solution_assert($plan->conflicts === []);
solution_assert($plan->steps !== [], 'a plan without steps explains nothing');
solution_assert($plan->toArray()['planning_only'] === true);

// All four conflict classes are reported in one pass, each naming the incumbent.
$installed = [
    'incumbent' => [
        'version' => '1.0.0',
        'scopes' => ['site'],
        'planes' => ['org_chart'],
        'structure_roles' => ['page'],
        'packages' => ['larena/storage'],
    ],
];

$conflicted = $planner->planInstall($manifest, $installed);
solution_assert(!$conflicted->isActionable());
solution_assert($conflicted->steps === [], 'a conflicted plan proposes no step');

$classes = array_map(
    static fn ($conflict): string => $conflict->conflictClass->value,
    $conflicted->conflicts,
);

foreach (SolutionConflictClass::cases() as $class) {
    solution_assert(in_array($class->value, $classes, true), $class->value . ' must be reported');
}

foreach ($conflicted->conflicts as $conflict) {
    solution_assert($conflict->heldBy === 'incumbent', 'a conflict names who holds the key');
    solution_assert(str_ends_with($conflict->reasonCode(), '_already_owned'));
}

// Installing over itself is an upgrade question, and planning says so rather than
// silently replacing a running solution.
solution_assert(
    $planner->planInstall($manifest, ['example_solution' => ['version' => '1.0.0']])->reasonCode
        === 'solution_already_installed',
);

// An upgrade does not conflict with the version it replaces.
$next = $validator->validate(solution_manifest([
    'version' => '1.1.0',
    'scopes' => ['site'],
    'planes' => ['org_chart'],
]));

$upgrade = $planner->planUpgrade($next, ['example_solution' => [
    'version' => '1.0.0',
    'scopes' => ['site'],
    'planes' => ['org_chart'],
    'packages' => ['larena/core', 'larena/storage'],
]]);
solution_assert($upgrade->isActionable(), 'a solution does not conflict with itself');
solution_assert($upgrade->fromVersion === '1.0.0' && $upgrade->toVersion === '1.1.0');

// An upgrade of something absent, and a downgrade, are both refused.
solution_assert($planner->planUpgrade($next)->reasonCode === 'solution_not_installed');
solution_assert(
    $planner->planUpgrade($manifest, ['example_solution' => ['version' => '2.0.0']])->reasonCode === 'downgrade_refused',
);

// The base compatibility range is the compatibility claim, and moving the base
// outside it is refused.
$derived = $validator->validate(solution_manifest([
    'solution_id' => 'derived_solution',
    'version' => '1.1.0',
    'base_solution' => ['solution_id' => 'minimal_cms', 'min_version' => '1.1.0', 'max_version' => '1.9.9'],
]));

$within = $planner->planUpgrade($derived, [
    'derived_solution' => ['version' => '1.0.0'],
    'minimal_cms' => ['version' => '1.2.0'],
]);
solution_assert($within->isActionable(), 'a base inside the range upgrades');

foreach (['1.0.0', '2.0.0'] as $outside) {
    solution_assert(
        $planner->planUpgrade($derived, [
            'derived_solution' => ['version' => '1.0.0'],
            'minimal_cms' => ['version' => $outside],
        ])->reasonCode === 'base_compatibility_broken',
        'a base at ' . $outside . ' is outside the declared range',
    );
}

solution_assert(
    $planner->planUpgrade($derived, ['derived_solution' => ['version' => '1.0.0']])->reasonCode
        === 'base_solution_absent',
);

// Both first manifests plan.
$docara = $validator->validate(solution_fixture('docara'));
solution_assert($planner->planInstall($docara)->isActionable(), 'the docara fixture plans');

$tracker = $validator->validate(solution_fixture('tracker'));
$trackerPlan = $planner->planUpgrade($tracker, [
    'tracker' => ['version' => '2.0.0'],
    'minimal_cms' => ['version' => '1.1.0'],
]);
solution_assert($trackerPlan->isActionable(), 'the tracker fixture upgrades on a compatible base');

echo "Solution planner conflict reporting passed.\n";
