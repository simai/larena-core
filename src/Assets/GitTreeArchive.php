<?php

declare(strict_types=1);

namespace Larena\Core\Assets;

use InvalidArgumentException;
use RuntimeException;

/**
 * Git trees as verified archives: archiving a pinned tree, checking that an archive is safe to extract,
 * extracting it, and proving that the extracted files are exactly the tree's blobs.
 */
final class GitTreeArchive
{
    /**
     * @param array{repository:string,commit:string,tree:string,mount:string,sha256:string} $source
     * @return array<string, string|int>
     */
    public function extractVerifiedSource(array $source, string $stage): array
    {
        $repository = $this->repositoryPath($source['repository']);
        $commit = AssetPathRules::fullSha($source['commit']);
        $tree = AssetPathRules::stablePath($source['tree'], 'core_assets_source_tree_invalid');
        $mount = AssetPathRules::stableSegment($source['mount'], 'core_assets_source_mount_invalid');
        $expected = strtolower($source['sha256']);
        if (!preg_match('/^[a-f0-9]{64}$/', $expected)) {
            throw new InvalidArgumentException('core_assets_source_checksum_invalid');
        }

        $tar = $stage . DIRECTORY_SEPARATOR . '.' . $mount . '.tar';
        $this->runGitArchive($repository, $commit, $tree, $tar);
        $actual = hash_file('sha256', $tar);
        if (!is_string($actual) || !hash_equals($expected, $actual)) {
            @unlink($tar);
            throw new RuntimeException('core_assets_source_checksum_mismatch:' . $mount);
        }
        $this->assertArchiveSafe($tar);

        $mountPath = $stage . DIRECTORY_SEPARATOR . $mount;
        AssetPathRules::ensureDirectory($mountPath);
        try {
            $this->extractArchive($tar, $mountPath);
            $verification = $this->verifyExtractedSource($repository, $commit, $tree, $mountPath);
        } finally {
            @unlink($tar);
        }

        return [
            'commit' => $commit,
            'tree' => $tree,
            'mount' => $mount,
            'sha256' => $actual,
            'file_count' => $verification['file_count'],
            'tree_fingerprint_sha256' => $verification['tree_fingerprint_sha256'],
            'object_format' => $verification['object_format'],
        ];
    }

    public function extractArchive(string $tar, string $destination): void
    {
        $process = proc_open(
            ['tar', '-xf', $tar, '-C', $destination],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('core_assets_archive_extract_start_failed');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            throw new RuntimeException('core_assets_archive_extract_failed:' . trim((string) ($stderr ?: $stdout)));
        }
    }

    /** @return array{file_count:int,tree_fingerprint_sha256:string,object_format:string} */
    public function verifyExtractedSource(
        string $repository,
        string $commit,
        string $tree,
        string $mountPath,
    ): array {
        $expected = $this->gitTreeEntries($repository, $commit, $tree);
        $first = reset($expected);
        $objectFormat = is_array($first) ? $first['object_format'] : '';
        $actual = $this->scanExtractedTree($mountPath, $objectFormat);
        $expectedPaths = array_keys($expected);
        sort($expectedPaths, SORT_STRING);
        if (array_keys($actual['entries']) !== $expectedPaths) {
            throw new RuntimeException('core_assets_extracted_tree_paths_mismatch');
        }

        foreach ($expectedPaths as $path) {
            $entry = $expected[$path];
            $actualEntry = $actual['entries'][$path];
            if ($entry['mode'] !== $actualEntry['mode']) {
                throw new RuntimeException('core_assets_extracted_entry_mode_mismatch:' . $path);
            }
            if (!hash_equals($entry['object'], $actualEntry['object'])) {
                throw new RuntimeException('core_assets_extracted_entry_content_mismatch:' . $path);
            }
        }

        return [
            'file_count' => count($expectedPaths),
            'tree_fingerprint_sha256' => $actual['tree_fingerprint_sha256'],
            'object_format' => $objectFormat,
        ];
    }

    /**
     * @return array{
     *   entries:array<string,array{mode:string,object:string}>,
     *   tree_fingerprint_sha256:string
     * }
     */
    public function scanExtractedTree(string $mountPath, string $objectFormat): array
    {
        if (!in_array($objectFormat, ['sha1', 'sha256'], true) || !is_dir($mountPath)) {
            throw new RuntimeException('core_assets_extracted_tree_invalid');
        }
        $entries = [];
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($mountPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                continue;
            }
            if (!$item->isFile() && !$item->isLink()) {
                throw new RuntimeException('core_assets_extracted_entry_type_unsupported');
            }
            $relative = substr($item->getPathname(), strlen($mountPath) + 1);
            if ($relative === '' || !$this->safeArchivePath($relative)) {
                throw new RuntimeException('core_assets_archive_path_unsafe');
            }
            if ($item->isLink()) {
                $target = readlink($item->getPathname());
                if (!is_string($target)) {
                    throw new RuntimeException('core_assets_extracted_symlink_unreadable:' . $relative);
                }
                $mode = '120000';
                $object = $this->gitBlobHash($target, $objectFormat);
            } else {
                $mode = ((((int) $item->getPerms()) & 0111) !== 0) ? '100755' : '100644';
                $object = $this->gitFileHash($item->getPathname(), $objectFormat);
            }
            $entries[$relative] = ['mode' => $mode, 'object' => $object];
        }
        ksort($entries, SORT_STRING);
        $fingerprint = hash_init('sha256');
        foreach ($entries as $path => $entry) {
            hash_update($fingerprint, $entry['mode'] . ' ' . $entry['object'] . "\t" . $path . "\0");
        }

        return [
            'entries' => $entries,
            'tree_fingerprint_sha256' => hash_final($fingerprint),
        ];
    }

    /**
     * @return array<string, array{mode:string,object:string,object_format:string}>
     */
    private function gitTreeEntries(string $repository, string $commit, string $tree): array
    {
        $objectFormat = $this->gitOutput(
            ['git', '-C', $repository, 'rev-parse', '--show-object-format'],
            'core_assets_git_object_format_failed',
        );
        $objectFormat = trim($objectFormat);
        if (!in_array($objectFormat, ['sha1', 'sha256'], true)) {
            throw new RuntimeException('core_assets_git_object_format_unsupported');
        }
        $output = $this->gitOutput(
            ['git', '-C', $repository, 'ls-tree', '-r', '-z', '--full-tree', $commit, '--', $tree],
            'core_assets_git_tree_read_failed',
        );
        $entries = [];
        foreach (explode("\0", $output) as $record) {
            if ($record === '') {
                continue;
            }
            if (preg_match('/^([0-9]{6}) ([a-z]+) ([a-f0-9]+)\t(.+)$/s', $record, $matches) !== 1
                || $matches[2] !== 'blob'
                || !$this->safeArchivePath($matches[4])
            ) {
                throw new RuntimeException('core_assets_git_tree_entry_invalid');
            }
            $entries[$matches[4]] = [
                'mode' => $matches[1],
                'object' => $matches[3],
                'object_format' => $objectFormat,
            ];
        }
        if ($entries === []) {
            throw new RuntimeException('core_assets_git_tree_empty');
        }
        ksort($entries, SORT_STRING);

        return $entries;
    }

    public function repositoryPath(string $path): string
    {
        $path = trim($path);
        $repository = $path !== '' && !str_contains($path, "\0") ? realpath($path) : false;
        if ($repository === false || !is_dir($repository)) {
            throw new InvalidArgumentException('core_assets_source_repository_invalid');
        }
        try {
            $gitDirectory = trim($this->gitOutput(
                ['git', '-C', $repository, 'rev-parse', '--git-dir'],
                'core_assets_source_repository_invalid',
            ));
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException('core_assets_source_repository_invalid', 0, $exception);
        }
        if ($gitDirectory === '') {
            throw new InvalidArgumentException('core_assets_source_repository_invalid');
        }

        return $repository;
    }

    /** @param list<string> $command */
    private function gitOutput(array $command, string $error): string
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException($error);
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0) {
            throw new RuntimeException($error . ':' . trim((string) $stderr));
        }

        return (string) $stdout;
    }

    private function safeArchivePath(string $path): bool
    {
        if ($path === ''
            || $path[0] === '/'
            || str_contains($path, "\0")
            || str_contains($path, "\\")
            || str_contains($path, "\n")
            || str_contains($path, "\r")
        ) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    private static function gitBlobHash(string $contents, string $algorithm): string
    {
        return hash($algorithm, 'blob ' . strlen($contents) . "\0" . $contents);
    }

    public static function gitFileHash(string $path, string $algorithm): string
    {
        $size = filesize($path);
        $stream = fopen($path, 'rb');
        if (!is_int($size) || !is_resource($stream)) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            throw new RuntimeException('core_assets_extracted_entry_unreadable');
        }
        $context = hash_init($algorithm);
        hash_update($context, 'blob ' . $size . "\0");
        hash_update_stream($context, $stream);
        fclose($stream);

        return hash_final($context);
    }

    public function runGitArchive(string $repository, string $commit, string $tree, string $target): void
    {
        $process = proc_open(
            ['git', '-C', $repository, 'archive', '--format=tar', '--output=' . $target, $commit, $tree],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('core_assets_git_archive_start_failed');
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0 || !is_file($target)) {
            throw new RuntimeException('core_assets_git_archive_failed:' . trim((string) ($stderr ?: $stdout)));
        }
    }

    public function gzipArchive(string $tar, string $target): void
    {
        $this->runCommandToFile(
            ['gzip', '-n', '-9', '-c', $tar],
            $target,
            'core_assets_archive_compress_failed',
        );
    }

    public function decompressGzipArchive(string $archive, string $target): void
    {
        $this->runCommandToFile(
            ['gzip', '-d', '-c', $archive],
            $target,
            'core_assets_archive_decompress_failed',
        );
    }

    /** @param list<string> $command */
    private function runCommandToFile(array $command, string $target, string $error): void
    {
        $process = proc_open(
            $command,
            [1 => ['file', $target, 'wb'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException($error);
        }
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0 || !is_file($target)) {
            @unlink($target);
            throw new RuntimeException($error . ':' . trim((string) $stderr));
        }
    }

    public function assertArchiveSafe(string $tar): void
    {
        $pathsOutput = $this->gitOutput(
            ['tar', '-tf', $tar],
            'core_assets_archive_list_failed',
        );
        $verboseOutput = $this->gitOutput(
            ['tar', '-tvf', $tar],
            'core_assets_archive_verbose_list_failed',
        );
        $paths = $this->archiveOutputLines($pathsOutput);
        $verbose = $this->archiveOutputLines($verboseOutput);
        if ($paths === [] || count($paths) !== count($verbose)) {
            throw new RuntimeException('core_assets_archive_listing_invalid');
        }

        $seen = [];
        foreach ($paths as $index => $path) {
            $type = $verbose[$index][0] ?? '';
            $normalized = str_ends_with($path, '/') ? substr($path, 0, -1) : $path;
            if ($normalized === ''
                || !$this->safeArchivePath($normalized)
                || !in_array($type, ['-', 'd'], true)
                || isset($seen[$normalized])
            ) {
                throw new RuntimeException('core_assets_archive_entry_unsafe');
            }
            $seen[$normalized] = true;
        }
    }

    /** @return list<string> */
    private function archiveOutputLines(string $output): array
    {
        $output = rtrim($output, "\r\n");
        if ($output === '') {
            return [];
        }

        return preg_split('/\r?\n/', $output) ?: [];
    }
}
