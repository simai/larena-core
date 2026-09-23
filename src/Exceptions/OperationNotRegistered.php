<?php

declare(strict_types=1);

namespace Larena\Core\Exceptions;

use RuntimeException;

final class OperationNotRegistered extends RuntimeException
{
    public function __construct(public readonly string $operationName)
    {
        parent::__construct('Operation is not registered: ' . $operationName);
    }
}
