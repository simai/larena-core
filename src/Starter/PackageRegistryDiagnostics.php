<?php

declare(strict_types=1);

namespace Larena\Core\Starter;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class PackageRegistryDiagnostics
{
    /**
     * @param list<string> $requiredPackages
     *
     * @return array<string, mixed>
     */
    public static function inspect(string $basePath, array $requiredPackages): array
    {
        $databaseRegistry = self::inspectDatabaseRegistry($requiredPackages);
        if (($databaseRegistry['status'] ?? null) !== 'unavailable') {
            return $databaseRegistry;
        }

        $registryPath = 'storage/app/larena/package-registry.json';
        $absolutePath = rtrim($basePath, '/') . '/' . $registryPath;

        if (!is_file($absolutePath)) {
            return [
                'schema' => 'larena.package_registry_diagnostics.v1',
                'status' => 'missing',
                'generated_at' => gmdate('c'),
                'mutates_state' => false,
                'path' => $registryPath,
                'reason' => 'package_registry_file_missing',
                'source_layer' => 'none',
                'database_registry' => [
                    'status' => 'unavailable',
                    'reason' => $databaseRegistry['reason'] ?? 'package_registry_table_missing',
                ],
                'required_packages' => $requiredPackages,
                'packages' => [],
            ];
        }

        $content = (string) file_get_contents($absolutePath);
        $registry = json_decode($content, true);

        if (!is_array($registry) || ($registry['schema'] ?? null) !== 'larena.package_registry_seed.v1') {
            return [
                'schema' => 'larena.package_registry_diagnostics.v1',
                'status' => 'invalid',
                'generated_at' => gmdate('c'),
                'mutates_state' => false,
                'path' => $registryPath,
                'reason' => 'package_registry_schema_invalid',
                'sha256' => hash('sha256', $content),
                'source_layer' => 'file',
                'fallback_reason' => $databaseRegistry['reason'] ?? 'database_registry_unavailable',
                'required_packages' => $requiredPackages,
                'packages' => [],
            ];
        }

        $packages = self::packages($registry);
        $missingRequired = array_values(array_filter(
            $requiredPackages,
            static fn (string $package): bool => !isset($packages[$package])
                || ($packages[$package]['status'] ?? null) !== 'installed',
        ));

        return [
            'schema' => 'larena.package_registry_diagnostics.v1',
            'status' => $missingRequired === [] ? 'passed' : 'degraded',
            'generated_at' => gmdate('c'),
            'mutates_state' => false,
            'path' => $registryPath,
            'source_layer' => 'file',
            'fallback_reason' => $databaseRegistry['reason'] ?? 'database_registry_unavailable',
            'sha256' => hash('sha256', $content),
            'source' => $registry['source'] ?? null,
            'required_packages' => $requiredPackages,
            'missing_required_packages' => $missingRequired,
            'packages' => array_values($packages),
            'package_count' => count($packages),
        ];
    }

    /**
     * @param list<string> $requiredPackages
     *
     * @return array<string, mixed>
     */
    private static function inspectDatabaseRegistry(array $requiredPackages): array
    {
        try {
            if (!Schema::hasTable('larena_package_registry')) {
                return [
                    'status' => 'unavailable',
                    'reason' => 'package_registry_table_missing',
                ];
            }

            $rows = DB::table('larena_package_registry')
                ->orderBy('package_name')
                ->get()
                ->map(static fn (object $row): array => [
                    'name' => $row->package_name,
                    'status' => $row->package_status,
                    'version' => $row->version,
                    'install_path' => $row->install_path,
                    'source' => $row->source,
                ])
                ->all();

            if ($rows === []) {
                return [
                    'status' => 'unavailable',
                    'reason' => 'package_registry_table_empty',
                ];
            }

            $packages = [];
            foreach ($rows as $row) {
                if (isset($row['name']) && is_string($row['name'])) {
                    $packages[$row['name']] = $row;
                }
            }

            $missingRequired = array_values(array_filter(
                $requiredPackages,
                static fn (string $package): bool => !isset($packages[$package])
                    || ($packages[$package]['status'] ?? null) !== 'installed',
            ));

            return [
                'schema' => 'larena.package_registry_diagnostics.v1',
                'status' => $missingRequired === [] ? 'passed' : 'degraded',
                'generated_at' => gmdate('c'),
                'mutates_state' => false,
                'source_layer' => 'database',
                'table' => 'larena_package_registry',
                'source' => 'guarded_package_registry_db_seed',
                'required_packages' => $requiredPackages,
                'missing_required_packages' => $missingRequired,
                'packages' => array_values($packages),
                'package_count' => count($packages),
            ];
        } catch (Throwable) {
            return [
                'status' => 'unavailable',
                'reason' => 'database_registry_check_unavailable',
            ];
        }
    }

    /**
     * @param array<string, mixed> $registry
     *
     * @return array<string, array<string, mixed>>
     */
    private static function packages(array $registry): array
    {
        $result = [];
        $packages = $registry['packages'] ?? [];

        if (!is_array($packages)) {
            return $result;
        }

        foreach ($packages as $package) {
            if (!is_array($package) || !isset($package['name']) || !is_string($package['name'])) {
                continue;
            }

            $result[$package['name']] = [
                'name' => $package['name'],
                'status' => is_string($package['status'] ?? null) ? $package['status'] : 'unknown',
                'version' => $package['version'] ?? null,
                'install_path' => $package['install_path'] ?? null,
            ];
        }

        return $result;
    }
}
