<?php

declare(strict_types=1);

namespace Larena\Core\FirstRun;

final readonly class FirstRunAvailability
{
    public const AVAILABLE = 'available';
    public const COMPLETED = 'completed';
    public const EXISTING_INSTALL = 'existing_install';
    public const INCOMPATIBLE_PARTIAL = 'incompatible_partial';
    public const SCHEMA_MISSING = 'schema_missing';

    public function __construct(public string $state, public string $reason)
    {
        if (!in_array($state, [self::AVAILABLE, self::COMPLETED, self::EXISTING_INSTALL, self::INCOMPATIBLE_PARTIAL, self::SCHEMA_MISSING], true)) {
            throw new \InvalidArgumentException('Unknown first-run availability state.');
        }
    }

    public function isAvailable(): bool
    {
        return $this->state === self::AVAILABLE;
    }
}
