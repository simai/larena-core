<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

/**
 * The audit trail of applying a scenario. The composition binds the audit pipeline (larena/admin does, through
 * larena/audit); the payload carries ids and keys, never setting values.
 *
 * @phpstan-type Payload array<string, mixed>
 */
interface SiteScenarioAudit
{
    /** @param array<string, mixed> $payload */
    public function record(string $operation, string $actor, array $payload): void;
}
