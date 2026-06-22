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

$routePlan = CoreAssetActivationContract::adminSmartResourcePackReadOnlyRoute(
    'admin.smart.resource-pack',
    [
        [
            'carrier_key' => 'admin.menu',
            'asset_key' => 'admin.menu.smart',
            'kind' => 'module',
            'critical' => true,
            'resource_path' => 'resources/simai/smart/admin-menu/admin-menu.js',
            'final_path_owned_by_core_assets' => true,
        ],
        [
            'carrier_key' => 'navigation.breadcrumbs',
            'asset_key' => 'navigation.breadcrumbs.component',
            'kind' => 'module',
            'critical' => true,
            'resource_path' => 'resources/simai/smart/breadcrumbs/breadcrumbs.js',
            'final_path_owned_by_core_assets' => true,
        ],
    ],
    '/larena/internal/package-owned-admin-frontend/assets',
);

if (($routePlan['status'] ?? null) !== CoreAssetActivationContract::ROUTE_PUBLICATION_STATUS) {
    fwrite(STDERR, 'Core asset read-only route status mismatch.' . PHP_EOL);
    exit(1);
}

if (($routePlan['activation_mode'] ?? null) !== 'read_only_route') {
    fwrite(STDERR, 'Core asset read-only route activation mode mismatch.' . PHP_EOL);
    exit(1);
}

if (($routePlan['physical_publication_ready'] ?? null) !== true) {
    fwrite(STDERR, 'Core asset read-only route publication must be ready.' . PHP_EOL);
    exit(1);
}

if (($routePlan['copies_to_root'] ?? null) !== false || ($routePlan['uses_hardcoded_cdn'] ?? null) !== false) {
    fwrite(STDERR, 'Core asset read-only route must not copy to root or use CDN.' . PHP_EOL);
    exit(1);
}

if (($routePlan['assets'][0]['final_path'] ?? null) !== '/larena/internal/package-owned-admin-frontend/assets/admin.menu.smart') {
    fwrite(STDERR, 'Core asset read-only route final path mismatch.' . PHP_EOL);
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

$routeFailures = [
    'absolute_resource' => [
        [
            'carrier_key' => 'admin.menu',
            'asset_key' => 'admin.menu.smart',
            'kind' => 'module',
            'resource_path' => '/tmp/admin-menu.js',
            'final_path_owned_by_core_assets' => true,
        ],
        '/larena/internal/package-owned-admin-frontend/assets',
    ],
    'cdn_route' => [
        [
            'carrier_key' => 'admin.menu',
            'asset_key' => 'admin.menu.smart',
            'kind' => 'module',
            'resource_path' => 'resources/simai/smart/admin-menu/admin-menu.js',
            'final_path_owned_by_core_assets' => true,
        ],
        'https://cdn.example.invalid/assets',
    ],
    'root_copy' => [
        [
            'carrier_key' => 'admin.menu',
            'asset_key' => 'admin.menu.smart',
            'kind' => 'module',
            'resource_path' => 'resources/simai/smart/admin-menu/admin-menu.js',
            'final_path_owned_by_core_assets' => true,
            'root_copy_path' => 'public/vendor/larena/admin-menu.js',
        ],
        '/larena/internal/package-owned-admin-frontend/assets',
    ],
];

foreach ($routeFailures as $case => [$requirement, $routeBase]) {
    try {
        CoreAssetActivationContract::adminSmartResourcePackReadOnlyRoute('admin.smart.resource-pack', [$requirement], $routeBase);
        fwrite(STDERR, 'Core asset read-only route accepted unsafe case: ' . $case . PHP_EOL);
        exit(1);
    } catch (InvalidArgumentException) {
        continue;
    }
}

echo 'CoreAssetActivationContractTest passed.' . PHP_EOL;
