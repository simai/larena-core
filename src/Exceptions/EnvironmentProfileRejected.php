<?php

declare(strict_types=1);

namespace Larena\Core\Exceptions;

use RuntimeException;

/**
 * An environment profile boundary refused. The reason code is part of the frozen
 * contract; the message is not.
 */
final class EnvironmentProfileRejected extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        string $message = '',
    ) {
        parent::__construct($message === '' ? 'Environment profile refused: ' . $reasonCode : $message);
    }
}
