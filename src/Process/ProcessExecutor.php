<?php

declare(strict_types=1);

namespace Larena\Core\Process;

/**
 * The one way Larena packages start an external program. An executor never goes through a shell, never lets the
 * child inherit the parent's environment, and always reaps the child before it returns.
 */
interface ProcessExecutor
{
    /** Runs the child to its end, or stops it at its timeout or output limit, and reports what happened. */
    public function run(ProcessSpecification $specification): ProcessResult;

    /**
     * Direct children this executor started and has not reaped yet. Empty whenever no run is in progress.
     *
     * @return list<int>
     */
    public function ownedProcessIds(): array;
}
