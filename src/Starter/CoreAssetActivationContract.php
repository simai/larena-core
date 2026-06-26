<?php

declare(strict_types=1);

namespace Larena\Core\Starter;

use InvalidArgumentException;

final class CoreAssetActivationContract
{
    public const OWNER = 'larena/core:core.assets';
    public const STATUS = 'activation_contract_ready_publish_runtime_deferred';
    public const ROUTE_PUBLICATION_STATUS = 'activation_contract_ready_read_only_route_publication';
    public const FRONTEND_CONVEYOR_STATUS = 'activation_contract_ready_frontend_conveyor_demo';
    public const ASSET_DESCRIPTOR_STATUS = 'activation_contract_ready_package_asset_descriptor_pilot';

    /**
     * @param list<array<string, mixed>> $assetRequirements
     * @return array<string, mixed>
     */
    public static function adminSmartResourcePack(string $resourcePackKey, array $assetRequirements): array
    {
        $resourcePackKey = trim($resourcePackKey);

        if ($resourcePackKey === '') {
            throw new InvalidArgumentException('core_assets_resource_pack_key_required');
        }

        if ($assetRequirements === []) {
            throw new InvalidArgumentException('core_assets_requirements_required');
        }

        $assets = [];

        foreach ($assetRequirements as $requirement) {
            $assetKey = self::requiredString($requirement, 'asset_key');
            $kind = self::requiredString($requirement, 'kind');

            if (($requirement['final_path_owned_by_core_assets'] ?? null) !== true) {
                throw new InvalidArgumentException('core_assets_owner_required:' . $assetKey);
            }

            foreach (['final_path', 'root_copy_path', 'cdn_url', 'publishable_path'] as $unsafeField) {
                if (isset($requirement[$unsafeField]) && trim((string) $requirement[$unsafeField]) !== '') {
                    throw new InvalidArgumentException('core_assets_physical_resolution_deferred:' . $assetKey . ':' . $unsafeField);
                }
            }

            $assets[] = [
                'asset_key' => $assetKey,
                'kind' => $kind,
                'critical' => ($requirement['critical'] ?? true) === true,
                'activation_owner' => self::OWNER,
                'physical_publication_ready' => false,
                'final_path' => null,
            ];
        }

        return [
            'schema' => 'larena.core_assets.activation_contract.v1',
            'status' => self::STATUS,
            'resource_pack_key' => $resourcePackKey,
            'activation_owner' => self::OWNER,
            'activation_mode' => 'descriptor_only',
            'physical_publication_ready' => false,
            'writes_database' => false,
            'copies_to_root' => false,
            'uses_hardcoded_cdn' => false,
            'asset_count' => count($assets),
            'assets' => $assets,
        ];
    }

    /**
     * @param list<array<string, mixed>> $carrierAssets
     * @return array<string, mixed>
     */
    public static function adminSmartResourcePackReadOnlyRoute(
        string $resourcePackKey,
        array $carrierAssets,
        string $routeBase,
    ): array {
        $resourcePackKey = trim($resourcePackKey);
        $routeBase = self::safeRouteBase($routeBase);

        if ($resourcePackKey === '') {
            throw new InvalidArgumentException('core_assets_resource_pack_key_required');
        }

        if ($carrierAssets === []) {
            throw new InvalidArgumentException('core_assets_requirements_required');
        }

        $assets = [];

        foreach ($carrierAssets as $asset) {
            $assetKey = self::requiredString($asset, 'asset_key');
            $kind = self::requiredString($asset, 'kind');
            $resourcePath = self::safeResourcePath(self::requiredString($asset, 'resource_path'));

            if (($asset['final_path_owned_by_core_assets'] ?? null) !== true) {
                throw new InvalidArgumentException('core_assets_owner_required:' . $assetKey);
            }

            foreach (['root_copy_path', 'cdn_url', 'publishable_path'] as $unsafeField) {
                if (isset($asset[$unsafeField]) && trim((string) $asset[$unsafeField]) !== '') {
                    throw new InvalidArgumentException('core_assets_unsafe_publication_field:' . $assetKey . ':' . $unsafeField);
                }
            }

            $assets[] = [
                'carrier_key' => self::requiredString($asset, 'carrier_key'),
                'asset_key' => $assetKey,
                'kind' => $kind,
                'critical' => ($asset['critical'] ?? true) === true,
                'activation_owner' => self::OWNER,
                'physical_publication_ready' => true,
                'publication_mode' => 'read_only_route',
                'resource_path' => $resourcePath,
                'final_path' => rtrim($routeBase, '/') . '/' . rawurlencode($assetKey),
            ];
        }

        return [
            'schema' => 'larena.core_assets.activation_contract.v1',
            'status' => self::ROUTE_PUBLICATION_STATUS,
            'resource_pack_key' => $resourcePackKey,
            'activation_owner' => self::OWNER,
            'activation_mode' => 'read_only_route',
            'physical_publication_ready' => true,
            'writes_database' => false,
            'copies_to_root' => false,
            'uses_hardcoded_cdn' => false,
            'asset_count' => count($assets),
            'assets' => $assets,
            'renderable_tags' => self::renderTags($assets),
        ];
    }

    /**
     * @param object $uiAssetGraph Object-shape compatible with larena/ui UiAssetGraph.
     * @param list<array<string, mixed>> $publicationAssets
     * @return array<string, mixed>
     */
    public static function frontendConveyorDemoActivation(
        string $resourcePackKey,
        object $uiAssetGraph,
        array $publicationAssets,
        string $routeBase,
    ): array {
        $resourcePackKey = trim($resourcePackKey);
        $routeBase = self::safeRouteBase($routeBase);

        if ($resourcePackKey === '') {
            throw new InvalidArgumentException('core_assets_resource_pack_key_required');
        }

        $requirements = self::uiAssetGraphRequirements($uiAssetGraph);
        if ($requirements === []) {
            throw new InvalidArgumentException('core_assets_ui_asset_graph_requirements_required');
        }

        if ($publicationAssets === []) {
            throw new InvalidArgumentException('core_assets_publication_assets_required');
        }

        $publicationByKey = [];
        foreach ($publicationAssets as $asset) {
            $assetKey = self::requiredString($asset, 'asset_key');
            if (isset($publicationByKey[$assetKey])) {
                throw new InvalidArgumentException('core_assets_duplicate_publication_asset:' . $assetKey);
            }
            $publicationByKey[$assetKey] = $asset;
        }

        $assets = [];
        $tags = [];
        foreach ($requirements as $requirement) {
            $assetKey = self::objectString($requirement, 'assetKey', 'core_assets_requirement_field_required:assetKey');
            $kind = self::objectKind($requirement, 'kind');
            $critical = self::objectBool($requirement, 'critical', true);

            if (!self::stableKey($assetKey)) {
                throw new InvalidArgumentException('core_assets_asset_key_unstable:' . $assetKey);
            }

            if (!self::objectBool($requirement, 'finalPathOwnedByCoreAssets', false)) {
                throw new InvalidArgumentException('core_assets_owner_required:' . $assetKey);
            }

            if (!isset($publicationByKey[$assetKey])) {
                throw new InvalidArgumentException('core_assets_unknown_asset_key:' . $assetKey);
            }

            $publication = $publicationByKey[$assetKey];
            $publicationKind = self::requiredString($publication, 'kind');
            $resourcePath = self::safeResourcePath(self::requiredString($publication, 'resource_path'));

            if ($publicationKind !== $kind) {
                throw new InvalidArgumentException('core_assets_asset_kind_mismatch:' . $assetKey);
            }

            if (($publication['final_path_owned_by_core_assets'] ?? null) !== true) {
                throw new InvalidArgumentException('core_assets_owner_required:' . $assetKey);
            }

            foreach (['final_path', 'root_copy_path', 'cdn_url', 'publishable_path'] as $unsafeField) {
                if (isset($publication[$unsafeField]) && trim((string) $publication[$unsafeField]) !== '') {
                    throw new InvalidArgumentException('core_assets_unsafe_publication_field:' . $assetKey . ':' . $unsafeField);
                }
            }

            $finalPath = rtrim($routeBase, '/') . '/' . rawurlencode($assetKey);
            $asset = [
                'carrier_key' => (string) ($publication['carrier_key'] ?? $assetKey),
                'asset_key' => $assetKey,
                'kind' => $kind,
                'critical' => $critical,
                'activation_owner' => self::OWNER,
                'physical_publication_ready' => true,
                'publication_mode' => 'frontend_conveyor_demo_read_only_route',
                'resource_path' => $resourcePath,
                'final_path' => $finalPath,
            ];
            $assets[] = $asset;
            $tags[] = self::renderTag($asset);
        }

        return [
            'schema' => 'larena.core_assets.activation_contract.v1',
            'status' => self::FRONTEND_CONVEYOR_STATUS,
            'resource_pack_key' => $resourcePackKey,
            'activation_owner' => self::OWNER,
            'activation_mode' => 'frontend_conveyor_demo_read_only_route',
            'physical_publication_ready' => true,
            'writes_database' => false,
            'copies_to_root' => false,
            'uses_hardcoded_cdn' => false,
            'asset_count' => count($assets),
            'assets' => $assets,
            'renderable_tags' => $tags,
            'headers' => [
                'X-Larena-Asset-Activation-Owner' => self::OWNER,
                'X-Larena-Root-Copy' => 'false',
            ],
        ];
    }

    /**
     * @param object $uiAssetGraph Object-shape compatible with larena/ui UiAssetGraph.
     * @param array<string, mixed> $assetDescriptor
     * @return array<string, mixed>
     */
    public static function packageAssetDescriptorActivation(
        string $resourcePackKey,
        object $uiAssetGraph,
        array $assetDescriptor,
        string $routeBase,
    ): array {
        self::validateAssetDescriptorPolicy($assetDescriptor);

        $publicationAssets = [];
        foreach (self::assetDescriptorResources($assetDescriptor) as $resource) {
            $assetKey = self::requiredString($resource, 'key');
            $kind = self::requiredString($resource, 'kind');
            $resourcePath = self::safeResourcePath(self::requiredString($resource, 'path'));

            if (!self::stableKey($assetKey)) {
                throw new InvalidArgumentException('core_assets_asset_key_unstable:' . $assetKey);
            }

            $publicationAssets[] = [
                'carrier_key' => $assetKey,
                'asset_key' => $assetKey,
                'kind' => $kind,
                'critical' => self::requiredString($resource, 'load') === 'critical',
                'resource_path' => $resourcePath,
                'source_backed_status' => 'package_asset_descriptor_pilot',
                'final_path_owned_by_core_assets' => true,
            ];
        }

        $activation = self::frontendConveyorDemoActivation(
            $resourcePackKey,
            $uiAssetGraph,
            $publicationAssets,
            $routeBase,
        );

        $activation['status'] = self::ASSET_DESCRIPTOR_STATUS;
        $activation['activation_mode'] = 'package_asset_descriptor_read_only_route';
        $activation['asset_descriptor'] = [
            'schema' => self::requiredString($assetDescriptor, 'schema'),
            'asset_set' => self::requiredString($assetDescriptor, 'asset_set'),
            'owner_package' => self::requiredString($assetDescriptor, 'owner_package'),
            'activation_owner' => self::requiredString($assetDescriptor, 'activation_owner'),
            'context' => self::requiredString($assetDescriptor, 'context'),
            'resource_count' => count($publicationAssets),
            'allow_template_direct_include' => false,
        ];

        return $activation;
    }

    /**
     * @param list<array<string, mixed>> $activatedAssets
     * @return list<string>
     */
    public static function renderTags(array $activatedAssets): array
    {
        if ($activatedAssets === []) {
            throw new InvalidArgumentException('core_assets_render_tags_assets_required');
        }

        $tags = [];

        foreach ($activatedAssets as $asset) {
            $assetKey = self::requiredString($asset, 'asset_key');
            if (!self::stableKey($assetKey)) {
                throw new InvalidArgumentException('core_assets_asset_key_unstable:' . $assetKey);
            }

            $finalPath = self::safeFinalPath(self::requiredString($asset, 'final_path'));
            $kind = self::requiredString($asset, 'kind');

            if (($asset['activation_owner'] ?? null) !== self::OWNER) {
                throw new InvalidArgumentException('core_assets_render_tag_owner_invalid:' . $assetKey);
            }

            if (($asset['physical_publication_ready'] ?? null) !== true) {
                throw new InvalidArgumentException('core_assets_render_tag_publication_not_ready:' . $assetKey);
            }

            foreach (['root_copy_path', 'cdn_url', 'publishable_path'] as $unsafeField) {
                if (isset($asset[$unsafeField]) && trim((string) $asset[$unsafeField]) !== '') {
                    throw new InvalidArgumentException('core_assets_render_tag_unsafe_field:' . $assetKey . ':' . $unsafeField);
                }
            }

            $tags[] = self::renderTag([
                ...$asset,
                'asset_key' => $assetKey,
                'kind' => $kind,
                'final_path' => $finalPath,
            ]);
        }

        return $tags;
    }

    /**
     * @param array<string, mixed> $requirement
     */
    private static function requiredString(array $requirement, string $field): string
    {
        $value = trim((string) ($requirement[$field] ?? ''));

        if ($value === '') {
            throw new InvalidArgumentException('core_assets_requirement_field_required:' . $field);
        }

        return $value;
    }

    private static function safeRouteBase(string $routeBase): string
    {
        $routeBase = trim($routeBase);

        if ($routeBase === '' || !str_starts_with($routeBase, '/') || str_contains($routeBase, '://')) {
            throw new InvalidArgumentException('core_assets_route_base_must_be_relative');
        }

        if (str_contains($routeBase, '..') || str_contains($routeBase, '\\')) {
            throw new InvalidArgumentException('core_assets_route_base_unsafe');
        }

        return $routeBase;
    }

    private static function safeFinalPath(string $finalPath): string
    {
        if (
            $finalPath === ''
            || !str_starts_with($finalPath, '/')
            || str_contains($finalPath, '..')
            || str_contains($finalPath, '\\')
            || str_contains($finalPath, '://')
        ) {
            throw new InvalidArgumentException('core_assets_final_path_unsafe');
        }

        return $finalPath;
    }

    /**
     * @param array<string, mixed> $assetDescriptor
     */
    private static function validateAssetDescriptorPolicy(array $assetDescriptor): void
    {
        if (self::requiredString($assetDescriptor, 'schema') !== 'larena.core_assets.set.v1') {
            throw new InvalidArgumentException('core_assets_descriptor_schema_invalid');
        }

        if (self::requiredString($assetDescriptor, 'activation_owner') !== self::OWNER) {
            throw new InvalidArgumentException('core_assets_descriptor_activation_owner_invalid');
        }

        if (self::requiredString($assetDescriptor, 'owner_package') === self::OWNER) {
            throw new InvalidArgumentException('core_assets_descriptor_owner_package_invalid');
        }

        $policy = $assetDescriptor['policy'] ?? null;
        if (!is_array($policy)) {
            throw new InvalidArgumentException('core_assets_descriptor_policy_required');
        }

        foreach ([
            'local_only' => true,
            'allow_cdn' => false,
            'allow_template_direct_include' => false,
            'final_path_owned_by_core_assets' => true,
        ] as $key => $expected) {
            if (($policy[$key] ?? null) !== $expected) {
                throw new InvalidArgumentException('core_assets_descriptor_policy_invalid:' . $key);
            }
        }
    }

    /**
     * @param array<string, mixed> $assetDescriptor
     * @return list<array<string, mixed>>
     */
    private static function assetDescriptorResources(array $assetDescriptor): array
    {
        $resources = $assetDescriptor['resources'] ?? null;
        if (!is_array($resources) || $resources === []) {
            throw new InvalidArgumentException('core_assets_descriptor_resources_required');
        }

        $seen = [];
        $normalized = [];
        foreach ($resources as $resource) {
            if (!is_array($resource)) {
                throw new InvalidArgumentException('core_assets_descriptor_resource_invalid');
            }

            $key = self::requiredString($resource, 'key');
            if (isset($seen[$key])) {
                throw new InvalidArgumentException('core_assets_descriptor_duplicate_resource:' . $key);
            }
            $seen[$key] = true;

            $load = self::requiredString($resource, 'load');
            if (!in_array($load, ['critical', 'deferred'], true)) {
                throw new InvalidArgumentException('core_assets_descriptor_resource_load_invalid:' . $key);
            }

            foreach (['final_path', 'root_copy_path', 'cdn_url', 'publishable_path'] as $unsafeField) {
                if (isset($resource[$unsafeField]) && trim((string) $resource[$unsafeField]) !== '') {
                    throw new InvalidArgumentException('core_assets_descriptor_unsafe_resource_field:' . $key . ':' . $unsafeField);
                }
            }

            $normalized[] = $resource;
        }

        return $normalized;
    }

    private static function safeResourcePath(string $resourcePath): string
    {
        if (
            str_starts_with($resourcePath, '/')
            || str_contains($resourcePath, '..')
            || str_contains($resourcePath, '\\')
            || str_contains($resourcePath, '://')
        ) {
            throw new InvalidArgumentException('core_assets_resource_path_unsafe');
        }

        return $resourcePath;
    }

    private static function stableKey(string $key): bool
    {
        return preg_match('/^[a-z][a-z0-9_]*(\\.[a-z][a-z0-9_]*)*$/', $key) === 1;
    }

    /**
     * @return list<object>
     */
    private static function uiAssetGraphRequirements(object $uiAssetGraph): array
    {
        if (!property_exists($uiAssetGraph, 'requirements') || !is_array($uiAssetGraph->requirements)) {
            throw new InvalidArgumentException('core_assets_ui_asset_graph_requirements_required');
        }

        return array_values($uiAssetGraph->requirements);
    }

    private static function objectString(object $object, string $field, string $error): string
    {
        if (!property_exists($object, $field)) {
            throw new InvalidArgumentException($error);
        }

        $value = trim((string) $object->{$field});
        if ($value === '') {
            throw new InvalidArgumentException($error);
        }

        return $value;
    }

    private static function objectKind(object $object, string $field): string
    {
        if (!property_exists($object, $field)) {
            throw new InvalidArgumentException('core_assets_requirement_field_required:' . $field);
        }

        $value = $object->{$field};

        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        $kind = trim((string) $value);
        if ($kind === '') {
            throw new InvalidArgumentException('core_assets_requirement_field_required:' . $field);
        }

        return $kind;
    }

    private static function objectBool(object $object, string $field, bool $default): bool
    {
        if (!property_exists($object, $field)) {
            return $default;
        }

        return $object->{$field} === true;
    }

    /**
     * @param array<string, mixed> $asset
     */
    private static function renderTag(array $asset): string
    {
        $finalPath = htmlspecialchars((string) $asset['final_path'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $assetKey = htmlspecialchars((string) $asset['asset_key'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $owner = htmlspecialchars(self::OWNER, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return match ($asset['kind']) {
            'css' => '<link rel="stylesheet" href="' . $finalPath . '" data-larena-asset-key="' . $assetKey . '" data-larena-asset-owner="' . $owner . '">',
            'module' => '<script type="module" src="' . $finalPath . '" data-larena-asset-key="' . $assetKey . '" data-larena-asset-owner="' . $owner . '"></script>',
            'javascript' => '<script src="' . $finalPath . '" data-larena-asset-key="' . $assetKey . '" data-larena-asset-owner="' . $owner . '"></script>',
            default => throw new InvalidArgumentException('core_assets_unsupported_render_tag_kind:' . (string) $asset['kind']),
        };
    }
}
