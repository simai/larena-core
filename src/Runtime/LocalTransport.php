<?php

declare(strict_types=1);

namespace Larena\Core\Runtime;

use Larena\Core\Contracts\OperationContext;
use Larena\Core\Contracts\OperationDescriptor;
use Larena\Core\Contracts\OperationResult;
use Larena\Core\Contracts\OperationRuntime;
use Larena\Core\Contracts\OperationTransport;
use Larena\Core\Enums\TransportKind;

/**
 * The transport that is always there and always free.
 *
 * It adds nothing to the existing runtime on purpose: an in-process call must
 * not become slower or differently governed because a distributed mode exists.
 */
final readonly class LocalTransport implements OperationTransport
{
    public function __construct(private OperationRuntime $runtime)
    {
    }

    public function kind(): TransportKind
    {
        return TransportKind::Local;
    }

    public function send(OperationDescriptor $descriptor, OperationContext $context): OperationResult
    {
        return $this->runtime->execute($descriptor, $context);
    }
}
