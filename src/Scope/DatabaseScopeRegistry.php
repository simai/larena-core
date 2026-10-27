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
final class DatabaseScopeRegistry implements ScopeRegistry, ScopeRefResolver, \Larena\Core\Contracts\ScopeLegacyAliases
{
    public const TABLE = 'larena_core_scopes';
    /** The older references stored data uses for scopes (owner decision 2026-10-12). */
    public const LEGACY_PATTERN = '/\\Ascope:[a-z][a-z0-9_.:-]{1,119}\\z/D';

    /**
     * Scope rows read in the last second. A list checks the scope of every row; the registry lives as long as the
     * process (a queue worker runs for hours), so what it read is forgotten after a second, on archiving and on naming
     * a legacy alias. A scope not found is never remembered.
     *
     * @var array<string,object>
     */
    private array $rows = [];

    private int $readAt = 0;

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
        $this->rows = [];

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

        return $ref === null ? $this->resolveLegacy($reference) : $this->read($ref);
    }

    /** @phpstan-impure */
    public function resolveLegacy(string $legacyRef): ?ScopeRecord
    {
        if (preg_match(self::LEGACY_PATTERN, $legacyRef) !== 1) {
            return null;
        }
        $row = $this->connection->table(self::TABLE)->where('legacy_ref', $legacyRef)->first();

        return is_object($row) ? $this->hydrate($row) : null;
    }

    /** @phpstan-impure */
    public function siteForLegacyRef(string $legacyRef, string $actorId, ?ScopeRef $site = null): ScopeRecord
    {
        $known = $this->resolveLegacy($legacyRef);
        if ($known !== null) {
            return $known;
        }
        if (preg_match('/\\Ascope:([a-z][a-z0-9_.:-]{1,119})\\z/D', $legacyRef, $match) !== 1) {
            throw ScopeBoundaryViolation::unknownScope($legacyRef);
        }
        $identifier = str_replace(['.', ':'], '-', $match[1]);
        if ($site === null && strlen($identifier) > 63) {
            throw ScopeBoundaryViolation::unknownScope($legacyRef);
        }
        $ref = $site ?? ScopeRef::of(\Larena\Core\Enums\ScopeKind::Site, $identifier);
        if ($this->read($ref) === null) {
            $this->create($ref, $match[1], $actorId);
        }

        return $this->assignLegacyRef($ref, $legacyRef);
    }

    /** @phpstan-impure */
    public function assignLegacyRef(ScopeRef $ref, string $legacyRef): ScopeRecord
    {
        if (preg_match(self::LEGACY_PATTERN, $legacyRef) !== 1) {
            throw ScopeBoundaryViolation::invalidParent('The legacy scope reference "'.$legacyRef.'" is not of the form scope:<id>.');
        }
        $record = $this->read($ref);
        if ($record === null) {
            throw ScopeBoundaryViolation::unknownScope($ref->toString());
        }
        if ($record->legacyRef === $legacyRef) {
            return $record;
        }
        $holder = $this->resolveLegacy($legacyRef);
        if ($holder !== null || $record->legacyRef !== null) {
            throw ScopeBoundaryViolation::duplicate('legacy scope reference', $legacyRef);
        }
        $this->connection->table(self::TABLE)->where('scope_ref', $ref->toString())
            ->update(['legacy_ref' => $legacyRef, 'updated_at' => $this->now()]);
        $this->rows = [];

        return $this->read($ref) ?? $record;
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
        if (hrtime(true) - $this->readAt > 1_000_000_000) {
            $this->rows = [];
            $this->readAt = hrtime(true);
        }
        if (isset($this->rows[$scopeRef])) {
            return $this->rows[$scopeRef];
        }
        $row = $this->connection->table(self::TABLE)->where('scope_ref', $scopeRef)->first();

        return is_object($row) ? $this->rows[$scopeRef] = $row : null;
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
            isset($row->legacy_ref) && is_string($row->legacy_ref) ? $row->legacy_ref : null,
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
