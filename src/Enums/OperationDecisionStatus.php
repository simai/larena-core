<?php

declare(strict_types=1);

namespace Larena\Core\Enums;

enum OperationDecisionStatus: string
{
    case Allowed = 'allowed';
    case Denied = 'denied';
    case CapabilityLocked = 'capability_locked';
    case Invalid = 'invalid';
}
