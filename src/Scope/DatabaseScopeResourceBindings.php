<?php

declare(strict_types=1);

namespace Larena\Core\Scope;

use Illuminate\Database\ConnectionInterface;
use InvalidArgumentException;
use Larena\Core\Contracts\ScopeRecord;
use Larena\Core\Contracts\ScopeResourceBindings;
use Larena\Core\Exceptions\ScopeBoundaryViolation;

/** Core-owned bindings of resources (logical files and the like) to one scope each. */
final class DatabaseScopeResourceBindings implements ScopeResourceBindings
{
    public const TABLE = 'larena_core_scope_resources';
    private const TYPE = '/\A[a-z][a-z0-9_.]{1,63}\z/D';

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly DatabaseScopeRegistry $scopes,
    ) {}

    /** @phpstan-impure */
    public function bind(string $resourceType, string $resourceRef, ScopeRef $scope): void
    {
        [$resourceType, $resourceRef] = self::identity($resourceType, $resourceRef);
        $this->scopes->requireWritableScope($scope);
        $bound = $this->connection->table(self::TABLE)->where('resource_type', $resourceType)->where('resource_ref', $resourceRef)->value('scope_ref');
        if ($bound === $scope->toString()) {
            return;
        }
        if ($bound !== null) {
            throw ScopeBoundaryViolation::duplicate('scope resource', $resourceType.':'.$resourceRef);
        }
        $this->connection->table(self::TABLE)->insert(['resource_type' => $resourceType, 'resource_ref' => $resourceRef,
            'scope_ref' => $scope->toString(), 'created_at' => gmdate('Y-m-d H:i:s')]);
    }

    /** @phpstan-impure */
    public function scopeOf(string $resourceType, string $resourceRef): ?ScopeRecord
    {
        [$resourceType, $resourceRef] = self::identity($resourceType, $resourceRef);
        $ref = $this->connection->table(self::TABLE)->where('resource_type', $resourceType)->where('resource_ref', $resourceRef)->value('scope_ref');

        return is_string($ref) ? $this->scopes->resolve($ref) : null;
    }

    /** @phpstan-impure */
    public function belongsTo(string $resourceType, string $resourceRef, ScopeRef $scope): bool
    {
        [$resourceType, $resourceRef] = self::identity($resourceType, $resourceRef);

        return $this->connection->table(self::TABLE)->where('resource_type', $resourceType)->where('resource_ref', $resourceRef)
            ->where('scope_ref', $scope->toString())->exists();
    }

    /** @phpstan-impure */
    public function listRefs(ScopeRef $scope, string $resourceType, ?string $cursor = null, int $limit = 50): array
    {
        if (preg_match(self::TYPE, $resourceType) !== 1) {
            throw new InvalidArgumentException('core_scope_resource_type_invalid');
        }
        $limit = max(1, min(500, $limit));
        $query = $this->connection->table(self::TABLE)->where('scope_ref', $scope->toString())->where('resource_type', $resourceType);
        if ($cursor !== null) {
            $query->where('resource_ref', '>', $cursor);
        }
        $refs = [];
        foreach ($query->orderBy('resource_ref')->limit($limit + 1)->pluck('resource_ref') as $ref) {
            $refs[] = (string) $ref;
        }
        $more = count($refs) > $limit;
        $items = array_slice($refs, 0, $limit);

        return ['items' => $items, 'next_cursor' => $more ? $items[count($items) - 1] : null];
    }

    /** @return array{0:string,1:string} */
    private static function identity(string $resourceType, string $resourceRef): array
    {
        if (preg_match(self::TYPE, $resourceType) !== 1 || $resourceRef === '' || strlen($resourceRef) > 191 || preg_match('/[\x00-\x1F\x7F]/', $resourceRef) === 1) {
            throw new InvalidArgumentException('core_scope_resource_invalid');
        }

        return [$resourceType, $resourceRef];
    }
}
