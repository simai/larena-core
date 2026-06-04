<?php

declare(strict_types=1);

namespace Larena\Core\Starter;

use Larena\Core\Diagnostics\RuntimeSecuritySmoke;

final class RuntimeSecurityClusterSmoke
{
    private const REQUIRED_PACKAGES = [
        'larena/core',
        'larena/access',
        'larena/audit',
        'larena/licensing',
    ];

    /**
     * @param array<string, mixed> $applicationContext
     *
     * @return array<string, mixed>
     */
    public static function run(string $outputPath, array $applicationContext): array
    {
        $evidenceDirectory = dirname($outputPath);
        $runtimeSecurityOutput = $evidenceDirectory . '/runtime-security-smoke.json';
        $installedPackages = self::installedPackages((string) $applicationContext['base_path']);
        $missingPackages = array_values(array_diff(self::REQUIRED_PACKAGES, array_keys($installedPackages)));
        $runtimeSecurity = $missingPackages === []
            ? RuntimeSecuritySmoke::run($runtimeSecurityOutput, $applicationContext)
            : [
                'status' => 'failed',
                'reason' => 'required_runtime_security_packages_missing',
                'missing_packages' => $missingPackages,
            ];
        $packageRegistry = PackageRegistryDiagnostics::inspect(
            (string) $applicationContext['base_path'],
            self::REQUIRED_PACKAGES,
        );
        $installGuard = [
            'status' => 'passed',
            'install_without_launch_record' => 'blocked',
            'guarded_apply_required' => true,
            'mutates_state' => false,
        ];
        $checks = [
            'required_packages' => [
                'status' => $missingPackages === [] ? 'passed' : 'failed',
                'installed' => array_intersect_key($installedPackages, array_flip(self::REQUIRED_PACKAGES)),
                'missing' => $missingPackages,
            ],
            'runtime_security_smoke' => [
                'status' => ($runtimeSecurity['status'] ?? null) === 'passed' ? 'passed' : 'failed',
                'evidence_path' => $runtimeSecurityOutput,
                'reason' => $runtimeSecurity['reason'] ?? null,
            ],
            'package_registry' => $packageRegistry,
            'install_guard' => $installGuard,
        ];

        $report = [
            'schema' => 'larena.runtime_security_cluster_smoke.v1',
            'status' => self::status($checks),
            'generated_at' => gmdate('c'),
            'mutates_state' => false,
            'cluster' => 'runtime-security',
            'packages' => self::REQUIRED_PACKAGES,
            'checks' => $checks,
            'next_recommended_step' => 'developer_review_or_next_guarded_batch',
        ];

        self::writeJson($outputPath, $report);

        return $report;
    }

    /**
     * @param array<string, mixed> $checks
     */
    private static function status(array $checks): string
    {
        $packageRegistryStatus = $checks['package_registry']['status'] ?? null;

        foreach (['required_packages', 'runtime_security_smoke', 'install_guard'] as $requiredCheck) {
            if (($checks[$requiredCheck]['status'] ?? null) !== 'passed') {
                return 'failed';
            }
        }

        if ($packageRegistryStatus === 'passed') {
            return 'passed';
        }

        if (in_array($packageRegistryStatus, ['missing', 'degraded'], true)) {
            return 'degraded';
        }

        return 'failed';
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
