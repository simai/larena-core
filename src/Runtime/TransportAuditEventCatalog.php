<?php

declare(strict_types=1);

namespace Larena\Core\Runtime;

/**
 * Audit event names for transport invocations. Sanitized: operation, node ids
 * and correlation id only.
 */
final class TransportAuditEventCatalog
{
    public const INVOKED = 'core.transport.invoked';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::INVOKED];
    }
}
