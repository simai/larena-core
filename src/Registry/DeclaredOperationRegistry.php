<?php

declare(strict_types=1);

namespace Larena\Core\Registry;

use Larena\Core\Contracts\OperationDeclaration;
use Larena\Core\Contracts\OperationDescriptor;
use Larena\Core\Contracts\OperationProvider;
use Larena\Core\Contracts\OperationRegistry;
use Larena\Core\Enums\OperationRiskClass;
use Larena\Core\Exceptions\OperationNotRegistered;
use Larena\Core\Exceptions\OperationRegistrationRejected;

/**
 * The one transport-neutral catalogue of governed operations.
 *
 * It knows nothing about HTTP: no method, no path, no route. That is what makes
 * it usable by the transport, by MCP and by the console at once, and it is why
 * REST keeps its own compiled routing registry rather than being replaced.
 */
final class DeclaredOperationRegistry implements OperationRegistry
{
    private const HANDLER_REF_PATTERN = '/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+$/';

    /** @var array<string, OperationDeclaration> */
    private array $declarations = [];

    /** @var array<string, OperationDescriptor> */
    private array $descriptors = [];

    /** @var array<string, string> */
    private array $handlerRefs = [];

    public function __construct(private readonly DescriptorPolicy $policy = new DescriptorPolicy())
    {
    }

    /**
     * @param iterable<OperationProvider> $providers
     */
    public static function fromProviders(iterable $providers, ?DescriptorPolicy $policy = null): self
    {
        $registry = new self($policy ?? new DescriptorPolicy());

        foreach ($providers as $provider) {
            foreach ($provider->operations() as $operation) {
                $registry->register($operation['declaration'], $operation['descriptor'], $operation['handler_ref']);
            }
        }

        return $registry;
    }

    public function register(OperationDeclaration $declaration, OperationDescriptor $descriptor, string $handlerRef): void
    {
        if (isset($this->declarations[$declaration->name])) {
            throw new OperationRegistrationRejected('duplicate_operation_name', $declaration->name);
        }

        if (preg_match(self::HANDLER_REF_PATTERN, $handlerRef) !== 1) {
            throw new OperationRegistrationRejected('handler_reference_unsafe', $declaration->name, $handlerRef);
        }

        $violations = $this->policy->violations($declaration, $descriptor);
        if ($violations !== []) {
            throw new OperationRegistrationRejected(
                $violations[0]['reason_code'],
                $declaration->name,
                $violations[0]['detail'],
            );
        }

        $this->declarations[$declaration->name] = $declaration;
        $this->descriptors[$declaration->name] = $descriptor;
        $this->handlerRefs[$declaration->name] = $handlerRef;
    }

    public function has(string $name): bool
    {
        return isset($this->declarations[$name]);
    }

    public function describe(string $name): OperationDeclaration
    {
        return $this->declarations[$name] ?? throw new OperationNotRegistered($name);
    }

    public function descriptorFor(string $name): OperationDescriptor
    {
        return $this->descriptors[$name] ?? throw new OperationNotRegistered($name);
    }

    public function handlerRefFor(string $name): string
    {
        return $this->handlerRefs[$name] ?? throw new OperationNotRegistered($name);
    }

    /**
     * @return list<OperationDeclaration>
     */
    public function list(?string $packageFilter = null, ?OperationRiskClass $riskFilter = null): array
    {
        $matches = [];

        foreach ($this->declarations as $declaration) {
            if ($packageFilter !== null && $declaration->package !== $packageFilter) {
                continue;
            }

            if ($riskFilter !== null && $declaration->riskClass !== $riskFilter) {
                continue;
            }

            $matches[] = $declaration;
        }

        usort(
            $matches,
            static fn (OperationDeclaration $left, OperationDeclaration $right): int => strcmp($left->name, $right->name),
        );

        return $matches;
    }
}
