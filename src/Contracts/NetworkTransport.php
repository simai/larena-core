<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

/**
 * The port a network transport package implements.
 *
 * The open core carries this interface and no implementation: a remote call is
 * a paid, separately delivered capability, and the resolver refuses a network
 * resolution while nothing is bound here.
 */
interface NetworkTransport extends OperationTransport
{
    /**
     * @return OperationResult the result reported by the node that owns the target
     */
    public function sendToNode(string $nodeId, OperationDescriptor $descriptor, OperationContext $context): OperationResult;
}
