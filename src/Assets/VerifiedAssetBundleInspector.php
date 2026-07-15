<?php

declare(strict_types=1);

namespace Larena\Core\Assets;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Read-only integrity inspection for an activated immutable asset bundle.
 *
 * The expectation is owned by the caller. Core only validates its generic
 * shape and never assigns product or frontend ownership to the artifact.
 */
final class VerifiedAssetBundleInspector
{
    public const INSPECTION_SCHEMA = 'larena.core_assets.bundle_inspection.v1';

    /**
     * @param array{
     *   schema:string,
     *   publication_profile:string,
     *   bundle_id:string,
     *   sources:list<array{commit:string,tree:string,mount:string,archive_sha256:string,files:int}>
     * } $expectedContract
     * @param list<string> $requestedFiles
     * @return array{
     *   schema:string,
     *   status:'verified'|'not_ready',
     *   publication_profile:string,
     *   bundle_id:string,
     *   manifest_sha:?string,
     *   required_file_set_sha256:string,
     *   verified_files:list<string>,
     *   missing_or_invalid:list<string>,
     *   physical_publication_ready:bool
     * }
     */
    public function inspect(
        array $expectedContract,
        array $requestedFiles,
        string $destinationRoot,
        string $stateFile,
    ): array {
        $contract = $this->normalizeExpectedContract($expectedContract);
        $requestedFiles = self::normalizeRequestedFiles($requestedFiles);
        $destinationRoot = $this->absolutePath($destinationRoot, 'core_assets_destination_root_invalid');
        $stateFile = $this->absolutePath($stateFile, 'core_assets_state_file_invalid');
        $requiredFileSetHash = self::requiredFileSetSha256($requestedFiles);
        $bundleId = $contract['bundle_id'];

        $manifestHash = null;
        $verifiedFiles = [];
        $problems = [];

        if (!is_file($stateFile)) {
            $problems[] = 'state_missing';

            return $this->receipt($contract, $manifestHash, $requiredFileSetHash, $verifiedFiles, $problems);
        }
        if (is_link($stateFile)) {
            $problems[] = 'state_untrusted_symlink';

            return $this->receipt($contract, $manifestHash, $requiredFileSetHash, $verifiedFiles, $problems);
        }

        try {
            $state = $this->readJson($stateFile);
        } catch (Throwable) {
            $problems[] = 'state_invalid';

            return $this->receipt($contract, $manifestHash, $requiredFileSetHash, $verifiedFiles, $problems);
        }

        if (($state['schema'] ?? null) !== 'larena.core_assets.activation_state.v2') {
            $problems[] = 'state_schema_mismatch';
        }
        if (($state['active_bundle'] ?? null) !== $bundleId) {
            $problems[] = 'active_bundle_mismatch';
        }
        $externalManifestHash = $state['active_bundle_manifest_sha256'] ?? null;
        if (!is_string($externalManifestHash) || preg_match('/^[a-f0-9]{64}$/', $externalManifestHash) !== 1) {
            $problems[] = 'active_manifest_sha_invalid';
        }
        if ($problems !== []) {
            return $this->receipt($contract, $manifestHash, $requiredFileSetHash, $verifiedFiles, $problems);
        }

        $bundlePath = $destinationRoot . DIRECTORY_SEPARATOR . $bundleId;
        $manifestPath = $bundlePath . DIRECTORY_SEPARATOR . '.larena-bundle.json';
        if (!is_dir($bundlePath) || is_link($bundlePath)) {
            $problems[] = 'bundle_missing';

            return $this->receipt($contract, $manifestHash, $requiredFileSetHash, $verifiedFiles, $problems);
        }
        $resolvedBundlePath = realpath($bundlePath);
        if ($resolvedBundlePath === false) {
            $problems[] = 'bundle_unresolvable';

            return $this->receipt($contract, $manifestHash, $requiredFileSetHash, $verifiedFiles, $problems);
        }
        if (!is_file($manifestPath) || is_link($manifestPath)) {
            $problems[] = 'manifest_missing';

            return $this->receipt($contract, $manifestHash, $requiredFileSetHash, $verifiedFiles, $problems);
        }

        $actualManifestHash = hash_file('sha256', $manifestPath);
        if (!is_string($actualManifestHash)) {
            $problems[] = 'manifest_sha_unavailable';

            return $this->receipt($contract, $manifestHash, $requiredFileSetHash, $verifiedFiles, $problems);
        }
        $manifestHash = $actualManifestHash;
        if (!hash_equals((string) $externalManifestHash, $actualManifestHash)) {
            $problems[] = 'manifest_sha_mismatch';

            return $this->receipt($contract, $manifestHash, $requiredFileSetHash, $verifiedFiles, $problems);
        }

        try {
            $manifest = $this->readJson($manifestPath);
        } catch (Throwable) {
            $problems[] = 'manifest_invalid';

            return $this->receipt($contract, $manifestHash, $requiredFileSetHash, $verifiedFiles, $problems);
        }

        if (($manifest['schema'] ?? null) !== VerifiedAssetBundlePublisher::BUNDLE_SCHEMA) {
            $problems[] = 'manifest_schema_mismatch';
        }
        if (($manifest['artifact_contract_schema'] ?? null) !== $contract['schema']) {
            $problems[] = 'artifact_contract_schema_mismatch';
        }
        if (($manifest['publication_profile'] ?? null) !== $contract['publication_profile']) {
            $problems[] = 'publication_profile_mismatch';
        }
        if (($manifest['bundle_id'] ?? null) !== $bundleId) {
            $problems[] = 'bundle_id_mismatch';
        }
        $receipts = $manifest['sources'] ?? null;
        if (!is_array($receipts)) {
            $problems[] = 'source_receipts_invalid';
        } elseif (count($receipts) !== count($contract['sources'])) {
            $problems[] = 'source_receipt_count_mismatch';
        }
        if ($problems !== []) {
            return $this->receipt($contract, $manifestHash, $requiredFileSetHash, $verifiedFiles, $problems);
        }

        /** @var list<array<string, mixed>> $receipts */
        $receipts = array_values($receipts);
        foreach ($contract['sources'] as $index => $expected) {
            $actual = $receipts[$index] ?? [];
            foreach (['commit', 'tree', 'mount', 'sha256'] as $field) {
                if (($actual[$field] ?? null) !== $expected[$field]) {
                    $problems[] = 'source_receipt_mismatch:' . $expected['mount'] . ':' . $field;
                }
            }
            if (($actual['file_count'] ?? null) !== $expected['files']) {
                $problems[] = 'source_receipt_mismatch:' . $expected['mount'] . ':file_count';
            }
            if (!is_string($actual['tree_fingerprint_sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/', $actual['tree_fingerprint_sha256']) !== 1
            ) {
                $problems[] = 'source_receipt_invalid:' . $expected['mount'] . ':tree_fingerprint_sha256';
            }
            if (!in_array($actual['object_format'] ?? null, ['sha1', 'sha256'], true)) {
                $problems[] = 'source_receipt_invalid:' . $expected['mount'] . ':object_format';
            }
        }
        if ($problems !== []) {
            return $this->receipt($contract, $manifestHash, $requiredFileSetHash, $verifiedFiles, $problems);
        }

        $expectedRootEntries = ['.larena-bundle.json'];
        foreach ($contract['sources'] as $source) {
            $expectedRootEntries[] = $source['mount'];
        }
        sort($expectedRootEntries, SORT_STRING);
        $actualRootEntries = array_values(array_filter(
            scandir($bundlePath) ?: [],
            static fn (string $entry): bool => !in_array($entry, ['.', '..'], true),
        ));
        sort($actualRootEntries, SORT_STRING);
        if ($actualRootEntries !== $expectedRootEntries) {
            $problems[] = 'bundle_root_shape_mismatch';
        }

        foreach ($contract['sources'] as $index => $expected) {
            $actual = $receipts[$index];
            $mountPath = $bundlePath . DIRECTORY_SEPARATOR . $expected['mount'];
            if (!is_dir($mountPath) || is_link($mountPath)) {
                $problems[] = 'mount_tree_invalid:' . $expected['mount'];
                continue;
            }
            try {
                $tree = $this->scanExtractedTree(
                    $mountPath,
                    (string) $actual['object_format'],
                );
                if ($expected['files'] !== count($tree['entries'])
                    || !hash_equals((string) $actual['tree_fingerprint_sha256'], $tree['tree_fingerprint_sha256'])
                ) {
                    $problems[] = 'mount_fingerprint_mismatch:' . $expected['mount'];
                }
            } catch (Throwable) {
                $problems[] = 'mount_tree_invalid:' . $expected['mount'];
            }
        }

        $mounts = array_fill_keys(array_column($contract['sources'], 'mount'), true);
        foreach ($requestedFiles as $relativePath) {
            $firstSegment = explode('/', $relativePath, 2)[0];
            if (!isset($mounts[$firstSegment])) {
                $problems[] = 'requested_file_mount_unknown:' . $relativePath;
                continue;
            }
            $candidate = $bundlePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
            $resolved = realpath($candidate);
            if ($resolved === false || !is_file($resolved)) {
                $problems[] = 'requested_file_missing:' . $relativePath;
                continue;
            }
            if (!str_starts_with($resolved, $resolvedBundlePath . DIRECTORY_SEPARATOR)) {
                $problems[] = 'requested_file_outside_bundle:' . $relativePath;
                continue;
            }
            $verifiedFiles[] = $relativePath;
        }

        return $this->receipt($contract, $manifestHash, $requiredFileSetHash, $verifiedFiles, $problems);
    }

    /** @param list<string> $paths */
    public static function requiredFileSetSha256(array $paths): string
    {
        $paths = self::normalizeRequestedFiles($paths);

        return hash('sha256', implode("\0", $paths) . "\0");
    }

    /**
     * @param list<mixed> $paths
     * @return list<string>
     */
    private static function normalizeRequestedFiles(array $paths): array
    {
        if ($paths === []) {
            throw new InvalidArgumentException('core_assets_requested_files_required');
        }
        $normalized = [];
        foreach ($paths as $path) {
            if (!is_string($path) || !self::safeRelativePath($path)) {
                throw new InvalidArgumentException('core_assets_requested_file_path_invalid');
            }
            $normalized[$path] = true;
        }
        $paths = array_keys($normalized);
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * @param array<string, mixed> $expected
     * @return array{
     *   schema:string,
     *   publication_profile:string,
     *   bundle_id:string,
     *   sources:list<array{commit:string,tree:string,mount:string,sha256:string,files:int}>
     * }
     */
    private function normalizeExpectedContract(array $expected): array
    {
        $schema = $this->aliasedString(
            $expected,
            'schema',
            'artifact_schema',
            'core_assets_artifact_schema_invalid',
            'core_assets_artifact_schema_conflict',
        );
        $profile = $this->stableSegment((string) ($expected['publication_profile'] ?? ''), 'core_assets_publication_profile_invalid');
        $bundleId = $this->stableSegment((string) ($expected['bundle_id'] ?? ''), 'core_assets_bundle_id_invalid');
        if ($schema === '' || preg_match('/^[a-z][a-z0-9._-]{2,191}$/', $schema) !== 1) {
            throw new InvalidArgumentException('core_assets_artifact_schema_invalid');
        }
        if (!is_array($expected['sources'] ?? null) || $expected['sources'] === []) {
            throw new InvalidArgumentException('core_assets_bundle_sources_required');
        }

        $sources = [];
        $mounts = [];
        foreach (array_values($expected['sources']) as $source) {
            if (!is_array($source)) {
                throw new InvalidArgumentException('core_assets_source_contract_invalid');
            }
            $commit = strtolower(trim((string) ($source['commit'] ?? '')));
            $tree = trim((string) ($source['tree'] ?? ''));
            $mount = $this->stableSegment((string) ($source['mount'] ?? ''), 'core_assets_source_mount_invalid');
            $sha256 = $this->sourceArchiveChecksum($source);
            $files = $source['files'] ?? null;
            if (preg_match('/^[a-f0-9]{40}$/', $commit) !== 1) {
                throw new InvalidArgumentException('core_assets_source_commit_invalid');
            }
            if (!self::safeRelativePath($tree)) {
                throw new InvalidArgumentException('core_assets_source_tree_invalid');
            }
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
            $sources[] = compact('commit', 'tree', 'mount', 'sha256', 'files');
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
     * @param list<string> $verifiedFiles
     * @param list<string> $problems
     * @return array{
     *   schema:string,
     *   status:'verified'|'not_ready',
     *   publication_profile:string,
     *   bundle_id:string,
     *   manifest_sha:?string,
     *   required_file_set_sha256:string,
     *   verified_files:list<string>,
     *   missing_or_invalid:list<string>,
     *   physical_publication_ready:bool
     * }
     */
    private function receipt(
        array $contract,
        ?string $manifestHash,
        string $requiredFileSetHash,
        array $verifiedFiles,
        array $problems,
    ): array {
        $verifiedFiles = array_values(array_unique($verifiedFiles));
        sort($verifiedFiles, SORT_STRING);
        $problems = array_values(array_unique($problems));
        $ready = $problems === [];

        return [
            'schema' => self::INSPECTION_SCHEMA,
            'status' => $ready ? 'verified' : 'not_ready',
            'publication_profile' => $contract['publication_profile'],
            'bundle_id' => $contract['bundle_id'],
            'manifest_sha' => $manifestHash,
            'required_file_set_sha256' => $requiredFileSetHash,
            'verified_files' => $verifiedFiles,
            'missing_or_invalid' => $problems,
            'physical_publication_ready' => $ready,
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
            if ($item->isLink()) {
                throw new RuntimeException('core_assets_extracted_symlink_forbidden');
            }
            if ($item->isDir()) {
                continue;
            }
            if (!$item->isFile()) {
                throw new RuntimeException('core_assets_extracted_entry_type_unsupported');
            }
            $relative = substr($item->getPathname(), strlen($mountPath) + 1);
            if ($relative === '' || !self::safeRelativePath($relative)) {
                throw new RuntimeException('core_assets_archive_path_unsafe');
            }
            $mode = ((((int) $item->getPerms()) & 0111) !== 0) ? '100755' : '100644';
            $object = $this->gitFileHash($item->getPathname(), $objectFormat);
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

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('core_assets_json_invalid');
        }

        return $decoded;
    }

    private static function safeRelativePath(string $path): bool
    {
        if ($path === ''
            || $path[0] === '/'
            || str_contains($path, "\0")
            || str_contains($path, '\\')
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

    private function absolutePath(string $path, string $error): string
    {
        $path = rtrim(trim($path), DIRECTORY_SEPARATOR);
        if ($path === '' || $path[0] !== DIRECTORY_SEPARATOR || str_contains($path, "\0")) {
            throw new InvalidArgumentException($error);
        }

        return $path;
    }

    private function stableSegment(string $value, string $error): string
    {
        $value = trim($value);
        if (!preg_match('/^[a-z0-9][a-z0-9._-]{0,127}$/', $value)) {
            throw new InvalidArgumentException($error);
        }

        return $value;
    }
}
