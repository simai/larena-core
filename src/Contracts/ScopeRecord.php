<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

use Larena\Core\Enums\ScopeKind;
use Larena\Core\Enums\ScopeStatus;
use Larena\Core\Scope\ScopeRef;

final readonly class ScopeRecord
{
    public function __construct(
        public ScopeRef $ref,
        public ScopeKind $kind,
        public ?ScopeRef $parentRef,
        public string $name,
        public ScopeStatus $status,
        public string $createdBy,
        public ?string $correlationId = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'scope_ref' => $this->ref->toString(),
            'kind' => $this->kind->value,
            'parent_scope_ref' => $this->parentRef?->toString(),
            'name' => $this->name,
            'status' => $this->status->value,
        ];
    }
}
