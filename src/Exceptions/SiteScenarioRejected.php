<?php

declare(strict_types=1);

namespace Larena\Core\Exceptions;

use RuntimeException;

/** A scenario record, or applying one, was refused. The reason code is the contract; the message is not. */
final class SiteScenarioRejected extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        string $message = '',
    ) {
        parent::__construct($message === '' ? 'Scenario refused: ' . $reasonCode : $message);
    }
}
