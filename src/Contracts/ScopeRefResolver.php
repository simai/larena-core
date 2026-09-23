<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

/**
 * Resolves a raw "<kind>:<identifier>" string into a stored scope without a
 * database join from another package. Unknown or malformed references fail
 * closed by returning null; callers must treat null as denied.
 */
interface ScopeRefResolver
{
    public function resolve(string $reference): ?ScopeRecord;

    public function exists(string $reference): bool;
}
