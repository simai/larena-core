<?php

declare(strict_types=1);

namespace Larena\Core\Runtime;

use Larena\Core\Contracts\OperationContext;
use Larena\Core\Contracts\OperationDescriptor;
use Larena\Core\Contracts\OperationHandler;
use Larena\Core\Contracts\OperationProposalHandler;
use Larena\Core\Contracts\OperationRegistry;
use Larena\Core\Exceptions\OperationProposalUnsupported;

/**
 * Serves every registered operation by the handler its declaration names.
 *
 * The registry says which handler reference an operation belongs to; the
 * catalog says which handler serves that reference. Nothing else decides.
 */
final readonly class CatalogOperationHandler implements OperationHandler, OperationProposalHandler
{
    public function __construct(
        private OperationRegistry $registry,
        private OperationHandlerCatalog $catalog,
    ) {
    }

    public function handle(OperationDescriptor $descriptor, OperationContext $context): ?array
    {
        return $this->handlerFor($descriptor)->handle($descriptor, $context);
    }

    public function propose(OperationDescriptor $descriptor, OperationContext $context): array
    {
        $handler = $this->handlerFor($descriptor);
        if (!$handler instanceof OperationProposalHandler) {
            throw new OperationProposalUnsupported($descriptor->name);
        }

        return $handler->propose($descriptor, $context);
    }

    private function handlerFor(OperationDescriptor $descriptor): OperationHandler
    {
        return $this->catalog->resolve($this->registry->handlerRefFor($descriptor->name));
    }
}
