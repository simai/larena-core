<?php

declare(strict_types=1);

namespace Larena\Core\Assets;

use InvalidArgumentException;
use RuntimeException;

/**
 * Fields of an asset bundle contract read the same way by the publisher and the inspector: a value under its
 * canonical or legacy key, a source's archive checksum, and a JSON object file.
 */
final class AssetContractFields
{
    /** @param array<string, mixed> $values */
    public static function aliasedString(
        array $values,
        string $canonicalKey,
        string $legacyKey,
        string $invalidError,
        string $conflictError,
    ): string {
        $canonical = $values[$canonicalKey] ?? null;
        $legacy = $values[$legacyKey] ?? null;
        if (($canonical !== null && !is_string($canonical)) || ($legacy !== null && !is_string($legacy))) {
            throw new InvalidArgumentException($invalidError);
        }
        $canonical = trim((string) $canonical);
        $legacy = trim((string) $legacy);
        if ($canonical !== '' && $legacy !== '' && $canonical !== $legacy) {
            throw new InvalidArgumentException($conflictError);
        }

        return $canonical !== '' ? $canonical : $legacy;
    }

    /** @param array<string, mixed> $source */
    public static function sourceArchiveChecksum(array $source): string
    {
        $canonical = $source['archive_sha256'] ?? null;
        $legacy = $source['sha256'] ?? null;
        if (($canonical !== null && !is_string($canonical)) || ($legacy !== null && !is_string($legacy))) {
            throw new InvalidArgumentException('core_assets_source_checksum_invalid');
        }
        $canonical = strtolower(trim((string) $canonical));
        $legacy = strtolower(trim((string) $legacy));
        if (($canonical !== '' && preg_match('/^[a-f0-9]{64}$/', $canonical) !== 1)
            || ($legacy !== '' && preg_match('/^[a-f0-9]{64}$/', $legacy) !== 1)
        ) {
            throw new InvalidArgumentException('core_assets_source_checksum_invalid');
        }
        if ($canonical !== '' && $legacy !== '' && !hash_equals($canonical, $legacy)) {
            throw new InvalidArgumentException('core_assets_source_checksum_conflict');
        }
        $checksum = $canonical !== '' ? $canonical : $legacy;
        if ($checksum === '') {
            throw new InvalidArgumentException('core_assets_source_checksum_invalid');
        }

        return $checksum;
    }

    /** @return array<string, mixed> */
    public static function readJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('core_assets_json_invalid');
        }
        return $decoded;
    }
}
