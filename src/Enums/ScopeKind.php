<?php

declare(strict_types=1);

namespace Larena\Core\Enums;

enum ScopeKind: string
{
    case Site = 'site';
    case Organization = 'organization';

    public function acceptsParent(?self $parent): bool
    {
        return match ($this) {
            self::Organization => $parent === null,
            self::Site => $parent === null || $parent === self::Organization,
        };
    }
}
