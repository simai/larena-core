<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

use Larena\Core\Enums\PlaneKind;
use Larena\Core\Enums\PlaneStatus;
use Larena\Core\Scope\ScopeRef;

final readonly class PlaneRecord
{
    public function __construct(
        public string $planeId,
        public ScopeRef $scopeRef,
        public string $planeKey,
        public PlaneKind $kind,
        public string $name,
        public PlaneStatus $status,
        public string $createdBy,
        public ?string $correlationId = null,
    ) {
    }

    public static function identity(ScopeRef $scopeRef, string $planeKey): string
    {
        return $scopeRef->toString() . '/' . $planeKey;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'plane_id' => $this->planeId,
            'scope_ref' => $this->scopeRef->toString(),
            'plane_key' => $this->planeKey,
            'kind' => $this->kind->value,
            'name' => $this->name,
            'status' => $this->status->value,
        ];
    }
}
