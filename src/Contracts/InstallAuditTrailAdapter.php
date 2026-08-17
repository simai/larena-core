<?php

declare(strict_types=1);

namespace Larena\Core\Contracts;

interface InstallAuditTrailAdapter
{
    public function migrationPath(): string;

    /** @return list<array<string, mixed>> */
    public function plannedTables(): array;

    /**
     * @param array<string, mixed> $launchRecord
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function eventPayload(array $launchRecord, string $step, string $status, array $context): array;
}
