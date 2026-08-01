<?php

declare(strict_types=1);

namespace Larena\Core\WebInstall;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migrator;
use Larena\Access\Runtime\SystemRolePresetSynchronizer;
use PDO;
use Throwable;

final readonly class LaravelWebInstallDatabaseLifecycle implements WebInstallDatabaseLifecycle
{
    public function __construct(
        private Application $app,
        private ConfigRepository $config,
        private DatabaseManager $databases,
        private Migrator $migrator,
    ) {
    }

    public function inspect(WebInstallDatabaseConfiguration $database): WebInstallPreflightReport
    {
        $checks = [];
        foreach ([
            'runtime.php' => version_compare((string) phpversion(), '8.3.0', '>='),
            'extension.pdo' => extension_loaded('pdo'),
            'extension.pdo_mysql' => extension_loaded('pdo_mysql'),
            'extension.mbstring' => extension_loaded('mbstring'),
            'extension.openssl' => extension_loaded('openssl'),
            'path.storage' => is_dir($this->app->storagePath()) && is_writable($this->app->storagePath()),
            'path.cache' => is_dir($this->app->bootstrapPath('cache')) && is_writable($this->app->bootstrapPath('cache')),
        ] as $id => $passed) {
            $checks[] = ['id' => $id, 'passed' => $passed, 'reason' => $passed ? 'ready' : 'requirement_failed'];
        }
        if (!extension_loaded('pdo_mysql')) {
            $checks[] = ['id' => 'mysql.connection', 'passed' => false, 'reason' => 'pdo_mysql_missing'];
            $checks[] = ['id' => 'mysql.schema_empty', 'passed' => false, 'reason' => 'not_checked'];
            return new WebInstallPreflightReport($checks);
        }
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $database->host, $database->port, $database->database),
                $database->username,
                $database->password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 4, PDO::ATTR_EMULATE_PREPARES => false],
            );
            $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            $compatible = preg_match('/\A(8\.|9\.)/', $version) === 1 && stripos($version, 'mariadb') === false;
            $statement = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = :schema');
            $statement->execute(['schema' => $database->database]);
            $empty = (int) $statement->fetchColumn() === 0;
            $checks[] = ['id' => 'mysql.connection', 'passed' => true, 'reason' => 'ready'];
            $checks[] = ['id' => 'mysql.compatibility', 'passed' => $compatible, 'reason' => $compatible ? 'ready' : 'mysql_unsupported'];
            $checks[] = ['id' => 'mysql.schema_empty', 'passed' => $empty, 'reason' => $empty ? 'ready' : 'schema_not_empty'];
        } catch (Throwable) {
            $checks[] = ['id' => 'mysql.connection', 'passed' => false, 'reason' => 'mysql_connection_failed'];
            $checks[] = ['id' => 'mysql.compatibility', 'passed' => false, 'reason' => 'not_checked'];
            $checks[] = ['id' => 'mysql.schema_empty', 'passed' => false, 'reason' => 'not_checked'];
        }

        return new WebInstallPreflightReport($checks);
    }

    public function prepare(WebInstallDatabaseConfiguration $database, string $operationId): array
    {
        $this->configureRuntime($database);
        $paths = $this->migrationPaths();
        if ($paths === []) {
            throw new WebInstallException('web_install_migration_paths_missing');
        }
        if (!$this->migrator->getRepository()->repositoryExists()) {
            $this->migrator->getRepository()->createRepository();
        }
        $ran = $this->migrator->run($paths, ['pretend' => false, 'step' => false]);
        $this->app->make(SystemRolePresetSynchronizer::class)->synchronizeForLifecycle();
        $ledger = $this->migrationLedgerSha256();
        $now = gmdate('Y-m-d H:i:s');
        $this->databases->connection('mysql')->table('larena_install_state')->updateOrInsert(
            ['state_key' => 'ordinary_hosting_web_install'],
            [
                'state_status' => 'prepared',
                'payload' => json_encode([
                    'operation_sha256' => hash('sha256', $operationId),
                    'migration_ledger_sha256' => $ledger,
                    'install_mode' => 'ordinary_hosting_web',
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'updated_at' => $now,
                'created_at' => $now,
            ],
        );

        return ['migration_count' => count($ran), 'migration_ledger_sha256' => $ledger];
    }

    public function isPrepared(WebInstallDatabaseConfiguration $database, string $operationId, ?string $migrationLedgerSha256): bool
    {
        try {
            $this->configureRuntime($database);
            if (!$this->migrator->getRepository()->repositoryExists()) {
                return false;
            }
            $row = $this->databases->connection('mysql')->table('larena_install_state')
                ->where('state_key', 'ordinary_hosting_web_install')->first();
            if ($row === null || !is_string($row->payload ?? null)) {
                return false;
            }
            $payload = json_decode($row->payload, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($payload)
                || !hash_equals((string) ($payload['operation_sha256'] ?? ''), hash('sha256', $operationId))) {
                return false;
            }
            $actualLedger = $this->migrationLedgerSha256();
            $recordedLedger = (string) ($payload['migration_ledger_sha256'] ?? '');
            if ($recordedLedger === '') {
                return $migrationLedgerSha256 === null && $actualLedger !== hash('sha256', '[]');
            }
            if (!hash_equals($recordedLedger, $actualLedger)) {
                return false;
            }

            return $migrationLedgerSha256 === null || hash_equals($migrationLedgerSha256, $actualLedger);
        } catch (Throwable) {
            return false;
        }
    }

    public function rollback(WebInstallDatabaseConfiguration $database): void
    {
        $this->configureRuntime($database);
        if (!$this->migrator->getRepository()->repositoryExists()) {
            return;
        }
        $this->migrator->reset($this->migrationPaths(), false);
        $this->migrator->getRepository()->deleteRepository();
    }

    private function configureRuntime(WebInstallDatabaseConfiguration $database): void
    {
        $this->config->set('database.default', 'mysql');
        foreach ($database->toPrivateArray() as $key => $value) {
            if ($key !== 'connection') {
                $this->config->set('database.connections.mysql.'.$key, $value);
            }
        }
        $this->databases->purge('mysql');
        $this->databases->setDefaultConnection('mysql');
        $this->migrator->setConnection('mysql');
    }

    private function migrationLedgerSha256(): string
    {
        $ran = $this->migrator->getRepository()->getRan();
        sort($ran, SORT_STRING);
        return hash('sha256', json_encode($ran, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return list<string> */
    private function migrationPaths(): array
    {
        return array_values(array_unique([$this->app->databasePath('migrations'), ...$this->migrator->paths()]));
    }
}
