<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

use Larena\Core\Enums\EnvironmentCapability;
use Larena\Core\Enums\SolutionDistribution;

/**
 * A validated solution: packages, scopes, planes, structure roles, an optional
 * topology, an optional edition set and what it needs from its host.
 *
 * The manifest is a document, and this class is what a document becomes once the
 * validator has accepted it. Nothing here parses or checks — arriving as one of
 * these means the rules already passed, so a planner can read it without
 * re-deciding whether it is well formed.
 */
final readonly class SolutionManifest
{
    public const SCHEMA = 'larena.solution_manifest.v1';

    public const TOP_LEVEL_KEYS = [
        'schema', 'solution_id', 'version', 'title', 'author', 'distribution',
        'base_solution', 'edition', 'editions', 'packages', 'scopes', 'planes',
        'structure_roles', 'topology', 'seed', 'assistant_profile',
        'environment_requirements',
    ];

    public const REQUIRED_KEYS = ['schema', 'solution_id', 'version', 'title', 'author', 'distribution', 'packages'];

    public const SOLUTION_ID_PATTERN = '/^[a-z][a-z0-9_]{0,62}$/';

    /**
     * @param list<string> $packages
     * @param list<string> $scopes
     * @param list<string> $planes
     * @param list<string> $structureRoles
     * @param list<SolutionTopologyNode> $topology
     * @param array<string, SolutionEdition> $editions
     * @param array{solution_id: string, min_version: string, max_version: string}|null $baseSolution
     * @param array{sitepack: string, dry_run: bool}|null $seed
     * @param array{operations: list<string>, max_risk: string}|null $assistantProfile
     * @param list<EnvironmentCapability> $environmentRequirements
     */
    public function __construct(
        public string $solutionId,
        public string $version,
        public string $title,
        public string $author,
        public SolutionDistribution $distribution,
        public array $packages,
        public array $scopes = [],
        public array $planes = [],
        public array $structureRoles = [],
        public array $topology = [],
        public array $editions = [],
        public ?string $edition = null,
        public ?array $baseSolution = null,
        public ?array $seed = null,
        public ?array $assistantProfile = null,
        public array $environmentRequirements = [],
    ) {
    }

    public function nodeCount(): int
    {
        // No declared topology is a single node: an installation is always at least
        // itself.
        return max(1, count($this->topology));
    }

    public function isSingleNode(): bool
    {
        return $this->nodeCount() === 1;
    }

    /**
     * More than a pair of nodes is a cluster. A pair is the ordinary
     * two-machine split — a web node and a worker node — and it needs remote
     * operations; three or more is a topology somebody has to operate, and that is
     * what the cluster capability is for.
     */
    public function isCluster(): bool
    {
        return $this->nodeCount() > 2;
    }

    /**
     * @return list<string>
     */
    public function enabledCapabilities(): array
    {
        if ($this->edition === null) {
            return [];
        }

        return $this->editions[$this->edition]->capabilities ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'solution_id' => $this->solutionId,
            'version' => $this->version,
            'title' => $this->title,
            'author' => $this->author,
            'distribution' => $this->distribution->value,
            'base_solution' => $this->baseSolution,
            'edition' => $this->edition,
            'editions' => array_map(
                static fn (SolutionEdition $edition): array => $edition->toArray(),
                $this->editions,
            ),
            'packages' => $this->packages,
            'scopes' => $this->scopes,
            'planes' => $this->planes,
            'structure_roles' => $this->structureRoles,
            'topology' => array_map(
                static fn (SolutionTopologyNode $node): array => $node->toArray(),
                $this->topology,
            ),
            'node_count' => $this->nodeCount(),
            'seed' => $this->seed,
            'assistant_profile' => $this->assistantProfile,
            'environment_requirements' => array_map(
                static fn (EnvironmentCapability $capability): string => $capability->value,
                $this->environmentRequirements,
            ),
        ];
    }
}
