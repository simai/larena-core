<?php

declare(strict_types=1);

use Larena\Core\SitePack\SitePackCatalogEntry;
use Larena\Core\SitePack\SitePackEnvelope;
use Larena\Core\SitePack\SitePackPaths;
use Larena\Core\SitePack\SitePackRejected;

function sitepackAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param callable():mixed $operation */
function sitepackAssertRejected(callable $operation, string $reasonCode, string $message): void
{
    try {
        $operation();
    } catch (SitePackRejected $exception) {
        sitepackAssert($exception->reasonCode === $reasonCode, $message.': expected '.$reasonCode.', got '.$exception->reasonCode);

        return;
    }

    throw new RuntimeException($message.': operation unexpectedly succeeded');
}

function sitepackTemporaryDirectory(string $label): string
{
    $directory = sys_get_temp_dir().'/larena-core-sitepack-'.$label.'-'.bin2hex(random_bytes(6));
    if (!mkdir($directory, 0700, true)) {
        throw new RuntimeException('sitepack test directory cannot be created');
    }
    register_shutdown_function(static function () use ($directory): void {
        sitepackRemoveTree($directory);
    });

    return $directory;
}

function sitepackRemoveTree(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($directory);
}

/**
 * Builds an archive by hand so tests can break exactly one rule. Each artifact is
 * {id, path, bytes, lines?, mediaType?}; $mutateEntries may change the catalog before the
 * envelope is built (the package id stays consistent with it), $mutateZip may add, rename or
 * flag entries before the archive is closed.
 *
 * @param list<array{id:string,path:string,bytes:string,lines?:int,mediaType?:string}> $artifacts
 * @param (callable(list<SitePackCatalogEntry>):list<SitePackCatalogEntry>)|null $mutateEntries
 * @param (callable(ZipArchive):mixed)|null $mutateZip
 * @param (callable(string):string)|null $mutateManifest
 */
function sitepackCraft(
    string $file,
    array $artifacts,
    ?callable $mutateEntries = null,
    ?callable $mutateZip = null,
    ?callable $mutateManifest = null,
): void {
    $entries = [];
    foreach ($artifacts as $artifact) {
        $entries[] = new SitePackCatalogEntry(
            $artifact['id'],
            $artifact['mediaType'] ?? (isset($artifact['lines']) ? 'application/x-ndjson' : 'application/octet-stream'),
            $artifact['path'],
            strlen($artifact['bytes']),
            'sha256:'.hash('sha256', $artifact['bytes']),
            $artifact['lines'] ?? null,
        );
    }
    if ($mutateEntries !== null) {
        $entries = $mutateEntries($entries);
    }
    $envelope = SitePackEnvelope::build('test', '2026-10-22T00:00:00Z', ['test'], 'larena.test.sitepack', 1, [], $entries);
    $manifest = $envelope->manifestJson();
    if ($mutateManifest !== null) {
        $manifest = $mutateManifest($manifest);
    }
    $zip = new ZipArchive();
    if ($zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('sitepack test zip cannot be created');
    }
    foreach ($artifacts as $artifact) {
        $zip->addFromString($artifact['path'], $artifact['bytes']);
    }
    $zip->addFromString(SitePackPaths::CATALOG, $envelope->catalogJson());
    $zip->addFromString(SitePackPaths::MANIFEST, $manifest);
    if ($mutateZip !== null) {
        $mutateZip($zip);
    }
    if (!$zip->close()) {
        throw new RuntimeException('sitepack test zip cannot be written');
    }
}

/** @param list<array<string, mixed>> $rows */
function sitepackLines(array $rows): string
{
    $bytes = '';
    foreach ($rows as $row) {
        $bytes .= json_encode($row, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    return $bytes;
}
