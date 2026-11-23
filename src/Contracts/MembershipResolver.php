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
    /** The most subjects one membersOf() answer holds. */
    public const MEMBERS_MAX = 1000;

    public function assign(
        string $nodeId,
        string $subjectRef,
        string $createdBy,
        ?string $roleTag = null,
        ?string $correlationId = null,
    ): MembershipRecord;

    /**
     * Revoking an active membership dispatches Larena\Core\Events\NodeMemberRemoved once, after commit.
     */
    public function revoke(string $nodeId, string $subjectRef, string $actorId, ?string $correlationId = null): MembershipRecord;

    /**
     * Active members sorted by subject reference, at most $limit (clamped to 1..MEMBERS_MAX), after the subject $after.
     * The answer's `complete` is false when more members follow; read on with nextAfter() as $after.
     */
    public function membersOf(
        string $nodeId,
        bool $includeDescendants = false,
        int $limit = self::MEMBERS_MAX,
        ?string $after = null,
    ): ResolvedMemberSet;

    /**
     * @return list<PlaneNodeRecord>
     */
    public function nodesOf(string $subjectRef, ?string $planeId = null): array;

    /**
     * @return list<PlaneNodeRecord> root first, the node itself last
     */
    public function ancestryOf(string $nodeId): array;
}
