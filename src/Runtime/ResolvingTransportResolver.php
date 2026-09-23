<?php

declare(strict_types=1);

namespace Larena\Core\Runtime;

use Larena\Core\Contracts\DistributedEntitlementGate;
use Larena\Core\Contracts\NetworkTransport;
use Larena\Core\Contracts\NodeTrustVerifier;
use Larena\Core\Contracts\OperationRegistry;
use Larena\Core\Contracts\OperationTransport;
use Larena\Core\Contracts\TopologyBinding;
use Larena\Core\Contracts\TransportDiagnostic;
use Larena\Core\Contracts\TransportResolver;
use Larena\Core\Enums\TransportGate;
use Larena\Core\Enums\TransportKind;
use Larena\Core\Exceptions\TransportResolutionFailed;

/**
 * Chooses the transport for one operation.
 *
 * Two rules carry the design. An operation with no topology binding is local,
 * so a single-node installation never pays for a distributed mode it does not
 * use. And a network resolution that fails is a denial, never a quiet local
 * execution: falling back would run the call against the wrong node's data,
 * which is the one outcome worse than an error.
 */
final readonly class ResolvingTransportResolver implements TransportResolver
{
    public function __construct(
        private OperationRegistry $registry,
        private OperationTransport $localTransport,
        private TopologyBinding $topology,
        private ?NetworkTransport $networkTransport = null,
        private ?NodeTrustVerifier $nodeTrust = null,
        private ?DistributedEntitlementGate $entitlement = null,
    ) {
    }

    public function explain(string $operationName): TransportDiagnostic
    {
        $localNodeId = $this->topology->localNodeId();

        if (!$this->registry->has($operationName)) {
            return new TransportDiagnostic(
                $operationName,
                null,
                $localNodeId,
                null,
                [],
                'operation_not_registered',
            );
        }

        $targetNodeId = $this->topology->nodeForOperation($operationName);

        if ($targetNodeId === null || $targetNodeId === $localNodeId) {
            return new TransportDiagnostic(
                $operationName,
                TransportKind::Local,
                $localNodeId,
                $targetNodeId,
                [],
                'local_transport_resolved',
            );
        }

        if (!$this->registry->describe($operationName)->allowsTransport(TransportKind::Network)) {
            return new TransportDiagnostic(
                $operationName,
                null,
                $localNodeId,
                $targetNodeId,
                [],
                'transport_not_allowed',
            );
        }

        $missing = $this->missingGates($targetNodeId);
        if ($missing !== []) {
            return new TransportDiagnostic(
                $operationName,
                null,
                $localNodeId,
                $targetNodeId,
                $missing,
                $missing[0]->reasonCode(),
            );
        }

        return new TransportDiagnostic(
            $operationName,
            TransportKind::Network,
            $localNodeId,
            $targetNodeId,
            [],
            'network_transport_resolved',
        );
    }

    public function resolve(string $operationName): OperationTransport
    {
        $diagnostic = $this->explain($operationName);

        if (!$diagnostic->resolved()) {
            throw new TransportResolutionFailed($diagnostic);
        }

        if ($diagnostic->resolvedKind === TransportKind::Local) {
            return $this->localTransport;
        }

        // Unreachable while the open core ships no network transport: the
        // implementation gate above is what keeps it that way, and this line
        // states the intent rather than trusting the check above to be the only
        // one ever written.
        return $this->networkTransport ?? throw new TransportResolutionFailed(new TransportDiagnostic(
            $operationName,
            null,
            $diagnostic->localNodeId,
            $diagnostic->targetNodeId,
            [TransportGate::NetworkTransportImplementation],
            TransportGate::NetworkTransportImplementation->reasonCode(),
        ));
    }

    /**
     * Every gate is evaluated, not just the first: an operator fixing a
     * distributed installation needs the whole list, and the reason code names
     * the one to start with.
     *
     * @return list<TransportGate>
     */
    private function missingGates(string $targetNodeId): array
    {
        $missing = [];

        foreach (TransportGate::ordered() as $gate) {
            $satisfied = match ($gate) {
                TransportGate::DistributedEntitlement => $this->entitlement !== null
                    && $this->entitlement->allows(DistributedEntitlementGate::REMOTE_OPERATIONS),
                TransportGate::NodeTrust => $this->nodeTrust !== null && $this->nodeTrust->isTrusted($targetNodeId),
                TransportGate::NetworkTransportImplementation => $this->networkTransport !== null,
            };

            if (!$satisfied) {
                $missing[] = $gate;
            }
        }

        return $missing;
    }
}
