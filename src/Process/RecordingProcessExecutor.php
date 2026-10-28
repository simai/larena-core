<?php

declare(strict_types=1);

namespace Larena\Core\Process;

use Closure;
use LogicException;

/**
 * A test executor that starts nothing. It records every specification it is given and answers with the queued
 * results in order, or with the responder when no result is queued.
 */
final class RecordingProcessExecutor implements ProcessExecutor
{
    /** @var list<ProcessSpecification> */
    private array $recorded = [];

    /** @var list<ProcessResult> */
    private array $queued;

    /** @var ?Closure(ProcessSpecification): ProcessResult */
    private ?Closure $responder;

    public function __construct(ProcessResult ...$queued)
    {
        $this->queued = array_values($queued);
        $this->responder = null;
    }

    /** @param callable(ProcessSpecification): ProcessResult $responder */
    public static function answering(callable $responder): self
    {
        $executor = new self();
        $executor->responder = Closure::fromCallable($responder);

        return $executor;
    }

    public function run(ProcessSpecification $specification): ProcessResult
    {
        $this->recorded[] = $specification;

        if ($this->queued !== []) {
            return array_shift($this->queued);
        }
        if ($this->responder !== null) {
            return ($this->responder)($specification);
        }

        throw new LogicException('RecordingProcessExecutor has no result left for '.$specification->argv[0].'.');
    }

    public function ownedProcessIds(): array
    {
        return [];
    }

    /** @return list<ProcessSpecification> */
    public function recorded(): array
    {
        return $this->recorded;
    }
}
