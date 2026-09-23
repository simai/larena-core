<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

use Larena\Core\Enums\OperationRiskClass;

interface OperationRegistry
{
    /**
     * Register one operation. A declaration that fails the descriptor v2 policy,
     * duplicates a name or disagrees with its descriptor is rejected.
     */
    public function register(OperationDeclaration $declaration, OperationDescriptor $descriptor, string $handlerRef): void;

    public function has(string $name): bool;

    public function describe(string $name): OperationDeclaration;

    public function descriptorFor(string $name): OperationDescriptor;

    public function handlerRefFor(string $name): string;

    /**
     * @return list<OperationDeclaration>
     */
    public function list(?string $packageFilter = null, ?OperationRiskClass $riskFilter = null): array;
}
