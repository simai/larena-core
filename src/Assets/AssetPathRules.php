<?php

declare(strict_types=1);

namespace Larena\Core\Assets;

use InvalidArgumentException;
use RuntimeException;

/**
 * The path and identity rules shared by asset publication and Git tree archives: absolute paths, stable
 * segments and relative paths, full commit identities, and directories created on demand.
 */
final class AssetPathRules
{
    public static function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('core_assets_directory_create_failed');
        }
    }

    public static function absolutePath(string $path, string $error): string
    {
        $path = rtrim(trim($path), DIRECTORY_SEPARATOR);
        if ($path === '' || $path[0] !== DIRECTORY_SEPARATOR || str_contains($path, "\0")) {
            throw new InvalidArgumentException($error);
        }
        return $path;
    }

    public static function fullSha(string $sha): string
    {
        $sha = strtolower(trim($sha));
        if (!preg_match('/^[a-f0-9]{40}$/', $sha)) {
            throw new InvalidArgumentException('core_assets_source_commit_invalid');
        }
        return $sha;
    }

    public static function stableSegment(string $value, string $error): string
    {
        $value = trim($value);
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,127}$/', $value)) {
            throw new InvalidArgumentException($error);
        }
        return $value;
    }

    public static function stablePath(string $value, string $error): string
    {
        $value = trim($value, '/ ');
        if ($value === '' || str_contains($value, '..') || !preg_match('#^[a-zA-Z0-9._/-]+$#', $value)) {
            throw new InvalidArgumentException($error);
        }
        return $value;
    }
}
