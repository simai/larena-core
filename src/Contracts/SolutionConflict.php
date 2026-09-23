<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

use Larena\Core\Enums\SolutionConflictClass;

/**
 * One collision between a candidate solution and what is already installed.
 *
 * A conflict names the class, the key and who holds it. "Cannot install" without
 * the incumbent's name is a dead end for whoever has to resolve it.
 */
final readonly class SolutionConflict
{
    public function __construct(
        public SolutionConflictClass $conflictClass,
        public string $key,
        public string $heldBy,
    ) {
    }

    public function reasonCode(): string
    {
        return $this->conflictClass->reasonCode();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'class' => $this->conflictClass->value,
            'key' => $this->key,
            'held_by' => $this->heldBy,
            'reason_code' => $this->reasonCode(),
        ];
    }
}
