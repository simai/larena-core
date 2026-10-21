<?php

declare(strict_types=1);

namespace Larena\Core\SitePack;

/**
 * Archive path rules. A path is relative, uses `/`, has no empty, `.` or `..` segment, no control or shell-special
 * characters and no trailing slash, so it can never leave the directory it is unpacked into.
 */
final class SitePackPaths
{
    public const string MANIFEST = 'sitepack.manifest.json';
    public const string CATALOG = 'sitepack.catalog.json';
    public const int MAX_PATH_BYTES = 240;

    public static function isValid(string $path): bool
    {
        return $path !== ''
            && strlen($path) <= self::MAX_PATH_BYTES
            && preg_match('/\A[A-Za-z0-9_][A-Za-z0-9._-]*(?:\/[A-Za-z0-9_][A-Za-z0-9._-]*)*\z/D', $path) === 1;
    }

    public static function assertEntryPath(string $path): void
    {
        if (!self::isValid($path)) {
            throw new SitePackRejected('sitepack_archive_entry_path_invalid');
        }
    }

    /** An artifact path is a valid entry path that is not one of the envelope documents. */
    public static function assertArtifactPath(string $path): void
    {
        self::assertEntryPath($path);
        if ($path === self::MANIFEST || $path === self::CATALOG) {
            throw new SitePackRejected('sitepack_archive_entry_path_invalid', 'SitePack artifact path is reserved.');
        }
    }
}
