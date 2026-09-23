<?php

declare(strict_types=1);

namespace Larena\Core\Plane;

use Illuminate\Database\ConnectionInterface;
use Larena\Core\Contracts\MembershipRecord;
use Larena\Core\Contracts\MembershipResolver;
use Larena\Core\Contracts\PlaneNodeRecord;
use Larena\Core\Contracts\ResolvedMemberSet;
use Larena\Core\Enums\MembershipStatus;
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

    /** @phpstan-impure */
    public function revoke(string $nodeId, string $subjectRef, string $actorId, ?string $correlationId = null): MembershipRecord
    {
        $membershipId = MembershipRecord::identity($nodeId, $subjectRef);
        if ($this->row($membershipId) === null) {
            throw new ScopeBoundaryViolation('unknown_membership', sprintf('Membership "%s" does not exist.', $membershipId));
        }

        $this->connection->table(self::TABLE)
            ->where('membership_id', $membershipId)
            ->update([
                'status' => MembershipStatus::Revoked->value,
                'updated_at' => $this->now(),
            ]);

        return $this->require($membershipId);
    }

    /** @phpstan-impure */
    public function membersOf(string $nodeId, bool $includeDescendants = false): ResolvedMemberSet
    {
        $node = $this->planes->requireNode($nodeId);
        $nodeIds = [$node->nodeId];

        if ($includeDescendants) {
            foreach ($this->planes->listNodes($node->planeId) as $candidate) {
                if ($candidate->path->isDescendantOf($node->path)) {
                    $nodeIds[] = $candidate->nodeId;
                }
            }
        }

        sort($nodeIds);

        $rows = $this->rows(
            $this->connection->table(self::TABLE)
                ->whereIn('node_id', $nodeIds)
                ->where('status', MembershipStatus::Active->value)
                ->orderBy('subject_ref')
                ->get()
        );

        $subjects = [];
        foreach ($rows as $row) {
            $subject = (string) $row->subject_ref;
            if (!in_array($subject, $subjects, true)) {
                $subjects[] = $subject;
            }
        }

        return new ResolvedMemberSet($nodeId, $includeDescendants, $subjects, $nodeIds);
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
