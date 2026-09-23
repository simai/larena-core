<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

/**
 * A handler that can describe a change without making it.
 *
 * A mutation whose handler does not implement this fails closed in proposal
 * mode rather than silently running: a proposal that writes is worse than no
 * proposal at all.
 */
interface OperationProposalHandler extends OperationHandler
{
    /**
     * @return array<string, mixed> the change this operation would make
     */
    public function propose(OperationDescriptor $descriptor, OperationContext $context): array;
}
