<?php

declare(strict_types=1);

namespace Larena\Core\Exceptions;

use RuntimeException;

final class OperationDeclarationInvalid extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        public readonly string $path,
        string $detail = '',
    ) {
        parent::__construct(
            'Operation declaration is invalid (' . $reasonCode . ') in ' . $path
            . ($detail === '' ? '' : ': ' . $detail),
        );
    }
}
