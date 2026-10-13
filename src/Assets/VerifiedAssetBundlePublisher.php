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

    public function __construct(private readonly GitTreeArchive $archives = new GitTreeArchive())
    {
    }

    /**
     * @param list<array{repository:string,commit:string,tree:string,mount:string,sha256:string}> $sources
     * @return array<string, mixed>
     */
    public function publish(array $sources, string $destinationRoot, string $stateFile, string $bundleId): array
    {
        $bundleId = AssetPathRules::stableSegment($bundleId, 'core_assets_bundle_id_invalid');
        if ($sources === []) {
            throw new InvalidArgumentException('core_assets_bundle_sources_required');
        }

        $destinationRoot = AssetPathRules::absolutePath($destinationRoot, 'core_assets_destination_root_invalid');
        $stateFile = AssetPathRules::absolutePath($stateFile, 'core_assets_state_file_invalid');
        $bundlePath = $destinationRoot . DIRECTORY_SEPARATOR . $bundleId;

        AssetPathRules::ensureDirectory($destinationRoot);
        AssetPathRules::ensureDirectory(dirname($stateFile));

        $bundleAction = 'reused';
        if (!is_dir($bundlePath)) {
            $stage = $destinationRoot . DIRECTORY_SEPARATOR . '.' . $bundleId . '.stage-' . bin2hex(random_bytes(6));
            AssetPathRules::ensureDirectory($stage);

            try {
                $receipts = [];
                foreach ($sources as $source) {
                    $receipts[] = $this->archives->extractVerifiedSource($source, $stage);
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
                $bundleAction = 'created';
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
            $bundleAction,
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
        $artifactDirectory = AssetPathRules::absolutePath($artifactDirectory, 'core_assets_artifact_directory_invalid');
        $parent = dirname($artifactDirectory);
        AssetPathRules::ensureDirectory($parent);

        if (file_exists($artifactDirectory) && !is_dir($artifactDirectory)) {
            throw new RuntimeException('core_assets_artifact_directory_not_directory');
        }
        if (is_dir($artifactDirectory)) {
            $verificationStage = $parent . DIRECTORY_SEPARATOR . '.' . basename($artifactDirectory)
                . '.verify-' . bin2hex(random_bytes(6));
            AssetPathRules::ensureDirectory($verificationStage);
            try {
                $sources = $this->extractArtifactSources($contract, $artifactDirectory, $verificationStage);
            } finally {
                $this->removeTree($verificationStage);
            }

            return $this->artifactBuildReceipt($contract, $artifactDirectory, true);
        }

        $stage = $parent . DIRECTORY_SEPARATOR . '.' . basename($artifactDirectory)
            . '.stage-' . bin2hex(random_bytes(6));
        AssetPathRules::ensureDirectory($stage . DIRECTORY_SEPARATOR . 'sources');

        try {
            $manifestSources = [];
            foreach ($contract['sources'] as $source) {
                $repository = $this->archives->repositoryPath((string) $source['repository']);
                $mount = $source['mount'];
                $tar = $stage . DIRECTORY_SEPARATOR . '.' . $mount . '.tar';
                $verificationRoot = $stage . DIRECTORY_SEPARATOR . '.verify-' . $mount;
                $archiveRelative = 'sources/' . $mount . '.tar.gz';
                $archive = $stage . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $archiveRelative);

                $this->archives->runGitArchive($repository, $source['commit'], $source['tree'], $tar);
                $rawHash = hash_file('sha256', $tar);
                if (!is_string($rawHash) || !hash_equals($source['sha256'], $rawHash)) {
                    throw new RuntimeException('core_assets_source_checksum_mismatch:' . $mount);
                }
                $this->archives->assertArchiveSafe($tar);
                AssetPathRules::ensureDirectory($verificationRoot);
                $this->archives->extractArchive($tar, $verificationRoot);
                $verification = $this->archives->verifyExtractedSource(
                    $repository,
                    $source['commit'],
                    $source['tree'],
                    $verificationRoot,
                );
                if ($verification['file_count'] !== $source['files']) {
                    throw new RuntimeException('core_assets_source_file_count_mismatch:' . $mount);
                }

                $this->archives->gzipArchive($tar, $archive);
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
        return $this->publishArtifactDirectoryWithPolicy(
            $expectedContract,
            $artifactDirectory,
            $destinationRoot,
            $stateFile,
            false,
        );
    }

    /**
     * Repair physical damage in an existing exact-identity artifact bundle.
     *
     * This is deliberately separate from publishArtifactDirectory(): ordinary
     * publication must never replace an invalid immutable bundle implicitly.
     *
     * @param array{
     *   schema:string,
     *   publication_profile:string,
     *   bundle_id:string,
     *   sources:list<array{commit:string,tree:string,mount:string,archive_sha256:string,files:int}>
     * } $expectedContract
     * @return array<string, mixed>
     */
    public function repairArtifactDirectory(
        array $expectedContract,
        string $artifactDirectory,
        string $destinationRoot,
        string $stateFile,
    ): array {
        return $this->publishArtifactDirectoryWithPolicy(
            $expectedContract,
            $artifactDirectory,
            $destinationRoot,
            $stateFile,
            true,
        );
    }

    /**
     * @param array<string, mixed> $expectedContract
     * @return array<string, mixed>
     */
    private function publishArtifactDirectoryWithPolicy(
        array $expectedContract,
        string $artifactDirectory,
        string $destinationRoot,
        string $stateFile,
        bool $repairPhysicalDamage,
    ): array {
        $contract = $this->normalizeExpectedContract($expectedContract, false);
        $artifactDirectory = AssetPathRules::absolutePath($artifactDirectory, 'core_assets_artifact_directory_invalid');
        if (!is_dir($artifactDirectory)) {
            throw new InvalidArgumentException('core_assets_artifact_directory_missing');
        }
        $destinationRoot = AssetPathRules::absolutePath($destinationRoot, 'core_assets_destination_root_invalid');
        $stateFile = AssetPathRules::absolutePath($stateFile, 'core_assets_state_file_invalid');
        $bundleId = $contract['bundle_id'];
        $bundlePath = $destinationRoot . DIRECTORY_SEPARATOR . $bundleId;

        AssetPathRules::ensureDirectory($destinationRoot);
        AssetPathRules::ensureDirectory(dirname($stateFile));
        $stage = $destinationRoot . DIRECTORY_SEPARATOR . '.' . $bundleId . '.stage-' . bin2hex(random_bytes(6));
        AssetPathRules::ensureDirectory($stage);

        try {
            $artifactReceipts = $this->extractArtifactSources($contract, $artifactDirectory, $stage);
            $this->writeJson($stage . DIRECTORY_SEPARATOR . '.larena-bundle.json', [
                'schema' => self::BUNDLE_SCHEMA,
                'artifact_contract_schema' => $contract['schema'],
                'publication_profile' => $contract['publication_profile'],
                'bundle_id' => $bundleId,
                'sources' => $artifactReceipts,
            ]);
            $artifactReceipts = $this->validateExistingArtifactBundle(
                $stage,
                $contract,
                $artifactReceipts,
            );

            if (!is_dir($bundlePath)) {
                if (file_exists($bundlePath) || is_link($bundlePath)) {
                    throw new RuntimeException('core_assets_existing_bundle_manifest_invalid');
                }
                if (!rename($stage, $bundlePath)) {
                    throw new RuntimeException('core_assets_bundle_activate_rename_failed');
                }
            } else {
                $existingReceipts = $this->validateExistingArtifactBundleIdentity(
                    $bundlePath,
                    $contract,
                    $artifactReceipts,
                );
                try {
                    $this->validateBundleTreeFromManifest($bundlePath, $bundleId);
                    $this->removeTree($stage);
                    $artifactReceipts = $existingReceipts;
                    $bundleAction = 'reused';
                } catch (\Throwable $physicalFailure) {
                    if (!$repairPhysicalDamage || !$this->isRepairablePhysicalBundleFailure($physicalFailure)) {
                        throw $physicalFailure;
                    }

                    return $this->repairExistingArtifactBundle(
                        $bundlePath,
                        $stage,
                        $stateFile,
                        $contract,
                        $artifactReceipts,
                        $physicalFailure,
                    );
                }
            }
        } catch (\Throwable $exception) {
            $this->removeTree($stage);
            throw $exception;
        }

        $bundleAction ??= 'created';

        return $this->activateBundle(
            $bundlePath,
            $stateFile,
            $bundleId,
            $contract['publication_profile'],
            $artifactReceipts,
            $bundleAction,
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
        $schema = AssetContractFields::aliasedString(
            $expected,
            'schema',
            'artifact_schema',
            'core_assets_artifact_schema_invalid',
            'core_assets_artifact_schema_conflict',
        );
        if ($schema === '' || preg_match('/^[a-z][a-z0-9._-]{2,191}$/', $schema) !== 1) {
            throw new InvalidArgumentException('core_assets_artifact_schema_invalid');
        }
        $profile = AssetPathRules::stableSegment(
            (string) ($expected['publication_profile'] ?? ''),
            'core_assets_publication_profile_invalid',
        );
        $bundleId = AssetPathRules::stableSegment(
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
            $commit = AssetPathRules::fullSha((string) ($source['commit'] ?? ''));
            $tree = AssetPathRules::stablePath((string) ($source['tree'] ?? ''), 'core_assets_source_tree_invalid');
            $mount = AssetPathRules::stableSegment((string) ($source['mount'] ?? ''), 'core_assets_source_mount_invalid');
            $sha256 = AssetContractFields::sourceArchiveChecksum($source);
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


    /** @param array<string, mixed> $source */

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
        $manifest = AssetContractFields::readJson($artifactRoot . DIRECTORY_SEPARATOR . 'manifest.json');
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
                $artifactArchiveChecksum = AssetContractFields::sourceArchiveChecksum($source);
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
            $this->archives->decompressGzipArchive($archivePath, $tar);
            try {
                $rawHash = hash_file('sha256', $tar);
                if (!is_string($rawHash) || !hash_equals($expected['sha256'], $rawHash)) {
                    throw new RuntimeException('core_assets_artifact_raw_checksum_mismatch:' . $expected['mount']);
                }
                $this->archives->assertArchiveSafe($tar);
                $mountPath = $stage . DIRECTORY_SEPARATOR . $expected['mount'];
                AssetPathRules::ensureDirectory($mountPath);
                $this->archives->extractArchive($tar, $mountPath);
                $tree = $this->archives->scanExtractedTree($mountPath, (string) $source['object_format']);
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
        $receipts = $this->validateExistingArtifactBundleIdentity(
            $bundlePath,
            $contract,
            $artifactReceipts,
        );
        $this->validateBundleTreeFromManifest($bundlePath, $contract['bundle_id']);

        return $receipts;
    }

    /**
     * @param array{schema:string,publication_profile:string,bundle_id:string,sources:list<array<string,mixed>>} $contract
     * @param list<array<string, string|int>> $artifactReceipts
     * @return list<array<string, string|int>>
     */
    private function validateExistingArtifactBundleIdentity(
        string $bundlePath,
        array $contract,
        array $artifactReceipts,
    ): array {
        $manifestPath = $bundlePath . DIRECTORY_SEPARATOR . '.larena-bundle.json';
        if (!is_dir($bundlePath)
            || is_link($bundlePath)
            || !is_file($manifestPath)
            || is_link($manifestPath)
        ) {
            throw new RuntimeException('core_assets_existing_bundle_manifest_invalid');
        }
        $manifest = AssetContractFields::readJson($manifestPath);
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

        /** @var list<array<string, string|int>> $receipts */
        return $receipts;
    }

    private function isRepairablePhysicalBundleFailure(\Throwable $exception): bool
    {
        foreach ([
            'core_assets_existing_bundle_tree_mismatch:',
            'core_assets_existing_bundle_mount_invalid:',
            'core_assets_existing_bundle_root_shape_invalid',
        ] as $prefix) {
            if (str_starts_with($exception->getMessage(), $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{schema:string,publication_profile:string,bundle_id:string,sources:list<array<string,mixed>>} $contract
     * @param list<array<string, string|int>> $artifactReceipts
     * @return array<string, mixed>
     */
    private function repairExistingArtifactBundle(
        string $bundlePath,
        string $stage,
        string $stateFile,
        array $contract,
        array $artifactReceipts,
        \Throwable $physicalFailure,
    ): array {
        $destinationRoot = dirname($bundlePath);
        $bundleId = $contract['bundle_id'];
        $backup = $destinationRoot . DIRECTORY_SEPARATOR . '.' . $bundleId
            . '.repair-backup-' . bin2hex(random_bytes(6));
        $quarantine = $destinationRoot . DIRECTORY_SEPARATOR . '.' . $bundleId
            . '.repair-failed-' . bin2hex(random_bytes(6));
        $stateSnapshot = $this->snapshotStateFile($stateFile);

        if (!rename($bundlePath, $backup)) {
            throw new RuntimeException('core_assets_bundle_repair_backup_rename_failed');
        }

        try {
            if (!rename($stage, $bundlePath)) {
                throw new RuntimeException('core_assets_bundle_repair_activate_rename_failed');
            }

            $artifactReceipts = $this->validateExistingArtifactBundle(
                $bundlePath,
                $contract,
                $artifactReceipts,
            );
            $receipt = $this->activateBundle(
                $bundlePath,
                $stateFile,
                $bundleId,
                $contract['publication_profile'],
                $artifactReceipts,
                'repaired',
            );
        } catch (\Throwable $exception) {
            try {
                $this->restoreReplacedArtifactBundle(
                    $bundlePath,
                    $backup,
                    $quarantine,
                    $stateFile,
                    $stateSnapshot,
                );
            } catch (\Throwable $restoreFailure) {
                throw new RuntimeException(
                    'core_assets_bundle_repair_restore_failed:' . $restoreFailure->getMessage(),
                    0,
                    $exception,
                );
            }

            throw $exception;
        }

        $cleanupComplete = $this->removeTreeSafely($backup);
        $receipt['repair_reason'] = $physicalFailure->getMessage();
        $receipt['repair_cleanup'] = $cleanupComplete ? 'complete' : 'pending';
        if (!$cleanupComplete) {
            $receipt['repair_backup_path'] = $backup;
        }

        return $receipt;
    }

    /**
     * @return array{exists:bool,contents:?string,mode:?int}
     */
    private function snapshotStateFile(string $stateFile): array
    {
        if (is_link($stateFile)) {
            throw new RuntimeException('core_assets_state_file_untrusted_symlink');
        }
        if (!file_exists($stateFile)) {
            return ['exists' => false, 'contents' => null, 'mode' => null];
        }
        if (!is_file($stateFile)) {
            throw new RuntimeException('core_assets_state_file_invalid');
        }
        $contents = file_get_contents($stateFile);
        $permissions = fileperms($stateFile);
        if (!is_string($contents) || !is_int($permissions)) {
            throw new RuntimeException('core_assets_state_snapshot_failed');
        }

        return [
            'exists' => true,
            'contents' => $contents,
            'mode' => $permissions & 0777,
        ];
    }

    /**
     * @param array{exists:bool,contents:?string,mode:?int} $stateSnapshot
     */
    private function restoreReplacedArtifactBundle(
        string $bundlePath,
        string $backup,
        string $quarantine,
        string $stateFile,
        array $stateSnapshot,
    ): void {
        $replacementQuarantined = false;
        if (is_dir($bundlePath) && !is_link($bundlePath)) {
            if (!rename($bundlePath, $quarantine)) {
                throw new RuntimeException('core_assets_bundle_repair_quarantine_rename_failed');
            }
            $replacementQuarantined = true;
        } elseif (file_exists($bundlePath) || is_link($bundlePath)) {
            throw new RuntimeException('core_assets_bundle_repair_replacement_unsafe');
        }

        if (!is_dir($backup) || is_link($backup) || !rename($backup, $bundlePath)) {
            if ($replacementQuarantined && @lstat($bundlePath) === false) {
                @rename($quarantine, $bundlePath);
            }
            throw new RuntimeException('core_assets_bundle_repair_backup_restore_failed');
        }

        try {
            $this->restoreStateFile($stateFile, $stateSnapshot);
        } catch (\Throwable $stateRestoreFailure) {
            if ($replacementQuarantined) {
                $this->removeTreeSafely($quarantine);
            }
            throw $stateRestoreFailure;
        }
        if ($replacementQuarantined && !$this->removeTreeSafely($quarantine)) {
            throw new RuntimeException('core_assets_bundle_repair_quarantine_cleanup_failed:' . $quarantine);
        }
    }

    /**
     * @param array{exists:bool,contents:?string,mode:?int} $stateSnapshot
     */
    private function restoreStateFile(string $stateFile, array $stateSnapshot): void
    {
        if (!$stateSnapshot['exists']) {
            if (is_link($stateFile) || is_file($stateFile)) {
                if (!unlink($stateFile)) {
                    throw new RuntimeException('core_assets_state_restore_remove_failed');
                }
            } elseif (file_exists($stateFile)) {
                throw new RuntimeException('core_assets_state_restore_target_invalid');
            }

            return;
        }

        if (!is_string($stateSnapshot['contents']) || !is_int($stateSnapshot['mode'])) {
            throw new RuntimeException('core_assets_state_restore_snapshot_invalid');
        }
        $temporary = $stateFile . '.restore-' . bin2hex(random_bytes(6));
        if (file_put_contents($temporary, $stateSnapshot['contents'], LOCK_EX) === false
            || !chmod($temporary, $stateSnapshot['mode'])
            || !rename($temporary, $stateFile)
        ) {
            @unlink($temporary);
            throw new RuntimeException('core_assets_state_restore_failed');
        }
    }

    /**
     * @param array{schema:string,publication_profile:string,bundle_id:string,sources:list<array<string,mixed>>} $contract
     * @return array<string, mixed>
     */
    private function artifactBuildReceipt(array $contract, string $artifactDirectory, bool $reused): array
    {
        $manifestPath = $artifactDirectory . DIRECTORY_SEPARATOR . 'manifest.json';
        $manifestHash = hash_file('sha256', $manifestPath);
        $manifest = AssetContractFields::readJson($manifestPath);
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
        $destinationRoot = AssetPathRules::absolutePath($destinationRoot, 'core_assets_destination_root_invalid');
        $stateFile = AssetPathRules::absolutePath($stateFile, 'core_assets_state_file_invalid');
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
        $previous = AssetPathRules::stableSegment($previous, 'core_assets_previous_bundle_invalid');
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
     * @param list<array{repository:string,commit:string,tree:string,mount:string,sha256:string}> $sources
     * @return list<array<string, string|int>>
     */
    private function validateExistingBundle(string $bundlePath, string $bundleId, array $sources): array
    {
        $manifest = AssetContractFields::readJson($bundlePath . DIRECTORY_SEPARATOR . '.larena-bundle.json');
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
            $repository = $this->archives->repositoryPath($source['repository']);
            $verification = $this->archives->verifyExtractedSource(
                $repository,
                AssetPathRules::fullSha($source['commit']),
                AssetPathRules::stablePath($source['tree'], 'core_assets_source_tree_invalid'),
                $bundlePath . DIRECTORY_SEPARATOR . AssetPathRules::stableSegment($source['mount'], 'core_assets_source_mount_invalid'),
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
        $manifest = AssetContractFields::readJson($manifestPath);
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
            $mount = AssetPathRules::stableSegment($receipt['mount'], 'core_assets_source_mount_invalid');
            $mountPath = $bundlePath . DIRECTORY_SEPARATOR . $mount;
            if (!is_dir($mountPath) || is_link($mountPath)) {
                throw new RuntimeException('core_assets_existing_bundle_mount_invalid:' . $mount);
            }
            $expectedRootEntries[] = $mount;
            $verification = $this->archives->scanExtractedTree(
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
        string $bundleAction,
    ): array {
        if (!in_array($bundleAction, ['created', 'reused', 'repaired'], true)) {
            throw new InvalidArgumentException('core_assets_bundle_action_invalid');
        }
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
            'bundle_action' => $bundleAction,
            'sources' => $receipts,
            'writes_database' => false,
            'uses_cdn' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function readState(string $stateFile): array
    {
        return is_file($stateFile) ? AssetContractFields::readJson($stateFile) : [];
    }


    /** @param array<string, mixed> $value */
    private function writeJson(string $path, array $value): void
    {
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(4));
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        if (@file_put_contents($temporary, $json, LOCK_EX) === false || !@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('core_assets_state_write_failed');
        }
    }

    private function removeTree(string $path): void
    {
        $this->removeTreeSafely($path);
    }

    private function removeTreeSafely(string $path): bool
    {
        if (is_link($path) || is_file($path)) {
            return @unlink($path);
        }
        if (!file_exists($path)) {
            return true;
        }
        if (!is_dir($path)) {
            return @unlink($path);
        }

        try {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            $removed = true;
            foreach ($items as $item) {
                $itemPath = $item->getPathname();
                if ($item->isLink()) {
                    $removed = @unlink($itemPath) && $removed;
                } elseif ($item->isDir()) {
                    $removed = @rmdir($itemPath) && $removed;
                } else {
                    $removed = @unlink($itemPath) && $removed;
                }
            }

            return @rmdir($path) && $removed;
        } catch (\Throwable) {
            return false;
        }
    }
}
