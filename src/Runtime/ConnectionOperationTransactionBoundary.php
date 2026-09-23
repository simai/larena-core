<?php

declare(strict_types=1);

namespace Larena\Core\Runtime;

use Illuminate\Database\ConnectionInterface;
use Larena\Core\Contracts\OperationTransactionBoundary;
use RuntimeException;

/**
 * One database transaction per transactional operation, for callers inside the
 * application. An operation never joins a transaction someone else opened: its
 * audit and its rollback would then belong to that caller.
 */
final readonly class ConnectionOperationTransactionBoundary implements OperationTransactionBoundary
{
    public function __construct(private ConnectionInterface $connection)
    {
    }

    public function run(callable $operation): mixed
    {
        if ($this->connection->transactionLevel() !== 0) {
            throw new RuntimeException('ambient_operation_transaction_forbidden');
        }

        return $this->connection->transaction(static fn (): mixed => $operation());
    }
}
