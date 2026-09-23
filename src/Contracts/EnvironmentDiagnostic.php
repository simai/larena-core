<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

use Larena\Core\Enums\CapabilityPresence;
use Larena\Core\Enums\EnvironmentCapability;

/**
 * A difference between what was declared and what was detected.
 *
 * It names a capability and two presence values. It never names a path, a
 * credential, a host or anything else about the machine: a diagnostic is read by
 * whoever can see a log line, which is not the same as whoever may see the server.
 */
final readonly class EnvironmentDiagnostic
{
    public function __construct(
        public EnvironmentCapability $capability,
        public CapabilityPresence $declared,
        public CapabilityPresence $detected,
    ) {
    }

    /**
     * Whether the difference actually matters.
     *
     * A capability declared `absent` and detected `unknown` is not a disagreement
     * worth an operator's attention: both mean "not available", and every decision
     * the platform makes reads them the same way. Reporting it anyway made the
     * doctor produce ten findings on an ordinary machine, which is how an operator
     * learns to ignore the environment section — worse than having none.
     *
     * What matters is a difference in availability: a capability declared present
     * that the host does not offer, or one declared absent that the host does.
     */
    public function isMismatch(): bool
    {
        return $this->declared->isAvailable() !== $this->detected->isAvailable();
    }

    /**
     * The raw difference, kept separate so a reader can still see both values even
     * when they agree on availability.
     */
    public function differs(): bool
    {
        return $this->declared !== $this->detected;
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'capability' => $this->capability->value,
            'declared' => $this->declared->value,
            'detected' => $this->detected->value,
        ];
    }
}
