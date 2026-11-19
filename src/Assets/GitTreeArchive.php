<?php

declare(strict_types=1);

namespace Larena\Core\Assets;

use InvalidArgumentException;
use Larena\Core\Process\ProcessExecutor;
use Larena\Core\Process\ProcessResult;
use Larena\Core\Process\ProcessSpecification;
use Larena\Core\Process\ProcOpenProcessExecutor;
use RuntimeException;

/**
 * Git trees as verified archives: archiving a pinned tree, checking that an archive is safe to extract,
 * extracting it, and proving that the extracted files are exactly the tree's blobs.
 *
 * git, tar and gzip run through the shared ProcessExecutor: an argv list and never a shell string, a bounded run
 * time, bounded output, and only the few environment variables these tools need instead of the whole parent
 * environment (so a hook's GIT_DIR or GIT_INDEX_FILE cannot redirect them).
 */
final class GitTreeArchive
{
    /** Archiving, extracting and compressing a whole tree. */
    public const float LONG_TIMEOUT_SECONDS = 600.0;
    /** Reading a tree or listing an archive. */
    public const float SHORT_TIMEOUT_SECONDS = 120.0;
    /** Captured output of a listing; more is refused, not cut. */
    public const int LISTING_LIMIT_BYTES = 64 * 1024 * 1024;
    /** Bytes a command may write to its target file; more is refused, not cut. */
    public const int FILE_LIMIT_BYTES = 8 * 1024 * 1024 * 1024;
    /** Stderr kept for the error message. */
    public const int STDERR_LIMIT_BYTES = 65_536;
    /** Parent variables passed on to git, tar and gzip; nothing else of the parent environment reaches them. */
    private const array PASSED_ENVIRONMENT = ['PATH', 'HOME', 'TMPDIR', 'LANG', 'LC_ALL'];
    private const string DEFAULT_PATH = '/usr/local/bin:/usr/bin:/bin';

    public function __construct(private readonly ProcessExecutor $processes = new ProcOpenProcessExecutor())
    {
    }

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
        $result = $this->run(['tar', '-xf', $tar, '-C', $destination], self::LONG_TIMEOUT_SECONDS, self::LISTING_LIMIT_BYTES);
        if (!$result->started) {
            throw new RuntimeException('core_assets_archive_extract_start_failed');
        }
        if (!$this->completed($result)) {
            throw new RuntimeException('core_assets_archive_extract_failed:' . $this->failureDetail($result));
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
        $result = $this->run($command, self::SHORT_TIMEOUT_SECONDS, self::LISTING_LIMIT_BYTES);
        if (!$result->started) {
            throw new RuntimeException($error);
        }
        if (!$this->completed($result)) {
            throw new RuntimeException($error . ':' . $this->failureDetail($result, false));
        }

        return $result->stdout;
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
        $result = $this->run(
            ['git', '-C', $repository, 'archive', '--format=tar', '--output=' . $target, $commit, $tree],
            self::LONG_TIMEOUT_SECONDS,
            self::LISTING_LIMIT_BYTES,
        );
        if (!$result->started) {
            throw new RuntimeException('core_assets_git_archive_start_failed');
        }
        if (!$this->completed($result) || !is_file($target)) {
            throw new RuntimeException('core_assets_git_archive_failed:' . $this->failureDetail($result));
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
        $sink = @fopen($target, 'wb');
        if ($sink === false) {
            throw new RuntimeException($error);
        }
        try {
            $result = $this->run($command, self::LONG_TIMEOUT_SECONDS, self::FILE_LIMIT_BYTES, $sink);
        } finally {
            fclose($sink);
        }
        if (!$result->started) {
            @unlink($target);
            throw new RuntimeException($error);
        }
        if (!$this->completed($result) || !is_file($target)) {
            @unlink($target);
            throw new RuntimeException($error . ':' . $this->failureDetail($result, false));
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

    /**
     * @param list<string> $command
     * @param resource|null $stdoutSink
     */
    private function run(array $command, float $timeoutSeconds, int $stdoutLimitBytes, mixed $stdoutSink = null): ProcessResult
    {
        return $this->processes->run(new ProcessSpecification(
            argv: $command,
            workingDirectory: self::workingDirectory(),
            environment: self::environment(),
            stdoutSink: $stdoutSink,
            stdoutLimitBytes: $stdoutLimitBytes,
            stderrLimitBytes: self::STDERR_LIMIT_BYTES,
            timeoutSeconds: $timeoutSeconds,
            captureStdout: $stdoutSink === null,
        ));
    }

    /** The child ran to its own end with exit 0 and nothing it wrote was cut. */
    private function completed(ProcessResult $result): bool
    {
        return $result->successful() && !$result->stdoutTruncated && !$result->stderrTruncated;
    }

    /** Why a command failed: the reason the executor stopped it, else what it wrote (stderr first). */
    private function failureDetail(ProcessResult $result, bool $stdoutFallback = true): string
    {
        if ($result->stopReason !== null) {
            return $result->stopReason->value;
        }
        $detail = trim($result->stderr);
        if ($detail === '' && $stdoutFallback) {
            $detail = trim($result->stdout);
        }

        return $detail;
    }

    /** Relative paths keep meaning what they meant to the caller: the child starts in the caller's directory. */
    private static function workingDirectory(): string
    {
        $cwd = getcwd();

        return is_string($cwd) && str_starts_with($cwd, '/') ? $cwd : '/';
    }

    /** @return array<string, string> */
    private static function environment(): array
    {
        $environment = [];
        foreach (self::PASSED_ENVIRONMENT as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '' && !str_contains($value, "\0")) {
                $environment[$name] = $value;
            }
        }
        $environment['PATH'] ??= self::DEFAULT_PATH;

        return $environment;
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
