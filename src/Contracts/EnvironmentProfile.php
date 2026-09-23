<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

use Larena\Core\Enums\CapabilityPresence;
use Larena\Core\Enums\EnvironmentCapability;

interface EnvironmentProfile
{
    public function presence(EnvironmentCapability $capability): CapabilityPresence;

    /**
     * Whether the host actually offers this capability. Unknown counts as absent.
     */
    public function provides(EnvironmentCapability $capability): bool;

    /**
     * @return list<EnvironmentCapabilityState>
     */
    public function states(): array;

    /**
     * @param list<EnvironmentCapability> $required
     */
    public function verify(string $requiredBy, array $required): EnvironmentVerification;

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array;
}
