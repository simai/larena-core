<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

use InvalidArgumentException;
use Larena\Core\Enums\OperationExecutionMode;

final readonly class OperationDescriptor
{
    /**
     * @param array<string, mixed> $metadata
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
}
