<?php

declare(strict_types=1);

namespace Larena\Core\Enums;

enum PlaneStatus: string
{
    case Active = 'active';
    case Archived = 'archived';

    public function acceptsWrite(): bool
    {
        return $this === self::Active;
    }
}
