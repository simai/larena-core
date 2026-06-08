<?php

declare(strict_types=1);

namespace Larena\Core\Starter;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

final class InstallerDbSchemaApply
{
    /**
     * @param array<string, mixed> $launchRecord
     * @param array<string, mixed> $applicationContext
     *
     * @return array<string, mixed>
     */
    public static function apply(Application $app, string $basePath, array $launchRecord, array $applicationContext): array
    {
        $targetPath = self::absolutePath($basePath, (string) $launchRecord['backup']['target']);
        $backupPath = self::absolutePath($basePath, (string) $launchRecord['backup']['path']);
        $evidencePath = self::absolutePath($basePath, (string) $launchRecord['evidence_path']);
        $applyOutputPath = rtrim($evidencePath, '/') . '/db-schema-apply-output.json';
        $migrationPath = self::migrationPath();
        $before = self::schemaState();

        self::writeJson($backupPath, [
            'schema' => 'larena.installer_db_schema_apply_backup.v1',
            'generated_at' => gmdate('c'),
            'target' => self::relativePath($basePath, $targetPath),
            'schema_state_before' => $before,
            'migration_path' => $migrationPath,
        ]);

        Artisan::call('migrate', [
            '--path' => $migrationPath,
            '--realpath' => true,
            '--force' => true,
        ]);

        $migrateOutput = trim(Artisan::output());
        $after = self::schemaState();
        $missing = array_values(array_filter(
            self::plannedTables(),
            static fn (array $table): bool => ($after[$table['name']] ?? false) !== true,
        ));
        $changed = $before !== $after;

        $state = [
            'schema' => 'larena.installer_db_schema_state.v1',
            'generated_at' => gmdate('c'),
            'status' => $missing === [] ? 'applied' : 'incomplete',
            'launch_record' => [
                'id' => $launchRecord['id'] ?? null,
                'path' => $launchRecord['_relative_path'] ?? null,
            ],
            'database' => [
                'status' => $applicationContext['database_connection']['status'] ?? 'unknown',
                'driver' => $applicationContext['database_connection']['driver'] ?? null,
                'default_connection' => $applicationContext['database_connection']['default_connection'] ?? null,
                'secrets_included' => false,
            ],
            'planned_tables' => self::plannedTables(),
            'schema_state_before' => $before,
            'schema_state_after' => $after,
            'migration_path' => $migrationPath,
        ];

        self::writeJson($targetPath, $state);

        $result = [
            'schema' => 'larena.installer_db_schema_apply_result.v1',
            'status' => $missing === [] ? 'passed' : 'failed',
            'generated_at' => gmdate('c'),
            'mutation' => 'installer_db_schema_apply',
            'state' => $changed ? 'applied' : 'already_current',
            'idempotent' => true,
            'mutates_state' => $changed,
            'launch_record' => [
                'id' => $launchRecord['id'],
                'path' => $launchRecord['_relative_path'] ?? null,
            ],
            'target' => [
                'path' => self::relativePath($basePath, $targetPath),
            ],
            'backup' => [
                'path' => self::relativePath($basePath, $backupPath),
                'preserved' => true,
            ],
            'database_schema' => [
                'applies_database_migrations' => true,
                'creates_database' => false,
                'writes_environment' => false,
                'migration_path' => $migrationPath,
                'planned_tables' => self::plannedTables(),
                'missing_tables' => $missing,
                'migrate_output' => $migrateOutput,
            ],
            'rollback_plan' => [
                ...$launchRecord['rollback_plan'],
                'migration_path' => $migrationPath,
                'command' => 'php artisan migrate:rollback --path=' . $migrationPath . ' --realpath --step=2 --force',
                'realpath_required' => true,
            ],
            'recovery_guidance' => [
                'status' => 'available',
                'type' => 'migrate_rollback_then_restore_state_marker',
                'manual_steps' => [
                    'Stop further install batches.',
                    'Run php artisan migrate:rollback --path=' . $migrationPath . ' --realpath --step=2 --force.',
                    'Restore or delete the schema state marker according to the backup marker.',
                    'Run php artisan larena:doctor --full and php artisan larena:install --dry-run --full after recovery.',
                ],
            ],
            'evidence_path' => self::relativePath($basePath, $applyOutputPath),
        ];

        self::writeJson($applyOutputPath, $result);

        return $result;
    }

    /**
     * @return list<array<string, string>>
     */
    public static function plannedTables(): array
    {
        return [
            [
                'name' => 'larena_package_registry',
                'owner' => 'larena/core',
                'purpose' => 'Persistent package registry projection after guarded package registry seed is accepted.',
            ],
            [
                'name' => 'larena_install_state',
                'owner' => 'larena/core',
                'purpose' => 'Installer stage state, gates, versions and recovery checkpoints.',
            ],
        ];
    }

    public static function migrationPath(): string
    {
        return dirname(__DIR__, 2) . '/database/migrations';
    }

    /**
     * @return array<string, bool>
     */
    public static function schemaState(): array
    {
        $state = [];

        foreach (self::plannedTables() as $table) {
            $state[$table['name']] = Schema::hasTable($table['name']);
        }

        return $state;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function writeJson(string $path, array $payload): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    private static function absolutePath(string $basePath, string $path): string
    {
        return rtrim($basePath, '/') . '/' . ltrim($path, '/');
    }

    private static function relativePath(string $basePath, string $absolutePath): string
    {
        $basePath = rtrim($basePath, '/') . '/';

        if (str_starts_with($absolutePath, $basePath)) {
            return substr($absolutePath, strlen($basePath));
        }

        return $absolutePath;
    }
}
