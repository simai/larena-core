<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

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

if (!str_contains($routePlan['renderable_tags'][0] ?? '', 'data-larena-asset-owner="larena/core:core.assets"')) {
    fwrite(STDERR, 'Core asset read-only route must expose core-owned renderable tags.' . PHP_EOL);
    exit(1);
}

if (!str_contains($routePlan['renderable_tags'][1] ?? '', 'navigation.breadcrumbs.component')) {
    fwrite(STDERR, 'Core asset read-only route renderable tag key mismatch.' . PHP_EOL);
    exit(1);
}

$uiAssetGraph = new class {
    /**
     * @var list<object>
     */
    public array $requirements;

    public function __construct()
    {
        $this->requirements = [
            new class {
                public string $assetKey = 'admin.menu.smart';
                public string $kind = 'module';
                public bool $critical = true;
                public bool $finalPathOwnedByCoreAssets = true;
            },
            new class {
                public string $assetKey = 'admin.shell.read_only_route.css';
                public string $kind = 'css';
                public bool $critical = true;
                public bool $finalPathOwnedByCoreAssets = true;
            },
        ];
    }
};

$frontendConveyorPlan = CoreAssetActivationContract::frontendConveyorDemoActivation(
    'admin.frontend-conveyor.resource-pack',
    $uiAssetGraph,
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
            'carrier_key' => 'admin.shell',
            'asset_key' => 'admin.shell.read_only_route.css',
            'kind' => 'css',
            'critical' => true,
            'resource_path' => 'resources/simai/smart/admin-shell/read-only-route.css',
            'final_path_owned_by_core_assets' => true,
        ],
    ],
    '/larena/internal/package-owned-admin-frontend/assets',
);

if (($frontendConveyorPlan['status'] ?? null) !== CoreAssetActivationContract::FRONTEND_CONVEYOR_STATUS) {
    fwrite(STDERR, 'Frontend conveyor core assets status mismatch.' . PHP_EOL);
    exit(1);
}

if (($frontendConveyorPlan['activation_mode'] ?? null) !== 'frontend_conveyor_demo_read_only_route') {
    fwrite(STDERR, 'Frontend conveyor core assets activation mode mismatch.' . PHP_EOL);
    exit(1);
}

if (($frontendConveyorPlan['assets'][0]['final_path'] ?? null) !== '/larena/internal/package-owned-admin-frontend/assets/admin.menu.smart') {
    fwrite(STDERR, 'Frontend conveyor module final path mismatch.' . PHP_EOL);
    exit(1);
}

if (($frontendConveyorPlan['assets'][1]['final_path'] ?? null) !== '/larena/internal/package-owned-admin-frontend/assets/admin.shell.read_only_route.css') {
    fwrite(STDERR, 'Frontend conveyor CSS final path mismatch.' . PHP_EOL);
    exit(1);
}

if (!str_contains($frontendConveyorPlan['renderable_tags'][0] ?? '', 'type="module"')) {
    fwrite(STDERR, 'Frontend conveyor must render module tag.' . PHP_EOL);
    exit(1);
}

if (!str_contains($frontendConveyorPlan['renderable_tags'][1] ?? '', 'rel="stylesheet"')) {
    fwrite(STDERR, 'Frontend conveyor must render stylesheet tag.' . PHP_EOL);
    exit(1);
}

if (($frontendConveyorPlan['headers']['X-Larena-Asset-Activation-Owner'] ?? null) !== CoreAssetActivationContract::OWNER) {
    fwrite(STDERR, 'Frontend conveyor owner header mismatch.' . PHP_EOL);
    exit(1);
}

$descriptorGraph = new class {
    /**
     * @var list<object>
     */
    public array $requirements;

    public function __construct()
    {
        $this->requirements = [
            new class {
                public string $assetKey = 'data.table.read_only_adapter';
                public string $kind = 'module';
                public bool $critical = true;
                public bool $finalPathOwnedByCoreAssets = true;
            },
            new class {
                public string $assetKey = 'admin.shell.read_only_route.css';
                public string $kind = 'css';
                public bool $critical = true;
                public bool $finalPathOwnedByCoreAssets = true;
            },
        ];
    }
};

$assetDescriptor = [
    'schema' => 'larena.core_assets.set.v1',
    'asset_set' => 'admin.read_only_shell',
    'owner_package' => 'larena/ui',
    'activation_owner' => CoreAssetActivationContract::OWNER,
    'version' => '0.1.0',
    'context' => 'admin',
    'resources' => [
        [
            'key' => 'data.table.read_only_adapter',
            'kind' => 'module',
            'path' => 'resources/assets/smart/table/table.js',
            'load' => 'critical',
        ],
        [
            'key' => 'admin.shell.read_only_route.css',
            'kind' => 'css',
            'path' => 'resources/assets/admin-shell/read-only-route.css',
            'load' => 'critical',
        ],
    ],
    'policy' => [
        'local_only' => true,
        'allow_cdn' => false,
        'allow_template_direct_include' => false,
        'final_path_owned_by_core_assets' => true,
    ],
];

$descriptorActivation = CoreAssetActivationContract::packageAssetDescriptorActivation(
    'admin.read_only_shell',
    $descriptorGraph,
    $assetDescriptor,
    '/larena/internal/package-owned-admin-frontend/assets',
);

if (($descriptorActivation['status'] ?? null) !== CoreAssetActivationContract::ASSET_DESCRIPTOR_STATUS) {
    fwrite(STDERR, 'Asset descriptor activation status mismatch.' . PHP_EOL);
    exit(1);
}

if (($descriptorActivation['activation_mode'] ?? null) !== 'package_asset_descriptor_read_only_route') {
    fwrite(STDERR, 'Asset descriptor activation mode mismatch.' . PHP_EOL);
    exit(1);
}

if (($descriptorActivation['asset_descriptor']['allow_template_direct_include'] ?? null) !== false) {
    fwrite(STDERR, 'Asset descriptor must reject template direct include.' . PHP_EOL);
    exit(1);
}

if (!str_contains($descriptorActivation['renderable_tags'][0] ?? '', 'data.table.read_only_adapter')) {
    fwrite(STDERR, 'Asset descriptor module tag mismatch.' . PHP_EOL);
    exit(1);
}

if (!str_contains($descriptorActivation['renderable_tags'][1] ?? '', 'admin.shell.read_only_route.css')) {
    fwrite(STDERR, 'Asset descriptor CSS tag mismatch.' . PHP_EOL);
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

$renderTagFailures = [
    'owner' => [
        [
            'asset_key' => 'admin.menu.smart',
            'kind' => 'module',
            'activation_owner' => 'larena/admin',
            'physical_publication_ready' => true,
            'final_path' => '/larena/internal/package-owned-admin-frontend/assets/admin.menu.smart',
        ],
    ],
    'not_ready' => [
        [
            'asset_key' => 'admin.menu.smart',
            'kind' => 'module',
            'activation_owner' => CoreAssetActivationContract::OWNER,
            'physical_publication_ready' => false,
            'final_path' => '/larena/internal/package-owned-admin-frontend/assets/admin.menu.smart',
        ],
    ],
    'cdn_final_path' => [
        [
            'asset_key' => 'admin.menu.smart',
            'kind' => 'module',
            'activation_owner' => CoreAssetActivationContract::OWNER,
            'physical_publication_ready' => true,
            'final_path' => 'https://cdn.example.invalid/admin.menu.smart',
        ],
    ],
    'root_copy' => [
        [
            'asset_key' => 'admin.menu.smart',
            'kind' => 'module',
            'activation_owner' => CoreAssetActivationContract::OWNER,
            'physical_publication_ready' => true,
            'final_path' => '/larena/internal/package-owned-admin-frontend/assets/admin.menu.smart',
            'root_copy_path' => 'public/vendor/larena/admin-menu.js',
        ],
    ],
];

foreach ($renderTagFailures as $case => $assets) {
    try {
        CoreAssetActivationContract::renderTags($assets);
        fwrite(STDERR, 'Core asset render tags accepted unsafe case: ' . $case . PHP_EOL);
        exit(1);
    } catch (InvalidArgumentException) {
        continue;
    }
}

$frontendConveyorFailures = [
    'unknown_asset_key' => [
        new class {
            public array $requirements;

            public function __construct()
            {
                $this->requirements = [
                    new class {
                        public string $assetKey = 'unknown.asset';
                        public string $kind = 'module';
                        public bool $critical = true;
                        public bool $finalPathOwnedByCoreAssets = true;
                    },
                ];
            }
        },
        [
            [
                'carrier_key' => 'admin.menu',
                'asset_key' => 'admin.menu.smart',
                'kind' => 'module',
                'resource_path' => 'resources/simai/smart/admin-menu/admin-menu.js',
                'final_path_owned_by_core_assets' => true,
            ],
        ],
        '/larena/internal/package-owned-admin-frontend/assets',
    ],
    'direct_bypass_route' => [
        $uiAssetGraph,
        [
            [
                'carrier_key' => 'admin.menu',
                'asset_key' => 'admin.menu.smart',
                'kind' => 'module',
                'resource_path' => 'resources/simai/smart/admin-menu/admin-menu.js',
                'final_path_owned_by_core_assets' => true,
            ],
            [
                'carrier_key' => 'admin.shell',
                'asset_key' => 'admin.shell.read_only_route.css',
                'kind' => 'css',
                'resource_path' => 'resources/simai/smart/admin-shell/read-only-route.css',
                'final_path_owned_by_core_assets' => true,
            ],
        ],
        'https://cdn.example.invalid/assets',
    ],
    'unsafe_publication_final_path' => [
        $uiAssetGraph,
        [
            [
                'carrier_key' => 'admin.menu',
                'asset_key' => 'admin.menu.smart',
                'kind' => 'module',
                'resource_path' => 'resources/simai/smart/admin-menu/admin-menu.js',
                'final_path_owned_by_core_assets' => true,
                'final_path' => '/vendor/larena/admin-menu.js',
            ],
            [
                'carrier_key' => 'admin.shell',
                'asset_key' => 'admin.shell.read_only_route.css',
                'kind' => 'css',
                'resource_path' => 'resources/simai/smart/admin-shell/read-only-route.css',
                'final_path_owned_by_core_assets' => true,
            ],
        ],
        '/larena/internal/package-owned-admin-frontend/assets',
    ],
];

foreach ($frontendConveyorFailures as $case => [$graph, $publicationAssets, $routeBase]) {
    try {
        CoreAssetActivationContract::frontendConveyorDemoActivation(
            'admin.frontend-conveyor.resource-pack',
            $graph,
            $publicationAssets,
            $routeBase,
        );
        fwrite(STDERR, 'Frontend conveyor core assets accepted unsafe case: ' . $case . PHP_EOL);
        exit(1);
    } catch (InvalidArgumentException) {
        continue;
    }
}

$descriptorFailures = [
    'template_direct_include' => static function (array $descriptor): array {
        $descriptor['policy']['allow_template_direct_include'] = true;
        return $descriptor;
    },
    'cdn_allowed' => static function (array $descriptor): array {
        $descriptor['policy']['allow_cdn'] = true;
        return $descriptor;
    },
    'root_copy_resource' => static function (array $descriptor): array {
        $descriptor['resources'][0]['root_copy_path'] = 'public/larena/table.js';
        return $descriptor;
    },
    'duplicate_resource' => static function (array $descriptor): array {
        $descriptor['resources'][] = $descriptor['resources'][0];
        return $descriptor;
    },
    'unsafe_resource_path' => static function (array $descriptor): array {
        $descriptor['resources'][0]['path'] = '../table.js';
        return $descriptor;
    },
];

foreach ($descriptorFailures as $case => $mutate) {
    try {
        CoreAssetActivationContract::packageAssetDescriptorActivation(
            'admin.read_only_shell',
            $descriptorGraph,
            $mutate($assetDescriptor),
            '/larena/internal/package-owned-admin-frontend/assets',
        );
        fwrite(STDERR, 'Asset descriptor activation accepted unsafe case: ' . $case . PHP_EOL);
        exit(1);
    } catch (InvalidArgumentException) {
        continue;
    }
}

echo 'CoreAssetActivationContractTest passed.' . PHP_EOL;
