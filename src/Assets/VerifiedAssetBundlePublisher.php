<?php

declare(strict_types=1);

namespace Larena\Core\Assets;

use InvalidArgumentException;
use RuntimeException;

/**
 * Publishes complete immutable Git trees after verifying their deterministic
 * archive checksums. Activation state is deliberately filesystem-only.
 */
final class VerifiedAssetBundlePublisher
{
    public const BUNDLE_SCHEMA = 'larena.core_assets.immutable_bundle.v2';

    public const PUBLICATION_PROFILE = 'exact-git-tree-v2';

    /**
     * @param list<array{repository:string,commit:string,tree:string,mount:string,sha256:string}> $sources
     * @return array<string, mixed>
     */
    public function publish(array $sources, string $destinationRoot, string $stateFile, string $bundleId): array
    {
        $bundleId = $this->stableSegment($bundleId, 'core_assets_bundle_id_invalid');
        if ($sources === []) {
            throw new InvalidArgumentException('core_assets_bundle_sources_required');
        }

        $destinationRoot = $this->absolutePath($destinationRoot, 'core_assets_destination_root_invalid');
        $stateFile = $this->absolutePath($stateFile, 'core_assets_state_file_invalid');
        $bundlePath = $destinationRoot . DIRECTORY_SEPARATOR . $bundleId;

        $this->ensureDirectory($destinationRoot);
        $this->ensureDirectory(dirname($stateFile));

        if (!is_dir($bundlePath)) {
            $stage = $destinationRoot . DIRECTORY_SEPARATOR . '.' . $bundleId . '.stage-' . bin2hex(random_bytes(6));
            $this->ensureDirectory($stage);

            try {
                $receipts = [];
                foreach ($sources as $source) {
                    $receipts[] = $this->extractVerifiedSource($source, $stage);
                }
                $this->writeJson($stage . DIRECTORY_SEPARATOR . '.larena-bundle.json', [
                    'schema' => self::BUNDLE_SCHEMA,
                    'publication_profile' => self::PUBLICATION_PROFILE,
                    'bundle_id' => $bundleId,
                    'sources' => $receipts,
                ]);

                if (!rename($stage, $bundlePath)) {
                    throw new RuntimeException('core_assets_bundle_activate_rename_failed');
                }
            } catch (\Throwable $exception) {
                $this->removeTree($stage);
                throw $exception;
            }
        } else {
            $receipts = $this->validateExistingBundle($bundlePath, $bundleId, $sources);
        }

        $before = $this->readState($stateFile);
        $state = [
            'schema' => 'larena.core_assets.activation_state.v1',
            'active_bundle' => $bundleId,
            'previous_bundle' => $before['active_bundle'] ?? null,
        ];
        $this->writeJson($stateFile, $state);

        return [
            'schema' => 'larena.core_assets.publication_receipt.v2',
            'publication_profile' => self::PUBLICATION_PROFILE,
            'activation_owner' => 'larena/core:core.assets',
            'bundle_id' => $bundleId,
            'bundle_path' => $bundlePath,
            'active_bundle' => $bundleId,
            'previous_bundle' => $state['previous_bundle'],
            'sources' => $receipts,
            'writes_database' => false,
            'uses_cdn' => false,
        ];
    }

    /** @return array<string, mixed> */
    public function rollback(string $destinationRoot, string $stateFile): array
    {
        $destinationRoot = $this->absolutePath($destinationRoot, 'core_assets_destination_root_invalid');
        $stateFile = $this->absolutePath($stateFile, 'core_assets_state_file_invalid');
        $state = $this->readState($stateFile);
        $previous = $state['previous_bundle'] ?? null;

        if (!is_string($previous) || $previous === '') {
            throw new RuntimeException('core_assets_previous_bundle_unavailable');
        }
        $previous = $this->stableSegment($previous, 'core_assets_previous_bundle_invalid');
        $previousPath = $destinationRoot . DIRECTORY_SEPARATOR . $previous;
        if (!is_file($previousPath . DIRECTORY_SEPARATOR . '.larena-bundle.json')) {
            throw new RuntimeException('core_assets_previous_bundle_missing');
        }
        $this->validateBundleTreeFromManifest($previousPath, $previous);

        $rolledBackFrom = $state['active_bundle'] ?? null;
        $this->writeJson($stateFile, [
            'schema' => 'larena.core_assets.activation_state.v1',
            'active_bundle' => $previous,
            'previous_bundle' => is_string($rolledBackFrom) ? $rolledBackFrom : null,
        ]);

        return ['active_bundle' => $previous, 'rolled_back_from' => $rolledBackFrom];
    }

    /**
     * @param array{repository:string,commit:string,tree:string,mount:string,sha256:string} $source
     * @return array<string, string|int>
     */
    private function extractVerifiedSource(array $source, string $stage): array
    {
        $repository = realpath($source['repository']);
        if ($repository === false || !is_dir($repository . DIRECTORY_SEPARATOR . '.git')) {
            throw new InvalidArgumentException('core_assets_source_repository_invalid');
        }
        $commit = $this->fullSha($source['commit']);
        $tree = $this->stablePath($source['tree'], 'core_assets_source_tree_invalid');
        $mount = $this->stableSegment($source['mount'], 'core_assets_source_mount_invalid');
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

        $mountPath = $stage . DIRECTORY_SEPARATOR . $mount;
        $this->ensureDirectory($mountPath);
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

    private function extractArchive(string $tar, string $destination): void
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
    private function verifyExtractedSource(
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
    private function scanExtractedTree(string $mountPath, string $objectFormat): array
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
        if ($path === '' || $path[0] === '/' || str_contains($path, "\0")) {
            return false;
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
    }

    private function gitBlobHash(string $contents, string $algorithm): string
    {
        return hash($algorithm, 'blob ' . strlen($contents) . "\0" . $contents);
    }

    private function gitFileHash(string $path, string $algorithm): string
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

    private function runGitArchive(string $repository, string $commit, string $tree, string $target): void
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

    /**
     * @param list<array{repository:string,commit:string,tree:string,mount:string,sha256:string}> $sources
     * @return list<array<string, string|int>>
     */
    private function validateExistingBundle(string $bundlePath, string $bundleId, array $sources): array
    {
        $manifest = $this->readJson($bundlePath . DIRECTORY_SEPARATOR . '.larena-bundle.json');
        if (($manifest['schema'] ?? null) !== self::BUNDLE_SCHEMA
            || ($manifest['publication_profile'] ?? null) !== self::PUBLICATION_PROFILE
            || ($manifest['bundle_id'] ?? null) !== $bundleId
            || !is_array($manifest['sources'] ?? null)
        ) {
            throw new RuntimeException('core_assets_existing_bundle_manifest_invalid');
        }
        $receipts = array_values($manifest['sources']);
        if (count($receipts) !== count($sources)) {
            throw new RuntimeException('core_assets_existing_bundle_source_count_mismatch');
        }
        foreach ($sources as $index => $source) {
            $receipt = $receipts[$index] ?? [];
            foreach (['commit', 'tree', 'mount', 'sha256'] as $field) {
                if (($receipt[$field] ?? null) !== ($field === 'sha256' ? strtolower($source[$field]) : $source[$field])) {
                    throw new RuntimeException('core_assets_existing_bundle_source_mismatch:' . $field);
                }
            }
            $verification = $this->scanExtractedTree(
                $bundlePath . DIRECTORY_SEPARATOR . $this->stableSegment($source['mount'], 'core_assets_source_mount_invalid'),
                is_string($receipt['object_format'] ?? null) ? $receipt['object_format'] : '',
            );
            $verification['file_count'] = count($verification['entries']);
            foreach (['file_count', 'tree_fingerprint_sha256'] as $field) {
                if (($receipt[$field] ?? null) !== $verification[$field]) {
                    throw new RuntimeException('core_assets_existing_bundle_verification_mismatch:' . $field);
                }
            }
        }
        /** @var list<array<string, string|int>> $receipts */
        return $receipts;
    }

    private function validateBundleTreeFromManifest(string $bundlePath, string $bundleId): void
    {
        $manifest = $this->readJson($bundlePath . DIRECTORY_SEPARATOR . '.larena-bundle.json');
        if (($manifest['schema'] ?? null) !== self::BUNDLE_SCHEMA
            || ($manifest['publication_profile'] ?? null) !== self::PUBLICATION_PROFILE
            || ($manifest['bundle_id'] ?? null) !== $bundleId
            || !is_array($manifest['sources'] ?? null)
            || $manifest['sources'] === []
        ) {
            throw new RuntimeException('core_assets_existing_bundle_manifest_invalid');
        }
        foreach ($manifest['sources'] as $receipt) {
            if (!is_array($receipt)
                || !is_string($receipt['mount'] ?? null)
                || !is_int($receipt['file_count'] ?? null)
                || !is_string($receipt['tree_fingerprint_sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/', $receipt['tree_fingerprint_sha256']) !== 1
            ) {
                throw new RuntimeException('core_assets_existing_bundle_receipt_invalid');
            }
            $verification = $this->scanExtractedTree(
                $bundlePath . DIRECTORY_SEPARATOR . $this->stableSegment($receipt['mount'], 'core_assets_source_mount_invalid'),
                is_string($receipt['object_format'] ?? null) ? $receipt['object_format'] : '',
            );
            if ($receipt['file_count'] !== count($verification['entries'])
                || !hash_equals($receipt['tree_fingerprint_sha256'], $verification['tree_fingerprint_sha256'])
            ) {
                throw new RuntimeException('core_assets_existing_bundle_tree_mismatch:' . $receipt['mount']);
            }
        }
    }

    /** @return array<string, mixed> */
    private function readState(string $stateFile): array
    {
        return is_file($stateFile) ? $this->readJson($stateFile) : [];
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('core_assets_json_invalid');
        }
        return $decoded;
    }

    /** @param array<string, mixed> $value */
    private function writeJson(string $path, array $value): void
    {
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(4));
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('core_assets_state_write_failed');
        }
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('core_assets_directory_create_failed');
        }
    }

    private function absolutePath(string $path, string $error): string
    {
        $path = rtrim(trim($path), DIRECTORY_SEPARATOR);
        if ($path === '' || $path[0] !== DIRECTORY_SEPARATOR || str_contains($path, "\0")) {
            throw new InvalidArgumentException($error);
        }
        return $path;
    }

    private function fullSha(string $sha): string
    {
        $sha = strtolower(trim($sha));
        if (!preg_match('/^[a-f0-9]{40}$/', $sha)) {
            throw new InvalidArgumentException('core_assets_source_commit_invalid');
        }
        return $sha;
    }

    private function stableSegment(string $value, string $error): string
    {
        $value = trim($value);
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,127}$/', $value)) {
            throw new InvalidArgumentException($error);
        }
        return $value;
    }

    private function stablePath(string $value, string $error): string
    {
        $value = trim($value, '/ ');
        if ($value === '' || str_contains($value, '..') || !preg_match('#^[a-zA-Z0-9._/-]+$#', $value)) {
            throw new InvalidArgumentException($error);
        }
        return $value;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }
}
