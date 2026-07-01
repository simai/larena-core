<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Larena\Core\Starter\PackageRegistryDiagnostics;

$basePath = sys_get_temp_dir() . '/larena-core-package-registry-diagnostics-test-' . bin2hex(random_bytes(4));
$required = ['larena/core', 'larena/access'];

$missing = PackageRegistryDiagnostics::inspect($basePath, $required);
if (($missing['status'] ?? null) !== 'missing' || ($missing['mutates_state'] ?? null) !== false) {
    fwrite(STDERR, 'Missing registry diagnostics must be read-only missing.' . PHP_EOL);
    exit(1);
}

mkdir($basePath . '/storage/app/larena', 0775, true);
file_put_contents($basePath . '/storage/app/larena/package-registry.json', '{"schema":"wrong"}');

$invalid = PackageRegistryDiagnostics::inspect($basePath, $required);
if (($invalid['status'] ?? null) !== 'invalid' || ($invalid['reason'] ?? null) !== 'package_registry_schema_invalid') {
    fwrite(STDERR, 'Invalid registry diagnostics must fail closed.' . PHP_EOL);
    exit(1);
}

file_put_contents($basePath . '/storage/app/larena/package-registry.json', json_encode([
    'schema' => 'larena.package_registry_seed.v1',
    'source' => 'composer_installed_json',
    'packages' => [
        [
            'name' => 'larena/core',
            'status' => 'installed',
            'version' => 'dev-main',
            'install_path' => '../larena/core',
        ],
        [
            'name' => 'larena/access',
            'status' => 'installed',
            'version' => 'dev-main',
            'install_path' => '../larena/access',
        ],
    ],
], JSON_PRETTY_PRINT) . PHP_EOL);

$passed = PackageRegistryDiagnostics::inspect($basePath, $required);
if (($passed['status'] ?? null) !== 'passed') {
    fwrite(STDERR, 'Complete registry diagnostics must pass.' . PHP_EOL);
    exit(1);
}

if (($passed['package_count'] ?? null) !== 2) {
    fwrite(STDERR, 'Registry diagnostics should report package count.' . PHP_EOL);
    exit(1);
}

echo 'PackageRegistryDiagnosticsTest passed.' . PHP_EOL;
