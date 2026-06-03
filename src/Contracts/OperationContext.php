<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

use InvalidArgumentException;

final readonly class OperationContext
{
    /**
     * @param array<string, mixed> $accessContext
     * @param array<string, mixed> $capabilityContext
     * @param array<string, mixed> $auditContext
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $actorId,
        public string $correlationId,
        public array $accessContext = [],
        public array $capabilityContext = [],
        public array $auditContext = [],
        public array $metadata = [],
    ) {
        if (trim($this->actorId) === '') {
            throw new InvalidArgumentException('Operation actor id must not be empty.');
        }

        if (trim($this->correlationId) === '') {
            throw new InvalidArgumentException('Operation correlation id must not be empty.');
        }
    }
}
