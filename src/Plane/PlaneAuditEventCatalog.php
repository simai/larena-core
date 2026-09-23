<?php

declare(strict_types=1);

namespace Larena\Core\Plane;

/**
 * Audit event names for plane, node and membership mutations.
 */
final class PlaneAuditEventCatalog
{
    public const PLANE_CREATED = 'core.plane.created';
    public const PLANE_ARCHIVED = 'core.plane.archived';
    public const NODE_CREATED = 'core.plane.node.created';
    public const NODE_MOVED = 'core.plane.node.moved';
    public const NODE_ARCHIVED = 'core.plane.node.archived';
    public const MEMBERSHIP_ASSIGNED = 'core.plane.membership.assigned';
    public const MEMBERSHIP_REVOKED = 'core.plane.membership.revoked';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::PLANE_CREATED,
            self::PLANE_ARCHIVED,
            self::NODE_CREATED,
            self::NODE_MOVED,
            self::NODE_ARCHIVED,
            self::MEMBERSHIP_ASSIGNED,
            self::MEMBERSHIP_REVOKED,
        ];
    }
}
