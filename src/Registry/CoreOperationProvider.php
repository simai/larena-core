<?php

declare(strict_types=1);

namespace Larena\Core\Registry;

use Larena\Core\Contracts\OperationDeclaration;
use Larena\Core\Contracts\OperationDescriptor;
use Larena\Core\Contracts\OperationProvider;
use Larena\Core\Exceptions\OperationDeclarationInvalid;
use Larena\Core\Runtime\EnvironmentOperationHandlers;
use Larena\Core\Runtime\OperationRegistryOperationHandlers;
use Larena\Core\Runtime\PlaneOperationHandlers;
use Larena\Core\Runtime\ScopeOperationHandlers;
use Larena\Core\Runtime\TransportOperationHandlers;

/**
 * Core's own operations, paired with the descriptors that bind them.
 *
 * The declarations come from `operations.yaml` and the descriptors from the
 * handler classes. Keeping the two sources and checking them against each other
 * is deliberate: the handlers already dispatch on the descriptor in PHP, and
 * building descriptors from a file at boot would make a running operation depend
 * on a file parse. The registry's mismatch check gives the single-truth
 * guarantee without paying that price.
 */
final class CoreOperationProvider implements OperationProvider
{
    public const PACKAGE = 'larena/core';

    public function __construct(
        private readonly ?string $declarationPath = null,
        private readonly OperationDeclarationLoader $loader = new OperationDeclarationLoader(),
    ) {
    }

    /**
     * @return list<array{declaration: OperationDeclaration, descriptor: OperationDescriptor, handler_ref: string}>
     */
    public function operations(): array
    {
        $descriptors = self::descriptors();
        $handlerRefs = self::handlerRefs();
        $operations = [];

        foreach ($this->loader->loadFile($this->path(), self::PACKAGE) as $declaration) {
            $descriptor = $descriptors[$declaration->name] ?? null;
            if ($descriptor === null) {
                throw new OperationDeclarationInvalid(
                    'declared_without_descriptor',
                    $this->path(),
                    $declaration->name . ' is declared but no core handler binds it',
                );
            }

            $operations[] = [
                'declaration' => $declaration,
                'descriptor' => $descriptor,
                'handler_ref' => $handlerRefs[$declaration->name] ?? 'core.operation.unbound',
            ];
        }

        return $operations;
    }

    /**
     * Every descriptor core owns, indexed by operation name.
     *
     * @return array<string, OperationDescriptor>
     */
    public static function descriptors(): array
    {
        return [
            ...ScopeOperationHandlers::descriptors(),
            ...PlaneOperationHandlers::descriptors(),
            ...OperationRegistryOperationHandlers::descriptors(),
            ...TransportOperationHandlers::descriptors(),
            ...EnvironmentOperationHandlers::descriptors(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function handlerRefs(): array
    {
        $refs = [];

        foreach (array_keys(ScopeOperationHandlers::descriptors()) as $name) {
            $refs[$name] = 'core.handler.scope';
        }

        foreach (array_keys(PlaneOperationHandlers::descriptors()) as $name) {
            $refs[$name] = 'core.handler.plane';
        }

        foreach (array_keys(OperationRegistryOperationHandlers::descriptors()) as $name) {
            $refs[$name] = 'core.handler.operation_registry';
        }

        foreach (array_keys(TransportOperationHandlers::descriptors()) as $name) {
            $refs[$name] = 'core.handler.transport';
        }

        foreach (array_keys(EnvironmentOperationHandlers::descriptors()) as $name) {
            $refs[$name] = 'core.handler.environment';
        }

        return $refs;
    }

    public function path(): string
    {
        return $this->declarationPath ?? dirname(__DIR__, 2) . '/operations.yaml';
    }
}
