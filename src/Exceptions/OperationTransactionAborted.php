<?php

declare(strict_types=1);

namespace Larena\Core\Exceptions;

use Larena\Core\Contracts\OperationResult;
use RuntimeException;

final class OperationTransactionAborted extends RuntimeException
{
    public function __construct(public readonly OperationResult $result)
    {
        parent::__construct('operation_transaction_aborted');
    }
}
