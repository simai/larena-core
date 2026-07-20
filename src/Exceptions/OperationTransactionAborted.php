<?php

declare(strict_types=1);

namespace Larena\Core\Exceptions;

use Larena\Core\Contracts\OperationResult;
use RuntimeException;
use Throwable;

final class OperationTransactionAborted extends RuntimeException
{
    /**
     * The optional previous exception is an internal transaction-boundary
     * signal only. It lets a composition layer classify a retryable database
     * concurrency failure without exposing the original failure through the
     * normalized OperationResult.
     */
    public function __construct(
        public readonly OperationResult $result,
        ?Throwable $previous = null,
    ) {
        parent::__construct('operation_transaction_aborted', 0, $previous);
    }
}
