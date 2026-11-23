<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

final readonly class ResolvedMemberSet
{
    /**
     * @param list<string> $subjectRefs
     * @param list<string> $nodeIds
     * @param bool $complete false when more members follow the listed ones (the answer stopped at its limit)
     */
    public function __construct(
        public string $nodeId,
        public bool $includeDescendants,
        public array $subjectRefs,
        public array $nodeIds,
        public bool $complete = true,
    ) {
    }

    /** The subject to pass as membersOf()'s $after for the next page; null when the answer is complete. */
    public function nextAfter(): ?string
    {
        if ($this->complete || $this->subjectRefs === []) {
            return null;
        }

        return $this->subjectRefs[array_key_last($this->subjectRefs)];
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
