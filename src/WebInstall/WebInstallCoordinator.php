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

final readonly class WebInstallCoordinator
{
    private const CAPABILITY_TTL = 1800;

    public function __construct(
        private Application $app,
        private ConfigRepository $config,
        private DatabaseManager $databases,
        private Migrator $migrator,
        private WebInstallStateStore $state,
    ) {
    }

    /** @return array{status:string,reason:string} */
    public function availability(): array
    {
        $state = $this->state->read();
        if (is_file($this->state->configurationPath())) {
            return ($state['status'] ?? null) === 'completed'
                ? ['status' => 'closed', 'reason' => 'web_install_completed']
                : ['status' => 'blocked', 'reason' => 'web_install_configuration_without_completion'];
        }
        if (($state['status'] ?? null) === 'recovery_required') {
            return ['status' => 'blocked', 'reason' => 'web_install_recovery_required'];
        }

        return ['status' => 'available', 'reason' => 'web_install_available'];
    }

    public function claim(string $sessionId, string $capability): void
    {
        if ($sessionId === '' || strlen($capability) < 32) {
            throw new WebInstallException('web_install_capability_missing');
        }
        $now = time();
        $state = $this->state->read();
        if ($state === null) {
            $this->state->write($this->readyState($sessionId, $capability, $now));
            return;
        }
        if (($state['status'] ?? null) === 'completed' || is_file($this->state->configurationPath())) {
            throw new WebInstallException('web_install_closed');
        }
        if (($state['status'] ?? null) === 'recovery_required') {
            throw new WebInstallException('web_install_recovery_required');
        }
        if (($state['status'] ?? null) === 'ready' && (int) ($state['expires_at'] ?? 0) < $now) {
            if (hash_equals((string) ($state['session_hash'] ?? ''), hash('sha256', $sessionId))
                && hash_equals((string) ($state['capability_hash'] ?? ''), hash('sha256', $capability))) {
                throw new WebInstallException('web_install_capability_expired');
            }
            $this->state->write($this->readyState($sessionId, $capability, $now));
            return;
        }
        $this->assertCapability($state, $sessionId, $capability, $now);
    }

    public function preflight(string $sessionId, string $capability, WebInstallDatabaseConfiguration $database): WebInstallPreflightReport
    {
        $this->assertCapability($this->requiredState(), $sessionId, $capability, time());
        return $this->inspect($database);
    }

    /** @return array{status:string,checkpoint:string,migration_count:int,operation_id:string} */
    public function apply(string $sessionId, string $capability, WebInstallDatabaseConfiguration $database): array
    {
        return $this->state->withLock(function () use ($sessionId, $capability, $database): array {
            $state = $this->requiredState();
            $this->assertCapability($state, $sessionId, $capability, time());
            if (!in_array($state['status'] ?? null, ['ready', 'applying'], true)) {
                throw new WebInstallException('web_install_wrong_state');
            }
            if ($state['status'] === 'applying') {
                $this->rollbackInterrupted($state);
                $state = $this->requiredState();
                $this->assertCapability($state, $sessionId, $capability, time());
            }

            if (!$this->inspect($database)->passed()) {
                throw new WebInstallException('web_install_preflight_failed');
            }

            $operationId = bin2hex(random_bytes(16));
            $this->state->writeCandidate($database->toPrivateArray());
            $this->state->write([
                ...$state,
                'status' => 'applying',
                'checkpoint' => 'configuration_staged',
                'operation_id' => $operationId,
                'database_fingerprint' => $this->databaseFingerprint($database),
                'updated_at' => time(),
            ]);

            $configurationActivated = false;
            try {
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
                $connection = $this->databases->connection('mysql');
                $connection->table('larena_install_state')->updateOrInsert(
                    ['state_key' => 'ordinary_hosting_web_install'],
                    [
                        'state_status' => 'completed',
                        'payload' => json_encode([
                            'operation_sha256' => hash('sha256', $operationId),
                            'install_mode' => 'ordinary_hosting_web',
                        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                        'updated_at' => gmdate('Y-m-d H:i:s'),
                        'created_at' => gmdate('Y-m-d H:i:s'),
                    ],
                );
                $this->state->activateCandidate();
                $configurationActivated = true;
                $this->state->write([
                    'status' => 'completed',
                    'checkpoint' => 'completed',
                    'operation_id' => $operationId,
                    'database_fingerprint' => $this->databaseFingerprint($database),
                    'migration_count' => count($ran),
                    'completed_at' => time(),
                ]);

                return ['status' => 'completed', 'checkpoint' => 'completed', 'migration_count' => count($ran), 'operation_id' => $operationId];
            } catch (Throwable $exception) {
                $this->rollbackFailedApply($state, $operationId, $configurationActivated);
                throw $exception instanceof WebInstallException
                    ? $exception
                    : new WebInstallException('web_install_apply_failed');
            }
        });
    }

    private function inspect(WebInstallDatabaseConfiguration $database): WebInstallPreflightReport
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

    /** @param array<string, mixed> $state */
    private function rollbackInterrupted(array $state): void
    {
        $candidate = $this->state->readCandidate();
        $database = new WebInstallDatabaseConfiguration(
            (string) ($candidate['host'] ?? ''), (int) ($candidate['port'] ?? 0),
            (string) ($candidate['database'] ?? ''), (string) ($candidate['username'] ?? ''),
            (string) ($candidate['password'] ?? ''),
        );
        $this->configureRuntime($database);
        $this->rollbackToReady($state, 'interrupted_apply_rolled_back');
    }

    /** @param array<string, mixed> $priorState */
    private function rollbackFailedApply(array $priorState, string $operationId, bool $configurationActivated): void
    {
        $this->rollbackToReady(
            $priorState,
            'failed_apply_rolled_back',
            hash('sha256', $operationId),
            $configurationActivated,
        );
    }

    /** @param array<string, mixed> $state */
    private function rollbackToReady(
        array $state,
        string $checkpoint,
        ?string $lastOperation = null,
        bool $discardConfiguration = false,
    ): void
    {
        try {
            if ($this->migrator->getRepository()->repositoryExists()) {
                $this->migrator->reset($this->migrationPaths(), false);
            }
            if ($discardConfiguration) {
                $this->state->discardConfiguration();
            }
            $this->state->discardCandidate();
            $this->state->write([
                ...$state,
                'status' => 'ready',
                'checkpoint' => $checkpoint,
                'last_operation_sha256' => $lastOperation,
                'updated_at' => time(),
            ]);
        } catch (Throwable) {
            $this->state->write([
                ...$state,
                'status' => 'recovery_required',
                'checkpoint' => $checkpoint.'_failed',
                'last_operation_sha256' => $lastOperation,
                'updated_at' => time(),
            ]);
            throw new WebInstallException('web_install_recovery_required');
        }
    }

    /** @return array<string, mixed> */
    private function requiredState(): array
    {
        return $this->state->read() ?? throw new WebInstallException('web_install_capability_missing');
    }

    /** @param array<string, mixed> $state */
    private function assertCapability(array $state, string $sessionId, string $capability, int $now): void
    {
        if (($state['status'] ?? null) === 'completed') {
            throw new WebInstallException('web_install_closed');
        }
        if ((int) ($state['expires_at'] ?? 0) < $now) {
            throw new WebInstallException('web_install_capability_expired');
        }
        if (!hash_equals((string) ($state['session_hash'] ?? ''), hash('sha256', $sessionId))
            || !hash_equals((string) ($state['capability_hash'] ?? ''), hash('sha256', $capability))) {
            throw new WebInstallException('web_install_capability_invalid');
        }
    }

    /** @return array<string, mixed> */
    private function readyState(string $sessionId, string $capability, int $now): array
    {
        return [
            'status' => 'ready', 'checkpoint' => 'capability_claimed',
            'session_hash' => hash('sha256', $sessionId),
            'capability_hash' => hash('sha256', $capability),
            'expires_at' => $now + self::CAPABILITY_TTL,
            'created_at' => $now, 'updated_at' => $now,
        ];
    }

    private function databaseFingerprint(WebInstallDatabaseConfiguration $database): string
    {
        return hash_hmac('sha256', $database->database.'@'.$database->host.':'.$database->port, (string) $this->config->get('app.key'));
    }

    /** @return list<string> */
    private function migrationPaths(): array
    {
        return array_values(array_unique([
            $this->app->databasePath('migrations'),
            ...$this->migrator->paths(),
        ]));
    }
}
