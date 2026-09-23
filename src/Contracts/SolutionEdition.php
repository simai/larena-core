<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

/**
 * An edition is a name for an enabled capability set. That is the whole of it.
 *
 * It cannot add a package, a scope, a plane or a structure role, because an
 * edition that changes code is a fork wearing a price tag: the same solution at
 * two editions would no longer be the same software, and an upgrade could not be
 * reasoned about.
 */
final readonly class SolutionEdition
{
    /**
     * @param list<string> $capabilities
     */
    public function __construct(
        public string $editionId,
        public array $capabilities,
    ) {
    }

    public function enables(string $capabilityKey): bool
    {
        return in_array($capabilityKey, $this->capabilities, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['edition_id' => $this->editionId, 'capabilities' => $this->capabilities];
    }
}
