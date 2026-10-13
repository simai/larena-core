<?php

declare(strict_types=1);

namespace Larena\Core\Starter;

use InvalidArgumentException;

/**
 * The inputs an asset activation reads, checked before anything is activated: required fields, safe route
 * bases, final and resource paths, stable keys, a package asset descriptor and its resources, and the
 * requirements of a UI asset graph read by field name.
 */
final class AssetActivationInputs
{
    public static function requiredString(array $requirement, string $field): string
    {
        $value = trim((string) ($requirement[$field] ?? ''));

        if ($value === '') {
            throw new InvalidArgumentException('core_assets_requirement_field_required:' . $field);
        }

        return $value;
    }

    public static function safeRouteBase(string $routeBase): string
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

    public static function safeFinalPath(string $finalPath): string
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

    public static function validateAssetDescriptorPolicy(array $assetDescriptor): void
    {
        if (self::requiredString($assetDescriptor, 'schema') !== 'larena.core_assets.set.v1') {
            throw new InvalidArgumentException('core_assets_descriptor_schema_invalid');
        }

        if (self::requiredString($assetDescriptor, 'activation_owner') !== CoreAssetActivationContract::OWNER) {
            throw new InvalidArgumentException('core_assets_descriptor_activation_owner_invalid');
        }

        if (self::requiredString($assetDescriptor, 'owner_package') === CoreAssetActivationContract::OWNER) {
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

    public static function assetDescriptorResources(array $assetDescriptor): array
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

    public static function safeResourcePath(string $resourcePath): string
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

    public static function stableKey(string $key): bool
    {
        return preg_match('/^[a-z][a-z0-9_]*(\\.[a-z][a-z0-9_]*)*$/', $key) === 1;
    }

    public static function uiAssetGraphRequirements(object $uiAssetGraph): array
    {
        if (!property_exists($uiAssetGraph, 'requirements') || !is_array($uiAssetGraph->requirements)) {
            throw new InvalidArgumentException('core_assets_ui_asset_graph_requirements_required');
        }

        return array_values($uiAssetGraph->requirements);
    }

    public static function objectString(object $object, string $field, string $error): string
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

    public static function objectKind(object $object, string $field): string
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

    public static function objectBool(object $object, string $field, bool $default): bool
    {
        if (!property_exists($object, $field)) {
            return $default;
        }

        return $object->{$field} === true;
    }
}
