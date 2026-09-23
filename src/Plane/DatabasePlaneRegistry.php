<?php

declare(strict_types=1);

namespace Larena\Core\Plane;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use Larena\Core\Contracts\PlaneNodeRecord;
use Larena\Core\Contracts\PlaneRecord;
use Larena\Core\Contracts\PlaneRegistry;
use Larena\Core\Enums\NodeStatus;
use Larena\Core\Enums\PlaneKind;
use Larena\Core\Enums\PlaneStatus;
use Larena\Core\Exceptions\ScopeBoundaryViolation;
use Larena\Core\Scope\DatabaseScopeRegistry;
use Larena\Core\Scope\ScopeRef;

/**
 * Core-owned planes and nodes. A plane belongs to exactly one scope and is
 * never moved between scopes; nodes use adjacency plus a materialized path.
 */
final class DatabasePlaneRegistry implements PlaneRegistry
{
    public const PLANE_TABLE = 'larena_core_planes';
    public const NODE_TABLE = 'larena_core_plane_nodes';

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly DatabaseScopeRegistry $scopes,
    ) {
    }

    /** @phpstan-impure */
    public function createPlane(
        ScopeRef $scopeRef,
        string $planeKey,
        PlaneKind $kind,
        string $name,
        string $createdBy,
        ?string $correlationId = null,
    ): PlaneRecord {
        $this->scopes->requireWritableScope($scopeRef);

        $planeId = PlaneRecord::identity($scopeRef, $planeKey);
        if ($this->planeRow($planeId) !== null) {
            throw ScopeBoundaryViolation::duplicate('plane', $planeId);
        }

        $now = $this->now();
        $this->connection->table(self::PLANE_TABLE)->insert([
            'plane_id' => $planeId,
            'scope_ref' => $scopeRef->toString(),
            'plane_key' => $planeKey,
            'kind' => $kind->value,
            'name' => $name,
            'status' => PlaneStatus::Active->value,
            'created_by' => $createdBy,
            'correlation_id' => $correlationId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return new PlaneRecord($planeId, $scopeRef, $planeKey, $kind, $name, PlaneStatus::Active, $createdBy, $correlationId);
    }

    /** @phpstan-impure */
    public function archivePlane(string $planeId, string $actorId, ?string $correlationId = null): PlaneRecord
    {
        $plane = $this->readPlane($planeId);
        if ($plane === null) {
            throw ScopeBoundaryViolation::unknownPlane($planeId);
        }

        $this->connection->table(self::PLANE_TABLE)
            ->where('plane_id', $planeId)
            ->update(['status' => PlaneStatus::Archived->value, 'updated_at' => $this->now()]);

        return new PlaneRecord(
            $plane->planeId,
            $plane->scopeRef,
            $plane->planeKey,
            $plane->kind,
            $plane->name,
            PlaneStatus::Archived,
            $plane->createdBy,
            $correlationId ?? $plane->correlationId,
        );
    }

    /** @phpstan-impure */
    public function readPlane(string $planeId): ?PlaneRecord
    {
        $row = $this->planeRow($planeId);

        return $row === null ? null : $this->hydratePlane($row);
    }

    /** @phpstan-impure */
    public function listPlanes(ScopeRef $scopeRef, bool $includeArchived = false): array
    {
        $query = $this->connection->table(self::PLANE_TABLE)->where('scope_ref', $scopeRef->toString());
        if (!$includeArchived) {
            $query->where('status', PlaneStatus::Active->value);
        }

        return array_map(
            fn (object $row): PlaneRecord => $this->hydratePlane($row),
            $this->rows($query->orderBy('plane_key')->get()),
        );
    }

    /** @phpstan-impure */
    public function createNode(
        string $planeId,
        string $nodeKey,
        string $name,
        string $createdBy,
        ?string $parentNodeId = null,
        ?int $orderIndex = null,
        ?string $correlationId = null,
    ): PlaneNodeRecord {
        $plane = $this->requireWritablePlane($planeId);

        $nodeId = PlaneNodeRecord::identity($planeId, $nodeKey);
        if ($this->nodeRow($nodeId) !== null) {
            throw ScopeBoundaryViolation::duplicate('node', $nodeId);
        }

        $path = $this->rootPath($nodeKey);
        if ($parentNodeId !== null) {
            if (!$plane->kind->acceptsNesting()) {
                throw ScopeBoundaryViolation::nestingDenied($planeId);
            }
            $parent = $this->requireWritableNode($parentNodeId);
            if ($parent->planeId !== $planeId) {
                throw ScopeBoundaryViolation::crossPlane($nodeId);
            }
            $path = $this->childPath($parent->path, $nodeKey);
        }

        $now = $this->now();
        $this->connection->table(self::NODE_TABLE)->insert([
            'node_id' => $nodeId,
            'plane_id' => $planeId,
            'parent_node_id' => $parentNodeId,
            'node_key' => $nodeKey,
            'path' => $path->toString(),
            'depth' => $path->depth(),
            'order_index' => $orderIndex ?? $this->nextOrderIndex($planeId, $parentNodeId),
            'name' => $name,
            'status' => NodeStatus::Active->value,
            'created_by' => $createdBy,
            'correlation_id' => $correlationId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->requireNode($nodeId);
    }

    /** @phpstan-impure */
    public function moveNode(
        string $nodeId,
        ?string $parentNodeId,
        string $actorId,
        ?int $orderIndex = null,
        ?string $correlationId = null,
    ): PlaneNodeRecord {
        $node = $this->requireWritableNode($nodeId);
        $plane = $this->requireWritablePlane($node->planeId);

        $path = $this->rootPath($node->nodeKey);
        if ($parentNodeId !== null) {
            if (!$plane->kind->acceptsNesting()) {
                throw ScopeBoundaryViolation::nestingDenied($node->planeId);
            }
            if ($parentNodeId === $nodeId) {
                throw ScopeBoundaryViolation::cycleDetected($nodeId);
            }
            $parent = $this->requireWritableNode($parentNodeId);
            if ($parent->planeId !== $node->planeId) {
                throw ScopeBoundaryViolation::crossPlane($nodeId);
            }
            if ($parent->path->isDescendantOf($node->path)) {
                throw ScopeBoundaryViolation::cycleDetected($nodeId);
            }
            $path = $this->childPath($parent->path, $node->nodeKey);
        }

        $descendants = $this->descendantRows($node->planeId, $node->path);
        $shift = $path->depth() - $node->path->depth();
        foreach ($descendants as $row) {
            $descendantPath = NodePath::parse((string) $row->path);
            if ($descendantPath->depth() + $shift >= NodePath::MAX_DEPTH) {
                throw ScopeBoundaryViolation::depthExceeded($descendantPath->depth() + $shift, NodePath::MAX_DEPTH - 1);
            }
        }

        $this->connection->transaction(function () use ($node, $path, $parentNodeId, $orderIndex, $correlationId, $descendants): void {
            $now = $this->now();
            $this->connection->table(self::NODE_TABLE)
                ->where('node_id', $node->nodeId)
                ->update([
                    'parent_node_id' => $parentNodeId,
                    'path' => $path->toString(),
                    'depth' => $path->depth(),
                    'order_index' => $orderIndex ?? $this->nextOrderIndex($node->planeId, $parentNodeId),
                    'correlation_id' => $correlationId ?? $node->correlationId,
                    'updated_at' => $now,
                ]);

            $prefix = $node->path->toString() . NodePath::SEPARATOR;
            foreach ($descendants as $row) {
                $suffix = substr((string) $row->path, strlen($prefix));
                $moved = NodePath::parse($path->toString() . NodePath::SEPARATOR . $suffix);
                $this->connection->table(self::NODE_TABLE)
                    ->where('node_id', (string) $row->node_id)
                    ->update(['path' => $moved->toString(), 'depth' => $moved->depth(), 'updated_at' => $now]);
            }
        });

        return $this->requireNode($nodeId);
    }

    /** @phpstan-impure */
    public function archiveNode(string $nodeId, string $actorId, ?string $correlationId = null): PlaneNodeRecord
    {
        $node = $this->readNode($nodeId);
        if ($node === null) {
            throw ScopeBoundaryViolation::unknownNode($nodeId);
        }

        $this->connection->table(self::NODE_TABLE)
            ->where('node_id', $nodeId)
            ->update(['status' => NodeStatus::Archived->value, 'updated_at' => $this->now()]);

        return $this->requireNode($nodeId);
    }

    /** @phpstan-impure */
    public function readNode(string $nodeId): ?PlaneNodeRecord
    {
        $row = $this->nodeRow($nodeId);

        return $row === null ? null : $this->hydrateNode($row);
    }

    /** @phpstan-impure */
    public function listNodes(string $planeId, bool $includeArchived = false): array
    {
        $query = $this->connection->table(self::NODE_TABLE)->where('plane_id', $planeId);
        if (!$includeArchived) {
            $query->where('status', NodeStatus::Active->value);
        }

        return array_map(
            fn (object $row): PlaneNodeRecord => $this->hydrateNode($row),
            $this->rows($query->orderBy('depth')->orderBy('order_index')->orderBy('node_key')->get()),
        );
    }

    /** @phpstan-impure */
    public function explain(string $planeId): array
    {
        $plane = $this->readPlane($planeId);

        return [
            'plane_id' => $planeId,
            'exists' => $plane !== null,
            'scope_ref' => $plane?->scopeRef->toString(),
            'kind' => $plane?->kind->value,
            'status' => $plane?->status->value,
            'accepts_write' => $plane?->status->acceptsWrite() ?? false,
            'node_count' => $plane === null ? 0 : count($this->listNodes($planeId)),
        ];
    }

    /** @phpstan-impure */
    public function requireWritablePlane(string $planeId): PlaneRecord
    {
        $plane = $this->readPlane($planeId);
        if ($plane === null) {
            throw ScopeBoundaryViolation::unknownPlane($planeId);
        }
        if (!$plane->status->acceptsWrite()) {
            throw ScopeBoundaryViolation::archivedWrite('plane', $planeId);
        }
        $this->scopes->requireWritableScope($plane->scopeRef);

        return $plane;
    }

    /** @phpstan-impure */
    public function requireWritableNode(string $nodeId): PlaneNodeRecord
    {
        $node = $this->requireNode($nodeId);
        if (!$node->status->acceptsWrite()) {
            throw ScopeBoundaryViolation::archivedWrite('node', $nodeId);
        }

        return $node;
    }

    /** @phpstan-impure */
    public function requireNode(string $nodeId): PlaneNodeRecord
    {
        $node = $this->readNode($nodeId);
        if ($node === null) {
            throw ScopeBoundaryViolation::unknownNode($nodeId);
        }

        return $node;
    }

    /**
     * Node paths fail closed with a stable reason code: a malformed key or a
     * depth overflow is a boundary violation, never a raw argument error.
     */
    private function rootPath(string $nodeKey): NodePath
    {
        try {
            return NodePath::root($nodeKey);
        } catch (InvalidArgumentException $error) {
            throw new ScopeBoundaryViolation('invalid_node_key', $error->getMessage());
        }
    }

    private function childPath(NodePath $parent, string $nodeKey): NodePath
    {
        if ($parent->depth() + 1 >= NodePath::MAX_DEPTH) {
            throw ScopeBoundaryViolation::depthExceeded($parent->depth() + 1, NodePath::MAX_DEPTH - 1);
        }

        try {
            return $parent->child($nodeKey);
        } catch (InvalidArgumentException $error) {
            throw new ScopeBoundaryViolation('invalid_node_key', $error->getMessage());
        }
    }

    /** @return list<object> */
    private function descendantRows(string $planeId, NodePath $path): array
    {
        $prefix = $path->toString() . NodePath::SEPARATOR;

        return $this->rows(
            $this->connection->table(self::NODE_TABLE)
                ->where('plane_id', $planeId)
                ->where('path', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $prefix) . '%')
                ->orderBy('depth')
                ->get()
        );
    }

    private function nextOrderIndex(string $planeId, ?string $parentNodeId): int
    {
        $query = $this->connection->table(self::NODE_TABLE)->where('plane_id', $planeId);
        $query = $parentNodeId === null
            ? $query->whereNull('parent_node_id')
            : $query->where('parent_node_id', $parentNodeId);

        $max = $query->max('order_index');

        return $max === null ? 0 : ((int) $max) + 1;
    }

    private function planeRow(string $planeId): ?object
    {
        $row = $this->connection->table(self::PLANE_TABLE)->where('plane_id', $planeId)->first();

        return is_object($row) ? $row : null;
    }

    private function nodeRow(string $nodeId): ?object
    {
        $row = $this->connection->table(self::NODE_TABLE)->where('node_id', $nodeId)->first();

        return is_object($row) ? $row : null;
    }

    private function hydratePlane(object $row): PlaneRecord
    {
        return new PlaneRecord(
            (string) $row->plane_id,
            ScopeRef::parse((string) $row->scope_ref),
            (string) $row->plane_key,
            PlaneKind::from((string) $row->kind),
            (string) $row->name,
            PlaneStatus::from((string) $row->status),
            (string) $row->created_by,
            $row->correlation_id === null ? null : (string) $row->correlation_id,
        );
    }

    private function hydrateNode(object $row): PlaneNodeRecord
    {
        return new PlaneNodeRecord(
            (string) $row->node_id,
            (string) $row->plane_id,
            $row->parent_node_id === null ? null : (string) $row->parent_node_id,
            (string) $row->node_key,
            NodePath::parse((string) $row->path),
            (int) $row->order_index,
            (string) $row->name,
            NodeStatus::from((string) $row->status),
            (string) $row->created_by,
            $row->correlation_id === null ? null : (string) $row->correlation_id,
        );
    }

    /**
     * @return list<object>
     */
    private function rows(mixed $rows): array
    {
        if (is_array($rows)) {
            return array_values($rows);
        }

        return array_values(method_exists($rows, 'all') ? $rows->all() : iterator_to_array($rows));
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
