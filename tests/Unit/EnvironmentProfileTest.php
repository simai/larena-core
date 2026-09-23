<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/environment-profile-fixtures.php';

use Larena\Core\Enums\CapabilityPresence;
use Larena\Core\Enums\EnvironmentCapability;
use Larena\Core\Runtime\DeclaredEnvironmentProfile;
use Larena\Core\Runtime\EnvironmentFingerprint;

// Unknown behaves exactly as absent. This is the rule the whole design turns on,
// so it is asserted directly on every capability rather than on one example.
$unknownProfile = environment_profile([
    EnvironmentCapability::Redis->value => CapabilityPresence::Unknown,
]);
$absentProfile = environment_profile([
    EnvironmentCapability::Redis->value => CapabilityPresence::Absent,
]);

foreach (EnvironmentCapability::cases() as $capability) {
    environment_assert(
        $unknownProfile->provides($capability) === $absentProfile->provides($capability),
        $capability->value . ' must behave the same whether unknown or absent',
    );
    environment_assert(!$unknownProfile->provides($capability), 'and neither is available');
}

// The presence values themselves are still distinguishable, because a profile has
// to be able to say "nobody could tell" honestly.
environment_assert($unknownProfile->presence(EnvironmentCapability::Redis) === CapabilityPresence::Unknown);
environment_assert($absentProfile->presence(EnvironmentCapability::Redis) === CapabilityPresence::Absent);

// A capability nobody mentioned is unknown, not an error and not present.
$sparse = environment_profile([EnvironmentCapability::MailTransport->value => CapabilityPresence::Present]);
environment_assert($sparse->presence(EnvironmentCapability::SearchEngine) === CapabilityPresence::Unknown);
environment_assert(!$sparse->provides(EnvironmentCapability::SearchEngine));
environment_assert($sparse->provides(EnvironmentCapability::MailTransport));

// Every capability is reported, so a reader sees the whole picture rather than
// only what the author remembered.
environment_assert(count($sparse->states()) === count(EnvironmentCapability::cases()));
environment_assert(count($sparse->toArray()['capabilities']) === count(EnvironmentCapability::cases()));

// The ordinary-hosting profile is the platform's target, and it is first class.
$ordinary = DeclaredEnvironmentProfile::ordinaryHosting();
environment_assert($ordinary->isFirstClass(), 'ordinary hosting is first class, not a degraded mode');
environment_assert($ordinary->provides(EnvironmentCapability::WritableStoragePath));
environment_assert($ordinary->provides(EnvironmentCapability::MailTransport));
foreach ([
    EnvironmentCapability::QueueWorker,
    EnvironmentCapability::Scheduler,
    EnvironmentCapability::Redis,
    EnvironmentCapability::SearchEngine,
    EnvironmentCapability::ObjectStorage,
    EnvironmentCapability::ProcessControl,
] as $capability) {
    environment_assert(
        $ordinary->presence($capability) === CapabilityPresence::Absent,
        'ordinary hosting has no ' . $capability->value,
    );
}
environment_assert($ordinary->toArray()['schema'] === 'larena.environment_profile.v1');
environment_assert($ordinary->toArray()['version'] === 1);

// The fingerprint is stable for the same profile and different for a different one.
$again = DeclaredEnvironmentProfile::ordinaryHosting();
environment_assert(
    EnvironmentFingerprint::of($ordinary) === EnvironmentFingerprint::of($again),
    'the same profile fingerprints the same',
);
environment_assert(
    EnvironmentFingerprint::of($ordinary) !== EnvironmentFingerprint::of($sparse),
    'a different profile fingerprints differently',
);
environment_assert(EnvironmentFingerprint::matches($ordinary, EnvironmentFingerprint::of($again)));
environment_assert(!EnvironmentFingerprint::matches($ordinary, EnvironmentFingerprint::of($sparse)));

// The fingerprint carries nothing about the machine: it is a hash of the profile
// and a prefix, which is what lets it travel inside an entitlement snapshot.
$fingerprint = EnvironmentFingerprint::of($ordinary);
environment_assert(str_starts_with($fingerprint, 'env-sha256:'));
environment_assert(strlen($fingerprint) === strlen('env-sha256:') + 64);
foreach ([PHP_OS_FAMILY, gethostname() ?: 'hostname', '/'] as $hostDetail) {
    environment_assert(!str_contains($fingerprint, (string) $hostDetail), 'the fingerprint leaks nothing about the host');
}

// Changing one capability changes the fingerprint, so a node cannot present a
// snapshot issued for a different host.
$changed = DeclaredEnvironmentProfile::declare(
    'ordinary_hosting',
    1,
    array_merge(
        array_map(static fn (CapabilityPresence $p): string => $p->value, $ordinary->capabilities),
        [EnvironmentCapability::Redis->value => CapabilityPresence::Present->value],
    ),
    'larena/core',
);
environment_assert(EnvironmentFingerprint::of($changed) !== $fingerprint);

echo "Environment profile passed.\n";
