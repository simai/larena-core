<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/operation-registry-fixtures.php';

use Larena\Core\Enums\TransportKind;
use Larena\Core\Exceptions\TransportResolutionFailed;
use Larena\Core\Registry\DeclaredOperationRegistry;
use Larena\Core\Runtime\LocalTransport;
use Larena\Core\Runtime\ResolvingTransportResolver;
use Larena\Core\Runtime\StaticTopologyBinding;

$local = larena_test_declaration(['name' => 'test.thing.change']);
$networkCapable = larena_test_declaration([
    'name' => 'test.thing.remote',
    'transports' => [TransportKind::Local, TransportKind::Network],
]);

$registry = new DeclaredOperationRegistry();
$registry->register($local, larena_test_descriptor_for($local), 'core.handler.test');
$registry->register($networkCapable, larena_test_descriptor_for($networkCapable), 'core.handler.test');

$handler = new LarenaTestProposalHandler();
$localTransport = new LocalTransport(larena_test_runtime($handler));

// No topology binding at all: everything is local, and the local transport
// executes with no entitlement gate bound anywhere.
$resolver = new ResolvingTransportResolver($registry, $localTransport, new StaticTopologyBinding('node-a'));
$diagnostic = $resolver->explain('test.thing.change');
assert($diagnostic->resolvedKind === TransportKind::Local);
assert($diagnostic->missingGates === []);
assert($diagnostic->reasonCode === 'local_transport_resolved');
assert($diagnostic->localNodeId === 'node-a');
assert($diagnostic->targetNodeId === null);
assert($resolver->resolve('test.thing.change')->kind() === TransportKind::Local);

$result = $resolver->resolve('test.thing.change')->send(
    $registry->descriptorFor('test.thing.change'),
    larena_test_context(),
);
assert($result->decision->handlerMayRun, 'the free local transport must execute');
assert($handler->wrote('test.thing.change'));

// A binding that names this node is local too.
$onThisNode = new ResolvingTransportResolver(
    $registry,
    $localTransport,
    new StaticTopologyBinding('node-a', ['test.thing.remote' => 'node-a']),
);
assert($onThisNode->explain('test.thing.remote')->resolvedKind === TransportKind::Local);

// An unregistered operation cannot be transported at all.
$unknown = $resolver->explain('test.thing.absent');
assert(!$unknown->resolved());
assert($unknown->reasonCode === 'operation_not_registered');
assert($unknown->missingGates === [], 'an unregistered operation has no gates to report');

// An operation that does not allow the network transport is refused before any
// gate is considered: the declaration decides where it may run.
$elsewhere = new ResolvingTransportResolver(
    $registry,
    $localTransport,
    new StaticTopologyBinding('node-a', ['test.thing.change' => 'node-b']),
);
$refused = $elsewhere->explain('test.thing.change');
assert(!$refused->resolved());
assert($refused->reasonCode === 'transport_not_allowed');
assert($refused->targetNodeId === 'node-b');

// And resolve() turns every unresolved diagnostic into a failure, never a local
// fallback: the handler must not have been reached.
$handler->forget();
try {
    $elsewhere->resolve('test.thing.change');
    throw new RuntimeException('an unresolvable transport must throw');
} catch (TransportResolutionFailed $failure) {
    assert($failure->diagnostic->reasonCode === 'transport_not_allowed');
}
assert($handler->writeCount() === 0, 'a failed resolution must never execute locally');

echo "Transport resolution passed.\n";
