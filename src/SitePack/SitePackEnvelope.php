<?php

declare(strict_types=1);

namespace Larena\Core\SitePack;

use JsonException;
use Larena\Core\Support\SchemaName;

/**
 * The SitePack 0.4 envelope: `sitepack.manifest.json` and `sitepack.catalog.json`.
 *
 * The catalog lists every artifact, sorted by path, with its size, SHA-256 digest and, for JSON lines, its line count.
 * The package id is `larena-<prefix>-<sha256 of the canonical catalog>`, so the manifest is bound to exactly that set of
 * artifacts. The annotations carry the participant's own format as a schema stamp
 * (`{"schema": "<name>", "schema_version": N}`); no name carries a version.
 */
final readonly class SitePackEnvelope
{
    public const string SPEC_NAME = 'sitepack';
    public const string SPEC_VERSION = '0.4.0';

    private const string PREFIX_PATTERN = '[a-z][a-z0-9]*(?:-[a-z0-9]+)*';

    /** @var array<string, SitePackCatalogEntry> */
    private array $byPath;

    /**
     * @param list<SitePackCatalogEntry> $entries sorted by path
     * @param list<string> $profiles
     * @param array<string, mixed> $annotations schema stamp first
     */
    private function __construct(
        public string $packageId,
        public string $packagePrefix,
        public string $createdAt,
        public array $profiles,
        public array $annotations,
        public array $entries,
    ) {
        $byPath = [];
        foreach ($entries as $entry) {
            $byPath[$entry->path] = $entry;
        }
        $this->byPath = $byPath;
    }

    /**
     * @param list<SitePackCatalogEntry> $entries any order; the catalog is sorted by path
     * @param list<string> $profiles
     * @param array<string, mixed> $annotations participant fields beside the schema stamp
     */
    public static function build(
        string $packagePrefix,
        string $createdAt,
        array $profiles,
        string $schemaName,
        int $schemaVersion,
        array $annotations,
        array $entries,
    ): self {
        if (preg_match('/\A'.self::PREFIX_PATTERN.'\z/D', $packagePrefix) !== 1) {
            throw new SitePackRejected('sitepack_manifest_invalid', 'SitePack package prefix is invalid.');
        }
        if (preg_match('/\A[a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)+\z/D', $schemaName) !== 1 || $schemaVersion < 1) {
            throw new SitePackRejected('sitepack_manifest_invalid', 'SitePack annotation schema is invalid.');
        }
        unset($annotations['schema'], $annotations['schema_version']);
        $annotations = SchemaName::stamp($schemaName, $schemaVersion) + $annotations;
        $entries = self::sortedUnique($entries);
        self::assertManifestFields($createdAt, $profiles, $annotations);

        return new self(
            'larena-'.$packagePrefix.'-'.hash('sha256', self::catalogBytes($entries)),
            $packagePrefix,
            $createdAt,
            $profiles,
            $annotations,
            $entries,
        );
    }

    /**
     * Parses both documents and checks the spec, the fields and the package id against the catalog digest. It does not
     * look at artifact bytes; the archive reader verifies those.
     */
    public static function parse(string $manifestJson, string $catalogJson, SitePackLimits $limits = new SitePackLimits()): self
    {
        if (strlen($manifestJson) > $limits->maxDocumentBytes || strlen($catalogJson) > $limits->maxDocumentBytes) {
            throw new SitePackRejected('sitepack_document_too_large');
        }
        $manifest = self::object($manifestJson, $limits->maxJsonDepth, 'sitepack_manifest_invalid');
        $catalog = self::object($catalogJson, $limits->maxJsonDepth, 'sitepack_catalog_invalid');

        if (($manifest['spec'] ?? null) !== ['name' => self::SPEC_NAME, 'version' => self::SPEC_VERSION]) {
            throw new SitePackRejected('sitepack_manifest_incompatible', 'SitePack spec name or version is not supported.');
        }
        $known = ['spec' => true, 'package' => true, 'createdAt' => true, 'profiles' => true, 'artifacts' => true, 'annotations' => true];
        foreach (array_keys($manifest) as $key) {
            if (!isset($known[$key])) {
                throw new SitePackRejected('sitepack_manifest_invalid', 'SitePack manifest has an unknown field.');
            }
        }
        $package = $manifest['package'] ?? null;
        $packageId = is_array($package) && array_keys($package) === ['id'] ? $package['id'] : null;
        $createdAt = $manifest['createdAt'] ?? null;
        $profiles = $manifest['profiles'] ?? null;
        $annotations = $manifest['annotations'] ?? null;
        $artifactIds = $manifest['artifacts'] ?? null;
        if (
            !is_string($packageId)
            || preg_match('/\Alarena-('.self::PREFIX_PATTERN.')-([a-f0-9]{64})\z/D', $packageId, $match) !== 1
            || !is_string($createdAt)
            || !is_array($profiles)
            || !array_is_list($profiles)
            || !is_array($annotations)
            || ($annotations !== [] && array_is_list($annotations))
            || !is_array($artifactIds)
            || !array_is_list($artifactIds)
        ) {
            throw new SitePackRejected('sitepack_manifest_invalid');
        }
        foreach ($profiles as $profile) {
            if (!is_string($profile)) {
                throw new SitePackRejected('sitepack_manifest_invalid', 'SitePack profile is invalid.');
            }
        }
        self::assertManifestFields($createdAt, $profiles, $annotations);

        if (array_keys($catalog) !== ['artifacts'] || !is_array($catalog['artifacts']) || !array_is_list($catalog['artifacts'])) {
            throw new SitePackRejected('sitepack_catalog_invalid');
        }
        $entries = [];
        foreach ($catalog['artifacts'] as $row) {
            if (!is_array($row)) {
                throw new SitePackRejected('sitepack_catalog_invalid');
            }
            $entries[] = SitePackCatalogEntry::fromArray($row);
        }
        $previous = null;
        foreach ($entries as $entry) {
            if ($previous !== null && strcmp($previous, $entry->path) >= 0) {
                throw new SitePackRejected(
                    $previous === $entry->path ? 'sitepack_artifact_duplicate' : 'sitepack_catalog_invalid',
                    'SitePack catalog is not sorted by unique path.',
                );
            }
            $previous = $entry->path;
        }
        self::sortedUnique($entries);
        if ($artifactIds !== array_map(static fn (SitePackCatalogEntry $e): string => $e->id, $entries)) {
            throw new SitePackRejected('sitepack_manifest_invalid', 'SitePack manifest artifacts differ from the catalog.');
        }
        if (!hash_equals($match[2], hash('sha256', self::catalogBytes($entries)))) {
            throw new SitePackRejected('sitepack_package_identity_mismatch');
        }

        return new self($packageId, $match[1], $createdAt, $profiles, $annotations, $entries);
    }

    /** Whether the annotations carry this participant format (either stamp form, see SchemaName). */
    public function annotationsAre(string $schemaName, int $schemaVersion): bool
    {
        return SchemaName::is($this->annotations, $schemaName, $schemaVersion);
    }

    public function entry(string $path): ?SitePackCatalogEntry
    {
        return $this->byPath[$path] ?? null;
    }

    public function has(string $path): bool
    {
        return isset($this->byPath[$path]);
    }

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return [
            'spec' => ['name' => self::SPEC_NAME, 'version' => self::SPEC_VERSION],
            'package' => ['id' => $this->packageId],
            'createdAt' => $this->createdAt,
            'profiles' => $this->profiles,
            'artifacts' => array_map(static fn (SitePackCatalogEntry $e): string => $e->id, $this->entries),
            'annotations' => $this->annotations,
        ];
    }

    /** @return array{artifacts: list<array<string, int|string>>} */
    public function catalog(): array
    {
        return ['artifacts' => array_map(static fn (SitePackCatalogEntry $e): array => $e->toArray(), $this->entries)];
    }

    public function manifestJson(): string
    {
        return self::encode($this->manifest())."\n";
    }

    public function catalogJson(): string
    {
        return self::catalogBytes($this->entries);
    }

    /**
     * Canonical JSON: object keys sorted, no insignificant whitespace, slashes and unicode unescaped.
     */
    public static function canonicalJson(mixed $value): string
    {
        return self::encode(self::canonicalize($value));
    }

    /** @param list<SitePackCatalogEntry> $entries */
    private static function catalogBytes(array $entries): string
    {
        return self::canonicalJson(['artifacts' => array_map(static fn (SitePackCatalogEntry $e): array => $e->toArray(), $entries)])."\n";
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }
        ksort($value, SORT_STRING);

        return array_map(self::canonicalize(...), $value);
    }

    private static function encode(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException $exception) {
            throw new SitePackRejected('sitepack_manifest_invalid', 'SitePack document cannot be encoded.', $exception);
        }
    }

    /**
     * @param list<SitePackCatalogEntry> $entries
     * @return list<SitePackCatalogEntry>
     */
    private static function sortedUnique(array $entries): array
    {
        usort($entries, static fn (SitePackCatalogEntry $a, SitePackCatalogEntry $b): int => strcmp($a->path, $b->path));
        $ids = [];
        $previous = null;
        foreach ($entries as $entry) {
            if ($entry->path === $previous || isset($ids[$entry->id])) {
                throw new SitePackRejected('sitepack_artifact_duplicate');
            }
            $previous = $entry->path;
            $ids[$entry->id] = true;
        }

        return $entries;
    }

    /**
     * @param list<string> $profiles
     * @param array<string, mixed> $annotations
     */
    private static function assertManifestFields(string $createdAt, array $profiles, array $annotations): void
    {
        if (preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/D', $createdAt) !== 1) {
            throw new SitePackRejected('sitepack_manifest_invalid', 'SitePack createdAt is not an RFC 3339 time.');
        }
        if ($profiles === [] || count(array_unique($profiles)) !== count($profiles)) {
            throw new SitePackRejected('sitepack_manifest_invalid', 'SitePack profiles are empty or repeated.');
        }
        foreach ($profiles as $profile) {
            if (preg_match('/\A[a-z0-9][a-z0-9+._-]{0,63}\z/D', $profile) !== 1) {
                throw new SitePackRejected('sitepack_manifest_invalid', 'SitePack profile is invalid.');
            }
        }
        if (!is_string($annotations['schema'] ?? null) || !is_int($annotations['schema_version'] ?? null)) {
            throw new SitePackRejected('sitepack_manifest_invalid', 'SitePack annotations carry no schema stamp.');
        }
    }

    /** @return array<string, mixed> */
    private static function object(string $json, int $depth, string $reason): array
    {
        try {
            $value = json_decode($json, true, $depth, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new SitePackRejected($reason, 'SitePack document is not valid JSON.', $exception);
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new SitePackRejected($reason);
        }

        return $value;
    }
}
