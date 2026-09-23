<?php

declare(strict_types=1);

namespace Larena\Core\Runtime;

use Larena\Core\Contracts\SolutionEdition;
use Larena\Core\Contracts\SolutionManifest;
use Larena\Core\Contracts\SolutionTopologyNode;
use Larena\Core\Enums\EnvironmentCapability;
use Larena\Core\Enums\OperationRiskClass;
use Larena\Core\Enums\SolutionDistribution;
use Larena\Core\Exceptions\SolutionRejected;

/**
 * Turns a manifest document into a validated solution, or refuses it.
 *
 * Offline, always. Validating a manifest reads no service and needs no
 * entitlement, because a customer has to be able to evaluate a solution before
 * buying it and an air-gapped installation has to be able to check its own.
 *
 * The top-level key set is closed. A manifest carrying a key this version does not
 * know would be silently half-understood, and "the installer ignored my section"
 * is the worst possible way to learn that.
 */
final class SolutionManifestValidator
{
    public const MAX_NODES = 64;

    /**
     * A local ranking, used only for the assistant ceiling. It is not a general
     * ordering of risk — it answers one question: is this profile asking for more
     * than the installation allows.
     */
    private const RISK_RANK = [
        'read' => 0,
        'change' => 1,
        'bulk' => 2,
        'external' => 3,
        'irreversible' => 4,
    ];

    private const SEED_KEYS = ['sitepack', 'dry_run'];

    private const RAW_SEED_KEYS = ['sql', 'raw_sql', 'statements', 'tables', 'table_writes', 'inserts'];

    private const ASSISTANT_KEYS = ['operations', 'max_risk'];

    private const BASE_SOLUTION_KEYS = ['solution_id', 'min_version', 'max_version'];

    private const PACKAGE_PATTERN = '/^[a-z][a-z0-9-]*\/[a-z][a-z0-9-]*$/';

    /**
     * @param array<string, mixed> $document
     */
    public function validate(array $document): SolutionManifest
    {
        $this->assertKeySet($document, SolutionManifest::TOP_LEVEL_KEYS, 'unknown_manifest_key', 'manifest');

        foreach (SolutionManifest::REQUIRED_KEYS as $key) {
            if (!array_key_exists($key, $document)) {
                throw new SolutionRejected('missing_manifest_key', 'A solution manifest requires "' . $key . '".');
            }
        }

        if (($document['schema'] ?? null) !== SolutionManifest::SCHEMA) {
            throw new SolutionRejected('schema_mismatch', 'A solution manifest must declare ' . SolutionManifest::SCHEMA . '.');
        }

        $solutionId = $this->string($document, 'solution_id');
        if (preg_match(SolutionManifest::SOLUTION_ID_PATTERN, $solutionId) !== 1) {
            throw new SolutionRejected('invalid_solution_id', 'Not a valid solution id: ' . $solutionId);
        }

        $distribution = SolutionDistribution::tryFrom($this->string($document, 'distribution'));
        if ($distribution === null) {
            throw new SolutionRejected('unknown_distribution', 'Unknown distribution: ' . $this->string($document, 'distribution'));
        }

        $packages = $this->packages($document);
        $editions = $this->editions($document);
        $edition = $this->edition($document, $editions);

        return new SolutionManifest(
            solutionId: $solutionId,
            version: $this->version($document, 'version'),
            title: $this->string($document, 'title'),
            author: $this->string($document, 'author'),
            distribution: $distribution,
            packages: $packages,
            scopes: $this->keyList($document, 'scopes'),
            planes: $this->keyList($document, 'planes'),
            structureRoles: $this->keyList($document, 'structure_roles'),
            topology: $this->topology($document, $packages),
            editions: $editions,
            edition: $edition,
            baseSolution: $this->baseSolution($document),
            seed: $this->seed($document),
            assistantProfile: $this->assistantProfile($document),
            environmentRequirements: $this->environmentRequirements($document),
        );
    }

    /**
     * Whether the assistant profile stays inside what the installation has.
     *
     * A profile may only narrow. One that names an operation the installation does
     * not have, or raises the risk ceiling, is refused — an assistant profile is a
     * restriction, and a restriction that grants is a privilege escalation with a
     * friendly name.
     *
     * @param list<string> $availableOperations
     */
    public function verifyAssistantProfile(
        SolutionManifest $manifest,
        array $availableOperations,
        OperationRiskClass $ceiling = OperationRiskClass::Change,
    ): void {
        $profile = $manifest->assistantProfile;

        if ($profile === null) {
            return;
        }

        foreach ($profile['operations'] as $operation) {
            if (!in_array($operation, $availableOperations, true)) {
                throw new SolutionRejected(
                    'assistant_profile_broadens',
                    'The assistant profile names an operation this installation does not have: ' . $operation,
                );
            }
        }

        if (self::RISK_RANK[$profile['max_risk']] > self::RISK_RANK[$ceiling->value]) {
            throw new SolutionRejected(
                'assistant_profile_broadens',
                'The assistant profile raises the risk ceiling to ' . $profile['max_risk'] . '.',
            );
        }
    }

    /**
     * @param array<string, mixed> $document
     * @return list<string>
     */
    private function packages(array $document): array
    {
        $packages = $this->keyList($document, 'packages', self::PACKAGE_PATTERN);

        if ($packages === []) {
            throw new SolutionRejected('empty_package_list', 'A solution installs at least one package.');
        }

        return $packages;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, SolutionEdition>
     */
    private function editions(array $document): array
    {
        $declared = $document['editions'] ?? [];

        if (!is_array($declared)) {
            throw new SolutionRejected('invalid_editions', '"editions" must be a mapping of edition id to capability set.');
        }

        $editions = [];
        foreach ($declared as $editionId => $body) {
            if (!is_string($editionId) || !is_array($body)) {
                throw new SolutionRejected('invalid_editions', 'Each edition is an id mapped to a body.');
            }

            // The whole rule, in one check: an edition body may say `capabilities`
            // and nothing else. Anything more would make two editions of one
            // solution different software.
            $this->assertKeySet($body, ['capabilities'], 'edition_changes_more_than_capabilities', 'edition ' . $editionId);

            if (!array_key_exists('capabilities', $body)) {
                throw new SolutionRejected(
                    'edition_changes_more_than_capabilities',
                    'Edition ' . $editionId . ' must name a capability set.',
                );
            }

            $editions[$editionId] = new SolutionEdition($editionId, $this->keyList($body, 'capabilities'));
        }

        return $editions;
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, SolutionEdition> $editions
     */
    private function edition(array $document, array $editions): ?string
    {
        $edition = $document['edition'] ?? null;

        if ($edition === null) {
            return null;
        }

        if (!is_string($edition) || !isset($editions[$edition])) {
            throw new SolutionRejected('unknown_edition', 'The selected edition is not declared: ' . (is_string($edition) ? $edition : 'non-string'));
        }

        return $edition;
    }

    /**
     * @param array<string, mixed> $document
     * @param list<string> $packages
     * @return list<SolutionTopologyNode>
     */
    private function topology(array $document, array $packages): array
    {
        $declared = $document['topology'] ?? [];

        if (!is_array($declared) || !array_is_list($declared)) {
            throw new SolutionRejected('invalid_topology', '"topology" must be a list of nodes.');
        }

        if (count($declared) > self::MAX_NODES) {
            throw new SolutionRejected('topology_too_large', 'A declared topology holds at most ' . self::MAX_NODES . ' nodes.');
        }

        $nodes = [];
        $seen = [];

        foreach ($declared as $entry) {
            if (!is_array($entry)) {
                throw new SolutionRejected('invalid_topology', 'Each topology node is a mapping.');
            }

            $this->assertKeySet($entry, SolutionTopologyNode::KEYS, 'unknown_topology_key', 'topology node');

            $nodeId = $this->string($entry, 'node_id');
            if (isset($seen[$nodeId])) {
                throw new SolutionRejected('duplicate_node_id', 'Two nodes claim the id ' . $nodeId . '.');
            }
            $seen[$nodeId] = true;

            $nodePackages = $this->keyList($entry, 'packages', self::PACKAGE_PATTERN);
            if ($nodePackages === []) {
                throw new SolutionRejected('node_without_packages', 'Node ' . $nodeId . ' carries no package.');
            }

            foreach ($nodePackages as $package) {
                if (!in_array($package, $packages, true)) {
                    throw new SolutionRejected(
                        'unknown_package_in_node',
                        'Node ' . $nodeId . ' places ' . $package . ', which the solution does not install.',
                    );
                }
            }

            $headless = $entry['headless'] ?? false;
            if (!is_bool($headless)) {
                // Declared, never inferred — and an unreadable declaration is not a
                // declaration.
                throw new SolutionRejected('invalid_headless_marker', 'Node ' . $nodeId . ' must declare headless as a boolean.');
            }

            $nodes[] = new SolutionTopologyNode($nodeId, $nodePackages, $this->keyList($entry, 'roles'), $headless);
        }

        return $nodes;
    }

    /**
     * @param array<string, mixed> $document
     * @return array{solution_id: string, min_version: string, max_version: string}|null
     */
    private function baseSolution(array $document): ?array
    {
        $base = $document['base_solution'] ?? null;

        if ($base === null) {
            return null;
        }

        if (!is_array($base)) {
            throw new SolutionRejected('invalid_base_solution', '"base_solution" must be a mapping.');
        }

        $this->assertKeySet($base, self::BASE_SOLUTION_KEYS, 'unknown_base_solution_key', 'base_solution');

        foreach (self::BASE_SOLUTION_KEYS as $key) {
            if (!array_key_exists($key, $base)) {
                throw new SolutionRejected('invalid_base_solution', 'A base solution declares "' . $key . '".');
            }
        }

        $range = [
            'solution_id' => $this->string($base, 'solution_id'),
            'min_version' => $this->version($base, 'min_version'),
            'max_version' => $this->version($base, 'max_version'),
        ];

        if (version_compare($range['min_version'], $range['max_version'], '>')) {
            throw new SolutionRejected('invalid_base_solution', 'The base version range is inverted.');
        }

        return $range;
    }

    /**
     * @param array<string, mixed> $document
     * @return array{sitepack: string, dry_run: bool}|null
     */
    private function seed(array $document): ?array
    {
        $seed = $document['seed'] ?? null;

        if ($seed === null) {
            return null;
        }

        if (!is_array($seed)) {
            throw new SolutionRejected('invalid_seed', '"seed" must be a mapping.');
        }

        foreach (array_keys($seed) as $key) {
            if (in_array((string) $key, self::RAW_SEED_KEYS, true)) {
                // Seed data enters through SitePack, with a dry run and a diff, or
                // it does not enter. A manifest that writes rows itself bypasses
                // every boundary the platform has.
                throw new SolutionRejected(
                    'raw_seed_forbidden',
                    'Seed data enters through SitePack; "' . (string) $key . '" would write directly.',
                );
            }
        }

        $this->assertKeySet($seed, self::SEED_KEYS, 'raw_seed_forbidden', 'seed');

        $dryRun = $seed['dry_run'] ?? true;
        if (!is_bool($dryRun)) {
            throw new SolutionRejected('invalid_seed', '"dry_run" must be a boolean.');
        }

        return ['sitepack' => $this->string($seed, 'sitepack'), 'dry_run' => $dryRun];
    }

    /**
     * @param array<string, mixed> $document
     * @return array{operations: list<string>, max_risk: string}|null
     */
    private function assistantProfile(array $document): ?array
    {
        $profile = $document['assistant_profile'] ?? null;

        if ($profile === null) {
            return null;
        }

        if (!is_array($profile)) {
            throw new SolutionRejected('invalid_assistant_profile', '"assistant_profile" must be a mapping.');
        }

        $this->assertKeySet($profile, self::ASSISTANT_KEYS, 'unknown_assistant_profile_key', 'assistant_profile');

        $maxRisk = $this->string($profile, 'max_risk');
        if (!isset(self::RISK_RANK[$maxRisk])) {
            throw new SolutionRejected('invalid_assistant_profile', 'Unknown risk class: ' . $maxRisk);
        }

        return ['operations' => $this->keyList($profile, 'operations'), 'max_risk' => $maxRisk];
    }

    /**
     * @param array<string, mixed> $document
     * @return list<EnvironmentCapability>
     */
    private function environmentRequirements(array $document): array
    {
        $required = [];

        foreach ($this->keyList($document, 'environment_requirements') as $key) {
            $capability = EnvironmentCapability::tryFrom($key);
            if ($capability === null) {
                throw new SolutionRejected('unknown_environment_capability', 'Unknown environment capability: ' . $key);
            }

            $required[] = $capability;
        }

        return $required;
    }

    /**
     * @param array<string, mixed> $document
     * @param list<string> $allowed
     */
    private function assertKeySet(array $document, array $allowed, string $reasonCode, string $where): void
    {
        foreach (array_keys($document) as $key) {
            if (!in_array((string) $key, $allowed, true)) {
                throw new SolutionRejected($reasonCode, 'Unknown key in ' . $where . ': ' . (string) $key);
            }
        }
    }

    /**
     * @param array<string, mixed> $document
     */
    private function string(array $document, string $key): string
    {
        $value = $document[$key] ?? null;

        if (!is_string($value) || trim($value) === '') {
            throw new SolutionRejected('invalid_manifest_value', 'A solution manifest requires a non-empty "' . $key . '".');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $document
     */
    private function version(array $document, string $key): string
    {
        $value = $this->string($document, $key);

        if (preg_match('/^\d+\.\d+\.\d+$/', $value) !== 1) {
            throw new SolutionRejected('invalid_version', '"' . $key . '" must be a three-part version, got ' . $value . '.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $document
     * @return list<string>
     */
    private function keyList(array $document, string $key, ?string $pattern = null): array
    {
        $value = $document[$key] ?? [];

        if (!is_array($value) || !array_is_list($value)) {
            throw new SolutionRejected('invalid_manifest_value', '"' . $key . '" must be a list.');
        }

        $entries = [];
        foreach ($value as $entry) {
            if (!is_string($entry) || trim($entry) === '') {
                throw new SolutionRejected('invalid_manifest_value', 'Every member of "' . $key . '" must be a non-empty string.');
            }

            if ($pattern !== null && preg_match($pattern, $entry) !== 1) {
                throw new SolutionRejected('invalid_manifest_value', 'Not a valid entry for "' . $key . '": ' . $entry);
            }

            $entries[] = $entry;
        }

        return $entries;
    }
}
