<?php

declare(strict_types=1);

namespace Larena\Core\Starter;

use InvalidArgumentException;
use Larena\Core\Assets\VerifiedAssetBundleInspector;

final class CoreAssetActivationContract
{
    public const OWNER = 'larena/core:core.assets';
    public const STATUS = 'activation_contract_ready_publish_runtime_deferred';
    public const ROUTE_PUBLICATION_STATUS = 'activation_contract_ready_read_only_route_publication';
    public const FRONTEND_CONVEYOR_STATUS = 'activation_contract_ready_frontend_conveyor_demo';
    public const ASSET_DESCRIPTOR_STATUS = 'activation_contract_ready_package_asset_descriptor_pilot';
    public const IMMUTABLE_BUNDLE_STATUS = 'immutable_bundle_publication_verified';
    public const IMMUTABLE_BUNDLE_NOT_READY_STATUS = 'immutable_bundle_publication_not_verified';

    /**
     * @param list<array{asset_key:string,kind:string,relative_path:string,critical?:bool}> $assets
     * @return array<string, mixed>
     */
    public static function immutableBundle(
        string $bundleId,
        array $assets,
        string $publicBase,
        ?array $inspection = null,
    ): array
    {
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,127}$/', $bundleId)) {
            throw new InvalidArgumentException('core_assets_bundle_id_invalid');
        }
        $publicBase = AssetActivationInputs::safeRouteBase($publicBase);
        if ($assets === []) {
            throw new InvalidArgumentException('core_assets_bundle_assets_required');
        }

        $normalizedAssets = [];
        foreach ($assets as $asset) {
            $assetKey = AssetActivationInputs::requiredString($asset, 'asset_key');
            $kind = AssetActivationInputs::requiredString($asset, 'kind');
            $relativePath = AssetActivationInputs::safeResourcePath(AssetActivationInputs::requiredString($asset, 'relative_path'));
            $normalizedAssets[] = [
                'asset_key' => $assetKey,
                'kind' => $kind,
                'critical' => ($asset['critical'] ?? true) === true,
                'relative_path' => $relativePath,
            ];
        }

        $relativePaths = array_column($normalizedAssets, 'relative_path');
        $inspectionTrusted = self::trustedBundleInspection($inspection, $bundleId, $relativePaths);
        $verifiedFiles = $inspectionTrusted && is_array($inspection['verified_files'] ?? null)
            ? array_fill_keys($inspection['verified_files'], true)
            : [];

        $activated = [];
        foreach ($normalizedAssets as $asset) {
            $relativePath = $asset['relative_path'];
            $ready = isset($verifiedFiles[$relativePath]);
            $activated[] = [
                'asset_key' => $asset['asset_key'],
                'kind' => $asset['kind'],
                'critical' => $asset['critical'],
                'activation_owner' => self::OWNER,
                'physical_publication_ready' => $ready,
                'publication_mode' => 'verified_immutable_bundle',
                'bundle_id' => $bundleId,
                'relative_path' => $relativePath,
                'final_path' => rtrim($publicBase, '/') . '/' . rawurlencode($bundleId) . '/' . implode('/', array_map('rawurlencode', explode('/', $relativePath))),
            ];
        }

        return [
            'schema' => 'larena.core_assets.activation_contract.v1',
            'status' => $inspectionTrusted ? self::IMMUTABLE_BUNDLE_STATUS : self::IMMUTABLE_BUNDLE_NOT_READY_STATUS,
            'resource_pack_key' => 'immutable.' . $bundleId,
            'activation_owner' => self::OWNER,
            'activation_mode' => 'verified_immutable_bundle',
            'physical_publication_ready' => $inspectionTrusted,
            'writes_database' => false,
            'copies_to_root' => false,
            'publishes_verified_bundle_to_public_root' => true,
            'uses_hardcoded_cdn' => false,
            'asset_count' => count($activated),
            'assets' => $activated,
            'renderable_tags' => $inspectionTrusted ? self::renderTags($activated) : [],
            'inspection' => [
                'schema' => is_string($inspection['schema'] ?? null) ? $inspection['schema'] : null,
                'status' => is_string($inspection['status'] ?? null) ? $inspection['status'] : 'not_available',
                'manifest_sha' => is_string($inspection['manifest_sha'] ?? null) ? $inspection['manifest_sha'] : null,
                'required_file_set_sha256' => VerifiedAssetBundleInspector::requiredFileSetSha256($relativePaths),
                'missing_or_invalid' => is_array($inspection['missing_or_invalid'] ?? null)
                    ? array_values($inspection['missing_or_invalid'])
                    : ['inspection_missing_or_invalid'],
            ],
        ];
    }

    /**
     * @param array<string, mixed>|null $inspection
     * @param list<string> $relativePaths
     */
    private static function trustedBundleInspection(?array $inspection, string $bundleId, array $relativePaths): bool
    {
        if ($inspection === null
            || ($inspection['schema'] ?? null) !== VerifiedAssetBundleInspector::INSPECTION_SCHEMA
            || ($inspection['status'] ?? null) !== 'verified'
            || ($inspection['physical_publication_ready'] ?? null) !== true
            || ($inspection['bundle_id'] ?? null) !== $bundleId
            || !is_string($inspection['manifest_sha'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/', $inspection['manifest_sha']) !== 1
            || ($inspection['required_file_set_sha256'] ?? null) !== VerifiedAssetBundleInspector::requiredFileSetSha256($relativePaths)
            || !is_array($inspection['verified_files'] ?? null)
            || !is_array($inspection['missing_or_invalid'] ?? null)
            || $inspection['missing_or_invalid'] !== []
        ) {
            return false;
        }

        $expected = array_values(array_unique($relativePaths));
        $verified = array_values(array_unique(array_filter(
            $inspection['verified_files'],
            static fn (mixed $path): bool => is_string($path),
        )));
        sort($expected, SORT_STRING);
        sort($verified, SORT_STRING);

        return $verified === $expected;
    }

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
            $assetKey = AssetActivationInputs::requiredString($requirement, 'asset_key');
            $kind = AssetActivationInputs::requiredString($requirement, 'kind');

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
        $routeBase = AssetActivationInputs::safeRouteBase($routeBase);

        if ($resourcePackKey === '') {
            throw new InvalidArgumentException('core_assets_resource_pack_key_required');
        }

        if ($carrierAssets === []) {
            throw new InvalidArgumentException('core_assets_requirements_required');
        }

        $assets = [];

        foreach ($carrierAssets as $asset) {
            $assetKey = AssetActivationInputs::requiredString($asset, 'asset_key');
            $kind = AssetActivationInputs::requiredString($asset, 'kind');
            $resourcePath = AssetActivationInputs::safeResourcePath(AssetActivationInputs::requiredString($asset, 'resource_path'));

            if (($asset['final_path_owned_by_core_assets'] ?? null) !== true) {
                throw new InvalidArgumentException('core_assets_owner_required:' . $assetKey);
            }

            foreach (['root_copy_path', 'cdn_url', 'publishable_path'] as $unsafeField) {
                if (isset($asset[$unsafeField]) && trim((string) $asset[$unsafeField]) !== '') {
                    throw new InvalidArgumentException('core_assets_unsafe_publication_field:' . $assetKey . ':' . $unsafeField);
                }
            }

            $assets[] = [
                'carrier_key' => AssetActivationInputs::requiredString($asset, 'carrier_key'),
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
        $routeBase = AssetActivationInputs::safeRouteBase($routeBase);

        if ($resourcePackKey === '') {
            throw new InvalidArgumentException('core_assets_resource_pack_key_required');
        }

        $requirements = AssetActivationInputs::uiAssetGraphRequirements($uiAssetGraph);
        if ($requirements === []) {
            throw new InvalidArgumentException('core_assets_ui_asset_graph_requirements_required');
        }

        if ($publicationAssets === []) {
            throw new InvalidArgumentException('core_assets_publication_assets_required');
        }

        $publicationByKey = [];
        foreach ($publicationAssets as $asset) {
            $assetKey = AssetActivationInputs::requiredString($asset, 'asset_key');
            if (isset($publicationByKey[$assetKey])) {
                throw new InvalidArgumentException('core_assets_duplicate_publication_asset:' . $assetKey);
            }
            $publicationByKey[$assetKey] = $asset;
        }

        $assets = [];
        $tags = [];
        foreach ($requirements as $requirement) {
            $assetKey = AssetActivationInputs::objectString($requirement, 'assetKey', 'core_assets_requirement_field_required:assetKey');
            $kind = AssetActivationInputs::objectKind($requirement, 'kind');
            $critical = AssetActivationInputs::objectBool($requirement, 'critical', true);

            if (!AssetActivationInputs::stableKey($assetKey)) {
                throw new InvalidArgumentException('core_assets_asset_key_unstable:' . $assetKey);
            }

            if (!AssetActivationInputs::objectBool($requirement, 'finalPathOwnedByCoreAssets', false)) {
                throw new InvalidArgumentException('core_assets_owner_required:' . $assetKey);
            }

            if (!isset($publicationByKey[$assetKey])) {
                throw new InvalidArgumentException('core_assets_unknown_asset_key:' . $assetKey);
            }

            $publication = $publicationByKey[$assetKey];
            $publicationKind = AssetActivationInputs::requiredString($publication, 'kind');
            $resourcePath = AssetActivationInputs::safeResourcePath(AssetActivationInputs::requiredString($publication, 'resource_path'));

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
        AssetActivationInputs::validateAssetDescriptorPolicy($assetDescriptor);

        $publicationAssets = [];
        foreach (AssetActivationInputs::assetDescriptorResources($assetDescriptor) as $resource) {
            $assetKey = AssetActivationInputs::requiredString($resource, 'key');
            $kind = AssetActivationInputs::requiredString($resource, 'kind');
            $resourcePath = AssetActivationInputs::safeResourcePath(AssetActivationInputs::requiredString($resource, 'path'));

            if (!AssetActivationInputs::stableKey($assetKey)) {
                throw new InvalidArgumentException('core_assets_asset_key_unstable:' . $assetKey);
            }

            $publicationAssets[] = [
                'carrier_key' => $assetKey,
                'asset_key' => $assetKey,
                'kind' => $kind,
                'critical' => AssetActivationInputs::requiredString($resource, 'load') === 'critical',
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
            'schema' => AssetActivationInputs::requiredString($assetDescriptor, 'schema'),
            'asset_set' => AssetActivationInputs::requiredString($assetDescriptor, 'asset_set'),
            'owner_package' => AssetActivationInputs::requiredString($assetDescriptor, 'owner_package'),
            'activation_owner' => AssetActivationInputs::requiredString($assetDescriptor, 'activation_owner'),
            'context' => AssetActivationInputs::requiredString($assetDescriptor, 'context'),
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
            $assetKey = AssetActivationInputs::requiredString($asset, 'asset_key');
            if (!AssetActivationInputs::stableKey($assetKey)) {
                throw new InvalidArgumentException('core_assets_asset_key_unstable:' . $assetKey);
            }

            $finalPath = AssetActivationInputs::safeFinalPath(AssetActivationInputs::requiredString($asset, 'final_path'));
            $kind = AssetActivationInputs::requiredString($asset, 'kind');

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

    /**
     * @param array<string, mixed> $assetDescriptor
     */

    /**
     * @param array<string, mixed> $assetDescriptor
     * @return list<array<string, mixed>>
     */

    /**
     * @return list<object>
     */

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
