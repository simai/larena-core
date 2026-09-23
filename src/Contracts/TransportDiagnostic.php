<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

use Larena\Core\Enums\TransportGate;
use Larena\Core\Enums\TransportKind;

/**
 * Why a transport resolution ended the way it did.
 *
 * It names gates and node identifiers. It never names credentials, host names
 * or trust material: a diagnostic is read by whoever can see a log line, which
 * is not the same as whoever may hold a secret.
 */
final readonly class TransportDiagnostic
{
    /**
     * @param list<TransportGate> $missingGates
     */
    public function __construct(
        public string $operation,
        public ?TransportKind $resolvedKind,
        public string $localNodeId,
        public ?string $targetNodeId,
        public array $missingGates,
        public string $reasonCode,
    ) {
    }

    public function resolved(): bool
    {
        return $this->resolvedKind !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'operation' => $this->operation,
            'resolved_kind' => $this->resolvedKind?->value,
            'local_node_id' => $this->localNodeId,
            'target_node_id' => $this->targetNodeId,
            'missing_gates' => array_map(static fn (TransportGate $gate): string => $gate->value, $this->missingGates),
            'reason_code' => $this->reasonCode,
        ];
    }
}
