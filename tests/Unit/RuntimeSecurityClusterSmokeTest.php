<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Larena\Core\Starter\RuntimeSecurityClusterSmoke;

$basePath = sys_get_temp_dir() . '/larena-core-cluster-smoke-test-' . bin2hex(random_bytes(4));
$outputPath = $basePath . '/docs/project-management/evidence/cluster-smoke-output.json';

mkdir($basePath . '/vendor/composer', 0775, true);
mkdir($basePath . '/storage/app/larena', 0775, true);
mkdir($basePath . '/bootstrap/cache', 0775, true);

$requiredPackages = [
    'larena/core',
    'larena/access',
    'larena/audit',
    'larena/licensing',
];
$foundationPackages = [
    ...$requiredPackages,
    'larena/storage',
    'larena/filesystem',
    'larena/lang',
    'larena/search',
    'larena/link',
    'larena/backup',
    'larena/file-manager',
];

file_put_contents($basePath . '/vendor/composer/installed.json', json_encode([
    'packages' => array_map(
        static fn (string $package): array => [
            'name' => $package,
            'version' => 'dev-main',
            'install-path' => '../../larena-workspace/packages/' . substr($package, strlen('larena/')),
        ],
        $foundationPackages,
    ),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

file_put_contents($basePath . '/storage/app/larena/package-registry.json', json_encode([
    'schema' => 'larena.package_registry_seed.v1',
    'source' => 'composer_installed_json',
    'packages' => array_map(
        static fn (string $package): array => [
            'name' => $package,
            'status' => 'installed',
            'version' => 'dev-main',
            'install_path' => '../larena-workspace/packages/' . substr($package, strlen('larena/')),
        ],
        $foundationPackages,
    ),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

$report = RuntimeSecurityClusterSmoke::run($outputPath, [
    'base_path' => $basePath,
    'laravel_version' => '13.0.0',
]);

if (($report['schema'] ?? null) !== 'larena.runtime_security_cluster_smoke.v1') {
    fwrite(STDERR, 'Cluster smoke report schema mismatch.' . PHP_EOL);
    exit(1);
}

if (($report['status'] ?? null) !== 'passed') {
    fwrite(STDERR, 'Cluster smoke report must pass with all required inputs.' . PHP_EOL);
    exit(1);
}

if (($report['mutates_state'] ?? null) !== false) {
    fwrite(STDERR, 'Cluster smoke report must be read-only.' . PHP_EOL);
    exit(1);
}

if (($report['checks']['package_registry']['status'] ?? null) !== 'passed') {
    fwrite(STDERR, 'Cluster smoke must include passed package registry diagnostics.' . PHP_EOL);
    exit(1);
}

if (($report['checks']['package_registry']['package_count'] ?? null) !== count($foundationPackages)) {
    fwrite(STDERR, 'Cluster smoke package registry diagnostics must include the foundation package set.' . PHP_EOL);
    exit(1);
}

if (($report['checks']['runtime_security_smoke']['status'] ?? null) !== 'passed') {
    fwrite(STDERR, 'Cluster smoke must include passed runtime security smoke.' . PHP_EOL);
    exit(1);
}

if (!is_file($outputPath)) {
    fwrite(STDERR, 'Cluster smoke output was not written.' . PHP_EOL);
    exit(1);
}

if (!is_file(dirname($outputPath) . '/runtime-security-smoke.json')) {
    fwrite(STDERR, 'Runtime security evidence was not written.' . PHP_EOL);
    exit(1);
}

echo 'RuntimeSecurityClusterSmokeTest passed.' . PHP_EOL;
