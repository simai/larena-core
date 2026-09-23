<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

interface TransportResolver
{
    /**
     * Describe how this operation would be transported, without transporting it.
     */
    public function explain(string $operationName): TransportDiagnostic;

    /**
     * @throws \Larena\Core\Exceptions\TransportResolutionFailed when no transport may carry the operation
     */
    public function resolve(string $operationName): OperationTransport;
}
