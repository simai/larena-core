<?php

declare(strict_types=1);

namespace Larena\Core\Plane;

use Illuminate\Database\ConnectionInterface;
use Larena\Core\Contracts\MembershipRecord;
use Larena\Core\Contracts\MembershipResolver;
use Larena\Core\Contracts\PlaneNodeRecord;
use Larena\Core\Contracts\ResolvedMemberSet;
use Larena\Core\Enums\MembershipStatus;
use Larena\Core\Enums\NodeStatus;
use Larena\Core\Events\CommittedEvents;
use Larena\Core\Events\NodeMemberRemoved;
use Larena\Core\Exceptions\ScopeBoundaryViolation;

/**
 * Core-owned membership of subjects in plane nodes.
 *
 * The subject reference is an Auth EntryObject identifier and stays opaque to
 * core: users, services and AI actors are the same kind of subject. Membership
 * never grants a permission; Access grants role packs on nodes.
 */
final class DatabaseMembershipResolver implements MembershipResolver
{
    public const TABLE = 'larena_core_memberships';

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly DatabasePlaneRegistry $planes,
    ) {
    }

    /** @phpstan-impure */
    public function assign(
        string $nodeId,
        string $subjectRef,
        string $createdBy,
        ?string $roleTag = null,
        ?string $correlationId = null,
    ): MembershipRecord {
        $this->planes->requireWritableNode($nodeId);

        if (trim($subjectRef) === '') {
            throw new ScopeBoundaryViolation('invalid_subject', 'Membership subject reference must not be empty.');
        }

        $membershipId = MembershipRecord::identity($nodeId, $subjectRef);
        $existing = $this->row($membershipId);
        $now = $this->now();

        if ($existing === null) {
            $this->connection->table(self::TABLE)->insert([
                'membership_id' => $membershipId,
                'node_id' => $nodeId,
                'subject_ref' => $subjectRef,
                'role_tag' => $roleTag,
                'status' => MembershipStatus::Active->value,
                'created_by' => $createdBy,
                'correlation_id' => $correlationId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } else {
            $this->connection->table(self::TABLE)
                ->where('membership_id', $membershipId)
                ->update([
                    'role_tag' => $roleTag ?? ($existing->role_tag === null ? null : (string) $existing->role_tag),
                    'status' => MembershipStatus::Active->value,
                    'correlation_id' => $correlationId ?? ($existing->correlation_id === null ? null : (string) $existing->correlation_id),
                    'updated_at' => $now,
                ]);
        }

        return $this->require($membershipId);
    }

    /**
     * Revoking an active membership tells NodeMemberRemoved after commit; revoking one already revoked changes nothing
     * and tells nothing.
     *
     * @phpstan-impure
     */
    public function revoke(string $nodeId, string $subjectRef, string $actorId, ?string $correlationId = null): MembershipRecord
    {
        $membershipId = MembershipRecord::identity($nodeId, $subjectRef);
        $existing = $this->row($membershipId);
        if ($existing === null) {
            throw new ScopeBoundaryViolation('unknown_membership', sprintf('Membership "%s" does not exist.', $membershipId));
        }

        $this->connection->table(self::TABLE)
            ->where('membership_id', $membershipId)
            ->update([
                'status' => MembershipStatus::Revoked->value,
                'updated_at' => $this->now(),
            ]);

        if ((string) $existing->status === MembershipStatus::Active->value) {
            CommittedEvents::dispatch($this->connection, new NodeMemberRemoved(
                (string) $existing->node_id,
                (string) $existing->subject_ref,
                'revoked',
            ));
        }

        return $this->require($membershipId);
    }

    /**
     * Active members of the node, sorted by subject reference and bounded: at most $limit subjects (never more than
     * MEMBERS_MAX), starting after the subject $after. `complete` is false when more members follow; pass the last
     * subject as $after to read the next page.
     *
     * @phpstan-impure
     */
    public function membersOf(
        string $nodeId,
        bool $includeDescendants = false,
        int $limit = self::MEMBERS_MAX,
        ?string $after = null,
    ): ResolvedMemberSet {
        $limit = max(1, min(self::MEMBERS_MAX, $limit));
        $node = $this->planes->requireNode($nodeId);
        $nodeIds = [$node->nodeId];

        if ($includeDescendants) {
            // Only the node's subtree is read, not every node of the plane; the LIKE prefix may match a little more
            // (its wildcards are not escaped), so the path is checked again here.
            $prefix = $node->path->toString() . NodePath::SEPARATOR;
            $candidates = $this->connection->table(DatabasePlaneRegistry::NODE_TABLE)
                ->where('plane_id', $node->planeId)
                ->where('status', NodeStatus::Active->value)
                ->where('path', 'like', $prefix . '%')
                ->get(['node_id', 'path']);
            foreach ($this->rows($candidates) as $candidate) {
                if (str_starts_with((string) $candidate->path, $prefix) && (string) $candidate->node_id !== $node->nodeId) {
                    $nodeIds[] = (string) $candidate->node_id;
                }
            }
        }

        sort($nodeIds);

        $query = $this->connection->table(self::TABLE)
            ->whereIn('node_id', $nodeIds)
            ->where('status', MembershipStatus::Active->value);
        if ($after !== null) {
            $query->where('subject_ref', '>', $after);
        }

        $subjects = [];
        foreach ($this->rows($query->distinct()->orderBy('subject_ref')->limit($limit + 1)->get(['subject_ref'])) as $row) {
            $subjects[] = (string) $row->subject_ref;
        }
        $complete = count($subjects) <= $limit;

        return new ResolvedMemberSet($nodeId, $includeDescendants, array_slice($subjects, 0, $limit), $nodeIds, $complete);
    }

    /**
     * @return list<PlaneNodeRecord>
          * @phpstan-impure
     */
    public function nodesOf(string $subjectRef, ?string $planeId = null): array
    {
        $rows = $this->rows(
            $this->connection->table(self::TABLE)
                ->where('subject_ref', $subjectRef)
                ->where('status', MembershipStatus::Active->value)
                ->orderBy('node_id')
                ->get()
        );

        $nodes = [];
        foreach ($rows as $row) {
            $node = $this->planes->readNode((string) $row->node_id);
            if ($node === null) {
                continue;
            }
            if ($planeId !== null && $node->planeId !== $planeId) {
                continue;
            }
            $nodes[] = $node;
        }

        return $nodes;
    }

    /**
     * @return list<PlaneNodeRecord>
          * @phpstan-impure
     */
    public function ancestryOf(string $nodeId): array
    {
        $node = $this->planes->requireNode($nodeId);
        $byKey = [];
        foreach ($this->planes->listNodes($node->planeId, true) as $candidate) {
            $byKey[$candidate->nodeKey] = $candidate;
        }

        $ancestry = [];
        foreach ($node->path->segments as $segment) {
            if (isset($byKey[$segment])) {
                $ancestry[] = $byKey[$segment];
            }
        }

        return $ancestry;
    }

    private function require(string $membershipId): MembershipRecord
    {
        $row = $this->row($membershipId);
        if ($row === null) {
            throw new ScopeBoundaryViolation('unknown_membership', sprintf('Membership "%s" does not exist.', $membershipId));
        }

        return new MembershipRecord(
            (string) $row->membership_id,
            (string) $row->node_id,
            (string) $row->subject_ref,
            $row->role_tag === null ? null : (string) $row->role_tag,
            MembershipStatus::from((string) $row->status),
            (string) $row->created_by,
            $row->correlation_id === null ? null : (string) $row->correlation_id,
        );
    }

    private function row(string $membershipId): ?object
    {
        $row = $this->connection->table(self::TABLE)->where('membership_id', $membershipId)->first();

        return is_object($row) ? $row : null;
    }

    /** @return list<object> */
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
