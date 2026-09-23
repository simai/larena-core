<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

/**
 * A package contributes its operations through this port.
 *
 * Each entry pairs the declaration with the descriptor that binds it to a
 * handler, so the registry can check the two against each other.
 */
interface OperationProvider
{
    /**
     * @return list<array{declaration: OperationDeclaration, descriptor: OperationDescriptor, handler_ref: string}>
     */
    public function operations(): array;
}
