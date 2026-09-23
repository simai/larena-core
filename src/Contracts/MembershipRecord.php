<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

use Larena\Core\Enums\MembershipStatus;

final readonly class MembershipRecord
{
    public function __construct(
        public string $membershipId,
        public string $nodeId,
        public string $subjectRef,
        public ?string $roleTag,
        public MembershipStatus $status,
        public string $createdBy,
        public ?string $correlationId = null,
    ) {
    }

    public static function identity(string $nodeId, string $subjectRef): string
    {
        return $nodeId . '@' . substr(hash('sha256', $subjectRef), 0, 32);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'membership_id' => $this->membershipId,
            'node_id' => $this->nodeId,
            'subject_ref' => $this->subjectRef,
            'role_tag' => $this->roleTag,
            'status' => $this->status->value,
        ];
    }
}
