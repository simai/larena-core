<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

use Larena\Core\Enums\ScopeKind;
use Larena\Core\Scope\ScopeRef;

/**
 * Core owns scopes as fixed-shape system records. A site may exist without an
 * organization; an organization never has a parent.
 */
interface ScopeRegistry
{
    public function create(
        ScopeRef $ref,
        string $name,
        string $createdBy,
        ?ScopeRef $parentRef = null,
        ?string $correlationId = null,
    ): ScopeRecord;

    public function read(ScopeRef $ref): ?ScopeRecord;

    /**
     * @return list<ScopeRecord>
     */
    public function list(?ScopeKind $kind = null, bool $includeArchived = false): array;

    public function archive(ScopeRef $ref, string $actorId, ?string $correlationId = null): ScopeRecord;

    /**
     * @return array<string, mixed>
     */
    public function explain(ScopeRef $ref): array;
}
