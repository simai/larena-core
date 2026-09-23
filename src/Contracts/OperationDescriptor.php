<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

use InvalidArgumentException;
use Larena\Core\Enums\OperationExecutionMode;
use Larena\Core\Enums\OperationRiskClass;

final readonly class OperationDescriptor
{
    /**
     * The risk, reversibility and schema fields are additive: they are declared
     * here so that operations can carry them from Batch 1 on, while the
     * operation registry and the confirmation policy that consume them are
     * introduced with the registry batch.
     *
     * A null risk class means "undeclared" and resolves fail-safe through
     * effectiveRiskClass(): a non-reversible change, never a read. The default
     * is null rather than an enum case so that constructing a descriptor pulls
     * in no additional class, which keeps descriptor construction usable from
     * contexts that load contract files without the autoloader.
     *
     * @param array<string, mixed> $metadata
     * @param list<OperationExecutionMode>|null $allowedExecutionModes
     */
    public function __construct(
        public string $name,
        public OperationExecutionMode $executionMode,
        public ?string $accessScope = null,
        public ?string $requiredCapability = null,
        public ?string $auditEvent = null,
        public ?string $idempotencyKey = null,
        public int $timeoutSeconds = 30,
        public array $metadata = [],
        public bool $transactional = false,
        public ?OperationRiskClass $riskClass = null,
        public bool $reversible = false,
        public ?string $inputSchemaRef = null,
        public ?string $outputSchemaRef = null,
        public ?string $receiptSchemaRef = null,
        public ?array $allowedExecutionModes = null,
    ) {
        if (trim($this->name) === '') {
            throw new InvalidArgumentException('Operation descriptor name must not be empty.');
        }

        if ($this->timeoutSeconds < 1) {
            throw new InvalidArgumentException('Operation timeout must be a positive integer.');
        }
    }

    public function requiresAccessDecision(): bool
    {
        return $this->accessScope !== null;
    }

    public function requiresCapabilityDecision(): bool
    {
        return $this->requiredCapability !== null;
    }

    public function requiresAuditEvent(): bool
    {
        return $this->auditEvent !== null;
    }

    public function requiresTransactionBoundary(): bool
    {
        return $this->transactional;
    }

    public function effectiveRiskClass(): OperationRiskClass
    {
        return $this->riskClass ?? OperationRiskClass::Change;
    }

    public function isReadOnly(): bool
    {
        return $this->effectiveRiskClass()->isRead();
    }

    /**
     * A read never needs confirmation; a bulk, irreversible or external action
     * always does; anything else is decided by the owner policy that the
     * registry batch introduces.
     */
    public function alwaysRequiresConfirmation(): bool
    {
        $riskClass = $this->effectiveRiskClass();

        return $riskClass->alwaysRequiresConfirmation() || (!$this->reversible && !$riskClass->isRead());
    }

    /**
     * @return list<OperationExecutionMode>
     */
    public function allowedExecutionModes(): array
    {
        return $this->allowedExecutionModes ?? [$this->executionMode];
    }

    public function allowsExecutionMode(OperationExecutionMode $mode): bool
    {
        return in_array($mode, $this->allowedExecutionModes(), true);
    }
}
