<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

use Larena\Core\Enums\TransportKind;

interface OperationTransport
{
    public function kind(): TransportKind;

    public function send(OperationDescriptor $descriptor, OperationContext $context): OperationResult;
}
