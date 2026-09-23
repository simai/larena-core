<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/operation-registry-fixtures.php';

use Larena\Core\Enums\OperationRiskClass;
use Larena\Core\Registry\CoreOperationProvider;
use Larena\Core\Registry\DeclaredOperationRegistry;
use Larena\Core\Runtime\RiskClassConfirmationPolicy;

$policy = new RiskClassConfirmationPolicy();

$cases = [
    [OperationRiskClass::Read, true, false, 'read_needs_no_confirmation'],
    [OperationRiskClass::Read, false, false, 'read_needs_no_confirmation'],
    [OperationRiskClass::Change, true, false, 'reversible_change_needs_no_confirmation'],
    [OperationRiskClass::Change, false, true, 'non_reversible_change_confirms'],
    [OperationRiskClass::Bulk, true, true, 'bulk_always_confirms'],
    [OperationRiskClass::Irreversible, true, true, 'irreversible_always_confirms'],
    [OperationRiskClass::External, true, true, 'external_always_confirms'],
];

foreach ($cases as [$risk, $reversible, $expected, $expectedReason]) {
    $declaration = larena_test_declaration([
        'riskClass' => $risk,
        'reversible' => $reversible,
        'auditEvent' => $risk->isRead() ? null : 'test.thing.changed',
        'receiptSchema' => $risk->isRead() ? null : ['type' => 'object', 'properties' => ['change' => ['type' => 'string']]],
    ]);

    assert(
        $policy->requiresConfirmation($declaration) === $expected,
        $risk->value . ' reversible=' . var_export($reversible, true) . ' expected ' . var_export($expected, true),
    );
    assert($policy->reasonCode($declaration) === $expectedReason, 'expected reason ' . $expectedReason);
}

// A read is never asked about, whatever it reads. Applied to the real core
// catalogue: every read passes unconfirmed, and the subtree move — which touches
// every descendant — always asks.
$registry = DeclaredOperationRegistry::fromProviders([new CoreOperationProvider()]);

foreach ($registry->list(null, OperationRiskClass::Read) as $read) {
    assert(!$policy->requiresConfirmation($read), $read->name . ' is a read and must not ask for confirmation');
}

assert($policy->requiresConfirmation($registry->describe('core.plane.node.move')), 'a subtree move must ask');
assert(!$policy->requiresConfirmation($registry->describe('core.scope.create')), 'a reversible create must not ask');

echo "Risk-class confirmation policy passed.\n";
