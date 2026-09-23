<?php

declare(strict_types=1);

namespace Larena\Core\Runtime;

use Larena\Core\Contracts\NodeTrustVerifier;
use Larena\Core\Contracts\OperationContext;
use Larena\Core\Contracts\OperationDescriptor;
use Larena\Core\Contracts\OperationHandler;
use Larena\Core\Contracts\TransportResolver;
use Larena\Core\Enums\OperationExecutionMode;
use Larena\Core\Enums\OperationRiskClass;
use Larena\Core\Exceptions\ScopeBoundaryViolation;
use Larena\Core\Exceptions\TransportResolutionFailed;

/**
 * Transport resolution, trust verification and local invocation as operations.
 *
 * `core.transport.invoke_remote` is deliberately absent: there is no network
 * transport to invoke, and registering an operation that can only fail would
 * report coverage that does not exist.
 */
final readonly class TransportOperationHandlers implements OperationHandler
{
    public function __construct(
        private TransportResolver $resolver,
        private ?NodeTrustVerifier $nodeTrust = null,
    ) {
    }

    /**
     * @return array<string, OperationDescriptor>
     */
    public static function descriptors(): array
    {
        $read = 'core.transport.read';

        $descriptors = [
            new OperationDescriptor(
                name: 'core.transport.resolve',
                executionMode: OperationExecutionMode::Sync,
                accessScope: $read,
                riskClass: OperationRiskClass::Read,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'core.transport.invoke_local',
                executionMode: OperationExecutionMode::Sync,
                accessScope: 'core.transport.invoke',
                auditEvent: TransportAuditEventCatalog::INVOKED,
                idempotencyKey: 'correlation_id',
                riskClass: OperationRiskClass::Change,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'core.transport.verify_node_trust',
                executionMode: OperationExecutionMode::Sync,
                accessScope: $read,
                riskClass: OperationRiskClass::Read,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'core.transport.explain',
                executionMode: OperationExecutionMode::Sync,
                accessScope: $read,
                riskClass: OperationRiskClass::Read,
                reversible: true,
            ),
        ];

        $indexed = [];
        foreach ($descriptors as $descriptor) {
            $indexed[$descriptor->name] = $descriptor;
        }

        return $indexed;
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(OperationDescriptor $descriptor, OperationContext $context): array
    {
        return match ($descriptor->name) {
            'core.transport.resolve', 'core.transport.explain' => $this->resolver
                ->explain($this->operationName($context))
                ->toArray(),
            'core.transport.verify_node_trust' => $this->verifyNodeTrust($context),
            'core.transport.invoke_local' => $this->invokeLocal($context),
            default => throw new ScopeBoundaryViolation(
                'unknown_operation',
                sprintf('Operation "%s" is not a transport operation.', $descriptor->name),
            ),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function verifyNodeTrust(OperationContext $context): array
    {
        $nodeId = $context->metadata['node_id'] ?? null;
        if (!is_string($nodeId) || trim($nodeId) === '') {
            throw new ScopeBoundaryViolation('invalid_input', 'Node trust verification requires a non-empty "node_id".');
        }

        // No verifier bound means no trust, not "trust everyone".
        return ['node_id' => $nodeId, 'trusted' => $this->nodeTrust?->isTrusted($nodeId) ?? false];
    }

    /**
     * @return array<string, mixed>
     */
    private function invokeLocal(OperationContext $context): array
    {
        $operationName = $this->operationName($context);

        try {
            $transport = $this->resolver->resolve($operationName);
        } catch (TransportResolutionFailed $failure) {
            return $failure->diagnostic->toArray() + [
                'operation' => $operationName,
                'decision_status' => 'invalid',
                'decision_reason' => $failure->diagnostic->reasonCode,
            ];
        }

        return [
            'operation' => $operationName,
            'decision_status' => 'allowed',
            'decision_reason' => 'transport_resolved',
            'transport_kind' => $transport->kind()->value,
        ];
    }

    private function operationName(OperationContext $context): string
    {
        $name = $context->metadata['operation'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            throw new ScopeBoundaryViolation('invalid_input', 'Transport operations require a non-empty "operation".');
        }

        return $name;
    }
}
