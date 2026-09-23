<?php

declare(strict_types=1);

namespace Larena\Core\Exceptions;

use RuntimeException;

/**
 * A solution manifest, plan or topology was refused. The reason code is part of
 * the frozen contract; the message is not.
 */
final class SolutionRejected extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        string $message = '',
    ) {
        parent::__construct($message === '' ? 'Solution refused: ' . $reasonCode : $message);
    }
}
