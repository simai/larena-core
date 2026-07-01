<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Larena\Core\Starter\FoundationPackageSet;

$runtimeSecurity = FoundationPackageSet::runtimeSecurity();
$dataContent = FoundationPackageSet::dataContent();
$frontendCompositionAdmin = FoundationPackageSet::frontendCompositionAdmin();
$foundation = FoundationPackageSet::foundationPreview();

foreach (['larena/core', 'larena/access', 'larena/audit', 'larena/licensing'] as $package) {
    if (!in_array($package, $runtimeSecurity, true)) {
        fwrite(STDERR, "Missing runtime/security package: {$package}" . PHP_EOL);
        exit(1);
    }
}

foreach (['larena/storage', 'larena/filesystem', 'larena/lang', 'larena/search', 'larena/link', 'larena/backup', 'larena/file-manager'] as $package) {
    if (!in_array($package, $dataContent, true)) {
        fwrite(STDERR, "Missing data/content package: {$package}" . PHP_EOL);
        exit(1);
    }
}

foreach (['larena/setting', 'larena/property', 'larena/admin'] as $package) {
    if (!in_array($package, $frontendCompositionAdmin, true)) {
        fwrite(STDERR, "Missing frontend/composition/admin package: {$package}" . PHP_EOL);
        exit(1);
    }
}

if ($foundation !== [...$runtimeSecurity, ...$dataContent, ...$frontendCompositionAdmin]) {
    fwrite(STDERR, 'Foundation package set order must preserve runtime/security before data/content before frontend/composition/admin.' . PHP_EOL);
    exit(1);
}

if (count($foundation) !== count(array_unique($foundation))) {
    fwrite(STDERR, 'Foundation package set must not contain duplicates.' . PHP_EOL);
    exit(1);
}

echo 'FoundationPackageSetTest passed.' . PHP_EOL;
