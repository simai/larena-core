<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

use Larena\Core\Enums\EnvironmentCapability;

/**
 * Whether a package may run on this host.
 *
 * A refusal names every missing capability, not the first: an operator fixing a
 * host should learn everything they have to install in one pass.
 */
final readonly class EnvironmentVerification
{
    /**
     * @param list<EnvironmentCapability> $missing
     */
    public function __construct(
        public string $requiredBy,
        public bool $satisfied,
        public array $missing = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'required_by' => $this->requiredBy,
            'satisfied' => $this->satisfied,
            'missing' => array_map(static fn (EnvironmentCapability $c): string => $c->value, $this->missing),
        ];
    }
}
