<?php

declare(strict_types=1);

namespace Larena\Core\Runtime;

use Larena\Core\Contracts\OperationContext;
use Larena\Core\Contracts\OperationDescriptor;
use Larena\Core\Contracts\OperationHandler;
use Larena\Core\Contracts\OperationProposalHandler;
use Larena\Core\Enums\OperationExecutionMode;
use Larena\Core\Enums\OperationRiskClass;
use Larena\Core\Enums\PlaneKind;
use Larena\Core\Exceptions\ScopeBoundaryViolation;
use Larena\Core\Plane\DatabaseMembershipResolver;
use Larena\Core\Plane\DatabasePlaneRegistry;
use Larena\Core\Plane\PlaneAuditEventCatalog;
use Larena\Core\Scope\ScopeRef;

/**
 * Declared plane, node and membership operations. Core stores and resolves
 * them; products and solutions decide what a plane means.
 */
final class PlaneOperationHandlers implements OperationHandler, OperationProposalHandler
{
    public function __construct(
        private readonly DatabasePlaneRegistry $planes,
        private readonly DatabaseMembershipResolver $memberships,
    ) {
    }

    /**
     * @return array<string, OperationDescriptor>
     */
    public static function descriptors(): array
    {
        $manage = 'core.plane.manage';
        $read = 'core.plane.read';
        $membership = 'core.plane.membership.manage';

        $descriptors = [
            new OperationDescriptor(
                name: 'core.plane.create',
                executionMode: OperationExecutionMode::Sync,
                accessScope: $manage,
                auditEvent: PlaneAuditEventCatalog::PLANE_CREATED,
                idempotencyKey: 'plane_id',
                transactional: true,
                riskClass: OperationRiskClass::Change,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'core.plane.archive',
                executionMode: OperationExecutionMode::Sync,
                accessScope: $manage,
                auditEvent: PlaneAuditEventCatalog::PLANE_ARCHIVED,
                idempotencyKey: 'plane_id',
                transactional: true,
                riskClass: OperationRiskClass::Change,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'core.plane.node.create',
                executionMode: OperationExecutionMode::Sync,
                accessScope: $manage,
                auditEvent: PlaneAuditEventCatalog::NODE_CREATED,
                idempotencyKey: 'node_id',
                transactional: true,
                riskClass: OperationRiskClass::Change,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'core.plane.node.move',
                executionMode: OperationExecutionMode::Sync,
                accessScope: $manage,
                auditEvent: PlaneAuditEventCatalog::NODE_MOVED,
                idempotencyKey: 'node_id',
                transactional: true,
                riskClass: OperationRiskClass::Bulk,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'core.plane.node.archive',
                executionMode: OperationExecutionMode::Sync,
                accessScope: $manage,
                auditEvent: PlaneAuditEventCatalog::NODE_ARCHIVED,
                idempotencyKey: 'node_id',
                transactional: true,
                riskClass: OperationRiskClass::Change,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'core.plane.membership.assign',
                executionMode: OperationExecutionMode::Sync,
                accessScope: $membership,
                auditEvent: PlaneAuditEventCatalog::MEMBERSHIP_ASSIGNED,
                idempotencyKey: 'membership_id',
                transactional: true,
                riskClass: OperationRiskClass::Change,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'core.plane.membership.revoke',
                executionMode: OperationExecutionMode::Sync,
                accessScope: $membership,
                auditEvent: PlaneAuditEventCatalog::MEMBERSHIP_REVOKED,
                idempotencyKey: 'membership_id',
                transactional: true,
                riskClass: OperationRiskClass::Change,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'core.plane.resolve_members',
                executionMode: OperationExecutionMode::Sync,
                accessScope: $read,
                riskClass: OperationRiskClass::Read,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'core.plane.resolve_ancestry',
                executionMode: OperationExecutionMode::Sync,
                accessScope: $read,
                riskClass: OperationRiskClass::Read,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'core.plane.explain',
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

    public function handle(OperationDescriptor $descriptor, OperationContext $context): array
    {
        return match ($descriptor->name) {
            'core.plane.create' => $this->createPlane($context),
            'core.plane.archive' => $this->archivePlane($context),
            'core.plane.node.create' => $this->createNode($context),
            'core.plane.node.move' => $this->moveNode($context),
            'core.plane.node.archive' => $this->archiveNode($context),
            'core.plane.membership.assign' => $this->assign($context),
            'core.plane.membership.revoke' => $this->revoke($context),
            'core.plane.resolve_members' => $this->resolveMembers($context),
            'core.plane.resolve_ancestry' => $this->resolveAncestry($context),
            'core.plane.explain' => $this->planes->explain($this->string($context, 'plane_id')),
            default => throw new ScopeBoundaryViolation('unknown_operation', sprintf('Operation "%s" is not a plane operation.', $descriptor->name)),
        };
    }

    /**
     * What a plane change would do, read without making it. A move names the
     * node's current ancestry, so an approver sees where it leaves from.
     *
     * @return array<string, mixed>
     */
    public function propose(OperationDescriptor $descriptor, OperationContext $context): array
    {
        return match ($descriptor->name) {
            'core.plane.node.create' => [
                'kind' => 'create_node',
                'plane_id' => $this->string($context, 'plane_id'),
                'node_key' => $this->string($context, 'node_key'),
                'parent_node_id' => $this->optionalString($context, 'parent_node_id'),
            ],
            'core.plane.node.move' => [
                'kind' => 'move_node',
                'node_id' => $this->string($context, 'node_id'),
                'parent_node_id' => $this->optionalString($context, 'parent_node_id'),
                'order_index' => $this->optionalInt($context, 'order_index'),
                'current_ancestry' => array_map(
                    static fn ($node): string => $node->nodeId,
                    $this->memberships->ancestryOf($this->string($context, 'node_id')),
                ),
            ],
            'core.plane.node.archive' => ['kind' => 'archive_node', 'node_id' => $this->string($context, 'node_id')],
            'core.plane.membership.assign', 'core.plane.membership.revoke' => [
                'kind' => $descriptor->name === 'core.plane.membership.assign' ? 'assign_membership' : 'revoke_membership',
                'node_id' => $this->string($context, 'node_id'),
                'subject_ref' => $this->string($context, 'subject_ref'),
            ],
            default => throw new \Larena\Core\Exceptions\OperationProposalUnsupported($descriptor->name),
        };
    }

    /** @return array<string, mixed> */
    private function createPlane(OperationContext $context): array
    {
        $record = $this->planes->createPlane(
            ScopeRef::parse($this->string($context, 'scope_ref')),
            $this->string($context, 'plane_key'),
            PlaneKind::from($this->string($context, 'kind')),
            $this->string($context, 'name'),
            $context->actorId,
            $context->correlationId,
        );

        return ['plane' => $record->toArray()];
    }

    /** @return array<string, mixed> */
    private function archivePlane(OperationContext $context): array
    {
        $record = $this->planes->archivePlane($this->string($context, 'plane_id'), $context->actorId, $context->correlationId);

        return ['plane' => $record->toArray()];
    }

    /** @return array<string, mixed> */
    private function createNode(OperationContext $context): array
    {
        $record = $this->planes->createNode(
            $this->string($context, 'plane_id'),
            $this->string($context, 'node_key'),
            $this->string($context, 'name'),
            $context->actorId,
            $this->optionalString($context, 'parent_node_id'),
            $this->optionalInt($context, 'order_index'),
            $context->correlationId,
        );

        return ['node' => $record->toArray()];
    }

    /** @return array<string, mixed> */
    private function moveNode(OperationContext $context): array
    {
        $record = $this->planes->moveNode(
            $this->string($context, 'node_id'),
            $this->optionalString($context, 'parent_node_id'),
            $context->actorId,
            $this->optionalInt($context, 'order_index'),
            $context->correlationId,
        );

        return ['node' => $record->toArray()];
    }

    /** @return array<string, mixed> */
    private function archiveNode(OperationContext $context): array
    {
        $record = $this->planes->archiveNode($this->string($context, 'node_id'), $context->actorId, $context->correlationId);

        return ['node' => $record->toArray()];
    }

    /** @return array<string, mixed> */
    private function assign(OperationContext $context): array
    {
        $record = $this->memberships->assign(
            $this->string($context, 'node_id'),
            $this->string($context, 'subject_ref'),
            $context->actorId,
            $this->optionalString($context, 'role_tag'),
            $context->correlationId,
        );

        return ['membership' => $record->toArray()];
    }

    /** @return array<string, mixed> */
    private function revoke(OperationContext $context): array
    {
        $record = $this->memberships->revoke(
            $this->string($context, 'node_id'),
            $this->string($context, 'subject_ref'),
            $context->actorId,
            $context->correlationId,
        );

        return ['membership' => $record->toArray()];
    }

    /** @return array<string, mixed> */
    private function resolveMembers(OperationContext $context): array
    {
        $set = $this->memberships->membersOf(
            $this->string($context, 'node_id'),
            (bool) ($context->metadata['include_descendants'] ?? false),
        );

        return [
            'node_id' => $set->nodeId,
            'include_descendants' => $set->includeDescendants,
            'subject_refs' => $set->subjectRefs,
            'node_ids' => $set->nodeIds,
        ];
    }

    /** @return array<string, mixed> */
    private function resolveAncestry(OperationContext $context): array
    {
        $ancestry = $this->memberships->ancestryOf($this->string($context, 'node_id'));

        return ['ancestry' => array_map(static fn ($node): array => $node->toArray(), $ancestry)];
    }

    private function string(OperationContext $context, string $key): string
    {
        $value = $context->metadata[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new ScopeBoundaryViolation('invalid_input', sprintf('Plane operation requires a non-empty "%s".', $key));
        }

        return $value;
    }

    private function optionalString(OperationContext $context, string $key): ?string
    {
        $value = $context->metadata[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function optionalInt(OperationContext $context, string $key): ?int
    {
        $value = $context->metadata[$key] ?? null;

        return is_int($value) ? $value : null;
    }
}
