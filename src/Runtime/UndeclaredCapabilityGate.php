<?php

declare(strict_types=1);

namespace Larena\Core\Runtime;

use Larena\Core\Contracts\OperationCapabilityGate;
use Larena\Core\Contracts\OperationContext;
use Larena\Core\Contracts\OperationDecision;
use Larena\Core\Contracts\OperationDescriptor;

/**
 * The capability gate of a composition that declares no capabilities: an
 * operation that requires one is denied, never waved through.
 */
final readonly class UndeclaredCapabilityGate implements OperationCapabilityGate
{
    public function decideCapability(OperationDescriptor $descriptor, OperationContext $context): OperationDecision
    {
        return OperationDecision::denied('capability_unavailable', 'The required capability is not available here.');
    }
}
