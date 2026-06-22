<?php

declare(strict_types=1);

namespace Larena\Core\Starter;

use InvalidArgumentException;

final class CoreAssetActivationContract
{
    public const OWNER = 'larena/core:core.assets';
    public const STATUS = 'activation_contract_ready_publish_runtime_deferred';

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
}
