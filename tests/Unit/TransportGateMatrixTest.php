<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/operation-registry-fixtures.php';

use Larena\Core\Contracts\DistributedEntitlementGate;
use Larena\Core\Contracts\NetworkTransport;
use Larena\Core\Contracts\NodeTrustVerifier;
use Larena\Core\Contracts\OperationContext;
use Larena\Core\Contracts\OperationDescriptor;
use Larena\Core\Contracts\OperationResult;
use Larena\Core\Enums\TransportKind;
use Larena\Core\Registry\DeclaredOperationRegistry;
use Larena\Core\Runtime\LocalTransport;
use Larena\Core\Runtime\ResolvingTransportResolver;
use Larena\Core\Runtime\StaticTopologyBinding;

final class LarenaTestEntitlementGate implements DistributedEntitlementGate
{
    public function __construct(private readonly bool $allow)
    {
    }

    public function allows(string $capability): bool
    {
        return $this->allow && $capability === self::REMOTE_OPERATIONS;
    }
}

final class LarenaTestNodeTrust implements NodeTrustVerifier
{
    public function __construct(private readonly bool $trusted)
    {
    }

    public function isTrusted(string $nodeId): bool
    {
        return $this->trusted;
    }
}

/**
 * A stand-in for the network transport package that does not exist in the open
 * core. It is used only to prove that the implementation gate is what is
 * missing, never to make a call.
 */
final class LarenaTestNetworkTransport implements NetworkTransport
{
    public int $calls = 0;

    public function kind(): TransportKind
    {
        return TransportKind::Network;
    }

    public function send(OperationDescriptor $descriptor, OperationContext $context): OperationResult
    {
        return $this->sendToNode('node-b', $descriptor, $context);
    }

    public function sendToNode(string $nodeId, OperationDescriptor $descriptor, OperationContext $context): OperationResult
    {
        ++$this->calls;

        throw new RuntimeException('This double must never actually be asked to carry a call.');
    }
}

$declaration = larena_test_declaration([
    'name' => 'test.thing.remote',
    'transports' => [TransportKind::Local, TransportKind::Network],
]);
$registry = new DeclaredOperationRegistry();
$registry->register($declaration, larena_test_descriptor_for($declaration), 'core.handler.test');

$handler = new LarenaTestProposalHandler();
$localTransport = new LocalTransport(larena_test_runtime($handler));
$topology = new StaticTopologyBinding('node-a', ['test.thing.remote' => 'node-b']);

/**
 * The gate matrix. Every row states which gates are bound and which the
 * diagnostic must report as missing, in the frozen order.
 *
 * @var list<array{0: bool, 1: bool, 2: bool, 3: list<string>, 4: string}>
 */
$matrix = [
    [false, false, false, ['distributed_entitlement', 'node_trust', 'network_transport_implementation'], 'entitlement_missing'],
    [true, false, false, ['node_trust', 'network_transport_implementation'], 'node_trust_failed'],
    [false, true, false, ['distributed_entitlement', 'network_transport_implementation'], 'entitlement_missing'],
    [true, true, false, ['network_transport_implementation'], 'transport_not_implemented'],
    [false, false, true, ['distributed_entitlement', 'node_trust'], 'entitlement_missing'],
    [true, false, true, ['node_trust'], 'node_trust_failed'],
    [false, true, true, ['distributed_entitlement'], 'entitlement_missing'],
];

$network = new LarenaTestNetworkTransport();

foreach ($matrix as [$entitled, $trusted, $implemented, $expectedMissing, $expectedReason]) {
    $resolver = new ResolvingTransportResolver(
        $registry,
        $localTransport,
        $topology,
        $implemented ? $network : null,
        new LarenaTestNodeTrust($trusted),
        new LarenaTestEntitlementGate($entitled),
    );

    $diagnostic = $resolver->explain('test.thing.remote');
    $missing = array_map(static fn ($gate): string => $gate->value, $diagnostic->missingGates);

    assert(!$diagnostic->resolved(), 'an incomplete gate set must not resolve');
    assert($missing === $expectedMissing, 'expected missing ' . implode(', ', $expectedMissing) . ', got ' . implode(', ', $missing));
    assert($diagnostic->reasonCode === $expectedReason, 'expected reason ' . $expectedReason . ', got ' . $diagnostic->reasonCode);
    assert($diagnostic->targetNodeId === 'node-b');

    // A diagnostic names gates and nodes only.
    $rendered = json_encode($diagnostic->toArray());
    assert(is_string($rendered));
    assert(!str_contains($rendered, 'secret') && !str_contains($rendered, 'token'));
}

// Unbound gates are absences, not permissions: with nothing bound at all the
// answer is the same as with every gate explicitly failing.
$nothingBound = new ResolvingTransportResolver($registry, $localTransport, $topology);
$bare = $nothingBound->explain('test.thing.remote');
assert(array_map(static fn ($gate): string => $gate->value, $bare->missingGates) === [
    'distributed_entitlement', 'node_trust', 'network_transport_implementation',
]);
assert($bare->reasonCode === 'entitlement_missing');

// With every gate satisfied the resolution reaches the network transport. This
// is the only row where a network resolution succeeds, and it exists to prove
// the gates are what stands in the way — nothing in the open core reaches it.
$complete = new ResolvingTransportResolver(
    $registry,
    $localTransport,
    $topology,
    $network,
    new LarenaTestNodeTrust(true),
    new LarenaTestEntitlementGate(true),
);
$resolved = $complete->explain('test.thing.remote');
assert($resolved->resolvedKind === TransportKind::Network);
assert($resolved->missingGates === []);
assert($resolved->reasonCode === 'network_transport_resolved');
assert($complete->resolve('test.thing.remote')->kind() === TransportKind::Network);
assert($network->calls === 0, 'resolving a transport must not send anything');
assert($handler->writeCount() === 0, 'no network row may execute locally');

echo "Transport gate matrix passed.\n";
