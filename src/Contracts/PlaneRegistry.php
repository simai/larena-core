<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

use Larena\Core\Enums\PlaneKind;
use Larena\Core\Scope\ScopeRef;

/**
 * Core stores planes, nodes and their order and gives them no business
 * meaning. Products and solutions decide what a plane represents.
 */
interface PlaneRegistry
{
    public function createPlane(
        ScopeRef $scopeRef,
        string $planeKey,
        PlaneKind $kind,
        string $name,
        string $createdBy,
        ?string $correlationId = null,
    ): PlaneRecord;

    public function archivePlane(string $planeId, string $actorId, ?string $correlationId = null): PlaneRecord;

    public function readPlane(string $planeId): ?PlaneRecord;

    /**
     * @return list<PlaneRecord>
     */
    public function listPlanes(ScopeRef $scopeRef, bool $includeArchived = false): array;

    public function createNode(
        string $planeId,
        string $nodeKey,
        string $name,
        string $createdBy,
        ?string $parentNodeId = null,
        ?int $orderIndex = null,
        ?string $correlationId = null,
    ): PlaneNodeRecord;

    public function moveNode(
        string $nodeId,
        ?string $parentNodeId,
        string $actorId,
        ?int $orderIndex = null,
        ?string $correlationId = null,
    ): PlaneNodeRecord;

    public function archiveNode(string $nodeId, string $actorId, ?string $correlationId = null): PlaneNodeRecord;

    public function readNode(string $nodeId): ?PlaneNodeRecord;

    /**
     * @return list<PlaneNodeRecord>
     */
    public function listNodes(string $planeId, bool $includeArchived = false): array;

    /**
     * @return array<string, mixed>
     */
    public function explain(string $planeId): array;
}
