<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

interface OperationTransactionBoundary
{
    /**
     * Execute one complete operation lifecycle in a transaction owned by the
     * composition layer. Implementations must rethrow a callback failure only
     * after rollback is confirmed. If rollback cannot be confirmed, they must
     * throw their own boundary failure instead; the runtime treats that
     * outcome as unknown rather than claiming a rollback.
     *
     * The boundary must own the outermost transaction and must reject an
     * already active ambient transaction. The composition layer must enlist
     * the operation Audit recorder and domain writes in the same durable
     * transaction resource. A successful return means the boundary commit is
     * complete; a callback failure may be rethrown only after that boundary's
     * rollback is complete.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public function run(callable $operation): mixed;
}
