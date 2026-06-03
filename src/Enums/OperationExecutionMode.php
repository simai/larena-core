<?php

declare(strict_types=1);

namespace Larena\Core\Enums;

enum OperationExecutionMode: string
{
    case Sync = 'sync';
    case Queued = 'queued';
    case Scheduled = 'scheduled';
    case Denied = 'denied';

    public function isExecutable(): bool
    {
        return $this !== self::Denied;
    }
}
