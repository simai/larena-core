<?php

declare(strict_types=1);

namespace Larena\Core\Runtime;

use Larena\Core\Contracts\EnvironmentCapabilityState;
use Larena\Core\Contracts\EnvironmentProfile;
use Larena\Core\Contracts\EnvironmentVerification;
use Larena\Core\Enums\CapabilityPresence;
use Larena\Core\Enums\EnvironmentCapability;
use Larena\Core\Exceptions\EnvironmentProfileRejected;

/**
 * A declared, versioned description of what a host can do.
 *
 * Two rules carry it. A capability nobody declared is `unknown`, and `unknown` is
 * treated exactly as `absent` — a host capability nobody could detect is not
 * available, and assuming otherwise fails at the first moment something needs it.
 *
 * And the profile is *declared*. Detection compares against it and reports; it
 * never edits it. An installation that quietly reconfigures itself is one nobody
 * can reason about, least of all when it breaks.
 */
final readonly class DeclaredEnvironmentProfile implements EnvironmentProfile
{
    public const SCHEMA = 'larena.environment_profile.v1';

    public const ORDINARY_HOSTING = 'ordinary_hosting';

    public const PROFILE_ID_PATTERN = '/^[a-z][a-z0-9_]{0,62}$/';

    /**
     * @param array<string, CapabilityPresence> $capabilities keyed by capability value
     */
    private function __construct(
        public string $profileId,
        public int $version,
        public array $capabilities,
        public string $declaredBy,
        public ?string $notes = null,
    ) {
    }

    /**
     * @param array<string, string|CapabilityPresence> $capabilities
     */
    public static function declare(
        string $profileId,
        int $version,
        array $capabilities,
        string $declaredBy,
        ?string $notes = null,
    ): self {
        if (preg_match(self::PROFILE_ID_PATTERN, $profileId) !== 1) {
            throw new EnvironmentProfileRejected('invalid_profile_id', 'Not a valid profile id: ' . $profileId);
        }

        if ($version < 1) {
            throw new EnvironmentProfileRejected('invalid_version', 'A profile version must be a positive integer.');
        }

        $states = [];
        foreach ($capabilities as $key => $presence) {
            $capability = EnvironmentCapability::tryFrom((string) $key);
            if ($capability === null) {
                // The capability set is closed: describing something the platform
                // has not agreed to describe would make the profile unreadable by
                // anything but its author.
                throw new EnvironmentProfileRejected(
                    'unknown_environment_capability',
                    'Unknown environment capability: ' . (string) $key,
                );
            }

            $value = $presence instanceof CapabilityPresence
                ? $presence
                : CapabilityPresence::tryFrom((string) $presence);

            if ($value === null) {
                throw new EnvironmentProfileRejected(
                    'invalid_presence',
                    'Presence must be present, absent or unknown for ' . $capability->value . '.',
                );
            }

            $states[$capability->value] = $value;
        }

        // Anything the profile did not mention is unknown, which is absent.
        foreach (EnvironmentCapability::cases() as $capability) {
            $states[$capability->value] ??= CapabilityPresence::Unknown;
        }

        return new self($profileId, $version, $states, $declaredBy, $notes);
    }

    /**
     * The profile this platform targets: no queue worker, no Redis, no search
     * engine. It is first class, not a degraded mode — every core package must run
     * on it.
     */
    public static function ordinaryHosting(string $declaredBy = 'larena/core'): self
    {
        return self::declare(
            self::ORDINARY_HOSTING,
            1,
            [
                EnvironmentCapability::WritableStoragePath->value => CapabilityPresence::Present,
                EnvironmentCapability::MailTransport->value => CapabilityPresence::Present,
                EnvironmentCapability::QueueWorker->value => CapabilityPresence::Absent,
                EnvironmentCapability::Scheduler->value => CapabilityPresence::Absent,
                EnvironmentCapability::Redis->value => CapabilityPresence::Absent,
                EnvironmentCapability::SearchEngine->value => CapabilityPresence::Absent,
                EnvironmentCapability::ObjectStorage->value => CapabilityPresence::Absent,
                EnvironmentCapability::ProcessControl->value => CapabilityPresence::Absent,
                EnvironmentCapability::ImageProcessing->value => CapabilityPresence::Unknown,
                EnvironmentCapability::OutboundHttp->value => CapabilityPresence::Unknown,
                EnvironmentCapability::Opcache->value => CapabilityPresence::Unknown,
            ],
            $declaredBy,
            'Shared hosting with no worker process. Every core package must run here.',
        );
    }

    public function presence(EnvironmentCapability $capability): CapabilityPresence
    {
        return $this->capabilities[$capability->value] ?? CapabilityPresence::Unknown;
    }

    public function provides(EnvironmentCapability $capability): bool
    {
        // Unknown is absent. The whole design turns on this one line.
        return $this->presence($capability)->isAvailable();
    }

    /**
     * @return list<EnvironmentCapabilityState>
     */
    public function states(): array
    {
        $states = [];
        foreach (EnvironmentCapability::cases() as $capability) {
            $states[] = new EnvironmentCapabilityState($capability, $this->presence($capability));
        }

        return $states;
    }

    /**
     * @param list<EnvironmentCapability> $required
     */
    public function verify(string $requiredBy, array $required): EnvironmentVerification
    {
        $missing = [];
        foreach ($required as $capability) {
            if (!$this->provides($capability)) {
                $missing[] = $capability;
            }
        }

        return new EnvironmentVerification($requiredBy, $missing === [], $missing);
    }

    public function isFirstClass(): bool
    {
        return $this->profileId === self::ORDINARY_HOSTING;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $capabilities = [];
        foreach (EnvironmentCapability::cases() as $capability) {
            $capabilities[$capability->value] = $this->presence($capability)->value;
        }

        return [
            'schema' => self::SCHEMA,
            'profile_id' => $this->profileId,
            'version' => $this->version,
            'capabilities' => $capabilities,
            'declared_by' => $this->declaredBy,
            'notes' => $this->notes,
        ];
    }
}
