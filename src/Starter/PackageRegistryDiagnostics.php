<?php

declare(strict_types=1);

namespace Larena\Core\Starter;

final class PackageRegistryDiagnostics
{
    /**
     * @param list<string> $requiredPackages
     *
     * @return array<string, mixed>
     */
    public static function inspect(string $basePath, array $requiredPackages): array
    {
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
            'sha256' => hash('sha256', $content),
            'source' => $registry['source'] ?? null,
            'required_packages' => $requiredPackages,
            'missing_required_packages' => $missingRequired,
            'packages' => array_values($packages),
            'package_count' => count($packages),
        ];
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
