<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src', FilesystemIterator::SKIP_DOTS));
$forbidden = '/^use Larena\\\\(?!Core\\\\)/m';
foreach ($iterator as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $source = (string) file_get_contents($file->getPathname());
    if (preg_match($forbidden, $source) === 1) {
        throw new RuntimeException('upper_larena_import_in_core:'.$file->getPathname());
    }
}

echo "MinimalCmsCoreBoundaryTest passed.\n";
