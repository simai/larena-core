<?php

declare(strict_types=1);

namespace Larena\Core\Runtime;

use Larena\Core\Contracts\EnvironmentProfile;
use Larena\Core\Enums\EnvironmentCapability;

/**
 * A stable identifier for what a host can do.
 *
 * It is computed from the profile alone — the capability keys and their presence,
 * in a fixed order — and from nothing about the machine. That is deliberate twice
 * over: the fingerprint has to be reproducible so node admission can compare it,
 * and it must carry no host name, path or address, because it travels inside an
 * entitlement snapshot that other people handle.
 */
final class EnvironmentFingerprint
{
    public const PREFIX = 'env-sha256:';

    public static function of(EnvironmentProfile $profile): string
    {
        $parts = [];
        foreach (EnvironmentCapability::cases() as $capability) {
            $parts[] = $capability->value . '=' . $profile->presence($capability)->value;
        }

        // Sorted by the enum's declaration order, which is fixed, so the same
        // profile always produces the same string on every host.
        return self::PREFIX . hash('sha256', implode(';', $parts));
    }

    public static function matches(EnvironmentProfile $profile, string $fingerprint): bool
    {
        return hash_equals(self::of($profile), $fingerprint);
    }
}
