<?php

declare(strict_types=1);

namespace Larena\Core\Enums;

/**
 * Whether a host capability is there.
 *
 * `Unknown` exists so that a profile can be honest about what nobody could detect,
 * and it is treated as `Absent` everywhere. Treating it as present would fail at
 * the worst possible moment — the first time something actually needed it.
 */
enum CapabilityPresence: string
{
    case Present = 'present';
    case Absent = 'absent';
    case Unknown = 'unknown';

    public function isAvailable(): bool
    {
        return $this === self::Present;
    }
}
