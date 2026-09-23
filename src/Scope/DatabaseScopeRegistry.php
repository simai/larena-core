<?php

declare(strict_types=1);

namespace Larena\Core\Scope;

use Illuminate\Database\ConnectionInterface;
use Larena\Core\Contracts\ScopeRecord;
use Larena\Core\Contracts\ScopeRefResolver;
use Larena\Core\Contracts\ScopeRegistry;
use Larena\Core\Enums\ScopeKind;
use Larena\Core\Enums\ScopeStatus;
use Larena\Core\Exceptions\ScopeBoundaryViolation;

/**
 * Core-owned scope storage. Scopes are fixed-shape system records: no package
 * may duplicate them in Storage or in its own tables.
 */
final class DatabaseScopeRegistry implements ScopeRegistry, ScopeRefResolver
{
    public const TABLE = 'larena_core_scopes';

    public function __construct(private readonly ConnectionInterface $connection)
    {
    }

    /** @phpstan-impure */
    public function create(
        ScopeRef $ref,
        string $name,
        string $createdBy,
        ?ScopeRef $parentRef = null,
        ?string $correlationId = null,
    ): ScopeRecord {
        if ($this->row($ref->toString()) !== null) {
            throw ScopeBoundaryViolation::duplicate('scope', $ref->toString());
        }

        $parent = null;
        if ($parentRef !== null) {
            $parent = $this->read($parentRef);
            if ($parent === null) {
                throw ScopeBoundaryViolation::unknownScope($parentRef->toString());
            }
            if (!$parent->status->acceptsWrite()) {
                throw ScopeBoundaryViolation::archivedWrite('scope', $parentRef->toString());
            }
        }

        if (!$ref->kind->acceptsParent($parent?->kind)) {
            throw ScopeBoundaryViolation::invalidParent(sprintf(
                'Scope kind "%s" does not accept a parent of kind "%s".',
                $ref->kind->value,
                $parent?->kind->value ?? 'none',
            ));
        }

        $now = $this->now();
        $this->connection->table(self::TABLE)->insert([
            'scope_ref' => $ref->toString(),
            'kind' => $ref->kind->value,
            'identifier' => $ref->identifier,
            'parent_scope_ref' => $parentRef?->toString(),
            'name' => $name,
            'status' => ScopeStatus::Active->value,
            'created_by' => $createdBy,
            'correlation_id' => $correlationId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return new ScopeRecord($ref, $ref->kind, $parentRef, $name, ScopeStatus::Active, $createdBy, $correlationId);
    }

    /** @phpstan-impure */
    public function read(ScopeRef $ref): ?ScopeRecord
    {
        $row = $this->row($ref->toString());

        return $row === null ? null : $this->hydrate($row);
    }

    /** @phpstan-impure */
    public function list(?ScopeKind $kind = null, bool $includeArchived = false): array
    {
        $query = $this->connection->table(self::TABLE);
        if ($kind !== null) {
            $query->where('kind', $kind->value);
        }
        if (!$includeArchived) {
            $query->where('status', ScopeStatus::Active->value);
        }

        $rows = $query->orderBy('kind')->orderBy('identifier')->get();

        return array_map(fn (object $row): ScopeRecord => $this->hydrate($row), $this->toArray($rows));
    }

    /** @phpstan-impure */
    public function archive(ScopeRef $ref, string $actorId, ?string $correlationId = null): ScopeRecord
    {
        $record = $this->read($ref);
        if ($record === null) {
            throw ScopeBoundaryViolation::unknownScope($ref->toString());
        }

        $this->connection->table(self::TABLE)
            ->where('scope_ref', $ref->toString())
            ->update([
                'status' => ScopeStatus::Archived->value,
                'correlation_id' => $correlationId ?? $record->correlationId,
                'updated_at' => $this->now(),
            ]);

        return new ScopeRecord(
            $record->ref,
            $record->kind,
            $record->parentRef,
            $record->name,
            ScopeStatus::Archived,
            $record->createdBy,
            $correlationId ?? $record->correlationId,
        );
    }

    /** @phpstan-impure */
    public function explain(ScopeRef $ref): array
    {
        $record = $this->read($ref);

        return [
            'scope_ref' => $ref->toString(),
            'exists' => $record !== null,
            'kind' => $record?->kind->value,
            'status' => $record?->status->value,
            'parent_scope_ref' => $record?->parentRef?->toString(),
            'accepts_write' => $record?->status->acceptsWrite() ?? false,
        ];
    }

    /** @phpstan-impure */
    public function resolve(string $reference): ?ScopeRecord
    {
        $ref = ScopeRef::tryParse($reference);

        return $ref === null ? null : $this->read($ref);
    }

    /** @phpstan-impure */
    public function exists(string $reference): bool
    {
        return $this->resolve($reference) !== null;
    }

    /**
     * Fail-closed guard used by every package that binds a record to a scope.
          * @phpstan-impure
     */
    public function requireWritableScope(ScopeRef $ref): ScopeRecord
    {
        $record = $this->read($ref);
        if ($record === null) {
            throw ScopeBoundaryViolation::unknownScope($ref->toString());
        }
        if (!$record->status->acceptsWrite()) {
            throw ScopeBoundaryViolation::archivedWrite('scope', $ref->toString());
        }

        return $record;
    }

    private function row(string $scopeRef): ?object
    {
        $row = $this->connection->table(self::TABLE)->where('scope_ref', $scopeRef)->first();

        return is_object($row) ? $row : null;
    }

    private function hydrate(object $row): ScopeRecord
    {
        $parent = $row->parent_scope_ref === null ? null : ScopeRef::parse((string) $row->parent_scope_ref);

        return new ScopeRecord(
            ScopeRef::parse((string) $row->scope_ref),
            ScopeKind::from((string) $row->kind),
            $parent,
            (string) $row->name,
            ScopeStatus::from((string) $row->status),
            (string) $row->created_by,
            $row->correlation_id === null ? null : (string) $row->correlation_id,
        );
    }

    /**
     * @param mixed $rows
     * @return list<object>
     */
    private function toArray(mixed $rows): array
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
