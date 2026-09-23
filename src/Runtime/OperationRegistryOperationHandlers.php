<?php

declare(strict_types=1);

namespace Larena\Core\Runtime;

use Larena\Core\Contracts\OperationContext;
use Larena\Core\Contracts\OperationDescriptor;
use Larena\Core\Contracts\OperationHandler;
use Larena\Core\Contracts\OperationRegistry;
use Larena\Core\Enums\OperationExecutionMode;
use Larena\Core\Enums\OperationRiskClass;
use Larena\Core\Exceptions\ScopeBoundaryViolation;

/**
 * Reading the registry is itself a governed operation.
 *
 * "No button without an operation" applies to the catalogue too: an actor that
 * may not read the operation list does not get to read it through a side door.
 */
final readonly class OperationRegistryOperationHandlers implements OperationHandler
{
    public function __construct(private OperationRegistry $registry)
    {
    }

    /**
     * @return array<string, OperationDescriptor>
     */
    public static function descriptors(): array
    {
        $read = 'core.operation_registry.read';

        $descriptors = [
            new OperationDescriptor(
                name: 'core.operation_registry.describe',
                executionMode: OperationExecutionMode::Sync,
                accessScope: $read,
                riskClass: OperationRiskClass::Read,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'core.operation_registry.list',
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
            'core.operation_registry.describe' => $this->describe($context),
            'core.operation_registry.list' => $this->list($context),
            default => throw new ScopeBoundaryViolation(
                'unknown_operation',
                sprintf('Operation "%s" is not an operation-registry operation.', $descriptor->name),
            ),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(OperationContext $context): array
    {
        $name = $context->metadata['operation'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            throw new ScopeBoundaryViolation('invalid_input', 'Registry describe requires a non-empty "operation".');
        }

        return ['operation' => $this->registry->describe($name)->toArray()];
    }

    /**
     * @return array<string, mixed>
     */
    private function list(OperationContext $context): array
    {
        $package = $context->metadata['package'] ?? null;
        $risk = $context->metadata['risk'] ?? null;

        $declarations = $this->registry->list(
            is_string($package) && trim($package) !== '' ? $package : null,
            is_string($risk) ? OperationRiskClass::tryFrom($risk) : null,
        );

        return [
            'operations' => array_map(
                static fn ($declaration): array => $declaration->toArray(),
                $declarations,
            ),
        ];
    }
}
