<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

use Larena\Core\Enums\OperationDecisionStatus;

final readonly class OperationResult
{
    /**
     * @param array<string, mixed>|null $payload
     * @param array<string, mixed>|null $normalizedError
     * @param list<array<string, mixed>> $auditEvents
     * @param array<string, mixed> $runtimeTrace
     */
    public function __construct(
        public OperationDecision $decision,
        public ?array $payload = null,
        public ?array $normalizedError = null,
        public array $auditEvents = [],
        public array $runtimeTrace = [],
    ) {
    }

    /**
     * @param array<string, mixed>|null $payload
     * @param list<array<string, mixed>> $auditEvents
     * @param array<string, mixed> $runtimeTrace
     */
    public static function fromDecision(
        OperationDecision $decision,
        ?array $payload = null,
        array $auditEvents = [],
        array $runtimeTrace = [],
    ): self {
        $normalizedError = $decision->status === OperationDecisionStatus::Allowed
            ? null
            : [
                'code' => $decision->reasonCode,
                'message' => $decision->message,
            ];

        return new self($decision, $payload, $normalizedError, $auditEvents, $runtimeTrace);
    }

    public function successful(): bool
    {
        return $this->decision->status === OperationDecisionStatus::Allowed
            && $this->normalizedError === null;
    }
}
