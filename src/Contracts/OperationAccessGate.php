<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

interface OperationAccessGate
{
    public function decideAccess(OperationDescriptor $descriptor, OperationContext $context): OperationDecision;
}
