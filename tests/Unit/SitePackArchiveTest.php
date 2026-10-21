<?php

declare(strict_types=1);

require_once __DIR__.'/../bootstrap.php';
require_once __DIR__.'/../support/sitepack-fixtures.php';

use Larena\Core\SitePack\SitePackArchiveReader;
use Larena\Core\SitePack\SitePackArchiveWriter;
use Larena\Core\SitePack\SitePackEnvelope;
use Larena\Core\SitePack\SitePackLimits;
use Larena\Core\SitePack\SitePackPaths;

$root = sitepackTemporaryDirectory('roundtrip');
$blobBytes = random_bytes(3000);
$blobSource = $root.'/blob.bin';
file_put_contents($blobSource, $blobBytes);
$blobPath = 'artifacts/assets/blobs/sha256/'.hash('sha256', $blobBytes);
$records = [
    ['id' => 'r-1', 'title' => 'Первая', 'values' => ['ru' => 'а', 'en' => 'a'], 'weight' => 1.0],
    ['id' => 'r-2', 'title' => 'Second / slash', 'values' => [], 'weight' => 2],
    [],
];

/** Writes the same pack from a fresh staging directory. */
$pack = static function (string $staging, string $archive, int $touch) use ($records, $blobSource, $blobPath): SitePackEnvelope {
    $writer = new SitePackArchiveWriter($staging);
    $writer->addJson('larena.test.structure', 'artifacts/structure.json', ['b' => 2, 'a' => ['y' => 1, 'x' => 0]]);
    $writer->beginLines('larena.test.records.0001', 'artifacts/records/0001.jsonl');
    foreach ($records as $record) {
        $writer->appendLine('artifacts/records/0001.jsonl', $record);
    }
    $writer->addBlobFromFile('blob:'.basename($blobPath), $blobPath, $blobSource);
    sitepackAssert($writer->has($blobPath) && !$writer->has('artifacts/none.json'), 'writer tracks staged paths');
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($staging, FilesystemIterator::SKIP_DOTS)) as $file) {
        touch($file->getPathname(), $touch);
    }

    return $writer->finish($archive, 'test', '2026-10-22T10:00:00Z', ['storage', 'storage+files'], 'larena.test.sitepack', 1, ['adapter' => 'larena/core-test']);
};

$first = $pack($root.'/stage-a', $root.'/a.sitepack', 1_700_000_000);
$second = $pack($root.'/stage-b', $root.'/b.sitepack', 1_800_000_000);
sitepackAssert(hash_file('sha256', $root.'/a.sitepack') === hash_file('sha256', $root.'/b.sitepack'), 'archives are byte-identical regardless of staging time');
sitepackAssert($first->packageId === $second->packageId, 'package id is deterministic');
sitepackAssert(preg_match('/\Alarena-test-[a-f0-9]{64}\z/D', $first->packageId) === 1, 'package id carries the prefix and catalog digest');
sitepackAssert($first->packageId === 'larena-test-'.hash('sha256', $first->catalogJson()), 'package id is the canonical catalog digest');

$zip = new ZipArchive();
sitepackAssert($zip->open($root.'/a.sitepack') === true, 'archive opens');
$names = [];
for ($index = 0; $index < $zip->numFiles; $index++) {
    $stat = $zip->statIndex($index);
    sitepackAssert($stat !== false, 'entry stat');
    $names[] = $stat['name'];
    sitepackAssert($stat['mtime'] === (new DateTimeImmutable('1980-01-01 00:00:00'))->getTimestamp(), 'fixed mtime');
    $expected = str_starts_with($stat['name'], 'artifacts/assets/blobs/') ? ZipArchive::CM_STORE : ZipArchive::CM_DEFLATE;
    sitepackAssert($stat['comp_method'] === $expected, 'compression for '.$stat['name']);
}
$zip->close();
sitepackAssert($names === [
    $blobPath,
    'artifacts/records/0001.jsonl',
    'artifacts/structure.json',
    SitePackPaths::CATALOG,
    SitePackPaths::MANIFEST,
], 'artifacts in path order, envelope last: '.implode(', ', $names));

$reader = SitePackArchiveReader::open($root.'/a.sitepack');
sitepackAssert($reader->envelope->packageId === $first->packageId, 'reader sees the same package');
sitepackAssert($reader->envelope->annotationsAre('larena.test.sitepack', 1), 'annotation schema stamp');
sitepackAssert(!$reader->envelope->annotationsAre('larena.test.sitepack', 2), 'annotation schema version is data');
$manifest = $reader->manifest();
sitepackAssert($manifest['spec'] === ['name' => 'sitepack', 'version' => '0.4.0'], 'spec');
sitepackAssert(array_key_first($manifest['annotations']) === 'schema', 'stamp comes first');
sitepackAssert($manifest['annotations']['adapter'] === 'larena/core-test', 'participant annotations kept');
sitepackAssert($reader->envelope->profiles === ['storage', 'storage+files'], 'profiles');
sitepackAssert(count($reader->catalog()) === 3, 'catalog size');
$lines = $reader->entry('artifacts/records/0001.jsonl');
sitepackAssert($lines->lines === 3 && $lines->isLines(), 'line count recorded');
sitepackAssert($reader->entry('artifacts/structure.json')->lines === null, 'documents carry no line count');

$read = [];
foreach ($reader->lines('artifacts/records/0001.jsonl') as $number => $object) {
    $read[$number] = $object;
}
sitepackAssert($read === [1 => $records[0], 2 => $records[1], 3 => []], 'lines round-trip');
$blob = '';
foreach ($reader->stream($blobPath, 1000) as $chunk) {
    sitepackAssert(strlen($chunk) <= 1000, 'chunk bound');
    $blob .= $chunk;
}
sitepackAssert($blob === $blobBytes, 'blob round-trip');
$reader->copyTo($blobPath, $root.'/copy.bin');
sitepackAssert(hash_file('sha256', $root.'/copy.bin') === hash('sha256', $blobBytes), 'blob copy verified');
$structure = implode('', iterator_to_array($reader->stream('artifacts/structure.json')));
sitepackAssert($structure === "{\"a\":{\"x\":0,\"y\":1},\"b\":2}\n", 'documents are canonical JSON');
sitepackAssertRejected(static fn () => iterator_to_array($reader->lines('artifacts/structure.json')), 'sitepack_artifact_not_lines', 'a document is not lines');
sitepackAssertRejected(static fn () => $reader->entry('artifacts/none.json'), 'sitepack_artifact_unknown', 'unknown artifact');
$reader->close();

// Writer refusals.
$writer = new SitePackArchiveWriter($root.'/stage-c', new SitePackLimits(maxLineBytes: 64, maxJsonDepth: 4));
$writer->beginLines('larena.test.lines', 'artifacts/lines.jsonl');
sitepackAssertRejected(static fn () => $writer->beginLines('larena.test.lines', 'artifacts/other.jsonl'), 'sitepack_artifact_duplicate', 'duplicate id');
sitepackAssertRejected(static fn () => $writer->addJson('larena.test.other', 'artifacts/lines.jsonl', []), 'sitepack_artifact_duplicate', 'duplicate path');
sitepackAssertRejected(static fn () => $writer->addJson('larena.test.slip', '../slip.json', []), 'sitepack_archive_entry_path_invalid', 'writer refuses zip slip');
sitepackAssertRejected(static fn () => $writer->addJson('larena.test.reserved', SitePackPaths::MANIFEST, []), 'sitepack_archive_entry_path_invalid', 'writer refuses reserved path');
sitepackAssertRejected(static fn () => $writer->appendLine('artifacts/lines.jsonl', ['text' => str_repeat('x', 80)]), 'sitepack_line_too_long', 'writer refuses long line');
sitepackAssertRejected(static fn () => $writer->appendLine('artifacts/lines.jsonl', ['a' => ['b' => ['c' => ['d' => ['e' => 1]]]]]), 'sitepack_line_depth_exceeded', 'writer refuses deep line');
sitepackAssertRejected(static fn () => $writer->appendLine('artifacts/lines.jsonl', [1, 2]), 'sitepack_line_invalid', 'writer refuses a list line');
sitepackAssertRejected(static fn () => $writer->appendLine('artifacts/missing.jsonl', ['a' => 1]), 'sitepack_writer_invalid', 'lines must be begun');
sitepackAssertRejected(static fn () => $writer->finish($root.'/c.sitepack', 'Bad Prefix', '2026-10-22T10:00:00Z', ['x'], 'larena.test.sitepack', 1), 'sitepack_manifest_invalid', 'prefix rules');
$writer->abort();
sitepackAssertRejected(static fn () => $writer->addJson('larena.test.late', 'artifacts/late.json', []), 'sitepack_writer_finished', 'aborted writer refuses');
sitepackAssertRejected(static fn () => (new SitePackArchiveWriter($root.'/stage-d', new SitePackLimits(maxTotalBytes: 10)))->addJson('larena.test.big', 'artifacts/big.json', ['k' => str_repeat('x', 20)]), 'sitepack_archive_too_large', 'writer total limit');

// Envelope parsing.
$catalog = $first->catalogJson();
$manifestJson = $first->manifestJson();
sitepackAssert(SitePackEnvelope::parse($manifestJson, $catalog)->packageId === $first->packageId, 'envelope parses its own output');
$tampered = json_decode($manifestJson, true, 64, JSON_THROW_ON_ERROR);
$tampered['spec']['version'] = '0.3.0';
sitepackAssertRejected(static fn () => SitePackEnvelope::parse(json_encode($tampered, JSON_THROW_ON_ERROR), $catalog), 'sitepack_manifest_incompatible', 'spec version');
$tampered = json_decode($manifestJson, true, 64, JSON_THROW_ON_ERROR);
$tampered['spec']['name'] = 'otherpack';
sitepackAssertRejected(static fn () => SitePackEnvelope::parse(json_encode($tampered, JSON_THROW_ON_ERROR), $catalog), 'sitepack_manifest_incompatible', 'spec name');
$tampered = json_decode($manifestJson, true, 64, JSON_THROW_ON_ERROR);
$tampered['package']['id'] = 'larena-test-'.str_repeat('0', 64);
sitepackAssertRejected(static fn () => SitePackEnvelope::parse(json_encode($tampered, JSON_THROW_ON_ERROR), $catalog), 'sitepack_package_identity_mismatch', 'package id bound to the catalog');
$tampered = json_decode($manifestJson, true, 64, JSON_THROW_ON_ERROR);
unset($tampered['annotations']['schema']);
sitepackAssertRejected(static fn () => SitePackEnvelope::parse(json_encode($tampered, JSON_THROW_ON_ERROR), $catalog), 'sitepack_manifest_invalid', 'annotations need a schema stamp');
$tampered = json_decode($manifestJson, true, 64, JSON_THROW_ON_ERROR);
$tampered['extra'] = true;
sitepackAssertRejected(static fn () => SitePackEnvelope::parse(json_encode($tampered, JSON_THROW_ON_ERROR), $catalog), 'sitepack_manifest_invalid', 'unknown manifest field');
$catalogRows = json_decode($catalog, true, 64, JSON_THROW_ON_ERROR);
$catalogRows['artifacts'] = array_reverse($catalogRows['artifacts']);
sitepackAssertRejected(static fn () => SitePackEnvelope::parse($manifestJson, json_encode($catalogRows, JSON_THROW_ON_ERROR)), 'sitepack_catalog_invalid', 'catalog sorted by path');
$catalogRows = json_decode($catalog, true, 64, JSON_THROW_ON_ERROR);
$catalogRows['artifacts'][0]['size'] = 1;
sitepackAssertRejected(static fn () => SitePackEnvelope::parse($manifestJson, json_encode($catalogRows, JSON_THROW_ON_ERROR)), 'sitepack_package_identity_mismatch', 'catalog change breaks the package id');
sitepackAssertRejected(static fn () => SitePackEnvelope::parse('[]', $catalog), 'sitepack_manifest_incompatible', 'manifest must be an object');
sitepackAssertRejected(static fn () => SitePackEnvelope::parse('{', $catalog), 'sitepack_manifest_invalid', 'manifest JSON');

// Limits from config.
$limits = SitePackLimits::fromConfig(['max_entries' => '50', 'max_line_bytes' => 4096, 'max_json_depth' => 0, 'max_total_bytes' => 'lots']);
sitepackAssert($limits->maxEntries === 50 && $limits->maxLineBytes === 4096, 'configured limits');
sitepackAssert($limits->maxJsonDepth === 64 && $limits->maxTotalBytes === 2_147_483_648, 'invalid values keep defaults');
$defaults = SitePackLimits::fromConfig([]);
sitepackAssert($defaults->maxEntries === 200_000 && $defaults->maxLineBytes === 1_048_576 && $defaults->maxExpansionRatio === 200, 'defaults');

echo "SitePack envelope, writer and reader round trip passed.\n";
