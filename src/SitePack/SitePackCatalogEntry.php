<?php

declare(strict_types=1);

namespace Larena\Core\SitePack;

/**
 * One artifact of the catalog: where it lives in the archive, its exact size and SHA-256 digest and, for a JSON lines
 * artifact, its line count.
 */
final readonly class SitePackCatalogEntry
{
    public function __construct(
        public string $id,
        public string $mediaType,
        public string $path,
        public int $size,
        public string $digest,
        public ?int $lines = null,
    ) {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/+-]{0,190}\z/D', $id) !== 1) {
            throw new SitePackRejected('sitepack_catalog_invalid', 'SitePack artifact id is invalid.');
        }
        if (strlen($mediaType) > 127 || preg_match('/\A[a-z0-9][a-z0-9.+-]*\/[a-z0-9][a-z0-9.+-]*\z/D', $mediaType) !== 1) {
            throw new SitePackRejected('sitepack_catalog_invalid', 'SitePack artifact media type is invalid.');
        }
        SitePackPaths::assertArtifactPath($path);
        if ($size < 0 || ($lines !== null && $lines < 0)) {
            throw new SitePackRejected('sitepack_catalog_invalid', 'SitePack artifact size is invalid.');
        }
        if (preg_match('/\Asha256:[a-f0-9]{64}\z/D', $digest) !== 1) {
            throw new SitePackRejected('sitepack_catalog_invalid', 'SitePack artifact digest is invalid.');
        }
    }

    public function isLines(): bool
    {
        return $this->lines !== null;
    }

    /** The bare hex SHA-256 of the artifact bytes. */
    public function sha256(): string
    {
        return substr($this->digest, 7);
    }

    /** @return array{id:string,mediaType:string,path:string,size:int,digest:string,lines?:int} */
    public function toArray(): array
    {
        $entry = [
            'id' => $this->id,
            'mediaType' => $this->mediaType,
            'path' => $this->path,
            'size' => $this->size,
            'digest' => $this->digest,
        ];
        if ($this->lines !== null) {
            $entry['lines'] = $this->lines;
        }

        return $entry;
    }

    /** @param array<array-key, mixed> $entry */
    public static function fromArray(array $entry): self
    {
        $known = ['id' => true, 'mediaType' => true, 'path' => true, 'size' => true, 'digest' => true, 'lines' => true];
        foreach (array_keys($entry) as $key) {
            if (!isset($known[$key])) {
                throw new SitePackRejected('sitepack_catalog_invalid', 'SitePack catalog entry has an unknown field.');
            }
        }
        $id = $entry['id'] ?? null;
        $mediaType = $entry['mediaType'] ?? null;
        $path = $entry['path'] ?? null;
        $size = $entry['size'] ?? null;
        $digest = $entry['digest'] ?? null;
        $lines = $entry['lines'] ?? null;
        if (
            !is_string($id)
            || !is_string($mediaType)
            || !is_string($path)
            || !is_int($size)
            || !is_string($digest)
            || ($lines !== null && !is_int($lines))
        ) {
            throw new SitePackRejected('sitepack_catalog_invalid', 'SitePack catalog entry is incomplete.');
        }

        return new self($id, $mediaType, $path, $size, $digest, $lines);
    }
}
