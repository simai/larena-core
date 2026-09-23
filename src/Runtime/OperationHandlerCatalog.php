<?php

declare(strict_types=1);

namespace Larena\Core\Runtime;

use Closure;
use Larena\Core\Contracts\OperationHandler;
use LogicException;

/**
 * Handler references, as the operation registry names them, to the handlers
 * that serve them.
 *
 * A package registers each reference it owns once, with a factory, so that
 * building the catalog touches no database and no handler is built until an
 * operation it serves runs.
 */
final class OperationHandlerCatalog
{
    /** @var array<string, Closure(): OperationHandler> */
    private array $factories = [];

    /** @var array<string, OperationHandler> */
    private array $handlers = [];

    /**
     * @param Closure(): OperationHandler $factory
     */
    public function register(string $handlerRef, Closure $factory): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/', $handlerRef) !== 1) {
            throw new LogicException('operation_handler_ref_invalid');
        }
        if (isset($this->factories[$handlerRef])) {
            throw new LogicException('operation_handler_ref_duplicate:' . $handlerRef);
        }

        $this->factories[$handlerRef] = $factory;
    }

    public function has(string $handlerRef): bool
    {
        return isset($this->factories[$handlerRef]);
    }

    public function resolve(string $handlerRef): OperationHandler
    {
        if (isset($this->handlers[$handlerRef])) {
            return $this->handlers[$handlerRef];
        }

        $factory = $this->factories[$handlerRef] ?? throw new LogicException('operation_handler_ref_unknown:' . $handlerRef);

        return $this->handlers[$handlerRef] = $factory();
    }

    /**
     * @return list<string>
     */
    public function refs(): array
    {
        $refs = array_keys($this->factories);
        sort($refs);

        return $refs;
    }
}
