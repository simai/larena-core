<?php

declare(strict_types=1);

namespace Larena\Core\Runtime;

use Larena\Core\Contracts\EnvironmentProfile;
use Larena\Core\Contracts\SolutionConflict;
use Larena\Core\Contracts\SolutionManifest;
use Larena\Core\Contracts\SolutionPlan;
use Larena\Core\Enums\EnvironmentCapability;
use Larena\Core\Enums\SolutionConflictClass;

/**
 * Plans an install, an upgrade and a topology. Writes nothing, ever.
 *
 * Every conflict of every class is reported in one pass, before any mutation
 * would happen. Stopping at the first collision makes an operator resolve
 * conflicts one install attempt at a time; reporting all four classes at once
 * lets them see the real shape of the problem.
 */
final class SolutionPlanner
{
    public const REMOTE_OPERATIONS = 'distributed.remote_operations';

    public const CLUSTER = 'distributed.cluster';

    /**
     * @param callable(string): bool|null $entitled answers whether a capability key
     *                                              is held; null holds nothing, which
     *                                              is what a free installation is
     */
    public function __construct(
        private readonly mixed $entitled = null,
        private readonly ?EnvironmentProfile $profile = null,
    ) {
    }

    /**
     * @param array<string, array<string, mixed>> $installed keyed by installed solution id
     */
    public function planInstall(SolutionManifest $manifest, array $installed = []): SolutionPlan
    {
        $conflicts = $this->conflicts($manifest, $installed);

        if (isset($installed[$manifest->solutionId])) {
            // Already there. That is an upgrade question, and answering it here
            // would let an install silently replace a running solution.
            return new SolutionPlan(
                action: 'install',
                solutionId: $manifest->solutionId,
                fromVersion: $this->installedVersion($installed, $manifest->solutionId),
                toVersion: $manifest->version,
                steps: [],
                conflicts: $conflicts,
                reasonCode: 'solution_already_installed',
            );
        }

        return new SolutionPlan(
            action: 'install',
            solutionId: $manifest->solutionId,
            fromVersion: null,
            toVersion: $manifest->version,
            steps: $conflicts === [] ? $this->installSteps($manifest) : [],
            conflicts: $conflicts,
        );
    }

    /**
     * @param array<string, array<string, mixed>> $installed
     */
    public function planUpgrade(SolutionManifest $manifest, array $installed = []): SolutionPlan
    {
        $from = $this->installedVersion($installed, $manifest->solutionId);

        if ($from === null) {
            return $this->refusedUpgrade($manifest, null, 'solution_not_installed');
        }

        if (version_compare($from, $manifest->version, '>')) {
            return $this->refusedUpgrade($manifest, $from, 'downgrade_refused');
        }

        $base = $manifest->baseSolution;
        if ($base !== null) {
            $baseVersion = $this->installedVersion($installed, $base['solution_id']);

            if ($baseVersion === null) {
                return $this->refusedUpgrade($manifest, $from, 'base_solution_absent');
            }

            // The declared range is the compatibility claim. Moving the base outside
            // it is exactly the upgrade that breaks a customization nobody is
            // watching.
            if (version_compare($baseVersion, $base['min_version'], '<')
                || version_compare($baseVersion, $base['max_version'], '>')) {
                return $this->refusedUpgrade($manifest, $from, 'base_compatibility_broken');
            }
        }

        // An upgrade of a solution does not conflict with itself, so the incumbent
        // is excluded before conflicts are computed.
        $others = $installed;
        unset($others[$manifest->solutionId]);
        $conflicts = $this->conflicts($manifest, $others);

        return new SolutionPlan(
            action: 'upgrade',
            solutionId: $manifest->solutionId,
            fromVersion: $from,
            toVersion: $manifest->version,
            steps: $conflicts === [] ? $this->upgradeSteps($manifest, $from) : [],
            conflicts: $conflicts,
        );
    }

    /**
     * Whether the declared topology may run here.
     *
     * One node needs nothing at all: the local transport is free, and a
     * single-machine installation must never touch an entitlement service. More
     * than one node needs remote operations, and a cluster needs the cluster
     * capability on top.
     *
     * @return array<string, mixed>
     */
    public function validateTopology(SolutionManifest $manifest): array
    {
        $reasons = [];
        $missingCapabilities = [];

        if (!$manifest->isSingleNode() && !$this->holds(self::REMOTE_OPERATIONS)) {
            $reasons[] = 'multi_node_without_entitlement';
            $missingCapabilities[] = self::REMOTE_OPERATIONS;
        }

        if ($manifest->isCluster() && !$this->holds(self::CLUSTER)) {
            $reasons[] = 'cluster_without_entitlement';
            $missingCapabilities[] = self::CLUSTER;
        }

        $missingEnvironment = $this->missingEnvironment($manifest);
        if ($missingEnvironment !== []) {
            $reasons[] = 'environment_requirement_unmet';
        }

        return [
            'solution_id' => $manifest->solutionId,
            'node_count' => $manifest->nodeCount(),
            'single_node' => $manifest->isSingleNode(),
            'cluster' => $manifest->isCluster(),
            'headless_nodes' => array_values(array_map(
                static fn ($node): string => $node->nodeId,
                array_filter($manifest->topology, static fn ($node): bool => $node->headless),
            )),
            'allowed' => $reasons === [],
            'reason_codes' => $reasons,
            'missing_capabilities' => $missingCapabilities,
            'missing_environment_capabilities' => $missingEnvironment,
            'offline' => true,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $installed
     * @return list<SolutionConflict>
     */
    private function conflicts(SolutionManifest $manifest, array $installed): array
    {
        $claims = [
            SolutionConflictClass::Scope->value => $manifest->scopes,
            SolutionConflictClass::Plane->value => $manifest->planes,
            SolutionConflictClass::StructureRole->value => $manifest->structureRoles,
            SolutionConflictClass::Package->value => $manifest->packages,
        ];

        $held = [
            SolutionConflictClass::Scope->value => 'scopes',
            SolutionConflictClass::Plane->value => 'planes',
            SolutionConflictClass::StructureRole->value => 'structure_roles',
            SolutionConflictClass::Package->value => 'packages',
        ];

        $conflicts = [];

        foreach ($claims as $class => $keys) {
            $conflictClass = SolutionConflictClass::from($class);

            foreach ($keys as $key) {
                foreach ($installed as $solutionId => $owned) {
                    $ownedKeys = $owned[$held[$class]] ?? [];

                    if (is_array($ownedKeys) && in_array($key, $ownedKeys, true)) {
                        $conflicts[] = new SolutionConflict($conflictClass, $key, (string) $solutionId);
                    }
                }
            }
        }

        return $conflicts;
    }

    /**
     * @return list<string>
     */
    private function missingEnvironment(SolutionManifest $manifest): array
    {
        if ($this->profile === null) {
            // No profile is not the same as an empty one: with nothing declared
            // there is nothing to check against, and inventing a verdict here would
            // make planning depend on how the planner happened to be constructed.
            return [];
        }

        $verification = $this->profile->verify('solution:' . $manifest->solutionId, $manifest->environmentRequirements);

        return array_map(
            static fn (EnvironmentCapability $capability): string => $capability->value,
            $verification->missing,
        );
    }

    /**
     * @return list<string>
     */
    private function installSteps(SolutionManifest $manifest): array
    {
        $steps = ['install packages: ' . implode(', ', $manifest->packages)];

        foreach ([
            'create scopes' => $manifest->scopes,
            'register planes' => $manifest->planes,
            'register structure roles' => $manifest->structureRoles,
        ] as $label => $keys) {
            if ($keys !== []) {
                $steps[] = $label . ': ' . implode(', ', $keys);
            }
        }

        if ($manifest->edition !== null) {
            $steps[] = 'enable edition ' . $manifest->edition . ' capabilities: '
                . implode(', ', $manifest->enabledCapabilities());
        }

        if ($manifest->seed !== null) {
            $steps[] = 'apply sitepack ' . $manifest->seed['sitepack']
                . ($manifest->seed['dry_run'] ? ' as a dry run first' : '');
        }

        return $steps;
    }

    /**
     * @return list<string>
     */
    private function upgradeSteps(SolutionManifest $manifest, string $from): array
    {
        return [
            'upgrade ' . $manifest->solutionId . ' from ' . $from . ' to ' . $manifest->version,
            ...$this->installSteps($manifest),
        ];
    }

    private function refusedUpgrade(SolutionManifest $manifest, ?string $from, string $reasonCode): SolutionPlan
    {
        return new SolutionPlan(
            action: 'upgrade',
            solutionId: $manifest->solutionId,
            fromVersion: $from,
            toVersion: $manifest->version,
            steps: [],
            conflicts: [],
            reasonCode: $reasonCode,
        );
    }

    /**
     * @param array<string, array<string, mixed>> $installed
     */
    private function installedVersion(array $installed, string $solutionId): ?string
    {
        $version = $installed[$solutionId]['version'] ?? null;

        return is_string($version) ? $version : null;
    }

    private function holds(string $capabilityKey): bool
    {
        if (!is_callable($this->entitled)) {
            return false;
        }

        return ($this->entitled)($capabilityKey) === true;
    }
}
