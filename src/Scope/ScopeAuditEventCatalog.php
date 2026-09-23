<?php

declare(strict_types=1);

namespace Larena\Core\Scope;

/**
 * Audit event names for scope mutations. Core emits sanitized events: actor,
 * scope reference and correlation id only.
 */
final class ScopeAuditEventCatalog
{
    public const CREATED = 'core.scope.created';
    public const ARCHIVED = 'core.scope.archived';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::CREATED, self::ARCHIVED];
    }
}
