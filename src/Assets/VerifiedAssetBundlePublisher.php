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

        return $this->activateBundle(
            $bundlePath,
            $stateFile,
            $bundleId,
            self::PUBLICATION_PROFILE,
            $receipts,
        );
    }

    /**
     * Build a portable artifact directory from exact Git sources.
     *
     * @param array{
     *   schema:string,
     *   publication_profile:string,
     *   bundle_id:string,
     *   sources:list<array{repository:string,commit:string,tree:string,mount:string,archive_sha256:string,files:int}>
     * } $expectedContract
     * @return array<string, mixed>
     */
    public function buildArtifactDirectory(array $expectedContract, string $artifactDirectory): array
    {
        $contract = $this->normalizeExpectedContract($expectedContract, true);
        $artifactDirectory = $this->absolutePath($artifactDirectory, 'core_assets_artifact_directory_invalid');
        $parent = dirname($artifactDirectory);
        $this->ensureDirectory($parent);

        if (file_exists($artifactDirectory) && !is_dir($artifactDirectory)) {
            throw new RuntimeException('core_assets_artifact_directory_not_directory');
        }
        if (is_dir($artifactDirectory)) {
            $verificationStage = $parent . DIRECTORY_SEPARATOR . '.' . basename($artifactDirectory)
                . '.verify-' . bin2hex(random_bytes(6));
            $this->ensureDirectory($verificationStage);
            try {
                $sources = $this->extractArtifactSources($contract, $artifactDirectory, $verificationStage);
            } finally {
                $this->removeTree($verificationStage);
            }

            return $this->artifactBuildReceipt($contract, $artifactDirectory, true);
        }

        $stage = $parent . DIRECTORY_SEPARATOR . '.' . basename($artifactDirectory)
            . '.stage-' . bin2hex(random_bytes(6));
        $this->ensureDirectory($stage . DIRECTORY_SEPARATOR . 'sources');

        try {
            $manifestSources = [];
            foreach ($contract['sources'] as $source) {
                $repository = $this->repositoryPath((string) $source['repository']);
                $mount = $source['mount'];
                $tar = $stage . DIRECTORY_SEPARATOR . '.' . $mount . '.tar';
                $verificationRoot = $stage . DIRECTORY_SEPARATOR . '.verify-' . $mount;
                $archiveRelative = 'sources/' . $mount . '.tar.gz';
                $archive = $stage . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $archiveRelative);

                $this->runGitArchive($repository, $source['commit'], $source['tree'], $tar);
                $rawHash = hash_file('sha256', $tar);
                if (!is_string($rawHash) || !hash_equals($source['sha256'], $rawHash)) {
                    throw new RuntimeException('core_assets_source_checksum_mismatch:' . $mount);
                }
                $this->assertArchiveSafe($tar);
                $this->ensureDirectory($verificationRoot);
                $this->extractArchive($tar, $verificationRoot);
                $verification = $this->verifyExtractedSource(
                    $repository,
                    $source['commit'],
                    $source['tree'],
                    $verificationRoot,
                );
                if ($verification['file_count'] !== $source['files']) {
                    throw new RuntimeException('core_assets_source_file_count_mismatch:' . $mount);
                }

                $this->gzipArchive($tar, $archive);
                $compressedHash = hash_file('sha256', $archive);
                if (!is_string($compressedHash)) {
                    throw new RuntimeException('core_assets_compressed_archive_checksum_failed:' . $mount);
                }

                $manifestSources[] = [
                    'commit' => $source['commit'],
                    'tree' => $source['tree'],
                    'mount' => $mount,
                    'archive_sha256' => $rawHash,
                    'files' => $source['files'],
                    'archive' => $archiveRelative,
                    'compressed_sha256' => $compressedHash,
                    'tree_fingerprint_sha256' => $verification['tree_fingerprint_sha256'],
                    'object_format' => $verification['object_format'],
                    'file_count' => $verification['file_count'],
                ];

                @unlink($tar);
                $this->removeTree($verificationRoot);
            }

            $this->writeJson($stage . DIRECTORY_SEPARATOR . 'manifest.json', [
                'schema' => $contract['schema'],
                'publication_profile' => $contract['publication_profile'],
                'bundle_id' => $contract['bundle_id'],
                'sources' => $manifestSources,
            ]);
            if (!rename($stage, $artifactDirectory)) {
                throw new RuntimeException('core_assets_artifact_activate_rename_failed');
            }
        } catch (\Throwable $exception) {
            $this->removeTree($stage);
            throw $exception;
        }

        return $this->artifactBuildReceipt($contract, $artifactDirectory, false);
    }

    /**
     * Publish a bundle from a verified portable artifact directory.
     *
     * @param array{
     *   schema:string,
     *   publication_profile:string,
     *   bundle_id:string,
     *   sources:list<array{commit:string,tree:string,mount:string,archive_sha256:string,files:int}>
     * } $expectedContract
     * @return array<string, mixed>
     */
    public function publishArtifactDirectory(
        array $expectedContract,
        string $artifactDirectory,
        string $destinationRoot,
        string $stateFile,
    ): array {
        $contract = $this->normalizeExpectedContract($expectedContract, false);
        $artifactDirectory = $this->absolutePath($artifactDirectory, 'core_assets_artifact_directory_invalid');
        if (!is_dir($artifactDirectory)) {
            throw new InvalidArgumentException('core_assets_artifact_directory_missing');
        }
        $destinationRoot = $this->absolutePath($destinationRoot, 'core_assets_destination_root_invalid');
        $stateFile = $this->absolutePath($stateFile, 'core_assets_state_file_invalid');
        $bundleId = $contract['bundle_id'];
        $bundlePath = $destinationRoot . DIRECTORY_SEPARATOR . $bundleId;

        $this->ensureDirectory($destinationRoot);
        $this->ensureDirectory(dirname($stateFile));
        $stage = $destinationRoot . DIRECTORY_SEPARATOR . '.' . $bundleId . '.stage-' . bin2hex(random_bytes(6));
        $this->ensureDirectory($stage);

        try {
            $artifactReceipts = $this->extractArtifactSources($contract, $artifactDirectory, $stage);
            $this->writeJson($stage . DIRECTORY_SEPARATOR . '.larena-bundle.json', [
                'schema' => self::BUNDLE_SCHEMA,
                'artifact_contract_schema' => $contract['schema'],
                'publication_profile' => $contract['publication_profile'],
                'bundle_id' => $bundleId,
                'sources' => $artifactReceipts,
            ]);

            if (!is_dir($bundlePath)) {
                if (!rename($stage, $bundlePath)) {
                    throw new RuntimeException('core_assets_bundle_activate_rename_failed');
                }
            } else {
                $existingReceipts = $this->validateExistingArtifactBundle(
                    $bundlePath,
                    $contract,
                    $artifactReceipts,
                );
                $this->removeTree($stage);
                $artifactReceipts = $existingReceipts;
            }
        } catch (\Throwable $exception) {
            $this->removeTree($stage);
            throw $exception;
        }

        return $this->activateBundle(
            $bundlePath,
            $stateFile,
            $bundleId,
            $contract['publication_profile'],
            $artifactReceipts,
        );
    }

    /**
     * @param array<string, mixed> $expected
     * @return array{
     *   schema:string,
     *   publication_profile:string,
     *   bundle_id:string,
     *   sources:list<array{repository?:string,commit:string,tree:string,mount:string,sha256:string,files:int}>
     * }
     */
    private function normalizeExpectedContract(array $expected, bool $requireRepository): array
    {
        $schema = $this->aliasedString(
            $expected,
            'schema',
            'artifact_schema',
            'core_assets_artifact_schema_invalid',
            'core_assets_artifact_schema_conflict',
        );
        if ($schema === '' || preg_match('/^[a-z][a-z0-9._-]{2,191}$/', $schema) !== 1) {
            throw new InvalidArgumentException('core_assets_artifact_schema_invalid');
        }
        $profile = $this->stableSegment(
            (string) ($expected['publication_profile'] ?? ''),
            'core_assets_publication_profile_invalid',
        );
        $bundleId = $this->stableSegment(
            (string) ($expected['bundle_id'] ?? ''),
            'core_assets_bundle_id_invalid',
        );
        if (!is_array($expected['sources'] ?? null) || $expected['sources'] === []) {
            throw new InvalidArgumentException('core_assets_bundle_sources_required');
        }

        $sources = [];
        $mounts = [];
        foreach (array_values($expected['sources']) as $source) {
            if (!is_array($source)) {
                throw new InvalidArgumentException('core_assets_source_contract_invalid');
            }
            $commit = $this->fullSha((string) ($source['commit'] ?? ''));
            $tree = $this->stablePath((string) ($source['tree'] ?? ''), 'core_assets_source_tree_invalid');
            $mount = $this->stableSegment((string) ($source['mount'] ?? ''), 'core_assets_source_mount_invalid');
            $sha256 = $this->sourceArchiveChecksum($source);
            $files = $source['files'] ?? null;
            if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
                throw new InvalidArgumentException('core_assets_source_checksum_invalid');
            }
            if (!is_int($files) || $files < 1) {
                throw new InvalidArgumentException('core_assets_source_file_count_invalid');
            }
            if (isset($mounts[$mount])) {
                throw new InvalidArgumentException('core_assets_source_mount_duplicate:' . $mount);
            }
            $mounts[$mount] = true;
            $normalized = compact('commit', 'tree', 'mount', 'sha256', 'files');
            if ($requireRepository) {
                $repository = (string) ($source['repository'] ?? '');
                if ($repository === '') {
                    throw new InvalidArgumentException('core_assets_source_repository_required:' . $mount);
                }
                $normalized = ['repository' => $repository, ...$normalized];
            }
            $sources[] = $normalized;
        }

        return [
            'schema' => $schema,
            'publication_profile' => $profile,
            'bundle_id' => $bundleId,
            'sources' => $sources,
        ];
    }

    /** @param array<string, mixed> $values */
    private function aliasedString(
        array $values,
        string $canonicalKey,
        string $legacyKey,
        string $invalidError,
        string $conflictError,
    ): string {
        $canonical = $values[$canonicalKey] ?? null;
        $legacy = $values[$legacyKey] ?? null;
        if (($canonical !== null && !is_string($canonical)) || ($legacy !== null && !is_string($legacy))) {
            throw new InvalidArgumentException($invalidError);
        }
        $canonical = trim((string) $canonical);
        $legacy = trim((string) $legacy);
        if ($canonical !== '' && $legacy !== '' && $canonical !== $legacy) {
            throw new InvalidArgumentException($conflictError);
        }

        return $canonical !== '' ? $canonical : $legacy;
    }

    /** @param array<string, mixed> $source */
    private function sourceArchiveChecksum(array $source): string
    {
        $canonical = $source['archive_sha256'] ?? null;
        $legacy = $source['sha256'] ?? null;
        if (($canonical !== null && !is_string($canonical)) || ($legacy !== null && !is_string($legacy))) {
            throw new InvalidArgumentException('core_assets_source_checksum_invalid');
        }
        $canonical = strtolower(trim((string) $canonical));
        $legacy = strtolower(trim((string) $legacy));
        if (($canonical !== '' && preg_match('/^[a-f0-9]{64}$/', $canonical) !== 1)
            || ($legacy !== '' && preg_match('/^[a-f0-9]{64}$/', $legacy) !== 1)
        ) {
            throw new InvalidArgumentException('core_assets_source_checksum_invalid');
        }
        if ($canonical !== '' && $legacy !== '' && !hash_equals($canonical, $legacy)) {
            throw new InvalidArgumentException('core_assets_source_checksum_conflict');
        }
        $checksum = $canonical !== '' ? $canonical : $legacy;
        if ($checksum === '') {
            throw new InvalidArgumentException('core_assets_source_checksum_invalid');
        }

        return $checksum;
    }

    /**
     * @param array{schema:string,publication_profile:string,bundle_id:string,sources:list<array<string,mixed>>} $contract
     * @return list<array<string, string|int>>
     */
    private function extractArtifactSources(array $contract, string $artifactDirectory, string $stage): array
    {
        $this->assertArtifactDirectoryShape($contract, $artifactDirectory);
        $artifactRoot = realpath($artifactDirectory);
        if ($artifactRoot === false || !is_dir($artifactRoot) || is_link($artifactDirectory)) {
            throw new RuntimeException('core_assets_artifact_directory_invalid');
        }
        $manifest = $this->readJson($artifactRoot . DIRECTORY_SEPARATOR . 'manifest.json');
        if (($manifest['schema'] ?? null) !== $contract['schema']
            || ($manifest['publication_profile'] ?? null) !== $contract['publication_profile']
            || ($manifest['bundle_id'] ?? null) !== $contract['bundle_id']
            || !is_array($manifest['sources'] ?? null)
            || count($manifest['sources']) !== count($contract['sources'])
        ) {
            throw new RuntimeException('core_assets_artifact_manifest_invalid');
        }

        $manifestSources = array_values($manifest['sources']);
        $receipts = [];
        foreach ($contract['sources'] as $index => $expected) {
            $source = $manifestSources[$index] ?? null;
            if (!is_array($source)) {
                throw new RuntimeException('core_assets_artifact_source_invalid:' . $expected['mount']);
            }
            foreach (['commit', 'tree', 'mount', 'files'] as $field) {
                if (($source[$field] ?? null) !== $expected[$field]) {
                    throw new RuntimeException('core_assets_artifact_source_mismatch:' . $expected['mount'] . ':' . $field);
                }
            }
            try {
                $artifactArchiveChecksum = $this->sourceArchiveChecksum($source);
            } catch (InvalidArgumentException $exception) {
                throw new RuntimeException(
                    'core_assets_artifact_source_receipt_invalid:' . $expected['mount'] . ':archive_sha256',
                    0,
                    $exception,
                );
            }
            if (!hash_equals($expected['sha256'], $artifactArchiveChecksum)) {
                throw new RuntimeException('core_assets_artifact_source_mismatch:' . $expected['mount'] . ':archive_sha256');
            }
            $archiveRelative = 'sources/' . $expected['mount'] . '.tar.gz';
            if (($source['archive'] ?? null) !== $archiveRelative
                || !is_string($source['compressed_sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/', $source['compressed_sha256']) !== 1
                || !is_string($source['tree_fingerprint_sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/', $source['tree_fingerprint_sha256']) !== 1
                || !in_array($source['object_format'] ?? null, ['sha1', 'sha256'], true)
                || ($source['file_count'] ?? null) !== $expected['files']
            ) {
                throw new RuntimeException('core_assets_artifact_source_receipt_invalid:' . $expected['mount']);
            }

            $archive = $artifactRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $archiveRelative);
            $archivePath = realpath($archive);
            if ($archivePath === false
                || !is_file($archivePath)
                || is_link($archive)
                || !str_starts_with($archivePath, $artifactRoot . DIRECTORY_SEPARATOR)
            ) {
                throw new RuntimeException('core_assets_artifact_archive_missing:' . $expected['mount']);
            }
            $compressedHash = hash_file('sha256', $archivePath);
            if (!is_string($compressedHash) || !hash_equals($source['compressed_sha256'], $compressedHash)) {
                throw new RuntimeException('core_assets_artifact_compressed_checksum_mismatch:' . $expected['mount']);
            }

            $tar = $stage . DIRECTORY_SEPARATOR . '.' . $expected['mount'] . '.tar';
            $this->decompressGzipArchive($archivePath, $tar);
            try {
                $rawHash = hash_file('sha256', $tar);
                if (!is_string($rawHash) || !hash_equals($expected['sha256'], $rawHash)) {
                    throw new RuntimeException('core_assets_artifact_raw_checksum_mismatch:' . $expected['mount']);
                }
                $this->assertArchiveSafe($tar);
                $mountPath = $stage . DIRECTORY_SEPARATOR . $expected['mount'];
                $this->ensureDirectory($mountPath);
                $this->extractArchive($tar, $mountPath);
                $tree = $this->scanExtractedTree($mountPath, (string) $source['object_format']);
                if ($expected['files'] !== count($tree['entries'])
                    || !hash_equals($source['tree_fingerprint_sha256'], $tree['tree_fingerprint_sha256'])
                ) {
                    throw new RuntimeException('core_assets_artifact_tree_mismatch:' . $expected['mount']);
                }
            } finally {
                @unlink($tar);
            }

            $receipts[] = [
                'commit' => $expected['commit'],
                'tree' => $expected['tree'],
                'mount' => $expected['mount'],
                'sha256' => $expected['sha256'],
                'file_count' => $expected['files'],
                'tree_fingerprint_sha256' => $source['tree_fingerprint_sha256'],
                'object_format' => $source['object_format'],
            ];
        }

        return $receipts;
    }

    /**
     * @param array{bundle_id:string,sources:list<array<string,mixed>>} $contract
     */
    private function assertArtifactDirectoryShape(array $contract, string $artifactDirectory): void
    {
        $rootEntries = array_values(array_filter(
            scandir($artifactDirectory) ?: [],
            static fn (string $entry): bool => !in_array($entry, ['.', '..'], true),
        ));
        sort($rootEntries, SORT_STRING);
        if ($rootEntries !== ['manifest.json', 'sources']
            || !is_file($artifactDirectory . DIRECTORY_SEPARATOR . 'manifest.json')
            || is_link($artifactDirectory . DIRECTORY_SEPARATOR . 'manifest.json')
            || !is_dir($artifactDirectory . DIRECTORY_SEPARATOR . 'sources')
            || is_link($artifactDirectory . DIRECTORY_SEPARATOR . 'sources')
        ) {
            throw new RuntimeException('core_assets_artifact_directory_shape_invalid');
        }

        $expectedArchives = array_map(
            static fn (array $source): string => $source['mount'] . '.tar.gz',
            $contract['sources'],
        );
        sort($expectedArchives, SORT_STRING);
        $sourceEntries = array_values(array_filter(
            scandir($artifactDirectory . DIRECTORY_SEPARATOR . 'sources') ?: [],
            static fn (string $entry): bool => !in_array($entry, ['.', '..'], true),
        ));
        sort($sourceEntries, SORT_STRING);
        if ($sourceEntries !== $expectedArchives) {
            throw new RuntimeException('core_assets_artifact_sources_shape_invalid');
        }
    }

    /**
     * @param array{schema:string,publication_profile:string,bundle_id:string,sources:list<array<string,mixed>>} $contract
     * @return list<array<string, string|int>>
     */
    private function validateExistingArtifactBundle(
        string $bundlePath,
        array $contract,
        array $artifactReceipts,
    ): array {
        $manifest = $this->readJson($bundlePath . DIRECTORY_SEPARATOR . '.larena-bundle.json');
        if (($manifest['schema'] ?? null) !== self::BUNDLE_SCHEMA
            || ($manifest['artifact_contract_schema'] ?? null) !== $contract['schema']
            || ($manifest['publication_profile'] ?? null) !== $contract['publication_profile']
            || ($manifest['bundle_id'] ?? null) !== $contract['bundle_id']
            || !is_array($manifest['sources'] ?? null)
            || count($manifest['sources']) !== count($artifactReceipts)
        ) {
            throw new RuntimeException('core_assets_existing_bundle_manifest_invalid');
        }

        $receipts = array_values($manifest['sources']);
        foreach ($artifactReceipts as $index => $expected) {
            $receipt = $receipts[$index] ?? [];
            foreach (['commit', 'tree', 'mount', 'sha256', 'file_count', 'tree_fingerprint_sha256', 'object_format'] as $field) {
                if (($receipt[$field] ?? null) !== $expected[$field]) {
                    throw new RuntimeException('core_assets_existing_bundle_source_mismatch:' . $field);
                }
            }
        }
        $this->validateBundleTreeFromManifest($bundlePath, $contract['bundle_id']);

        /** @var list<array<string, string|int>> $receipts */
        return $receipts;
    }

    /**
     * @param array{schema:string,publication_profile:string,bundle_id:string,sources:list<array<string,mixed>>} $contract
     * @return array<string, mixed>
     */
    private function artifactBuildReceipt(array $contract, string $artifactDirectory, bool $reused): array
    {
        $manifestPath = $artifactDirectory . DIRECTORY_SEPARATOR . 'manifest.json';
        $manifestHash = hash_file('sha256', $manifestPath);
        $manifest = $this->readJson($manifestPath);
        if (!is_string($manifestHash) || !is_array($manifest['sources'] ?? null)) {
            throw new RuntimeException('core_assets_artifact_manifest_checksum_failed');
        }

        return [
            'schema' => 'larena.core_assets.artifact_build_receipt.v1',
            'artifact_contract_schema' => $contract['schema'],
            'publication_profile' => $contract['publication_profile'],
            'bundle_id' => $contract['bundle_id'],
            'artifact_directory' => $artifactDirectory,
            'manifest_path' => $manifestPath,
            'manifest_sha256' => $manifestHash,
            'sources' => array_values($manifest['sources']),
            'reused' => $reused,
        ];
    }

    /** @return array<string, mixed> */
    public function rollback(string $destinationRoot, string $stateFile): array
    {
        $destinationRoot = $this->absolutePath($destinationRoot, 'core_assets_destination_root_invalid');
        $stateFile = $this->absolutePath($stateFile, 'core_assets_state_file_invalid');
        $state = $this->readState($stateFile);
        $previous = $state['previous_bundle'] ?? null;
        $previousManifestHash = $state['previous_bundle_manifest_sha256'] ?? null;

        if (!is_string($previous) || $previous === '') {
            throw new RuntimeException('core_assets_previous_bundle_unavailable');
        }
        if (($state['schema'] ?? null) !== 'larena.core_assets.activation_state.v2'
            || !is_string($previousManifestHash)
            || preg_match('/^[a-f0-9]{64}$/', $previousManifestHash) !== 1
        ) {
            throw new RuntimeException('core_assets_previous_bundle_trust_unavailable');
        }
        $previous = $this->stableSegment($previous, 'core_assets_previous_bundle_invalid');
        $previousPath = $destinationRoot . DIRECTORY_SEPARATOR . $previous;
        $previousManifestPath = $previousPath . DIRECTORY_SEPARATOR . '.larena-bundle.json';
        if (!is_file($previousManifestPath)) {
            throw new RuntimeException('core_assets_previous_bundle_missing');
        }
        $actualManifestHash = hash_file('sha256', $previousManifestPath);
        if (!is_string($actualManifestHash) || !hash_equals($previousManifestHash, $actualManifestHash)) {
            throw new RuntimeException('core_assets_previous_bundle_manifest_checksum_mismatch');
        }
        $this->validateBundleTreeFromManifest($previousPath, $previous);

        $rolledBackFrom = $state['active_bundle'] ?? null;
        $rolledBackFromManifestHash = $state['active_bundle_manifest_sha256'] ?? null;
        $this->writeJson($stateFile, [
            'schema' => 'larena.core_assets.activation_state.v2',
            'active_bundle' => $previous,
            'active_bundle_manifest_sha256' => $previousManifestHash,
            'previous_bundle' => is_string($rolledBackFrom) ? $rolledBackFrom : null,
            'previous_bundle_manifest_sha256' => is_string($rolledBackFromManifestHash)
                ? $rolledBackFromManifestHash
                : null,
        ]);

        return ['active_bundle' => $previous, 'rolled_back_from' => $rolledBackFrom];
    }

    /**
     * @param array{repository:string,commit:string,tree:string,mount:string,sha256:string} $source
     * @return array<string, string|int>
     */
    private function extractVerifiedSource(array $source, string $stage): array
    {
        $repository = $this->repositoryPath($source['repository']);
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
        $this->assertArchiveSafe($tar);

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

    private function repositoryPath(string $path): string
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

    private function gzipArchive(string $tar, string $target): void
    {
        $this->runCommandToFile(
            ['gzip', '-n', '-9', '-c', $tar],
            $target,
            'core_assets_archive_compress_failed',
        );
    }

    private function decompressGzipArchive(string $archive, string $target): void
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

    private function assertArchiveSafe(string $tar): void
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
            $repository = $this->repositoryPath($source['repository']);
            $verification = $this->verifyExtractedSource(
                $repository,
                $this->fullSha($source['commit']),
                $this->stablePath($source['tree'], 'core_assets_source_tree_invalid'),
                $bundlePath . DIRECTORY_SEPARATOR . $this->stableSegment($source['mount'], 'core_assets_source_mount_invalid'),
            );
            foreach (['file_count', 'tree_fingerprint_sha256', 'object_format'] as $field) {
                if (($receipt[$field] ?? null) !== $verification[$field]) {
                    throw new RuntimeException('core_assets_existing_bundle_verification_mismatch:' . $field);
                }
            }
        }
        $this->validateBundleTreeFromManifest($bundlePath, $bundleId);
        /** @var list<array<string, string|int>> $receipts */
        return $receipts;
    }

    private function validateBundleTreeFromManifest(string $bundlePath, string $bundleId): void
    {
        $manifestPath = $bundlePath . DIRECTORY_SEPARATOR . '.larena-bundle.json';
        if (!is_dir($bundlePath)
            || is_link($bundlePath)
            || !is_file($manifestPath)
            || is_link($manifestPath)
        ) {
            throw new RuntimeException('core_assets_existing_bundle_manifest_invalid');
        }
        $manifest = $this->readJson($manifestPath);
        if (($manifest['schema'] ?? null) !== self::BUNDLE_SCHEMA
            || !is_string($manifest['publication_profile'] ?? null)
            || preg_match('/^[a-z0-9][a-z0-9._-]{0,127}$/', $manifest['publication_profile']) !== 1
            || ($manifest['bundle_id'] ?? null) !== $bundleId
            || !is_array($manifest['sources'] ?? null)
            || $manifest['sources'] === []
        ) {
            throw new RuntimeException('core_assets_existing_bundle_manifest_invalid');
        }
        $expectedRootEntries = ['.larena-bundle.json'];
        foreach ($manifest['sources'] as $receipt) {
            if (!is_array($receipt)
                || !is_string($receipt['mount'] ?? null)
                || !is_int($receipt['file_count'] ?? null)
                || !is_string($receipt['tree_fingerprint_sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/', $receipt['tree_fingerprint_sha256']) !== 1
            ) {
                throw new RuntimeException('core_assets_existing_bundle_receipt_invalid');
            }
            $mount = $this->stableSegment($receipt['mount'], 'core_assets_source_mount_invalid');
            $mountPath = $bundlePath . DIRECTORY_SEPARATOR . $mount;
            if (!is_dir($mountPath) || is_link($mountPath)) {
                throw new RuntimeException('core_assets_existing_bundle_mount_invalid:' . $mount);
            }
            $expectedRootEntries[] = $mount;
            $verification = $this->scanExtractedTree(
                $mountPath,
                is_string($receipt['object_format'] ?? null) ? $receipt['object_format'] : '',
            );
            if ($receipt['file_count'] !== count($verification['entries'])
                || !hash_equals($receipt['tree_fingerprint_sha256'], $verification['tree_fingerprint_sha256'])
            ) {
                throw new RuntimeException('core_assets_existing_bundle_tree_mismatch:' . $receipt['mount']);
            }
        }
        sort($expectedRootEntries, SORT_STRING);
        $actualRootEntries = array_values(array_filter(
            scandir($bundlePath) ?: [],
            static fn (string $entry): bool => !in_array($entry, ['.', '..'], true),
        ));
        sort($actualRootEntries, SORT_STRING);
        if ($actualRootEntries !== $expectedRootEntries) {
            throw new RuntimeException('core_assets_existing_bundle_root_shape_invalid');
        }
    }

    /**
     * @param list<array<string, string|int>> $receipts
     * @return array<string, mixed>
     */
    private function activateBundle(
        string $bundlePath,
        string $stateFile,
        string $bundleId,
        string $publicationProfile,
        array $receipts,
    ): array {
        $manifestHash = hash_file('sha256', $bundlePath . DIRECTORY_SEPARATOR . '.larena-bundle.json');
        if (!is_string($manifestHash)) {
            throw new RuntimeException('core_assets_bundle_manifest_checksum_failed');
        }
        $before = $this->readState($stateFile);
        $sameActiveBundle = ($before['active_bundle'] ?? null) === $bundleId;
        $trustedActive = is_string($before['active_bundle'] ?? null)
            && is_string($before['active_bundle_manifest_sha256'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/', $before['active_bundle_manifest_sha256']) === 1;
        $state = [
            'schema' => 'larena.core_assets.activation_state.v2',
            'active_bundle' => $bundleId,
            'active_bundle_manifest_sha256' => $manifestHash,
            'previous_bundle' => $sameActiveBundle
                ? ($before['previous_bundle'] ?? null)
                : ($trustedActive ? $before['active_bundle'] : null),
            'previous_bundle_manifest_sha256' => $sameActiveBundle
                ? ($before['previous_bundle_manifest_sha256'] ?? null)
                : ($trustedActive ? $before['active_bundle_manifest_sha256'] : null),
        ];
        $this->writeJson($stateFile, $state);

        return [
            'schema' => 'larena.core_assets.publication_receipt.v2',
            'publication_profile' => $publicationProfile,
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
