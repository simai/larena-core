<?php

declare(strict_types=1);

namespace Larena\Core\Events;

/**
 * A subject left a plane node: its active membership was revoked (`revoked`, audited as
 * `core.plane.membership.revoked`). Rights granted on the node, or on an ancestor with descendants, no longer reach the
 * subject through this node. Dispatched once, after the change is committed; never for a change that was rolled back,
 * and never for a membership that was already revoked.
 */
final readonly class NodeMemberRemoved
{
    public function __construct(
        public string $nodeId,
        public string $subjectRef,
        /** `revoked`. */
        public string $reason,
    ) {
    }

    /** @return array{node_id:string,subject_ref:string,reason:string} */
    public function toArray(): array
    {
        return ['node_id' => $this->nodeId, 'subject_ref' => $this->subjectRef, 'reason' => $this->reason];
    }
}
