<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/operation-registry-fixtures.php';

use Larena\Core\Enums\OperationRiskClass;
use Larena\Core\Enums\TransportKind;
use Larena\Core\Registry\OperationDeclarationLoader;

$loader = new OperationDeclarationLoader();
$declarations = $loader->loadFile(dirname(__DIR__, 2) . '/operations.yaml', 'larena/core');

assert($declarations !== [], 'core must declare its operations');

$byName = [];
foreach ($declarations as $declaration) {
    $byName[$declaration->name] = $declaration;
}

// The kernel operations Batch 1 shipped are all declared.
foreach ([
    'core.scope.create', 'core.scope.read', 'core.scope.list', 'core.scope.archive',
    'core.scope.resolve_ref', 'core.scope.explain',
    'core.plane.create', 'core.plane.archive', 'core.plane.node.create', 'core.plane.node.move',
    'core.plane.node.archive', 'core.plane.membership.assign', 'core.plane.membership.revoke',
    'core.plane.resolve_members', 'core.plane.resolve_ancestry', 'core.plane.explain',
] as $expected) {
    assert(isset($byName[$expected]), $expected . ' must be declared');
}

// And so are the operations this batch introduces.
foreach ([
    'core.operation_registry.describe', 'core.operation_registry.list',
    'core.transport.resolve', 'core.transport.invoke_local',
    'core.transport.verify_node_trust', 'core.transport.explain',
] as $expected) {
    assert(isset($byName[$expected]), $expected . ' must be declared');
}

// core.transport.invoke_remote is deliberately absent while no network
// transport exists: registering it would report coverage that is not real.
assert(!isset($byName['core.transport.invoke_remote']), 'the remote invocation must not be declared yet');

$create = $byName['core.scope.create'];
assert($create->riskClass === OperationRiskClass::Change);
assert($create->reversible === true);
assert($create->transactional === true);
assert($create->idempotencyKey === 'scope_ref');
assert($create->auditEvent === 'core.scope.created');
assert($create->accessScope === 'core.scope.manage');
assert($create->allowsTransport(TransportKind::Local));
assert(!$create->allowsTransport(TransportKind::Network));
assert($create->receiptSchema !== null, 'a mutation must declare its proposal receipt');
assert($create->schemaRef('input') === 'larena/core:operations.yaml#core.scope.create.input');

$read = $byName['core.scope.read'];
assert($read->isRead());
assert($read->receiptSchema === null, 'a read has nothing to propose');
assert($read->auditEvent === null);

// core.plane.node.move is bulk: moving a subtree touches every descendant.
assert($byName['core.plane.node.move']->riskClass === OperationRiskClass::Bulk);

echo "Operation declaration loading passed.\n";
