<?php

declare(strict_types=1);

namespace Larena\Core\Starter;

final class DataContentClusterSmoke
{
    /**
     * @param array<string, mixed> $applicationContext
     *
     * @return array<string, mixed>
     */
    public static function run(string $outputPath, array $applicationContext): array
    {
        $basePath = (string) $applicationContext['base_path'];
        $installedPackages = self::installedPackages($basePath);
        $dataContentPackages = FoundationPackageSet::dataContent();
        $missingPackages = array_values(array_diff($dataContentPackages, array_keys($installedPackages)));
        $packageRegistry = PackageRegistryDiagnostics::inspect($basePath, FoundationPackageSet::foundationPreview());
        $packageRegistryMissing = self::missingFromRegistry($packageRegistry, $dataContentPackages);

        $checks = [
            'data_content_packages' => [
                'status' => $missingPackages === [] ? 'passed' : 'failed',
                'installed' => array_intersect_key($installedPackages, array_flip($dataContentPackages)),
                'missing' => $missingPackages,
            ],
            'package_registry' => $packageRegistry,
            'registry_data_content_packages' => [
                'status' => $packageRegistryMissing === [] ? 'passed' : 'degraded',
                'missing' => $packageRegistryMissing,
            ],
            'scope_boundary' => [
                'status' => 'passed',
                'mutates_state' => false,
                'user_facing_behavior' => false,
                'production_runtime' => false,
            ],
        ];

        $report = [
            'schema' => 'larena.data_content_cluster_smoke.v1',
            'status' => self::status($checks),
            'generated_at' => gmdate('c'),
            'mutates_state' => false,
            'cluster' => 'data-content-foundation',
            'packages' => $dataContentPackages,
            'checks' => $checks,
            'known_limitations' => [
                'developer_testable_foundation_only',
                'no_admin_ui',
                'no_public_ui',
                'no_production_backup_runtime',
                'no_full_search_engine_integration',
                'no_sitepack_runtime',
            ],
            'next_recommended_step' => 'developer_cockpit_review_or_next_guarded_batch',
        ];

        self::writeJson($outputPath, $report);

        return $report;
    }

    /**
     * @param array<string, mixed> $checks
     */
    private static function status(array $checks): string
    {
        if (($checks['data_content_packages']['status'] ?? null) !== 'passed') {
            return 'failed';
        }

        if (($checks['scope_boundary']['status'] ?? null) !== 'passed') {
            return 'failed';
        }

        $packageRegistryStatus = $checks['package_registry']['status'] ?? null;
        $registryDataContentStatus = $checks['registry_data_content_packages']['status'] ?? null;

        if ($packageRegistryStatus === 'passed' && $registryDataContentStatus === 'passed') {
            return 'passed';
        }

        if (in_array($packageRegistryStatus, ['missing', 'degraded'], true) || $registryDataContentStatus === 'degraded') {
            return 'degraded';
        }

        return 'failed';
    }

    /**
     * @param array<string, mixed> $packageRegistry
     * @param list<string> $requiredPackages
     *
     * @return list<string>
     */
    private static function missingFromRegistry(array $packageRegistry, array $requiredPackages): array
    {
        $packages = $packageRegistry['packages'] ?? [];
        if (!is_array($packages)) {
            return $requiredPackages;
        }

        $installed = [];
        foreach ($packages as $package) {
            if (!is_array($package) || !isset($package['name']) || !is_string($package['name'])) {
                continue;
            }

            if (($package['status'] ?? null) === 'installed') {
                $installed[$package['name']] = true;
            }
        }

        return array_values(array_filter(
            $requiredPackages,
            static fn (string $package): bool => !isset($installed[$package]),
        ));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function installedPackages(string $basePath): array
    {
        $installedPath = rtrim($basePath, '/') . '/vendor/composer/installed.json';
        if (!is_file($installedPath)) {
            return [];
        }

        $installed = json_decode((string) file_get_contents($installedPath), true);
        $packages = $installed['packages'] ?? $installed;

        if (!is_array($packages)) {
            return [];
        }

        $byName = [];
        foreach ($packages as $package) {
            if (is_array($package) && isset($package['name']) && is_string($package['name'])) {
                $byName[$package['name']] = [
                    'version' => $package['version'] ?? null,
                    'install_path' => $package['install-path'] ?? null,
                ];
            }
        }

        return $byName;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function writeJson(string $outputPath, array $payload): void
    {
        $directory = dirname($outputPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($outputPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }
}
