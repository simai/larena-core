<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

interface OperationHandler
{
    /**
     * @return array<string, mixed>|null
     */
    public function handle(OperationDescriptor $descriptor, OperationContext $context): ?array;
}
