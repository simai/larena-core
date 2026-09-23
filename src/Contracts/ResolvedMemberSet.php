<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

final readonly class ResolvedMemberSet
{
    /**
     * @param list<string> $subjectRefs
     * @param list<string> $nodeIds
     */
    public function __construct(
        public string $nodeId,
        public bool $includeDescendants,
        public array $subjectRefs,
        public array $nodeIds,
    ) {
    }

    public function contains(string $subjectRef): bool
    {
        return in_array($subjectRef, $this->subjectRefs, true);
    }

    public function count(): int
    {
        return count($this->subjectRefs);
    }
}
