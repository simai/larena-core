<?php

declare(strict_types=1);

namespace Larena\Core\Enums;

enum PlaneKind: string
{
    case Tree = 'tree';
    case Flat = 'flat';

    public function acceptsNesting(): bool
    {
        return $this === self::Tree;
    }
}
