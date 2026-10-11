<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

use Larena\Core\Scope\ScopeRef;

/**
 * A resource bound to one scope, by type and reference (a logical file is `filesystem.logical_file`), so access can
 * tell whether it belongs to a scope without reading its owner's tables.
 */
interface ScopeResourceBindings
{
    /** Bind once; binding the same resource to the same scope again changes nothing, to another scope fails. */
    public function bind(string $resourceType, string $resourceRef, ScopeRef $scope): void;

    public function scopeOf(string $resourceType, string $resourceRef): ?ScopeRecord;

    public function belongsTo(string $resourceType, string $resourceRef, ScopeRef $scope): bool;

    /**
     * The resources of one type bound to a scope, in reference order, a page at a time.
     *
     * @return array{items:list<string>,next_cursor:?string}
     */
    public function listRefs(ScopeRef $scope, string $resourceType, ?string $cursor = null, int $limit = 50): array;
}
