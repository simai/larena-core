<?php

declare(strict_types=1);

namespace Larena\Core\Enums;

/**
 * How a caller enters an operation.
 *
 * A proposal is the same operation as its execution, not a second one: it runs
 * the identical gates, describes the change it would make and writes nothing.
 */
enum OperationInvocationMode: string
{
    case Propose = 'propose';
    case Execute = 'execute';

    public function writes(): bool
    {
        return $this === self::Execute;
    }
}
