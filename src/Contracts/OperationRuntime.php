<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

interface OperationRuntime
{
    public function decide(OperationDescriptor $descriptor, OperationContext $context): OperationDecision;

    public function execute(OperationDescriptor $descriptor, OperationContext $context): OperationResult;

    /**
     * @return array<string, mixed>
     */
    public function explain(OperationDescriptor $descriptor, OperationContext $context): array;
}
