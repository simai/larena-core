<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/operation-registry-fixtures.php';

use Larena\Core\Enums\OperationRiskClass;
use Larena\Core\Exceptions\OperationNotRegistered;
use Larena\Core\Exceptions\OperationRegistrationRejected;
use Larena\Core\Registry\CoreOperationProvider;
use Larena\Core\Registry\DeclaredOperationRegistry;

$registry = new DeclaredOperationRegistry();
$declaration = larena_test_declaration();
$registry->register($declaration, larena_test_descriptor_for($declaration), 'core.handler.test');

assert($registry->has('test.thing.change'));
assert(!$registry->has('test.thing.absent'));
assert($registry->describe('test.thing.change')->name === 'test.thing.change');
assert($registry->descriptorFor('test.thing.change')->riskClass === OperationRiskClass::Change);
assert($registry->handlerRefFor('test.thing.change') === 'core.handler.test');

// An unknown name fails closed in every accessor, and nothing returns a
// permissive default.
foreach (['describe', 'descriptorFor', 'handlerRefFor'] as $method) {
    try {
        $registry->{$method}('test.thing.absent');
        throw new RuntimeException($method . ' must fail closed on an unknown operation');
    } catch (OperationNotRegistered $rejection) {
        assert($rejection->operationName === 'test.thing.absent');
    }
}

// A duplicate name is rejected.
try {
    $registry->register($declaration, larena_test_descriptor_for($declaration), 'core.handler.test');
    throw new RuntimeException('a duplicate operation name must be rejected');
} catch (OperationRegistrationRejected $rejection) {
    assert($rejection->reasonCode === 'duplicate_operation_name');
}

// The whole of core registers from its declaration file, which is the real
// proof that the declarations and the descriptors agree.
$coreRegistry = DeclaredOperationRegistry::fromProviders([new CoreOperationProvider()]);
$all = $coreRegistry->list();
assert(count($all) === 31, 'core registers every declared operation, got ' . count($all));
assert($coreRegistry->has('core.scope.create'));
assert($coreRegistry->has('core.transport.invoke_local'));
assert($coreRegistry->handlerRefFor('core.scope.create') === 'core.handler.scope');
assert($coreRegistry->handlerRefFor('core.plane.node.move') === 'core.handler.plane');
assert($coreRegistry->handlerRefFor('core.transport.resolve') === 'core.handler.transport');
assert($coreRegistry->handlerRefFor('core.operation_registry.list') === 'core.handler.operation_registry');
assert($coreRegistry->handlerRefFor('core.environment.verify') === 'core.handler.environment');

// list() is sorted and filterable.
$names = array_map(static fn ($d): string => $d->name, $all);
$sorted = $names;
sort($sorted);
assert($names === $sorted, 'the registry lists operations in a stable order');

$reads = $coreRegistry->list(null, OperationRiskClass::Read);
foreach ($reads as $read) {
    assert($read->riskClass === OperationRiskClass::Read);
}
assert(count($reads) < count($all) && $reads !== []);
assert($coreRegistry->list('larena/access') === [], 'core registers nothing on behalf of another package');
assert(count($coreRegistry->list('larena/core')) === count($all));

echo "Declared operation registry passed.\n";
