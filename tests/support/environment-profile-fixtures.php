<?php

declare(strict_types=1);

require_once __DIR__ . '/operation-registry-fixtures.php';

use Larena\Core\Enums\CapabilityPresence;
use Larena\Core\Enums\EnvironmentCapability;
use Larena\Core\Runtime\DeclaredEnvironmentProfile;
use Larena\Core\Runtime\HostEnvironmentDetector;

function environment_assert(bool $condition, string $message = 'environment assertion failed'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/**
 * A detector whose every probe answers the same way, for testing the reporting
 * rather than the probing.
 */
function environment_detector_answering(?bool $answer): HostEnvironmentDetector
{
    $probes = [];
    foreach (EnvironmentCapability::cases() as $capability) {
        $probes[$capability->value] = static fn (): ?bool => $answer;
    }

    return new HostEnvironmentDetector($probes);
}

/**
 * @param array<string, bool|null> $answers capability value => probe answer
 */
function environment_detector_with(array $answers): HostEnvironmentDetector
{
    $probes = [];
    foreach ($answers as $key => $answer) {
        $probes[$key] = static fn (): ?bool => $answer;
    }

    return new HostEnvironmentDetector($probes);
}

/**
 * @param array<string, CapabilityPresence> $capabilities
 */
function environment_profile(array $capabilities, string $profileId = 'test_profile'): DeclaredEnvironmentProfile
{
    return DeclaredEnvironmentProfile::declare($profileId, 1, $capabilities, 'larena/core');
}
