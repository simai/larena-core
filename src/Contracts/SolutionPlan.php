<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

/**
 * What an install or an upgrade would do, and why it cannot.
 *
 * A plan is a document. Producing one writes nothing, reaches no network and needs
 * no entitlement — a customer has to be able to evaluate a solution before buying
 * it, and an operator has to be able to see every conflict before the first table
 * is touched rather than after the third.
 */
final readonly class SolutionPlan
{
    /**
     * @param list<string> $steps
     * @param list<SolutionConflict> $conflicts
     */
    public function __construct(
        public string $action,
        public string $solutionId,
        public ?string $fromVersion,
        public string $toVersion,
        public array $steps,
        public array $conflicts = [],
        public ?string $reasonCode = null,
    ) {
    }

    public function isActionable(): bool
    {
        return $this->conflicts === [] && $this->reasonCode === null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'solution_id' => $this->solutionId,
            'from_version' => $this->fromVersion,
            'to_version' => $this->toVersion,
            'actionable' => $this->isActionable(),
            'steps' => $this->steps,
            'conflicts' => array_map(
                static fn (SolutionConflict $conflict): array => $conflict->toArray(),
                $this->conflicts,
            ),
            'conflict_count' => count($this->conflicts),
            'reason_code' => $this->reasonCode,
            'planning_only' => true,
        ];
    }
}
