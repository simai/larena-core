<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

use Larena\Core\Enums\CapabilityPresence;
use Larena\Core\Enums\EnvironmentCapability;

/**
 * One host capability and whether it is there.
 */
final readonly class EnvironmentCapabilityState
{
    public function __construct(
        public EnvironmentCapability $capability,
        public CapabilityPresence $presence,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->presence->isAvailable();
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return ['capability' => $this->capability->value, 'presence' => $this->presence->value];
    }
}
