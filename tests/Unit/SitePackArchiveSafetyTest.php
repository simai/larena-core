<?php

declare(strict_types=1);

require_once __DIR__.'/../bootstrap.php';
require_once __DIR__.'/../support/sitepack-fixtures.php';

use Larena\Core\SitePack\SitePackArchiveReader;
use Larena\Core\SitePack\SitePackCatalogEntry;
use Larena\Core\SitePack\SitePackLimits;
use Larena\Core\SitePack\SitePackPaths;

$root = sitepackTemporaryDirectory('safety');
$linesBytes = sitepackLines([['id' => 'a', 'n' => 1], ['id' => 'b', 'n' => 2]]);
$blobBytes = 'binary-blob-bytes';
$base = [
    ['id' => 'larena.test.records', 'path' => 'artifacts/records.jsonl', 'bytes' => $linesBytes, 'lines' => 2],
    ['id' => 'blob:one', 'path' => 'artifacts/blobs/sha256/'.hash('sha256', $blobBytes), 'bytes' => $blobBytes],
];
$counter = 0;
$file = static function () use ($root, &$counter): string {
    return $root.'/case-'.(++$counter).'.sitepack';
};
$drain = static function (SitePackArchiveReader $reader, string $path): int {
    $count = 0;
    foreach ($reader->lines($path) as $ignored) {
        $count++;
    }

    return $count;
};

// A well-formed control archive opens and reads.
$control = $file();
sitepackCraft($control, $base);
$reader = SitePackArchiveReader::open($control);
sitepackAssert($drain($reader, 'artifacts/records.jsonl') === 2, 'control archive reads');
$reader->close();

// Zip slip and other unsafe names, both in the archive and in the catalog.
foreach (['../evil.json', 'artifacts/../../evil.json', '/etc/evil.json', 'artifacts\\evil.json', 'artifacts//evil.json', './evil.json', 'artifacts/', "evil\x01.json"] as $name) {
    $path = $file();
    sitepackCraft($path, $base, mutateZip: static fn (ZipArchive $zip) => $zip->addFromString($name, 'x'));
    sitepackAssertRejected(static fn () => SitePackArchiveReader::open($path), 'sitepack_archive_entry_path_invalid', 'unsafe entry name '.json_encode($name));
}
sitepackAssertRejected(
    static fn () => new SitePackCatalogEntry('x', 'application/json', '../evil.json', 1, 'sha256:'.str_repeat('a', 64)),
    'sitepack_archive_entry_path_invalid',
    'catalog path slip',
);

// Symlink entry.
$path = $file();
sitepackCraft($path, $base, mutateZip: static fn (ZipArchive $zip) => $zip->setExternalAttributesName('artifacts/records.jsonl', ZipArchive::OPSYS_UNIX, 0120777 << 16));
sitepackAssertRejected(static fn () => SitePackArchiveReader::open($path), 'sitepack_archive_entry_symlink', 'symlink entry');

// Encrypted entry.
$path = $file();
sitepackCraft($path, $base, mutateZip: static fn (ZipArchive $zip) => $zip->setEncryptionName($base[1]['path'], ZipArchive::EM_AES_256, 'secret'));
sitepackAssertRejected(static fn () => SitePackArchiveReader::open($path), 'sitepack_archive_entry_encrypted', 'encrypted entry');

// Duplicate entry: two same-length names, the second renamed in the raw bytes to the first.
$path = $file();
sitepackCraft($path, $base, mutateZip: static function (ZipArchive $zip): void {
    $zip->addFromString('artifacts/dupe-a.json', '{}');
    $zip->addFromString('artifacts/dupe-b.json', '{}');
});
file_put_contents($path, str_replace('artifacts/dupe-b.json', 'artifacts/dupe-a.json', (string) file_get_contents($path)));
sitepackAssertRejected(static fn () => SitePackArchiveReader::open($path), 'sitepack_archive_entry_duplicate', 'duplicate entry');

// Unknown entry and a missing artifact.
$path = $file();
sitepackCraft($path, $base, mutateZip: static fn (ZipArchive $zip) => $zip->addFromString('artifacts/smuggled.json', '{}'));
sitepackAssertRejected(static fn () => SitePackArchiveReader::open($path), 'sitepack_archive_entry_unknown', 'unknown entry');
$path = $file();
sitepackCraft($path, $base, mutateZip: static fn (ZipArchive $zip) => $zip->deleteName('artifacts/records.jsonl'));
sitepackAssertRejected(static fn () => SitePackArchiveReader::open($path), 'sitepack_artifact_missing', 'missing artifact');

// Missing envelope documents.
$path = $file();
sitepackCraft($path, $base, mutateZip: static fn (ZipArchive $zip) => $zip->deleteName(SitePackPaths::MANIFEST));
sitepackAssertRejected(static fn () => SitePackArchiveReader::open($path), 'sitepack_manifest_invalid', 'missing manifest');

// Oversized: the archive file, the total expansion and the compression ratio.
$zeros = str_repeat("\0", 3 * 1_048_576);
$big = [['id' => 'blob:zeros', 'path' => 'artifacts/zeros.bin', 'bytes' => $zeros]];
$path = $file();
sitepackCraft($path, $big);
sitepackAssertRejected(static fn () => SitePackArchiveReader::open($path, new SitePackLimits(maxTotalBytes: 1024)), 'sitepack_archive_too_large', 'archive file over the limit');
sitepackAssertRejected(static fn () => SitePackArchiveReader::open($path, new SitePackLimits(maxTotalBytes: 2 * 1_048_576)), 'sitepack_archive_expansion_limit_exceeded', 'expansion over the limit');
sitepackAssertRejected(static fn () => SitePackArchiveReader::open($path), 'sitepack_archive_expansion_ratio_exceeded', 'compression bomb ratio');
sitepackAssert(SitePackArchiveReader::open($path, new SitePackLimits(maxExpansionRatio: 100_000))->entry('artifacts/zeros.bin')->size === strlen($zeros), 'ratio is configurable');
sitepackAssertRejected(static fn () => SitePackArchiveReader::open($control, new SitePackLimits(maxEntries: 1)), 'sitepack_archive_entry_count_invalid', 'entry count limit');
sitepackAssertRejected(static fn () => SitePackArchiveReader::open($control, new SitePackLimits(maxDocumentBytes: 16)), 'sitepack_document_too_large', 'envelope document limit');

// Size declared in the catalog differs from the entry.
$path = $file();
sitepackCraft($path, $base, mutateEntries: static fn (array $entries): array => [
    new SitePackCatalogEntry($entries[0]->id, $entries[0]->mediaType, $entries[0]->path, $entries[0]->size, $entries[0]->digest, $entries[0]->lines),
    new SitePackCatalogEntry($entries[1]->id, $entries[1]->mediaType, $entries[1]->path, $entries[1]->size + 1, $entries[1]->digest),
]);
sitepackAssertRejected(static fn () => SitePackArchiveReader::open($path), 'sitepack_artifact_size_mismatch', 'declared size differs');

// Digest mismatch: same size, different bytes, found when the stream ends.
$forged = str_replace('"n":2', '"n":3', $linesBytes);
$path = $file();
sitepackCraft($path, $base, mutateZip: static fn (ZipArchive $zip) => $zip->addFromString('artifacts/records.jsonl', $forged, ZipArchive::FL_OVERWRITE));
$reader = SitePackArchiveReader::open($path);
$seen = 0;
sitepackAssertRejected(static function () use ($reader, &$seen): void {
    foreach ($reader->lines('artifacts/records.jsonl') as $ignored) {
        $seen++;
    }
}, 'sitepack_artifact_digest_mismatch', 'lines digest mismatch');
sitepackAssert($seen === 2, 'digest is checked at the end of the stream');
$path = $file();
sitepackCraft($path, $base, mutateZip: static fn (ZipArchive $zip) => $zip->addFromString($base[1]['path'], strrev($blobBytes), ZipArchive::FL_OVERWRITE));
$reader = SitePackArchiveReader::open($path);
sitepackAssertRejected(static fn () => iterator_to_array($reader->stream($base[1]['path'])), 'sitepack_artifact_digest_mismatch', 'blob digest mismatch');
sitepackAssertRejected(static fn () => $reader->copyTo($base[1]['path'], $root.'/forged.bin'), 'sitepack_artifact_digest_mismatch', 'blob copy digest mismatch');
sitepackAssert(!is_file($root.'/forged.bin'), 'a failed copy leaves no file');

// Truncated or padded line count.
foreach ([3 => 'fewer lines than declared', 1 => 'more lines than declared'] as $declared => $label) {
    $path = $file();
    sitepackCraft($path, [['id' => 'larena.test.records', 'path' => 'artifacts/records.jsonl', 'bytes' => $linesBytes, 'lines' => $declared]]);
    $reader = SitePackArchiveReader::open($path);
    sitepackAssertRejected(static fn () => $drain($reader, 'artifacts/records.jsonl'), 'sitepack_artifact_line_count_mismatch', $label);
}

// Malformed lines.
$cases = [
    'sitepack_line_depth_exceeded' => str_repeat('{"a":', 100).'1'.str_repeat('}', 100)."\n",
    'sitepack_line_too_long' => '{"text":"'.str_repeat('x', 2000)."\"}\n",
    'sitepack_line_unterminated' => '{"id":"a"}',
    'sitepack_line_invalid' => "[1,2]\n",
];
foreach ($cases as $reason => $bytes) {
    $path = $file();
    sitepackCraft($path, [['id' => 'larena.test.records', 'path' => 'artifacts/records.jsonl', 'bytes' => $bytes, 'lines' => 1]]);
    $reader = SitePackArchiveReader::open($path, new SitePackLimits(maxLineBytes: 1024));
    sitepackAssertRejected(static fn () => $drain($reader, 'artifacts/records.jsonl'), $reason, 'line case '.$reason);
}
foreach (["\n", "{\"a\":\n", "\"text\"\n"] as $bytes) {
    $path = $file();
    sitepackCraft($path, [['id' => 'larena.test.records', 'path' => 'artifacts/records.jsonl', 'bytes' => $bytes, 'lines' => 1]]);
    $reader = SitePackArchiveReader::open($path);
    sitepackAssertRejected(static fn () => $drain($reader, 'artifacts/records.jsonl'), 'sitepack_line_invalid', 'invalid line '.json_encode($bytes));
}

// Envelope tampering inside the archive.
$path = $file();
sitepackCraft($path, $base, mutateManifest: static fn (string $manifest): string => str_replace('"0.4.0"', '"0.5.0"', $manifest));
sitepackAssertRejected(static fn () => SitePackArchiveReader::open($path), 'sitepack_manifest_incompatible', 'spec version in archive');
$path = $file();
sitepackCraft($path, $base, mutateManifest: static fn (string $manifest): string => preg_replace('/larena-test-[a-f0-9]{64}/', 'larena-test-'.str_repeat('f', 64), $manifest) ?? $manifest);
sitepackAssertRejected(static fn () => SitePackArchiveReader::open($path), 'sitepack_package_identity_mismatch', 'package id in archive');

// Not an archive at all.
file_put_contents($root.'/garbage.sitepack', 'not a zip file');
sitepackAssertRejected(static fn () => SitePackArchiveReader::open($root.'/garbage.sitepack'), 'sitepack_archive_invalid', 'garbage archive');
sitepackAssertRejected(static fn () => SitePackArchiveReader::open($root.'/absent.sitepack'), 'sitepack_archive_unavailable', 'absent archive');
symlink($control, $root.'/link.sitepack');
sitepackAssertRejected(static fn () => SitePackArchiveReader::open($root.'/link.sitepack'), 'sitepack_archive_unavailable', 'archive path is a symlink');

echo "SitePack archive safety passed.\n";
