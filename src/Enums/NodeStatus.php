<?php

declare(strict_types=1);

namespace Larena\Core\Enums;

enum NodeStatus: string
{
    case Active = 'active';
    case Archived = 'archived';

    public function acceptsWrite(): bool
    {
        return $this === self::Active;
    }
}
