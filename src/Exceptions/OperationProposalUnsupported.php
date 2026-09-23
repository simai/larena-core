<?php

declare(strict_types=1);

namespace Larena\Core\Exceptions;

use RuntimeException;

/**
 * The handler serving an operation cannot describe a change without making it.
 */
final class OperationProposalUnsupported extends RuntimeException
{
    public function __construct(public readonly string $operation)
    {
        parent::__construct(sprintf('Operation "%s" has no proposal.', $operation));
    }
}
