<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

/**
 * One node of a declared topology.
 *
 * `headless` is declared, never inferred. A node that happens to carry no package
 * with an HTTP surface today would silently become non-headless the moment
 * someone adds one, and an operator would learn about the new public surface from
 * the internet rather than from the manifest.
 */
final readonly class SolutionTopologyNode
{
    public const KEYS = ['node_id', 'packages', 'roles', 'headless'];

    /**
     * @param list<string> $packages
     * @param list<string> $roles
     */
    public function __construct(
        public string $nodeId,
        public array $packages,
        public array $roles = [],
        public bool $headless = false,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'node_id' => $this->nodeId,
            'packages' => $this->packages,
            'roles' => $this->roles,
            'headless' => $this->headless,
        ];
    }
}
