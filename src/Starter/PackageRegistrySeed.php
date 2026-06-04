<?php

declare(strict_types=1);

namespace Larena\Core\Starter;

final class PackageRegistrySeed
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
        $targetPath = self::absolutePath($basePath, (string) $launchRecord['backup']['target']);
        $backupPath = self::absolutePath($basePath, (string) $launchRecord['backup']['path']);
        $evidencePath = self::absolutePath($basePath, (string) $launchRecord['evidence_path']);
        $applyOutputPath = rtrim($evidencePath, '/') . '/install-apply-output.json';
        $before = is_file($targetPath) ? (string) file_get_contents($targetPath) : null;
        $backup = is_file($backupPath)
            ? json_decode((string) file_get_contents($backupPath), true)
            : null;

        if (!is_array($backup)) {
            $backup = [
                'schema' => 'larena.package_registry_seed_backup.v1',
                'generated_at' => gmdate('c'),
                'target' => self::relativePath($basePath, $targetPath),
                'existed' => $before !== null,
                'sha256' => $before === null ? null : hash('sha256', $before),
                'content' => $before === null ? null : json_decode($before, true),
            ];

            self::writeJson($backupPath, $backup);
        }

        $registry = self::registryPayload($installedPackages, $requiredPackages);
        $registryJson = json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        $changed = $before !== $registryJson;

        self::writeString($targetPath, $registryJson);

        $result = [
            'schema' => 'larena.install_apply_result.v1',
            'status' => 'passed',
            'generated_at' => gmdate('c'),
            'mutation' => 'package_registry_seed',
            'idempotent' => true,
            'mutates_state' => $changed,
            'launch_record' => [
                'id' => $launchRecord['id'],
                'path' => $launchRecord['_relative_path'] ?? null,
            ],
            'target' => [
                'path' => self::relativePath($basePath, $targetPath),
                'sha256_before' => $before === null ? null : hash('sha256', $before),
                'sha256_after' => hash('sha256', $registryJson),
            ],
            'backup' => [
                'path' => self::relativePath($basePath, $backupPath),
                'existed' => $backup['existed'] ?? null,
                'preserved' => true,
            ],
            'rollback_plan' => $launchRecord['rollback_plan'],
            'packages' => $registry['packages'],
            'evidence_path' => self::relativePath($basePath, $applyOutputPath),
        ];

        self::writeJson($applyOutputPath, $result);

        return $result;
    }

    /**
     * @param array<string, array<string, mixed>> $installedPackages
     * @param list<string> $requiredPackages
     *
     * @return array<string, mixed>
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
            'schema' => 'larena.package_registry_seed.v1',
            'source' => 'composer_installed_json',
            'packages' => $packages,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function writeJson(string $path, array $payload): void
    {
        self::writeString($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    }

    private static function writeString(string $path, string $content): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($path, $content);
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
