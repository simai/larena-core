<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

/**
 * Where an operation is hosted.
 *
 * Topology is data, not discovery: this port is answered from configuration and
 * never probes the network.
 */
interface TopologyBinding
{
    public function localNodeId(): string;

    /**
     * @return string|null the node hosting this operation, or null when nothing is bound
     */
    public function nodeForOperation(string $operationName): ?string;
}
