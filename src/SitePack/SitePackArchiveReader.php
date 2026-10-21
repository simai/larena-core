<?php

declare(strict_types=1);

namespace Larena\Core\SitePack;

use Generator;
use JsonException;
use ZipArchive;

/**
 * Opens a SitePack archive and streams its artifacts without loading them.
 *
 * Opening checks the archive as a whole: size and entry count, no encrypted, symlinked, duplicate or unknown entry,
 * safe paths, store or deflate only, the expansion limit and ratio, the envelope (spec, package id against the catalog
 * digest) and that every catalog artifact is present with its declared size. Artifact bytes are verified while they
 * are streamed: an artifact that grows past its declared size fails at once; a size, line count or SHA-256 mismatch
 * fails when the stream ends. Objects yielded before that point are provisional: a caller writes them only where a
 * failure can discard them.
 */
final class SitePackArchiveReader
{
    private const int CHUNK_BYTES = 1_048_576;

    private ?ZipArchive $zip;

    private function __construct(
        ZipArchive $zip,
        public readonly SitePackEnvelope $envelope,
        private readonly SitePackLimits $limits,
    ) {
        $this->zip = $zip;
    }

    public static function open(string $archivePath, SitePackLimits $limits = new SitePackLimits()): self
    {
        $size = is_file($archivePath) && !is_link($archivePath) ? filesize($archivePath) : false;
        if (!is_int($size) || $size < 1) {
            throw new SitePackRejected('sitepack_archive_unavailable');
        }
        if ($size > $limits->maxTotalBytes) {
            throw new SitePackRejected('sitepack_archive_too_large');
        }
        $zip = self::openZip($archivePath);
        try {
            $sizes = self::inspectEntries($zip, $limits);
            $envelope = SitePackEnvelope::parse(
                self::readDocument($zip, SitePackPaths::MANIFEST, $sizes, $limits),
                self::readDocument($zip, SitePackPaths::CATALOG, $sizes, $limits),
                $limits,
            );
            if (count($envelope->entries) > $limits->maxEntries) {
                throw new SitePackRejected('sitepack_archive_entry_count_invalid');
            }
            foreach ($envelope->entries as $entry) {
                if (!isset($sizes[$entry->path])) {
                    throw new SitePackRejected('sitepack_artifact_missing', 'SitePack artifact is missing: '.$entry->path);
                }
                if ($sizes[$entry->path] !== $entry->size) {
                    throw new SitePackRejected('sitepack_artifact_size_mismatch', 'SitePack artifact size differs: '.$entry->path);
                }
                unset($sizes[$entry->path]);
            }
            unset($sizes[SitePackPaths::MANIFEST], $sizes[SitePackPaths::CATALOG]);
            if ($sizes !== []) {
                throw new SitePackRejected('sitepack_archive_entry_unknown', 'SitePack archive has an entry the catalog does not list.');
            }
        } catch (\Throwable $exception) {
            $zip->close();
            throw $exception;
        }

        return new self($zip, $envelope, $limits);
    }

    /** @return array<string, mixed> */
    public function manifest(): array
    {
        return $this->envelope->manifest();
    }

    /** @return list<SitePackCatalogEntry> */
    public function catalog(): array
    {
        return $this->envelope->entries;
    }

    public function entry(string $path): SitePackCatalogEntry
    {
        return $this->envelope->entry($path)
            ?? throw new SitePackRejected('sitepack_artifact_unknown', 'SitePack artifact is not in the catalog: '.$path);
    }

    /**
     * Streams a JSON lines artifact, one decoded object per line, keyed by its 1-based line number.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function lines(string $path): Generator
    {
        $entry = $this->entry($path);
        if (!$entry->isLines()) {
            throw new SitePackRejected('sitepack_artifact_not_lines');
        }
        $handle = $this->artifactStream($path);
        $hash = hash_init('sha256');
        $size = 0;
        $count = 0;
        try {
            while (true) {
                $line = fgets($handle, $this->limits->maxLineBytes + 1);
                if ($line === false) {
                    if (!feof($handle)) {
                        throw new SitePackRejected('sitepack_artifact_unreadable');
                    }
                    break;
                }
                $size += strlen($line);
                if ($size > $entry->size) {
                    throw new SitePackRejected('sitepack_artifact_size_mismatch', 'SitePack artifact is longer than declared: '.$path);
                }
                hash_update($hash, $line);
                if (!str_ends_with($line, "\n")) {
                    throw new SitePackRejected(
                        strlen($line) >= $this->limits->maxLineBytes ? 'sitepack_line_too_long' : 'sitepack_line_unterminated',
                    );
                }
                $count++;
                if ($count > (int) $entry->lines) {
                    throw new SitePackRejected('sitepack_artifact_line_count_mismatch', 'SitePack artifact has more lines than declared: '.$path);
                }

                yield $count => $this->decodeLine($line);
            }
        } finally {
            fclose($handle);
        }
        $this->verify($entry, $size, hash_final($hash));
        if ($count !== $entry->lines) {
            throw new SitePackRejected('sitepack_artifact_line_count_mismatch', 'SitePack artifact has fewer lines than declared: '.$path);
        }
    }

    /**
     * Streams any artifact in chunks of at most $chunkBytes; the SHA-256 and size are checked when it ends.
     *
     * @return Generator<int, string>
     */
    public function stream(string $path, int $chunkBytes = self::CHUNK_BYTES): Generator
    {
        $entry = $this->entry($path);
        if ($chunkBytes < 1) {
            throw new \InvalidArgumentException('sitepack_chunk_size_invalid');
        }
        $handle = $this->artifactStream($path);
        $hash = hash_init('sha256');
        $size = 0;
        try {
            while (!feof($handle)) {
                $chunk = fread($handle, $chunkBytes);
                if ($chunk === false) {
                    throw new SitePackRejected('sitepack_artifact_unreadable');
                }
                if ($chunk === '') {
                    continue;
                }
                $size += strlen($chunk);
                if ($size > $entry->size) {
                    throw new SitePackRejected('sitepack_artifact_size_mismatch', 'SitePack artifact is longer than declared: '.$path);
                }
                hash_update($hash, $chunk);

                yield $chunk;
            }
        } finally {
            fclose($handle);
        }
        $this->verify($entry, $size, hash_final($hash));
    }

    /**
     * Copies an artifact to a new file and verifies it; on any failure the file is removed.
     */
    public function copyTo(string $path, string $destinationFile): SitePackCatalogEntry
    {
        $entry = $this->entry($path);
        $target = @fopen($destinationFile, 'xb');
        if ($target === false) {
            throw new SitePackRejected('sitepack_artifact_unreadable', 'SitePack copy target cannot be created.');
        }
        try {
            foreach ($this->stream($path) as $chunk) {
                if (fwrite($target, $chunk) !== strlen($chunk)) {
                    throw new SitePackRejected('sitepack_artifact_unreadable', 'SitePack copy target cannot be written.');
                }
            }
            fclose($target);
            $target = null;
        } catch (\Throwable $exception) {
            if (is_resource($target)) {
                fclose($target);
            }
            @unlink($destinationFile);
            throw $exception;
        }

        return $entry;
    }

    public function close(): void
    {
        $this->zip?->close();
        $this->zip = null;
    }

    public function __destruct()
    {
        $this->close();
    }

    private static function openZip(string $archivePath): ZipArchive
    {
        $zip = new ZipArchive();
        $result = $zip->open($archivePath, ZipArchive::RDONLY | ZipArchive::CHECKCONS);
        if ($result === true) {
            return $zip;
        }
        // Under CHECKCONS libzip refuses an archive whose central directory names one entry twice.
        if ($result === ZipArchive::ER_EXISTS) {
            throw new SitePackRejected('sitepack_archive_entry_duplicate');
        }

        throw new SitePackRejected('sitepack_archive_invalid');
    }

    /**
     * Checks every entry once and returns entry path => uncompressed size.
     *
     * @return array<string, int>
     */
    private static function inspectEntries(ZipArchive $zip, SitePackLimits $limits): array
    {
        if ($zip->numFiles < 3 || $zip->numFiles > $limits->maxEntries + 2) {
            throw new SitePackRejected('sitepack_archive_entry_count_invalid');
        }
        $sizes = [];
        $total = 0;
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $stat = $zip->statIndex($index, ZipArchive::FL_UNCHANGED);
            if ($stat === false) {
                throw new SitePackRejected('sitepack_archive_entry_invalid');
            }
            if ($stat['encryption_method'] !== ZipArchive::EM_NONE) {
                throw new SitePackRejected('sitepack_archive_entry_encrypted');
            }
            $system = 0;
            $attributes = 0;
            if (
                $zip->getExternalAttributesIndex($index, $system, $attributes, ZipArchive::FL_UNCHANGED)
                && (($attributes >> 16) & 0170000) === 0120000
            ) {
                throw new SitePackRejected('sitepack_archive_entry_symlink');
            }
            $name = $stat['name'];
            SitePackPaths::assertEntryPath($name);
            if (isset($sizes[$name])) {
                throw new SitePackRejected('sitepack_archive_entry_duplicate');
            }
            if ($stat['comp_method'] !== ZipArchive::CM_STORE && $stat['comp_method'] !== ZipArchive::CM_DEFLATE) {
                throw new SitePackRejected('sitepack_archive_entry_compression_unsupported');
            }
            $size = $stat['size'];
            $total += $size;
            if ($total > $limits->maxTotalBytes) {
                throw new SitePackRejected('sitepack_archive_expansion_limit_exceeded');
            }
            if (
                $size > SitePackLimits::EXPANSION_RATIO_FLOOR_BYTES
                && $size > $limits->maxExpansionRatio * max(1, $stat['comp_size'])
            ) {
                throw new SitePackRejected('sitepack_archive_expansion_ratio_exceeded');
            }
            $sizes[$name] = $size;
        }

        return $sizes;
    }

    /** @param array<string, int> $sizes */
    private static function readDocument(ZipArchive $zip, string $path, array $sizes, SitePackLimits $limits): string
    {
        if (!isset($sizes[$path])) {
            throw new SitePackRejected($path === SitePackPaths::MANIFEST ? 'sitepack_manifest_invalid' : 'sitepack_catalog_invalid', 'SitePack envelope document is missing.');
        }
        if ($sizes[$path] > $limits->maxDocumentBytes) {
            throw new SitePackRejected('sitepack_document_too_large');
        }
        $contents = $zip->getFromName($path, 0, ZipArchive::FL_UNCHANGED);
        if (!is_string($contents) || strlen($contents) !== $sizes[$path]) {
            throw new SitePackRejected('sitepack_artifact_unreadable', 'SitePack envelope document cannot be read.');
        }

        return $contents;
    }

    /** @return resource */
    private function artifactStream(string $path)
    {
        if ($this->zip === null) {
            throw new SitePackRejected('sitepack_archive_unavailable', 'SitePack archive is closed.');
        }
        $handle = $this->zip->getStream($path);
        if ($handle === false) {
            throw new SitePackRejected('sitepack_artifact_unreadable');
        }

        return $handle;
    }

    private function verify(SitePackCatalogEntry $entry, int $size, string $sha256): void
    {
        if ($size !== $entry->size) {
            throw new SitePackRejected('sitepack_artifact_size_mismatch', 'SitePack artifact is shorter than declared: '.$entry->path);
        }
        if (!hash_equals($entry->sha256(), $sha256)) {
            throw new SitePackRejected('sitepack_artifact_digest_mismatch', 'SitePack artifact digest differs: '.$entry->path);
        }
    }

    /** @return array<string, mixed> */
    private function decodeLine(string $line): array
    {
        if ($line[0] !== '{') {
            throw new SitePackRejected('sitepack_line_invalid', 'A SitePack line must be a JSON object.');
        }
        try {
            $value = json_decode($line, true, $this->limits->maxJsonDepth, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new SitePackRejected(
                $exception->getCode() === JSON_ERROR_DEPTH ? 'sitepack_line_depth_exceeded' : 'sitepack_line_invalid',
                'SitePack line is not valid JSON.',
                $exception,
            );
        }
        if (!is_array($value)) {
            throw new SitePackRejected('sitepack_line_invalid');
        }

        return $value;
    }
}
