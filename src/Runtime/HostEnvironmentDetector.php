<?php

declare(strict_types=1);

namespace Larena\Core\Runtime;

use Larena\Core\Contracts\EnvironmentDiagnostic;
use Larena\Core\Contracts\EnvironmentProfile;
use Larena\Core\Enums\CapabilityPresence;
use Larena\Core\Enums\EnvironmentCapability;

/**
 * Looks at the host and reports what it sees.
 *
 * It never writes a declaration. Detection produces diagnostics — a capability,
 * what was declared and what was observed — and the operator decides what to do.
 * An installation that reconfigured itself on a detection would be impossible to
 * reason about the day a probe returns the wrong answer, and probes do.
 *
 * What it cannot determine is `unknown`, never `absent`: "I could not tell" and
 * "it is not there" are different facts, and only the profile is allowed to turn
 * the first into the second.
 */
final class HostEnvironmentDetector
{
    /**
     * @param array<string, callable(): ?bool> $probes capability value => probe
     */
    public function __construct(private readonly array $probes = [])
    {
    }

    /**
     * The default probes: only what PHP can answer about itself without touching
     * the network, the filesystem outside the storage path, or any credential.
     *
     * @param string|null $storagePath the one path the detector may look at, and it
     *                                 reports writability rather than the path
     */
    public static function forHost(?string $storagePath = null): self
    {
        // A probe that can always answer returns bool; the one that cannot returns
        // null when it has nothing to look at. The array's type allows either,
        // because "I could not tell" has to be expressible.
        return new self([
            EnvironmentCapability::ProcessControl->value => static fn (): bool => function_exists('proc_open'),
            EnvironmentCapability::Opcache->value => static fn (): bool => function_exists('opcache_get_status'),
            EnvironmentCapability::ImageProcessing->value => static fn (): bool => extension_loaded('gd')
                || extension_loaded('imagick'),
            EnvironmentCapability::Redis->value => static fn (): bool => extension_loaded('redis'),
            EnvironmentCapability::OutboundHttp->value => static fn (): bool => extension_loaded('curl'),
            EnvironmentCapability::WritableStoragePath->value => static fn (): ?bool => $storagePath === null
                ? null
                : is_dir($storagePath) && is_writable($storagePath),
        ]);
    }

    public function detect(EnvironmentCapability $capability): CapabilityPresence
    {
        $probe = $this->probes[$capability->value] ?? null;

        if ($probe === null) {
            // Nobody knows how to look. That is unknown, and the profile turns
            // unknown into absent — but the detector must not do it here, or a
            // missing probe would read as a missing capability.
            return CapabilityPresence::Unknown;
        }

        $observed = $probe();

        return match ($observed) {
            true => CapabilityPresence::Present,
            false => CapabilityPresence::Absent,
            default => CapabilityPresence::Unknown,
        };
    }

    /**
     * Every difference between the declaration and the host.
     *
     * @return list<EnvironmentDiagnostic>
     */
    public function diagnose(EnvironmentProfile $profile): array
    {
        $diagnostics = [];

        foreach (EnvironmentCapability::cases() as $capability) {
            $declared = $profile->presence($capability);
            $detected = $this->detect($capability);

            $diagnostic = new EnvironmentDiagnostic($capability, $declared, $detected);

            // Only a difference in availability is reported. Absent versus unknown
            // is not one: both mean the capability is not there.
            if (!$diagnostic->isMismatch()) {
                continue;
            }

            $diagnostics[] = $diagnostic;
        }

        return $diagnostics;
    }

    /**
     * What detection saw, as a report. The declared profile is included so a reader
     * can compare, and it is returned rather than written.
     *
     * @return array<string, mixed>
     */
    public function report(EnvironmentProfile $profile): array
    {
        $diagnostics = $this->diagnose($profile);

        $detected = [];
        foreach (EnvironmentCapability::cases() as $capability) {
            $detected[$capability->value] = $this->detect($capability)->value;
        }

        return [
            'declared_profile' => $profile->toArray()['profile_id'],
            'detected' => $detected,
            'mismatches' => array_map(
                static fn (EnvironmentDiagnostic $diagnostic): array => $diagnostic->toArray(),
                $diagnostics,
            ),
            'mismatch_count' => count($diagnostics),
            'declaration_changed' => false,
            'note' => 'detection reports; it never overrides a declaration',
        ];
    }
}
