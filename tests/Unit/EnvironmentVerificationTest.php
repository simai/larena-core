<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/environment-profile-fixtures.php';

use Larena\Core\Enums\CapabilityPresence;
use Larena\Core\Enums\EnvironmentCapability;
use Larena\Core\Runtime\DeclaredEnvironmentProfile;

$ordinary = DeclaredEnvironmentProfile::ordinaryHosting();

// A package that needs only what ordinary hosting provides runs there. This is the
// requirement the whole profile exists to state: every core package must run on
// ordinary hosting.
$satisfied = $ordinary->verify('larena/core', [
    EnvironmentCapability::WritableStoragePath,
    EnvironmentCapability::MailTransport,
]);
environment_assert($satisfied->satisfied, 'core runs on ordinary hosting');
environment_assert($satisfied->missing === []);
environment_assert($satisfied->requiredBy === 'larena/core');

// A package that needs a worker does not, and the refusal names the capability
// rather than only saying no.
$needsWorker = $ordinary->verify('larena/queue', [EnvironmentCapability::QueueWorker]);
environment_assert(!$needsWorker->satisfied);
environment_assert($needsWorker->toArray()['missing'] === ['queue_worker'], 'the missing capability is named');

// Every missing capability is named, not the first: an operator fixing a host
// should learn everything in one pass rather than one round trip per package.
$needsEverything = $ordinary->verify('acme/heavy', [
    EnvironmentCapability::QueueWorker,
    EnvironmentCapability::Redis,
    EnvironmentCapability::SearchEngine,
    EnvironmentCapability::WritableStoragePath,
]);
environment_assert(!$needsEverything->satisfied);
environment_assert(
    $needsEverything->toArray()['missing'] === ['queue_worker', 'redis', 'search_engine'],
    'all three are named and the satisfied one is not: ' . implode(', ', $needsEverything->toArray()['missing']),
);

// A capability declared unknown fails verification exactly as an absent one does.
// This is where unknown-is-absent stops being a definition and starts being a
// behaviour someone depends on.
$unknownImaging = environment_profile([
    EnvironmentCapability::ImageProcessing->value => CapabilityPresence::Unknown,
]);
$absentImaging = environment_profile([
    EnvironmentCapability::ImageProcessing->value => CapabilityPresence::Absent,
]);

$fromUnknown = $unknownImaging->verify('larena/filesystem', [EnvironmentCapability::ImageProcessing]);
$fromAbsent = $absentImaging->verify('larena/filesystem', [EnvironmentCapability::ImageProcessing]);

environment_assert(!$fromUnknown->satisfied, 'unknown fails closed');
environment_assert($fromUnknown->toArray() === $fromAbsent->toArray(), 'and fails identically to absent');

// Requiring nothing is satisfied by any profile, including an empty one.
environment_assert(environment_profile([])->verify('acme/nothing', [])->satisfied);

// A host that has everything satisfies everything.
$generous = environment_profile(array_combine(
    EnvironmentCapability::keys(),
    array_fill(0, count(EnvironmentCapability::cases()), CapabilityPresence::Present),
));
$all = $generous->verify('acme/greedy', EnvironmentCapability::cases());
environment_assert($all->satisfied);
environment_assert($all->missing === []);

// The verification payload is a report, not a decision someone can misread: it
// names who required what and what is missing.
$payload = $needsWorker->toArray();
environment_assert(array_keys($payload) === ['required_by', 'satisfied', 'missing']);
environment_assert($payload['required_by'] === 'larena/queue');
environment_assert($payload['satisfied'] === false);

echo "Environment verification passed.\n";
