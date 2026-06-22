<?php

declare(strict_types=1);

namespace Larena\Core\Starter;

use InvalidArgumentException;

final class CoreAssetActivationContract
{
    public const OWNER = 'larena/core:core.assets';
    public const STATUS = 'activation_contract_ready_publish_runtime_deferred';
    public const ROUTE_PUBLICATION_STATUS = 'activation_contract_ready_read_only_route_publication';

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
        ];
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
}
