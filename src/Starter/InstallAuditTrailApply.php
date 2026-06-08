<?php

declare(strict_types=1);

namespace Larena\Core\Starter;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Larena\Audit\Install\InstallAuditTrail;
use Throwable;

final class InstallAuditTrailApply
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
        $applyOutputPath = rtrim($evidencePath, '/') . '/install-audit-trail-apply-output.json';

        if (!class_exists(InstallAuditTrail::class)) {
            return self::blocked($applyOutputPath, 'audit_install_trail_contract_missing', [
                'required_package' => 'larena/audit',
            ]);
        }

        $migrationPath = InstallAuditTrail::migrationPath();
        if (!is_dir($migrationPath)) {
            return self::blocked($applyOutputPath, 'audit_install_trail_migration_path_missing', [
                'migration_path' => $migrationPath,
            ]);
        }

        $beforeRows = self::eventRows();
        self::writeJson($backupPath, [
            'schema' => 'larena.install_audit_trail_apply_backup.v1',
            'generated_at' => gmdate('c'),
            'target' => self::relativePath($basePath, $targetPath),
            'table' => 'larena_install_events',
            'migration_path' => $migrationPath,
            'table_existed' => Schema::hasTable('larena_install_events'),
            'rows' => $beforeRows,
        ]);

        Artisan::call('migrate', [
            '--path' => $migrationPath,
            '--realpath' => true,
            '--force' => true,
        ]);

        $migrateOutput = trim(Artisan::output());

        if (!Schema::hasTable('larena_install_events')) {
            return self::failed($applyOutputPath, 'install_audit_events_table_missing_after_migration', [
                'migration_path' => $migrationPath,
                'migrate_output' => $migrateOutput,
            ]);
        }

        $event = InstallAuditTrail::eventPayload(
            $launchRecord,
            'install_audit_trail_apply',
            'passed',
            [
                'database_status' => $applicationContext['database_connection']['status'] ?? 'unknown',
                'migration_path' => $migrationPath,
            ],
        );

        self::writeEvent($event);

        $afterRows = self::eventRows();
        $changed = self::fingerprint($beforeRows) !== self::fingerprint($afterRows);

        $state = [
            'schema' => 'larena.install_audit_trail_state.v1',
            'generated_at' => gmdate('c'),
            'status' => 'developer_testable_foundation',
            'launch_record' => [
                'id' => $launchRecord['id'] ?? null,
                'path' => $launchRecord['_relative_path'] ?? null,
            ],
            'table' => 'larena_install_events',
            'owner' => 'larena/audit',
            'event_count' => count($afterRows),
            'migration_path' => $migrationPath,
            'database' => [
                'status' => $applicationContext['database_connection']['status'] ?? 'unknown',
                'driver' => $applicationContext['database_connection']['driver'] ?? null,
                'default_connection' => $applicationContext['database_connection']['default_connection'] ?? null,
                'secrets_included' => false,
            ],
        ];
        self::writeJson($targetPath, $state);

        $result = [
            'schema' => 'larena.install_audit_trail_apply_result.v1',
            'status' => 'passed',
            'generated_at' => gmdate('c'),
            'mutation' => 'install_audit_trail_apply',
            'state' => $changed ? 'applied' : 'already_current',
            'idempotent' => true,
            'mutates_state' => $changed,
            'launch_record' => [
                'id' => $launchRecord['id'],
                'path' => $launchRecord['_relative_path'] ?? null,
            ],
            'target' => [
                'path' => self::relativePath($basePath, $targetPath),
                'table' => 'larena_install_events',
                'owner' => 'larena/audit',
                'event_count' => count($afterRows),
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
                'planned_tables' => InstallAuditTrail::plannedTables(),
                'migrate_output' => $migrateOutput,
            ],
            'audit_event' => $event,
            'rollback_plan' => [
                ...$launchRecord['rollback_plan'],
                'migration_path' => $migrationPath,
                'command' => 'php artisan migrate:rollback --path=' . $migrationPath . ' --realpath --step=1 --force',
                'realpath_required' => true,
            ],
            'recovery_guidance' => [
                'status' => 'available',
                'type' => 'migrate_rollback_then_restore_audit_marker',
                'manual_steps' => [
                    'Stop further install batches.',
                    'Run php artisan migrate:rollback --path=' . $migrationPath . ' --realpath --step=1 --force.',
                    'Restore larena_install_events rows from the backup marker if rollback is required.',
                    'Run php artisan larena:doctor --full and php artisan larena:install --dry-run --full after recovery.',
                ],
            ],
            'evidence_path' => self::relativePath($basePath, $applyOutputPath),
        ];

        self::writeJson($applyOutputPath, $result);

        return $result;
    }

    /**
     * @param array<string, mixed> $event
     */
    private static function writeEvent(array $event): void
    {
        $existing = DB::table('larena_install_events')
            ->where('event_key', $event['event_key'])
            ->first();

        if ($existing !== null && self::storedPayload($existing) === $event) {
            return;
        }

        $timestamp = gmdate('Y-m-d H:i:s');
        DB::table('larena_install_events')->updateOrInsert(
            ['event_key' => $event['event_key']],
            [
                'source_package' => $event['source_package'],
                'category' => $event['category'],
                'event_type' => $event['event_type'],
                'actor' => $event['actor'],
                'subject' => $event['subject'],
                'severity' => $event['severity'],
                'retention_class' => $event['retention_class'],
                'correlation_id' => $event['correlation_id'],
                'launch_record_id' => $event['launch_record_id'],
                'target_step' => $event['target_step'],
                'result_status' => $event['result_status'],
                'evidence_path' => $event['evidence_path'],
                'payload' => json_encode($event, JSON_UNESCAPED_SLASHES),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function eventRows(): array
    {
        if (!Schema::hasTable('larena_install_events')) {
            return [];
        }

        return DB::table('larena_install_events')
            ->orderBy('event_key')
            ->get()
            ->map(static fn (object $row): array => [
                'event_key' => $row->event_key,
                'source_package' => $row->source_package,
                'category' => $row->category,
                'event_type' => $row->event_type,
                'actor' => $row->actor,
                'subject' => $row->subject,
                'severity' => $row->severity,
                'retention_class' => $row->retention_class,
                'correlation_id' => $row->correlation_id,
                'launch_record_id' => $row->launch_record_id,
                'target_step' => $row->target_step,
                'result_status' => $row->result_status,
                'evidence_path' => $row->evidence_path,
                'payload' => is_string($row->payload) ? json_decode($row->payload, true) : $row->payload,
            ])
            ->all();
    }

    private static function storedPayload(object $row): ?array
    {
        if (!is_string($row->payload)) {
            return null;
        }

        try {
            $payload = json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function fingerprint(array $rows): string
    {
        return hash('sha256', json_encode($rows, JSON_UNESCAPED_SLASHES) ?: '');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function blocked(string $applyOutputPath, string $reason, array $payload = []): array
    {
        $result = array_merge([
            'schema' => 'larena.install_apply_result.v1',
            'status' => 'blocked',
            'generated_at' => gmdate('c'),
            'reason' => $reason,
            'mutation' => 'install_audit_trail_apply',
            'mutates_state' => false,
            'safe_command' => 'php artisan larena:install --dry-run',
        ], $payload);

        self::writeJson($applyOutputPath, $result);

        return $result;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function failed(string $applyOutputPath, string $reason, array $payload = []): array
    {
        $result = array_merge([
            'schema' => 'larena.install_apply_result.v1',
            'status' => 'failed',
            'generated_at' => gmdate('c'),
            'reason' => $reason,
            'mutation' => 'install_audit_trail_apply',
            'mutates_state' => false,
        ], $payload);

        self::writeJson($applyOutputPath, $result);

        return $result;
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
