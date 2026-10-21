<?php

declare(strict_types=1);

namespace Larena\Core\SitePack;

use HashContext;
use JsonException;
use Throwable;
use ZipArchive;

/**
 * Writes a SitePack archive without holding it in memory.
 *
 * Callers stage artifacts in a working directory: JSON lines are appended one object at a time, JSON documents and
 * blobs are written once. Size, line count and SHA-256 are computed while the bytes are written. `finish()` builds the
 * catalog and manifest and packs a deterministic zip: entries in path order with the envelope last, a fixed mtime and
 * fixed permissions, deflate for JSON and JSON lines, store for blobs. libzip reads the staged files while the archive
 * is closed, so memory stays flat whatever the archive size.
 */
final class SitePackArchiveWriter
{
    public const string LINES_MEDIA_TYPE = 'application/x-ndjson';
    public const string JSON_MEDIA_TYPE = 'application/json';
    public const string BLOB_MEDIA_TYPE = 'application/octet-stream';

    /** 1980-01-01T00:00:00Z, the earliest time a zip entry can carry. */
    private const int DETERMINISTIC_MTIME = 315532800;
    private const int FILE_MODE = 0100644;
    private const int CHUNK_BYTES = 1_048_576;

    /** @var array<string, array{id:string,mediaType:string,handle:resource,hash:HashContext,size:int,lines:int}> */
    private array $open = [];

    /** @var array<string, SitePackCatalogEntry> */
    private array $closed = [];

    /** @var array<string, true> */
    private array $ids = [];

    /** @var array<string, true> paths whose bytes are deflated */
    private array $deflate = [];

    private int $totalBytes = 0;

    private bool $finished = false;

    private readonly string $staging;

    public function __construct(
        string $stagingDirectory,
        private readonly SitePackLimits $limits = new SitePackLimits(),
    ) {
        $staging = rtrim($stagingDirectory, '/');
        if ($staging === '' || (!is_dir($staging) && !@mkdir($staging, 0700, true) && !is_dir($staging)) || !is_writable($staging)) {
            throw new SitePackRejected('sitepack_writer_invalid', 'SitePack staging directory is not writable.');
        }
        $this->staging = $staging;
    }

    /** Opens a JSON lines artifact; objects are then appended with appendLine(). */
    public function beginLines(string $id, string $path, string $mediaType = self::LINES_MEDIA_TYPE): void
    {
        $this->reserve($id, $path, $mediaType);
        $this->open[$path] = [
            'id' => $id,
            'mediaType' => $mediaType,
            'handle' => $this->createFile($path),
            'hash' => hash_init('sha256'),
            'size' => 0,
            'lines' => 0,
        ];
        $this->deflate[$path] = true;
    }

    /**
     * Appends one JSON object as one line.
     *
     * @param array<array-key, mixed>|\stdClass $object a JSON object: string keys, or a stdClass
     */
    public function appendLine(string $path, array|\stdClass $object): void
    {
        $this->assertWritable();
        if (!isset($this->open[$path])) {
            throw new SitePackRejected('sitepack_writer_invalid', 'SitePack lines artifact is not open: '.$path);
        }
        if (is_array($object) && $object !== [] && array_is_list($object)) {
            throw new SitePackRejected('sitepack_line_invalid', 'A SitePack line must be a JSON object.');
        }
        try {
            $line = json_encode(
                $object === [] ? new \stdClass() : $object,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
                $this->limits->maxJsonDepth,
            )."\n";
        } catch (JsonException $exception) {
            throw new SitePackRejected(
                $exception->getCode() === JSON_ERROR_DEPTH ? 'sitepack_line_depth_exceeded' : 'sitepack_line_invalid',
                'SitePack line cannot be encoded.',
                $exception,
            );
        }
        if (strlen($line) > $this->limits->maxLineBytes) {
            throw new SitePackRejected('sitepack_line_too_long');
        }
        $artifact = &$this->open[$path];
        $this->count(strlen($line));
        $this->write($artifact['handle'], $line);
        hash_update($artifact['hash'], $line);
        $artifact['size'] += strlen($line);
        $artifact['lines']++;
        unset($artifact);
    }

    /** Closes a JSON lines artifact. finish() closes any still open. */
    public function endLines(string $path): SitePackCatalogEntry
    {
        $artifact = $this->open[$path] ?? null;
        if ($artifact === null) {
            throw new SitePackRejected('sitepack_writer_invalid', 'SitePack lines artifact is not open: '.$path);
        }
        unset($this->open[$path]);
        if (!fclose($artifact['handle'])) {
            throw new SitePackRejected('sitepack_writer_io_failed');
        }

        return $this->closed[$path] = new SitePackCatalogEntry(
            $artifact['id'],
            $artifact['mediaType'],
            $path,
            $artifact['size'],
            'sha256:'.hash_final($artifact['hash']),
            $artifact['lines'],
        );
    }

    /**
     * Writes one JSON document artifact (canonical JSON, newline-terminated).
     *
     * @param array<array-key, mixed> $document
     */
    public function addJson(string $id, string $path, array $document, string $mediaType = self::JSON_MEDIA_TYPE): SitePackCatalogEntry
    {
        $this->reserve($id, $path, $mediaType);
        $bytes = SitePackEnvelope::canonicalJson($document)."\n";
        if (strlen($bytes) > $this->limits->maxDocumentBytes) {
            throw new SitePackRejected('sitepack_document_too_large');
        }
        $this->count(strlen($bytes));
        $handle = $this->createFile($path);
        $this->write($handle, $bytes);
        fclose($handle);
        $this->deflate[$path] = true;

        return $this->closed[$path] = new SitePackCatalogEntry($id, $mediaType, $path, strlen($bytes), 'sha256:'.hash('sha256', $bytes));
    }

    /** Copies a blob from a file, hashing it on the way. */
    public function addBlobFromFile(string $id, string $path, string $sourceFile, string $mediaType = self::BLOB_MEDIA_TYPE): SitePackCatalogEntry
    {
        $source = is_file($sourceFile) && !is_link($sourceFile) ? @fopen($sourceFile, 'rb') : false;
        if ($source === false) {
            throw new SitePackRejected('sitepack_writer_invalid', 'SitePack blob source is not a readable file.');
        }
        try {
            return $this->addBlobFromStream($id, $path, $source, $mediaType);
        } finally {
            fclose($source);
        }
    }

    /**
     * Copies a blob from a readable stream, hashing it on the way. The caller keeps and closes the stream.
     *
     * @param resource $source
     */
    public function addBlobFromStream(string $id, string $path, $source, string $mediaType = self::BLOB_MEDIA_TYPE): SitePackCatalogEntry
    {
        $this->reserve($id, $path, $mediaType);
        $handle = $this->createFile($path);
        $hash = hash_init('sha256');
        $size = 0;
        try {
            while (!feof($source)) {
                $chunk = fread($source, self::CHUNK_BYTES);
                if ($chunk === false) {
                    throw new SitePackRejected('sitepack_writer_io_failed', 'SitePack blob source cannot be read.');
                }
                if ($chunk === '') {
                    continue;
                }
                $this->count(strlen($chunk));
                $this->write($handle, $chunk);
                hash_update($hash, $chunk);
                $size += strlen($chunk);
            }
        } finally {
            fclose($handle);
        }

        return $this->closed[$path] = new SitePackCatalogEntry($id, $mediaType, $path, $size, 'sha256:'.hash_final($hash));
    }

    public function has(string $path): bool
    {
        return isset($this->open[$path]) || isset($this->closed[$path]);
    }

    /**
     * Closes open line artifacts, writes the catalog and manifest and packs the archive at $archivePath atomically.
     *
     * @param string $packagePrefix the id becomes `larena-<prefix>-<catalog sha256>`
     * @param list<string> $profiles
     * @param array<string, mixed> $annotations participant fields beside the schema stamp
     */
    public function finish(
        string $archivePath,
        string $packagePrefix,
        string $createdAt,
        array $profiles,
        string $schemaName,
        int $schemaVersion,
        array $annotations = [],
    ): SitePackEnvelope {
        $this->assertWritable();
        foreach (array_keys($this->open) as $path) {
            $this->endLines($path);
        }
        if ($this->closed === []) {
            throw new SitePackRejected('sitepack_writer_invalid', 'SitePack archive has no artifacts.');
        }
        $envelope = SitePackEnvelope::build(
            $packagePrefix,
            $createdAt,
            $profiles,
            $schemaName,
            $schemaVersion,
            $annotations,
            array_values($this->closed),
        );
        $catalog = $envelope->catalogJson();
        $manifest = $envelope->manifestJson();
        if (strlen($catalog) > $this->limits->maxDocumentBytes || strlen($manifest) > $this->limits->maxDocumentBytes) {
            throw new SitePackRejected('sitepack_document_too_large');
        }
        $this->count(strlen($catalog) + strlen($manifest));
        $this->finished = true;

        $directory = dirname($archivePath);
        $temporary = is_dir($directory) && is_writable($directory) ? tempnam($directory, '.sitepack-') : false;
        if (!is_string($temporary)) {
            throw new SitePackRejected('sitepack_writer_io_failed', 'SitePack archive directory is not writable.');
        }
        try {
            $zip = new ZipArchive();
            if ($zip->open($temporary, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new SitePackRejected('sitepack_writer_io_failed', 'SitePack archive cannot be created.');
            }
            foreach ($envelope->entries as $entry) {
                $this->addEntry($zip, $entry->path, isset($this->deflate[$entry->path]), file: $this->staged($entry->path));
            }
            $this->addEntry($zip, SitePackPaths::CATALOG, true, contents: $catalog);
            $this->addEntry($zip, SitePackPaths::MANIFEST, true, contents: $manifest);
            if (!$zip->close()) {
                throw new SitePackRejected('sitepack_writer_io_failed', 'SitePack archive cannot be written.');
            }
            $size = filesize($temporary);
            if (!is_int($size) || $size > $this->limits->maxTotalBytes) {
                throw new SitePackRejected('sitepack_archive_too_large');
            }
            if (!rename($temporary, $archivePath)) {
                throw new SitePackRejected('sitepack_writer_io_failed', 'SitePack archive cannot be published.');
            }
        } catch (Throwable $exception) {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
            throw $exception;
        }

        return $envelope;
    }

    /** Closes open handles without packing; staged files stay for the caller to remove. */
    public function abort(): void
    {
        foreach ($this->open as $artifact) {
            fclose($artifact['handle']);
        }
        $this->open = [];
        $this->finished = true;
    }

    private function addEntry(ZipArchive $zip, string $path, bool $deflate, ?string $file = null, ?string $contents = null): void
    {
        $added = $file !== null ? $zip->addFile($file, $path) : $zip->addFromString($path, (string) $contents);
        if (
            !$added
            || !$zip->setCompressionName($path, $deflate ? ZipArchive::CM_DEFLATE : ZipArchive::CM_STORE)
            || !$zip->setMtimeName($path, self::DETERMINISTIC_MTIME)
            || !$zip->setExternalAttributesName($path, ZipArchive::OPSYS_UNIX, self::FILE_MODE << 16)
        ) {
            throw new SitePackRejected('sitepack_writer_io_failed', 'SitePack archive entry cannot be added: '.$path);
        }
    }

    private function reserve(string $id, string $path, string $mediaType): void
    {
        $this->assertWritable();
        // Validates the id, media type and path before anything touches the disk.
        new SitePackCatalogEntry($id, $mediaType, $path, 0, 'sha256:'.str_repeat('0', 64));
        if ($this->has($path) || isset($this->ids[$id])) {
            throw new SitePackRejected('sitepack_artifact_duplicate', 'SitePack artifact is already staged: '.$path);
        }
        if (count($this->open) + count($this->closed) >= $this->limits->maxEntries) {
            throw new SitePackRejected('sitepack_archive_entry_count_invalid');
        }
        $this->ids[$id] = true;
    }

    private function count(int $bytes): void
    {
        $this->totalBytes += $bytes;
        if ($this->totalBytes > $this->limits->maxTotalBytes) {
            throw new SitePackRejected('sitepack_archive_too_large');
        }
    }

    /** @return resource */
    private function createFile(string $path)
    {
        $file = $this->staged($path);
        $directory = dirname($file);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new SitePackRejected('sitepack_writer_io_failed', 'SitePack staging directory cannot be created.');
        }
        $handle = @fopen($file, 'xb');
        if ($handle === false) {
            throw new SitePackRejected('sitepack_writer_io_failed', 'SitePack staging file cannot be created: '.$path);
        }

        return $handle;
    }

    /** @param resource $handle */
    private function write($handle, string $bytes): void
    {
        $length = strlen($bytes);
        $written = 0;
        while ($written < $length) {
            $result = fwrite($handle, $written === 0 ? $bytes : substr($bytes, $written));
            if ($result === false || $result === 0) {
                throw new SitePackRejected('sitepack_writer_io_failed', 'SitePack staging file cannot be written.');
            }
            $written += $result;
        }
    }

    private function staged(string $path): string
    {
        return $this->staging.'/'.$path;
    }

    private function assertWritable(): void
    {
        if ($this->finished) {
            throw new SitePackRejected('sitepack_writer_finished');
        }
    }
}
