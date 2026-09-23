<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

/**
 * Membership binds an Auth EntryObject reference to a plane node. Core stores
 * it and resolves it; it never authorizes. Access grants roles on nodes and
 * consumes these read contracts.
 */
interface MembershipResolver
{
    public function assign(
        string $nodeId,
        string $subjectRef,
        string $createdBy,
        ?string $roleTag = null,
        ?string $correlationId = null,
    ): MembershipRecord;

    public function revoke(string $nodeId, string $subjectRef, string $actorId, ?string $correlationId = null): MembershipRecord;

    public function membersOf(string $nodeId, bool $includeDescendants = false): ResolvedMemberSet;

    /**
     * @return list<PlaneNodeRecord>
     */
    public function nodesOf(string $subjectRef, ?string $planeId = null): array;

    /**
     * @return list<PlaneNodeRecord> root first, the node itself last
     */
    public function ancestryOf(string $nodeId): array;
}
