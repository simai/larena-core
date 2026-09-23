<?php

declare(strict_types=1);

namespace Larena\Core\Enums;

enum MembershipStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';
}
