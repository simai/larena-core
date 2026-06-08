<?php

declare(strict_types=1);

namespace Larena\Core\Starter;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PackageRegistryDbSeed
{
    /**
     * @param array<string, mixed> $launchRecord
     * @param array<string, array<string, mixed>> $installedPackages
     * @param list<string> $requiredPackages
     *
     * @return array<string, mixed>
     */
    public static function apply(
        string $basePath,
        array $launchRecord,
        array $installedPackages,
        array $requiredPackages
    ): array {
        $backupPath = self::absolutePath($basePath, (string) $launchRecord['backup']['path']);
        $evidencePath = self::absolutePath($basePath, (string) $launchRecord['evidence_path']);
        $applyOutputPath = rtrim($evidencePath, '/') . '/package-registry-db-seed-output.json';

        if (!Schema::hasTable('larena_package_registry')) {
            $result = [
                'schema' => 'larena.install_apply_result.v1',
                'status' => 'blocked',
                'generated_at' => gmdate('c'),
                'reason' => 'package_registry_table_missing',
                'mutation' => 'package_registry_db_seed',
                'mutates_state' => false,
                'required_prior_step' => 'installer_db_schema_apply',
                'safe_command' => 'php artisan larena:install --dry-run',
            ];

            self::writeJson($applyOutputPath, $result);

            return $result;
        }

        $beforeRows = self::registryRows();
        self::writeJson($backupPath, [
            'schema' => 'larena.package_registry_db_seed_backup.v1',
            'generated_at' => gmdate('c'),
            'table' => 'larena_package_registry',
            'rows' => $beforeRows,
        ]);

        $payload = self::registryPayload($installedPackages, $requiredPackages);
        $timestamp = gmdate('Y-m-d H:i:s');
        foreach ($payload['packages'] as $package) {
            DB::table('larena_package_registry')->updateOrInsert(
                ['package_name' => $package['name']],
                [
                    'package_status' => $package['status'],
                    'version' => $package['version'],
                    'install_path' => $package['install_path'],
                    'source' => 'guarded_package_registry_db_seed',
                    'payload' => json_encode($package, JSON_UNESCAPED_SLASHES),
                    'updated_at' => $timestamp,
                    'created_at' => $timestamp,
                ],
            );
        }

        $afterRows = self::registryRows();
        $changed = self::fingerprint($beforeRows) !== self::fingerprint($afterRows);
        $result = [
            'schema' => 'larena.install_apply_result.v1',
            'status' => 'passed',
            'generated_at' => gmdate('c'),
            'mutation' => 'package_registry_db_seed',
            'state' => $changed ? 'applied' : 'already_current',
            'idempotent' => true,
            'mutates_state' => $changed,
            'launch_record' => [
                'id' => $launchRecord['id'],
                'path' => $launchRecord['_relative_path'] ?? null,
            ],
            'target' => [
                'table' => 'larena_package_registry',
                'package_count' => count($afterRows),
            ],
            'backup' => [
                'path' => self::relativePath($basePath, $backupPath),
                'preserved' => true,
            ],
            'rollback_plan' => [
                ...$launchRecord['rollback_plan'],
                'table' => 'larena_package_registry',
                'manual_restore_required' => true,
            ],
            'recovery_guidance' => [
                'status' => 'available',
                'type' => 'restore_table_rows_from_backup',
                'manual_steps' => [
                    'Stop further install batches.',
                    'Restore larena_package_registry rows from the backup marker if rollback is required.',
                    'Run php artisan larena:packages --full and php artisan larena:doctor --full after recovery.',
                ],
            ],
            'packages' => $payload['packages'],
            'evidence_path' => self::relativePath($basePath, $applyOutputPath),
        ];

        self::writeJson($applyOutputPath, $result);

        return $result;
    }

    /**
     * @param array<string, array<string, mixed>> $installedPackages
     * @param list<string> $requiredPackages
     *
     * @return array{schema: string, source: string, packages: list<array{name: string, status: string, version: mixed, install_path: mixed}>}
     */
    private static function registryPayload(array $installedPackages, array $requiredPackages): array
    {
        $packages = [];

        foreach ($requiredPackages as $package) {
            $installed = $installedPackages[$package] ?? null;
            $packages[] = [
                'name' => $package,
                'status' => $installed === null ? 'missing' : 'installed',
                'version' => $installed['version'] ?? null,
                'install_path' => $installed['install_path'] ?? null,
            ];
        }

        return [
            'schema' => 'larena.package_registry_db_seed.v1',
            'source' => 'composer_installed_json',
            'packages' => $packages,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function registryRows(): array
    {
        $rows = DB::table('larena_package_registry')
            ->orderBy('package_name')
            ->get()
            ->map(static fn (object $row): array => [
                'package_name' => $row->package_name,
                'package_status' => $row->package_status,
                'version' => $row->version,
                'install_path' => $row->install_path,
                'source' => $row->source,
                'payload' => is_string($row->payload) ? json_decode($row->payload, true) : $row->payload,
            ])
            ->all();

        return array_values($rows);
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
