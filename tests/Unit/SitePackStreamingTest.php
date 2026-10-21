<?php

declare(strict_types=1);

require_once __DIR__.'/../bootstrap.php';
require_once __DIR__.'/../support/sitepack-fixtures.php';

use Larena\Core\SitePack\SitePackArchiveReader;
use Larena\Core\SitePack\SitePackArchiveWriter;

// 200 000 records (~26 MB of JSON lines) are written and read back. Neither side may hold the
// artifact in memory: the peak stays a small fraction of the artifact size.
const SITEPACK_STREAMING_LINES = 200_000;
const SITEPACK_STREAMING_PEAK_BYTES = 8 * 1_048_576;

$root = sitepackTemporaryDirectory('streaming');
$record = static fn (int $n): array => [
    'id' => sprintf('rec-%06d', $n),
    'structure' => 'catalog',
    'values' => ['title' => ['ru' => 'Запись '.$n, 'en' => 'Record '.$n], 'price' => $n * 1.5],
    'state' => $n % 3 === 0 ? 'archived' : 'active',
];
$path = 'artifacts/records/catalog/0001.jsonl';

gc_collect_cycles();
$baseline = memory_get_usage();
memory_reset_peak_usage();
$writer = new SitePackArchiveWriter($root.'/stage');
$writer->beginLines('larena.test.records.catalog.0001', $path);
for ($n = 1; $n <= SITEPACK_STREAMING_LINES; $n++) {
    $writer->appendLine($path, $record($n));
}
$envelope = $writer->finish($root.'/big.sitepack', 'test', '2026-10-22T10:00:00Z', ['storage'], 'larena.test.sitepack', 1);
$writePeak = memory_get_peak_usage() - $baseline;
$entry = $envelope->entry($path);
sitepackAssert($entry !== null && $entry->lines === SITEPACK_STREAMING_LINES, 'line count');
sitepackAssert($entry->size > 3 * SITEPACK_STREAMING_PEAK_BYTES, 'artifact is far larger than the memory bound: '.$entry->size);
sitepackAssert($writePeak < SITEPACK_STREAMING_PEAK_BYTES, 'writer memory stays bounded: '.$writePeak);
unset($writer);

gc_collect_cycles();
$baseline = memory_get_usage();
memory_reset_peak_usage();
$reader = SitePackArchiveReader::open($root.'/big.sitepack');
$count = 0;
foreach ($reader->lines($path) as $number => $object) {
    $count++;
    if ($number !== $count || $object['id'] !== sprintf('rec-%06d', $count)) {
        throw new RuntimeException('line '.$number.' read back out of order');
    }
}
$reader->close();
$readPeak = memory_get_peak_usage() - $baseline;
sitepackAssert($count === SITEPACK_STREAMING_LINES, 'every line read back');
sitepackAssert($readPeak < SITEPACK_STREAMING_PEAK_BYTES, 'reader memory stays bounded: '.$readPeak);

printf("SitePack streaming passed (%d lines, %.1f MB; peak write %.1f MB, read %.1f MB).\n", $count, $entry->size / 1_048_576, $writePeak / 1_048_576, $readPeak / 1_048_576);
