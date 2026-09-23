<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/environment-profile-fixtures.php';

use Larena\Core\Enums\CapabilityPresence;
use Larena\Core\Enums\EnvironmentCapability;
use Larena\Core\Exceptions\EnvironmentProfileRejected;
use Larena\Core\Runtime\DeclaredEnvironmentProfile;

/**
 * @param callable(): mixed $call
 */
function environment_denied(callable $call, string $expectedReason): void
{
    try {
        $call();
    } catch (EnvironmentProfileRejected $rejection) {
        environment_assert(
            $rejection->reasonCode === $expectedReason,
            'expected ' . $expectedReason . ', got ' . $rejection->reasonCode,
        );

        return;
    }

    throw new RuntimeException('Boundary "' . $expectedReason . '" did not fail closed.');
}

// The capability set is closed. Describing something the platform has not agreed
// to describe would produce a profile only its author can read.
environment_denied(
    static fn (): mixed => DeclaredEnvironmentProfile::declare('test', 1, ['quantum_accelerator' => 'present'], 'acme'),
    'unknown_environment_capability',
);

// A presence value has exactly three possibilities.
environment_denied(
    static fn (): mixed => DeclaredEnvironmentProfile::declare('test', 1, ['redis' => 'maybe'], 'acme'),
    'invalid_presence',
);
environment_denied(
    static fn (): mixed => DeclaredEnvironmentProfile::declare('test', 1, ['redis' => 'yes'], 'acme'),
    'invalid_presence',
);

// A profile id is a slug and a version is a positive integer.
foreach (['', 'Test', 'test profile', '1test', str_repeat('a', 64)] as $badId) {
    environment_denied(
        static fn (): mixed => DeclaredEnvironmentProfile::declare($badId, 1, [], 'acme'),
        'invalid_profile_id',
    );
}
foreach ([0, -1] as $badVersion) {
    environment_denied(
        static fn (): mixed => DeclaredEnvironmentProfile::declare('test', $badVersion, [], 'acme'),
        'invalid_version',
    );
}

// An empty profile is legal and provides nothing: a host nobody described is a
// host that offers nothing, which is the safe reading.
$empty = DeclaredEnvironmentProfile::declare('empty_profile', 1, [], 'acme');
foreach (EnvironmentCapability::cases() as $capability) {
    environment_assert($empty->presence($capability) === CapabilityPresence::Unknown);
    environment_assert(!$empty->provides($capability), 'an undescribed host provides nothing');
}
environment_assert(!$empty->verify('acme/anything', [EnvironmentCapability::MailTransport])->satisfied);

// The presence enum accepts its own instances as well as strings, so a caller may
// use either without a conversion that could get the mapping wrong.
$typed = DeclaredEnvironmentProfile::declare('typed', 1, [
    EnvironmentCapability::Redis->value => CapabilityPresence::Present,
], 'acme');
$stringly = DeclaredEnvironmentProfile::declare('stringly', 1, ['redis' => 'present'], 'acme');
environment_assert($typed->presence(EnvironmentCapability::Redis) === $stringly->presence(EnvironmentCapability::Redis));

// A profile is immutable: nothing on it can turn absent into present after the
// fact, which is what makes the fingerprint worth comparing.
$ordinary = DeclaredEnvironmentProfile::ordinaryHosting();
$snapshot = $ordinary->toArray();
$ordinary->verify('acme/anything', EnvironmentCapability::cases());
$ordinary->states();
environment_assert($ordinary->toArray() === $snapshot, 'reading a profile does not change it');

echo "Environment profile fail-closed boundaries passed.\n";
