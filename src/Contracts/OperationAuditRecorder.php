<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

interface OperationAuditRecorder
{
    /**
     * @return array<string, mixed>
     */
    public function recordDecision(
        OperationDescriptor $descriptor,
        OperationContext $context,
        OperationDecision $decision,
        string $phase,
    ): array;

    /**
     * @return array<string, mixed>
     */
    public function recordResult(
        OperationDescriptor $descriptor,
        OperationContext $context,
        OperationResult $result,
        string $phase,
    ): array;
}
