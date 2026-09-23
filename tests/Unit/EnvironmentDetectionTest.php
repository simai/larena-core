<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/environment-profile-fixtures.php';

use Larena\Core\Enums\CapabilityPresence;
use Larena\Core\Enums\EnvironmentCapability;
use Larena\Core\Runtime\DeclaredEnvironmentProfile;
use Larena\Core\Runtime\HostEnvironmentDetector;

$ordinary = DeclaredEnvironmentProfile::ordinaryHosting();
$before = $ordinary->toArray();

// A host that has everything disagrees with ordinary hosting about a lot, and every
// disagreement is a diagnostic rather than a change.
$generous = environment_detector_answering(true);
$report = $generous->report($ordinary);

environment_assert($report['mismatch_count'] > 0, 'a generous host disagrees with ordinary hosting');

// Absent versus unknown is not a mismatch: both mean the capability is not there,
// and reporting it produced ten findings on an ordinary machine — which is how an
// operator learns to ignore the environment section.
$cannotTellAnything = environment_detector_answering(null);
$quiet = $cannotTellAnything->report($ordinary);
foreach ($quiet['mismatches'] as $mismatch) {
    environment_assert(
        $mismatch['declared'] === 'present',
        'the only findings against a host that cannot tell are capabilities declared present: '
        . json_encode($mismatch),
    );
}
environment_assert(
    $quiet['mismatch_count'] === 2,
    'ordinary hosting declares two capabilities present, so exactly two are unconfirmed, got '
    . $quiet['mismatch_count'],
);

// A capability declared absent and detected present is a real finding: the host has
// more than the declaration admits, and an operator may want to use it.
$hasRedis = environment_detector_with(['redis' => true]);
$redisFindings = $hasRedis->diagnose($ordinary);

$byCapability = [];
foreach ($redisFindings as $finding) {
    $byCapability[$finding->capability->value] = $finding;
}

environment_assert(isset($byCapability['redis']), 'the extra capability is reported');
environment_assert($byCapability['redis']->declared === CapabilityPresence::Absent);
environment_assert($byCapability['redis']->detected === CapabilityPresence::Present);
environment_assert($byCapability['redis']->isMismatch(), 'absent versus present is a mismatch');

// This detector has no probe for anything else, so the two capabilities ordinary
// hosting declares present are unconfirmed and reported as well. Nothing declared
// absent appears, which is the whole point of the rule.
environment_assert(count($redisFindings) === 3, 'redis plus the two unconfirmed, got ' . count($redisFindings));
foreach ($redisFindings as $finding) {
    environment_assert(
        $finding->capability === EnvironmentCapability::Redis || $finding->declared === CapabilityPresence::Present,
        'every other finding is a capability declared present that could not be confirmed',
    );
}

// And the raw difference is still available to a reader who wants it.
$absentVersusUnknown = new \Larena\Core\Contracts\EnvironmentDiagnostic(
    EnvironmentCapability::Redis,
    CapabilityPresence::Absent,
    CapabilityPresence::Unknown,
);
environment_assert(!$absentVersusUnknown->isMismatch(), 'not worth reporting');
environment_assert($absentVersusUnknown->differs(), 'but the values are still different and readable');
environment_assert($report['declaration_changed'] === false, 'and the declaration is untouched');
environment_assert($ordinary->toArray() === $before, 'literally untouched');

// Each mismatch names the capability and both values, so the operator can see what
// to do without reading two files.
foreach ($report['mismatches'] as $mismatch) {
    environment_assert(isset($mismatch['capability'], $mismatch['declared'], $mismatch['detected']));
    environment_assert($mismatch['declared'] !== $mismatch['detected'], 'a mismatch is a difference');
}

// A host that matches the declaration produces no mismatch at all.
$matching = environment_detector_with([
    EnvironmentCapability::WritableStoragePath->value => true,
    EnvironmentCapability::MailTransport->value => true,
    EnvironmentCapability::QueueWorker->value => false,
    EnvironmentCapability::Scheduler->value => false,
    EnvironmentCapability::Redis->value => false,
    EnvironmentCapability::SearchEngine->value => false,
    EnvironmentCapability::ObjectStorage->value => false,
    EnvironmentCapability::ProcessControl->value => false,
    EnvironmentCapability::ImageProcessing->value => null,
    EnvironmentCapability::OutboundHttp->value => null,
    EnvironmentCapability::Opcache->value => null,
]);
environment_assert($matching->diagnose($ordinary) === [], 'a matching host reports nothing');

// A probe that cannot answer reports unknown, not absent: "I could not tell" and
// "it is not there" are different facts, and only the profile turns the first into
// the second.
$cannotTell = environment_detector_answering(null);
environment_assert($cannotTell->detect(EnvironmentCapability::Redis) === CapabilityPresence::Unknown);

// A capability with no probe at all is unknown too, so a missing probe never reads
// as a missing capability.
$noProbes = new HostEnvironmentDetector();
foreach (EnvironmentCapability::cases() as $capability) {
    environment_assert(
        $noProbes->detect($capability) === CapabilityPresence::Unknown,
        'a capability with no probe is unknown, not absent',
    );
}

// The real host detector answers something for the capabilities PHP can see about
// itself, and unknown for the rest. Which values it returns depends on the machine,
// so the assertion is about the shape rather than the answers.
$host = HostEnvironmentDetector::forHost();
foreach (EnvironmentCapability::cases() as $capability) {
    $presence = $host->detect($capability);
    environment_assert(
        in_array($presence, [CapabilityPresence::Present, CapabilityPresence::Absent, CapabilityPresence::Unknown], true),
        'every detection is one of the three values',
    );
}
environment_assert(
    $host->detect(EnvironmentCapability::QueueWorker) === CapabilityPresence::Unknown,
    'a queue worker cannot be detected from inside PHP, so it stays unknown',
);

// The detector reports writability of the storage path without naming the path.
$writable = HostEnvironmentDetector::forHost(sys_get_temp_dir());
environment_assert(
    $writable->detect(EnvironmentCapability::WritableStoragePath) === CapabilityPresence::Present,
    'a writable temp directory is detected as present',
);
$missingPath = HostEnvironmentDetector::forHost('/no/such/directory/here');
environment_assert($missingPath->detect(EnvironmentCapability::WritableStoragePath) === CapabilityPresence::Absent);

// A report carries no path, credential or host name — only capability keys and
// presence values.
$rendered = json_encode($writable->report($ordinary));
environment_assert(is_string($rendered));
foreach ([sys_get_temp_dir(), gethostname() ?: 'hostname', '/etc', 'password', 'secret', 'token'] as $forbidden) {
    environment_assert(
        !str_contains($rendered, (string) $forbidden),
        'a diagnostic must not contain ' . (string) $forbidden,
    );
}

echo "Environment detection passed.\n";
