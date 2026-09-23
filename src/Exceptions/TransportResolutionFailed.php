<?php

declare(strict_types=1);

namespace Larena\Core\Exceptions;

use Larena\Core\Contracts\TransportDiagnostic;
use RuntimeException;

final class TransportResolutionFailed extends RuntimeException
{
    public function __construct(public readonly TransportDiagnostic $diagnostic)
    {
        parent::__construct('Transport resolution failed: ' . $diagnostic->reasonCode);
    }
}
