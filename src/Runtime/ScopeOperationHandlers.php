<?php

declare(strict_types=1);

namespace Larena\Core\Runtime;

use Larena\Core\Contracts\OperationContext;
use Larena\Core\Contracts\OperationDescriptor;
use Larena\Core\Contracts\OperationHandler;
use Larena\Core\Enums\OperationExecutionMode;
use Larena\Core\Enums\OperationRiskClass;
use Larena\Core\Enums\ScopeKind;
use Larena\Core\Exceptions\ScopeBoundaryViolation;
use Larena\Core\Scope\DatabaseScopeRegistry;
use Larena\Core\Scope\ScopeAuditEventCatalog;
use Larena\Core\Scope\ScopeRef;

/**
 * Declared scope operations. Every scope mutation an administrator can perform
 * exists once here, so Admin, REST, MCP, AI and another node are adapters over
 * the same operation rather than separate code paths.
 */
final class ScopeOperationHandlers implements OperationHandler
{
    public function __construct(private readonly DatabaseScopeRegistry $scopes)
    {
    }

    /**
     * @return array<string, OperationDescriptor>
     */
    public static function descriptors(): array
    {
        $descriptors = [
            new OperationDescriptor(
                name: 'core.scope.create',
                executionMode: OperationExecutionMode::Sync,
                accessScope: 'core.scope.manage',
                auditEvent: ScopeAuditEventCatalog::CREATED,
                idempotencyKey: 'scope_ref',
                transactional: true,
                riskClass: OperationRiskClass::Change,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'core.scope.read',
                executionMode: OperationExecutionMode::Sync,
                accessScope: 'core.scope.read',
                riskClass: OperationRiskClass::Read,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'core.scope.list',
                executionMode: OperationExecutionMode::Sync,
                accessScope: 'core.scope.read',
                riskClass: OperationRiskClass::Read,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'core.scope.archive',
                executionMode: OperationExecutionMode::Sync,
                accessScope: 'core.scope.manage',
                auditEvent: ScopeAuditEventCatalog::ARCHIVED,
                idempotencyKey: 'scope_ref',
                transactional: true,
                riskClass: OperationRiskClass::Change,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'core.scope.resolve_ref',
                executionMode: OperationExecutionMode::Sync,
                accessScope: 'core.scope.read',
                riskClass: OperationRiskClass::Read,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'core.scope.explain',
                executionMode: OperationExecutionMode::Sync,
                accessScope: 'core.scope.read',
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

    public function handle(OperationDescriptor $descriptor, OperationContext $context): array
    {
        return match ($descriptor->name) {
            'core.scope.create' => $this->create($context),
            'core.scope.read' => $this->read($context),
            'core.scope.list' => $this->list($context),
            'core.scope.archive' => $this->archive($context),
            'core.scope.resolve_ref' => $this->resolveRef($context),
            'core.scope.explain' => $this->explain($context),
            default => throw new ScopeBoundaryViolation('unknown_operation', sprintf('Operation "%s" is not a scope operation.', $descriptor->name)),
        };
    }

    /** @return array<string, mixed> */
    private function create(OperationContext $context): array
    {
        $ref = ScopeRef::parse($this->string($context, 'scope_ref'));
        $parent = $this->optionalString($context, 'parent_scope_ref');

        $record = $this->scopes->create(
            $ref,
            $this->string($context, 'name'),
            $context->actorId,
            $parent === null ? null : ScopeRef::parse($parent),
            $context->correlationId,
        );

        return ['scope' => $record->toArray()];
    }

    /** @return array<string, mixed> */
    private function read(OperationContext $context): array
    {
        $record = $this->scopes->read(ScopeRef::parse($this->string($context, 'scope_ref')));

        return ['scope' => $record?->toArray()];
    }

    /** @return array<string, mixed> */
    private function list(OperationContext $context): array
    {
        $kind = $this->optionalString($context, 'kind');
        $records = $this->scopes->list(
            $kind === null ? null : ScopeKind::from($kind),
            (bool) ($context->metadata['include_archived'] ?? false),
        );

        return ['scopes' => array_map(static fn ($record): array => $record->toArray(), $records)];
    }

    /** @return array<string, mixed> */
    private function archive(OperationContext $context): array
    {
        $record = $this->scopes->archive(
            ScopeRef::parse($this->string($context, 'scope_ref')),
            $context->actorId,
            $context->correlationId,
        );

        return ['scope' => $record->toArray()];
    }

    /** @return array<string, mixed> */
    private function resolveRef(OperationContext $context): array
    {
        $record = $this->scopes->resolve($this->string($context, 'scope_ref'));

        return ['resolved' => $record !== null, 'scope' => $record?->toArray()];
    }

    /** @return array<string, mixed> */
    private function explain(OperationContext $context): array
    {
        return $this->scopes->explain(ScopeRef::parse($this->string($context, 'scope_ref')));
    }

    private function string(OperationContext $context, string $key): string
    {
        $value = $context->metadata[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new ScopeBoundaryViolation('invalid_input', sprintf('Scope operation requires a non-empty "%s".', $key));
        }

        return $value;
    }

    private function optionalString(OperationContext $context, string $key): ?string
    {
        $value = $context->metadata[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
