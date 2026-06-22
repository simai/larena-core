<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Larena\Core\Starter\CoreAssetActivationContract;

$plan = CoreAssetActivationContract::adminSmartResourcePack('admin.smart.resource-pack', [
    [
        'asset_key' => 'admin.menu.smart',
        'kind' => 'module',
        'critical' => true,
        'final_path_owned_by_core_assets' => true,
    ],
    [
        'asset_key' => 'navigation.breadcrumbs.component',
        'kind' => 'css',
        'critical' => true,
        'final_path_owned_by_core_assets' => true,
    ],
]);

if (($plan['schema'] ?? null) !== 'larena.core_assets.activation_contract.v1') {
    fwrite(STDERR, 'Core asset activation schema mismatch.' . PHP_EOL);
    exit(1);
}

if (($plan['activation_owner'] ?? null) !== CoreAssetActivationContract::OWNER) {
    fwrite(STDERR, 'Core asset activation owner mismatch.' . PHP_EOL);
    exit(1);
}

if (($plan['status'] ?? null) !== CoreAssetActivationContract::STATUS) {
    fwrite(STDERR, 'Core asset activation status mismatch.' . PHP_EOL);
    exit(1);
}

foreach (['physical_publication_ready', 'writes_database', 'copies_to_root', 'uses_hardcoded_cdn'] as $flag) {
    if (($plan[$flag] ?? null) !== false) {
        fwrite(STDERR, 'Core asset activation must keep ' . $flag . '=false.' . PHP_EOL);
        exit(1);
    }
}

if (($plan['asset_count'] ?? null) !== 2 || count($plan['assets'] ?? []) !== 2) {
    fwrite(STDERR, 'Core asset activation asset count mismatch.' . PHP_EOL);
    exit(1);
}

$failures = [
    'owner' => [
        'asset_key' => 'admin.menu.smart',
        'kind' => 'module',
        'critical' => true,
        'final_path_owned_by_core_assets' => false,
    ],
    'root_copy' => [
        'asset_key' => 'admin.menu.smart',
        'kind' => 'module',
        'critical' => true,
        'final_path_owned_by_core_assets' => true,
        'root_copy_path' => 'public/vendor/larena/admin-menu.js',
    ],
    'cdn' => [
        'asset_key' => 'admin.menu.smart',
        'kind' => 'module',
        'critical' => true,
        'final_path_owned_by_core_assets' => true,
        'cdn_url' => 'https://cdn.example.invalid/admin-menu.js',
    ],
    'publishable' => [
        'asset_key' => 'admin.menu.smart',
        'kind' => 'module',
        'critical' => true,
        'final_path_owned_by_core_assets' => true,
        'publishable_path' => 'vendor/larena/admin-menu.js',
    ],
];

foreach ($failures as $case => $requirement) {
    try {
        CoreAssetActivationContract::adminSmartResourcePack('admin.smart.resource-pack', [$requirement]);
        fwrite(STDERR, 'Core asset activation accepted unsafe case: ' . $case . PHP_EOL);
        exit(1);
    } catch (InvalidArgumentException) {
        continue;
    }
}

echo 'CoreAssetActivationContractTest passed.' . PHP_EOL;
