<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Larena\Core\Starter\DataContentClusterSmoke;
use Larena\Core\Starter\FoundationPackageSet;

$basePath = sys_get_temp_dir() . '/larena-core-data-content-smoke-test-' . bin2hex(random_bytes(4));
$outputPath = $basePath . '/docs/project-management/evidence/data-content-smoke-output.json';
$foundationPackages = FoundationPackageSet::foundationPreview();

mkdir($basePath . '/vendor/composer', 0775, true);
mkdir($basePath . '/storage/app/larena', 0775, true);

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

$report = DataContentClusterSmoke::run($outputPath, [
    'base_path' => $basePath,
    'laravel_version' => '13.0.0',
]);

if (($report['schema'] ?? null) !== 'larena.data_content_cluster_smoke.v1') {
    fwrite(STDERR, 'Data/content smoke report schema mismatch.' . PHP_EOL);
    exit(1);
}

if (($report['status'] ?? null) !== 'passed') {
    fwrite(STDERR, 'Data/content smoke report must pass with all required inputs.' . PHP_EOL);
    exit(1);
}

if (($report['mutates_state'] ?? null) !== false) {
    fwrite(STDERR, 'Data/content smoke report must be read-only.' . PHP_EOL);
    exit(1);
}

if (($report['checks']['data_content_packages']['status'] ?? null) !== 'passed') {
    fwrite(STDERR, 'Data/content packages check must pass.' . PHP_EOL);
    exit(1);
}

if (($report['checks']['registry_data_content_packages']['status'] ?? null) !== 'passed') {
    fwrite(STDERR, 'Data/content registry check must pass.' . PHP_EOL);
    exit(1);
}

if (($report['checks']['scope_boundary']['user_facing_behavior'] ?? null) !== false) {
    fwrite(STDERR, 'Data/content smoke must not claim user-facing behavior.' . PHP_EOL);
    exit(1);
}

if (!in_array('no_admin_ui', $report['known_limitations'] ?? [], true)) {
    fwrite(STDERR, 'Data/content smoke must expose known limitations.' . PHP_EOL);
    exit(1);
}

if (!is_file($outputPath)) {
    fwrite(STDERR, 'Data/content smoke output was not written.' . PHP_EOL);
    exit(1);
}

$missingRegistryBasePath = sys_get_temp_dir() . '/larena-core-data-content-smoke-missing-registry-test-' . bin2hex(random_bytes(4));
mkdir($missingRegistryBasePath . '/vendor/composer', 0775, true);
file_put_contents($missingRegistryBasePath . '/vendor/composer/installed.json', json_encode([
    'packages' => array_map(
        static fn (string $package): array => ['name' => $package, 'version' => 'dev-main'],
        $foundationPackages,
    ),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

$missingRegistry = DataContentClusterSmoke::run(
    $missingRegistryBasePath . '/source/output/data-content-smoke.json',
    ['base_path' => $missingRegistryBasePath],
);

if (($missingRegistry['status'] ?? null) !== 'degraded') {
    fwrite(STDERR, 'Missing package registry should degrade, not pass.' . PHP_EOL);
    exit(1);
}

echo 'DataContentClusterSmokeTest passed.' . PHP_EOL;
