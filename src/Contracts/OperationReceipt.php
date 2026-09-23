<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

use Larena\Core\Enums\OperationInvocationMode;
use Larena\Core\Enums\OperationRiskClass;

/**
 * What a proposal hands back: the change it would make, and the digest that an
 * approval must carry for the execution to be accepted.
 */
final readonly class OperationReceipt
{
    /**
     * @param array<string, mixed> $intendedChange
     */
    public function __construct(
        public string $operation,
        public OperationInvocationMode $invocationMode,
        public string $actorId,
        public string $correlationId,
        public OperationRiskClass $riskClass,
        public bool $reversible,
        public bool $confirmationRequired,
        public string $proposalDigest,
        public array $intendedChange,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'operation' => $this->operation,
            'invocation_mode' => $this->invocationMode->value,
            'actor_id' => $this->actorId,
            'correlation_id' => $this->correlationId,
            'risk_class' => $this->riskClass->value,
            'reversible' => $this->reversible,
            'confirmation_required' => $this->confirmationRequired,
            'proposal_digest' => $this->proposalDigest,
            'intended_change' => $this->intendedChange,
        ];
    }
}
