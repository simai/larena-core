<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

interface ConfirmationPolicy
{
    /**
     * Whether this operation may only execute against an approval.
     *
     * The answer depends on the operation, never on whether the actor is a
     * person or an AI: an AI acts with the rights of the user it acts for.
     */
    public function requiresConfirmation(OperationDeclaration $declaration): bool;
}
