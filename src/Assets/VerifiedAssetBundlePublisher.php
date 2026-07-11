<?php

declare(strict_types=1);

namespace Larena\Core\Assets;

use InvalidArgumentException;
use PharData;
use RuntimeException;

/**
 * Publishes complete immutable Git trees after verifying their deterministic
 * archive checksums. Activation state is deliberately filesystem-only.
 */
final class VerifiedAssetBundlePublisher
{
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
                    'schema' => 'larena.core_assets.immutable_bundle.v1',
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
            'schema' => 'larena.core_assets.publication_receipt.v1',
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
        if (!is_file($destinationRoot . DIRECTORY_SEPARATOR . $previous . DIRECTORY_SEPARATOR . '.larena-bundle.json')) {
            throw new RuntimeException('core_assets_previous_bundle_missing');
        }

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
        $archive = new PharData($tar);
        $fileCount = 0;
        foreach (new \RecursiveIteratorIterator($archive) as $entry) {
            $relative = str_replace('phar://' . $tar . '/', '', $entry->getPathname());
            if ($relative !== '' && ($relative[0] === '/' || str_contains($relative, '../'))) {
                throw new RuntimeException('core_assets_archive_path_unsafe');
            }
            if ($entry->isFile()) {
                ++$fileCount;
            }
        }
        $archive->extractTo($mountPath, null, false);
        unset($archive);
        @unlink($tar);

        return [
            'commit' => $commit,
            'tree' => $tree,
            'mount' => $mount,
            'sha256' => $actual,
            'file_count' => $fileCount,
        ];
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
        if (($manifest['bundle_id'] ?? null) !== $bundleId || !is_array($manifest['sources'] ?? null)) {
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
        }
        /** @var list<array<string, string|int>> $receipts */
        return $receipts;
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
