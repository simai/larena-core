<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

use InvalidArgumentException;
use Larena\Core\Enums\OperationExecutionMode;
use Larena\Core\Enums\OperationRiskClass;
use Larena\Core\Enums\TransportKind;

/**
 * What a package declares about one operation.
 *
 * The declaration is the contract: the name, the gates, the risk, the schemas
 * and the transports it allows. A PHP descriptor binds that contract to a
 * handler; the registry refuses to register the two when they disagree.
 */
final readonly class OperationDeclaration
{
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*){2,}$/';

    public const NAME_MAX_LENGTH = 120;

    /**
     * @param list<TransportKind> $transports
     * @param array<string, mixed> $inputSchema
     * @param array<string, mixed> $outputSchema
     * @param array<string, mixed>|null $receiptSchema
     * @param list<OperationExecutionMode>|null $allowedExecutionModes
     */
    public function __construct(
        public string $package,
        public string $name,
        public OperationExecutionMode $executionMode,
        public OperationRiskClass $riskClass,
        public bool $reversible,
        public array $inputSchema,
        public array $outputSchema,
        public ?array $receiptSchema = null,
        public ?string $accessScope = null,
        public ?string $auditEvent = null,
        public ?string $idempotencyKey = null,
        public bool $transactional = false,
        public array $transports = [TransportKind::Local],
        public ?array $allowedExecutionModes = null,
    ) {
        if (preg_match(self::NAME_PATTERN, $this->name) !== 1 || strlen($this->name) > self::NAME_MAX_LENGTH) {
            throw new InvalidArgumentException('Operation declaration name is not a valid operation name: ' . $this->name);
        }

        if ($this->transports === []) {
            throw new InvalidArgumentException('Operation declaration must allow at least one transport.');
        }
    }

    public function isRead(): bool
    {
        return $this->riskClass->isRead();
    }

    public function allowsTransport(TransportKind $kind): bool
    {
        return in_array($kind, $this->transports, true);
    }

    /**
     * @return list<OperationExecutionMode>
     */
    public function allowedExecutionModes(): array
    {
        return $this->allowedExecutionModes ?? [$this->executionMode];
    }

    public function schemaRef(string $part): string
    {
        return $this->package . ':operations.yaml#' . $this->name . '.' . $part;
    }

    /**
     * The registry-facing view of the declaration, and the shape both
     * `core.operation_registry.describe` and the MCP projection read.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'package' => $this->package,
            'name' => $this->name,
            'execution_mode' => $this->executionMode->value,
            'allowed_execution_modes' => array_map(
                static fn (OperationExecutionMode $mode): string => $mode->value,
                $this->allowedExecutionModes(),
            ),
            'risk' => $this->riskClass->value,
            'reversible' => $this->reversible,
            'access_scope' => $this->accessScope,
            'audit_event' => $this->auditEvent,
            'idempotency_key' => $this->idempotencyKey,
            'transactional' => $this->transactional,
            'transports' => array_map(static fn (TransportKind $kind): string => $kind->value, $this->transports),
            'input_schema_ref' => $this->schemaRef('input'),
            'output_schema_ref' => $this->schemaRef('output'),
            'receipt_schema_ref' => $this->receiptSchema === null ? null : $this->schemaRef('receipt'),
            'input' => $this->inputSchema,
            'output' => $this->outputSchema,
            'receipt' => $this->receiptSchema,
        ];
    }
}
