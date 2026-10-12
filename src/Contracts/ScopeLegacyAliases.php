<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

use Larena\Core\Scope\ScopeRef;

/**
 * Older references (`scope:<id>`) that stored data still carries for a scope. Each scope has at most one and each
 * alias names one scope; the alias goes once the data uses the scope's own reference (owner decision 2026-10-12).
 */
interface ScopeLegacyAliases
{
    public function resolveLegacy(string $legacyRef): ?ScopeRecord;

    public function assignLegacyRef(ScopeRef $ref, string $legacyRef): ScopeRecord;

    /**
     * The site an older reference names, made when there is none: the given site, or `site:<id>` for `scope:<id>`
     * with `.` and `:` written as `-`.
     */
    public function siteForLegacyRef(string $legacyRef, string $actorId, ?ScopeRef $site = null): ScopeRecord;
}
