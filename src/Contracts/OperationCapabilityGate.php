<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

interface OperationCapabilityGate
{
    public function decideCapability(OperationDescriptor $descriptor, OperationContext $context): OperationDecision;
}
