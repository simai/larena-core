<?php

declare(strict_types=1);

namespace Larena\Core\Runtime;

use Larena\Core\Contracts\TopologyBinding;

/**
 * Topology read from configuration.
 *
 * Nothing here probes the network: a node placement is a recorded decision, so
 * a misconfigured installation fails with a named gate instead of discovering a
 * host and trusting it.
 */
final readonly class StaticTopologyBinding implements TopologyBinding
{
    /**
     * @param array<string, string> $operationNodes operation name => node id
     */
    public function __construct(
        private string $localNodeId = 'local',
        private array $operationNodes = [],
    ) {
    }

    public function localNodeId(): string
    {
        return $this->localNodeId;
    }

    public function nodeForOperation(string $operationName): ?string
    {
        return $this->operationNodes[$operationName] ?? null;
    }
}
