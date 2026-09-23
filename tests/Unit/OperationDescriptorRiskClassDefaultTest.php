<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Larena\Core\Contracts\OperationDescriptor;
use Larena\Core\Enums\OperationExecutionMode;
use Larena\Core\Enums\OperationRiskClass;
use Larena\Core\Runtime\PlaneOperationHandlers;
use Larena\Core\Runtime\ScopeOperationHandlers;

// Existing descriptors stay valid: the risk fields are additive with fail-safe
// defaults, so an operation that declares nothing counts as a non-reversible
// change and never as a read.
$legacy = new OperationDescriptor(name: 'core.legacy.operation', executionMode: OperationExecutionMode::Sync);
assert($legacy->riskClass === null, 'an undeclared risk class stays null');
assert($legacy->effectiveRiskClass() === OperationRiskClass::Change, 'and resolves fail-safe to change');
assert($legacy->reversible === false);
assert(!$legacy->isReadOnly());
assert($legacy->alwaysRequiresConfirmation());
assert($legacy->allowedExecutionModes() === [OperationExecutionMode::Sync]);
assert($legacy->allowsExecutionMode(OperationExecutionMode::Sync));
assert(!$legacy->allowsExecutionMode(OperationExecutionMode::Queued));
assert($legacy->inputSchemaRef === null && $legacy->receiptSchemaRef === null);

// A declared read needs no confirmation; a bulk action always does.
$read = new OperationDescriptor(
    name: 'core.scope.read',
    executionMode: OperationExecutionMode::Sync,
    riskClass: OperationRiskClass::Read,
    reversible: true,
);
assert($read->isReadOnly() && !$read->alwaysRequiresConfirmation());

$bulk = new OperationDescriptor(
    name: 'core.plane.node.move',
    executionMode: OperationExecutionMode::Sync,
    riskClass: OperationRiskClass::Bulk,
    reversible: true,
);
assert($bulk->alwaysRequiresConfirmation());

// Every Batch 1 operation is declared with a scope, a risk class and, for
// mutations, an audit event and a transaction boundary.
$descriptors = ScopeOperationHandlers::descriptors() + PlaneOperationHandlers::descriptors();
assert(count($descriptors) === 16, 'the frozen Batch 1 operation list has 16 entries');

foreach ($descriptors as $name => $descriptor) {
    assert($descriptor->name === $name);
    assert($descriptor->requiresAccessDecision(), $name . ' must declare an access scope');
    if ($descriptor->isReadOnly()) {
        assert(!$descriptor->requiresAuditEvent(), $name . ' is a read and needs no audit event');
        continue;
    }
    assert($descriptor->requiresAuditEvent(), $name . ' must declare an audit event');
    assert($descriptor->requiresTransactionBoundary(), $name . ' must run inside a transaction');
    assert($descriptor->idempotencyKey !== null, $name . ' must declare an idempotency key');
    assert($descriptor->reversible, $name . ' must be reversible in this batch');
}

echo "Operation descriptor risk classes and Batch 1 declarations passed.\n";
