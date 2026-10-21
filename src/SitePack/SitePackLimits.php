<?php

declare(strict_types=1);

namespace Larena\Core\SitePack;

/**
 * Bounds every SitePack archive is read and written within. A storage pack can reach gigabytes, so the defaults are
 * generous; the archive is never held in memory, these limits only stop hostile or broken archives.
 */
final readonly class SitePackLimits
{
    public const int DEFAULT_MAX_TOTAL_BYTES = 2_147_483_648;
    public const int DEFAULT_MAX_ENTRIES = 200_000;
    public const int DEFAULT_MAX_LINE_BYTES = 1_048_576;
    public const int DEFAULT_MAX_JSON_DEPTH = 64;
    public const int DEFAULT_MAX_EXPANSION_RATIO = 200;
    public const int DEFAULT_MAX_DOCUMENT_BYTES = 67_108_864;

    /** An entry smaller than this is never refused for its compression ratio alone. */
    public const int EXPANSION_RATIO_FLOOR_BYTES = 1_048_576;

    /**
     * @param int $maxTotalBytes uncompressed bytes of all entries together, and the archive file size
     * @param int $maxEntries artifacts in one archive, manifest and catalog not counted
     * @param int $maxLineBytes one JSON line, newline included
     * @param int $maxJsonDepth nesting depth of one JSON line or document
     * @param int $maxExpansionRatio uncompressed to compressed size of one entry above the floor
     * @param int $maxDocumentBytes the manifest or the catalog
     */
    public function __construct(
        public int $maxTotalBytes = self::DEFAULT_MAX_TOTAL_BYTES,
        public int $maxEntries = self::DEFAULT_MAX_ENTRIES,
        public int $maxLineBytes = self::DEFAULT_MAX_LINE_BYTES,
        public int $maxJsonDepth = self::DEFAULT_MAX_JSON_DEPTH,
        public int $maxExpansionRatio = self::DEFAULT_MAX_EXPANSION_RATIO,
        public int $maxDocumentBytes = self::DEFAULT_MAX_DOCUMENT_BYTES,
    ) {
        if (
            $maxTotalBytes < 1
            || $maxEntries < 1
            || $maxLineBytes < 2
            || $maxJsonDepth < 2
            || $maxJsonDepth > 512
            || $maxExpansionRatio < 1
            || $maxDocumentBytes < 2
        ) {
            throw new \InvalidArgumentException('sitepack_limits_invalid');
        }
    }

    /**
     * Reads `larena-core.sitepack`. A missing or non-positive value keeps the safe default.
     *
     * @param array<array-key, mixed> $config
     */
    public static function fromConfig(array $config): self
    {
        $read = static function (string $key, int $default) use ($config): int {
            $value = $config[$key] ?? null;
            if (is_string($value) && preg_match('/\A[0-9]+\z/D', $value) === 1) {
                $value = (int) $value;
            }

            return is_int($value) && $value > 0 ? $value : $default;
        };

        return new self(
            maxTotalBytes: $read('max_total_bytes', self::DEFAULT_MAX_TOTAL_BYTES),
            maxEntries: $read('max_entries', self::DEFAULT_MAX_ENTRIES),
            maxLineBytes: $read('max_line_bytes', self::DEFAULT_MAX_LINE_BYTES),
            maxJsonDepth: $read('max_json_depth', self::DEFAULT_MAX_JSON_DEPTH),
            maxExpansionRatio: $read('max_expansion_ratio', self::DEFAULT_MAX_EXPANSION_RATIO),
            maxDocumentBytes: $read('max_document_bytes', self::DEFAULT_MAX_DOCUMENT_BYTES),
        );
    }
}
