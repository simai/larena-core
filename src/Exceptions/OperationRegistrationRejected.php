<?php

declare(strict_types=1);

namespace Larena\Core\Exceptions;

use RuntimeException;

final class OperationRegistrationRejected extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        public readonly string $operationName,
        string $detail = '',
    ) {
        parent::__construct(
            $detail === ''
                ? 'Operation registration rejected (' . $reasonCode . '): ' . $operationName
                : 'Operation registration rejected (' . $reasonCode . '): ' . $operationName . ' — ' . $detail,
        );
    }
}
