<?php

declare(strict_types=1);

namespace Larena\Core\WebInstall;

interface WebInstallDatabaseLifecycle
{
    public function inspect(WebInstallDatabaseConfiguration $database): WebInstallPreflightReport;

    /** @return array{migration_count:int,migration_ledger_sha256:string} */
    public function prepare(WebInstallDatabaseConfiguration $database, string $operationId): array;

    public function isPrepared(
        WebInstallDatabaseConfiguration $database,
        string $operationId,
        ?string $migrationLedgerSha256,
    ): bool;

    public function rollback(WebInstallDatabaseConfiguration $database): void;
}
