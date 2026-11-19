<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Larena\Core\Assets\GitTreeArchive;
use Larena\Core\Process\ProcessResult;
use Larena\Core\Process\ProcessSpecification;
use Larena\Core\Process\ProcessStopReason;
use Larena\Core\Process\RecordingProcessExecutor;

// GitTreeArchive starts git, tar and gzip only through the shared ProcessExecutor: argv lists, bounded time and
// output, a small passed environment, and the same error codes as before for start failures and failed runs.

function archiveAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

/** @param callable():mixed $operation */
function archiveAssertFails(callable $operation, string $expected, string $message): void
{
    try {
        $operation();
    } catch (Throwable $exception) {
        archiveAssert($exception->getMessage() === $expected, $message . ': got ' . $exception->getMessage());

        return;
    }
    archiveAssert(false, $message . ': operation unexpectedly succeeded');
}

function stoppedResult(ProcessStopReason $reason, bool $stdoutTruncated = false): ProcessResult
{
    $empty = hash('sha256', '');

    return new ProcessResult(true, null, null, true, $reason === ProcessStopReason::Timeout, $reason, true, '', '', 0, 0, $stdoutTruncated, false, $empty, $empty, 0.0);
}

$root = sys_get_temp_dir() . '/larena-core-git-tree-archive-' . bin2hex(random_bytes(5));
mkdir($root, 0775, true);
putenv('GIT_INDEX_FILE=/should/not/reach/the/child');

try {
    // A listing: argv, no shell, the short timeout, the listing limit and a passed environment without GIT_*.
    $fake = new RecordingProcessExecutor(ProcessResult::finished(0, "a.txt\n"), ProcessResult::finished(0, "-rw-r--r-- a.txt\n"));
    (new GitTreeArchive($fake))->assertArchiveSafe($root . '/x.tar');
    $recorded = $fake->recorded();
    archiveAssert(count($recorded) === 2, 'a safety check lists the archive twice');
    archiveAssert($recorded[0]->argv === ['tar', '-tf', $root . '/x.tar'], 'tar listing is an argv list');
    archiveAssert($recorded[1]->argv === ['tar', '-tvf', $root . '/x.tar'], 'verbose listing is an argv list');
    archiveAssert($recorded[0]->timeoutSeconds === GitTreeArchive::SHORT_TIMEOUT_SECONDS, 'a listing has the short timeout');
    archiveAssert($recorded[0]->stdoutLimitBytes === GitTreeArchive::LISTING_LIMIT_BYTES, 'a listing has the listing limit');
    archiveAssert(isset($recorded[0]->environment['PATH']), 'the child gets PATH');
    archiveAssert(!isset($recorded[0]->environment['GIT_INDEX_FILE']), 'a parent GIT_INDEX_FILE never reaches the child');
    archiveAssert(array_diff(array_keys($recorded[0]->environment), ['PATH', 'HOME', 'TMPDIR', 'LANG', 'LC_ALL']) === [], 'only the passed variables reach the child');
    archiveAssert(str_starts_with($recorded[0]->workingDirectory, '/'), 'the child starts in an absolute directory');

    // A listing that was cut is refused, not parsed.
    $cut = new RecordingProcessExecutor(stoppedResult(ProcessStopReason::StdoutLimit, true));
    archiveAssertFails(
        static fn () => (new GitTreeArchive($cut))->assertArchiveSafe($root . '/x.tar'),
        'core_assets_archive_list_failed:stdout_limit',
        'a cut listing fails with the limit as reason',
    );

    // A listing that never started keeps the bare error code.
    $never = new RecordingProcessExecutor(ProcessResult::notStarted());
    archiveAssertFails(
        static fn () => (new GitTreeArchive($never))->assertArchiveSafe($root . '/x.tar'),
        'core_assets_archive_list_failed',
        'a listing that never started has the bare code',
    );

    // A failing git command reports its stderr.
    $failing = new RecordingProcessExecutor(ProcessResult::finished(128, '', "fatal: not a git repository\n"));
    archiveAssertFails(
        static fn () => (new GitTreeArchive($failing))->repositoryPath($root),
        'core_assets_source_repository_invalid',
        'a failing rev-parse rejects the repository',
    );
    archiveAssert($failing->recorded()[0]->argv === ['git', '-C', realpath($root), 'rev-parse', '--git-dir'], 'rev-parse is an argv list');

    // git archive: the long timeout, start failure and run failure codes.
    $archiveTarget = $root . '/out.tar';
    $archiving = RecordingProcessExecutor::answering(static function (ProcessSpecification $spec) use ($archiveTarget): ProcessResult {
        file_put_contents($archiveTarget, 'tar');

        return ProcessResult::finished(0);
    });
    (new GitTreeArchive($archiving))->runGitArchive('/repo', str_repeat('a', 40), 'distr', $archiveTarget);
    $spec = $archiving->recorded()[0];
    archiveAssert($spec->argv === ['git', '-C', '/repo', 'archive', '--format=tar', '--output=' . $archiveTarget, str_repeat('a', 40), 'distr'], 'git archive is an argv list');
    archiveAssert($spec->timeoutSeconds === GitTreeArchive::LONG_TIMEOUT_SECONDS, 'git archive has the long timeout');
    archiveAssertFails(
        static fn () => (new GitTreeArchive(new RecordingProcessExecutor(ProcessResult::notStarted())))->runGitArchive('/repo', 'c', 'distr', $root . '/none.tar'),
        'core_assets_git_archive_start_failed',
        'git archive that never started',
    );
    archiveAssertFails(
        static fn () => (new GitTreeArchive(new RecordingProcessExecutor(ProcessResult::finished(1, '', "bad tree\n"))))->runGitArchive('/repo', 'c', 'distr', $root . '/none.tar'),
        'core_assets_git_archive_failed:bad tree',
        'git archive that failed reports stderr',
    );
    archiveAssertFails(
        static fn () => (new GitTreeArchive(new RecordingProcessExecutor(stoppedResult(ProcessStopReason::Timeout))))->runGitArchive('/repo', 'c', 'distr', $archiveTarget),
        'core_assets_git_archive_failed:timeout',
        'git archive past its timeout fails even when a file is there',
    );

    // tar -xf: start failure and run failure codes.
    archiveAssertFails(
        static fn () => (new GitTreeArchive(new RecordingProcessExecutor(ProcessResult::notStarted())))->extractArchive($root . '/x.tar', $root),
        'core_assets_archive_extract_start_failed',
        'extract that never started',
    );
    archiveAssertFails(
        static fn () => (new GitTreeArchive(new RecordingProcessExecutor(ProcessResult::finished(2, 'out', ''))))->extractArchive($root . '/x.tar', $root),
        'core_assets_archive_extract_failed:out',
        'extract failure falls back to stdout when stderr is empty',
    );

    // gzip writes into the target file through a sink, and a failed run removes the partial file.
    $gzipTarget = $root . '/out.tar.gz';
    $gzipping = RecordingProcessExecutor::answering(static function (ProcessSpecification $spec): ProcessResult {
        archiveAssert(is_resource($spec->stdoutSink), 'gzip output goes to a sink');
        archiveAssert($spec->captureStdout === false, 'gzip output is not held in memory');
        fwrite($spec->stdoutSink, 'gz');

        return ProcessResult::finished(0);
    });
    (new GitTreeArchive($gzipping))->gzipArchive($root . '/x.tar', $gzipTarget);
    archiveAssert(file_get_contents($gzipTarget) === 'gz', 'gzip output lands in the target');
    $gzipSpec = $gzipping->recorded()[0];
    archiveAssert($gzipSpec->argv === ['gzip', '-n', '-9', '-c', $root . '/x.tar'], 'gzip is an argv list');
    archiveAssert($gzipSpec->stdoutLimitBytes === GitTreeArchive::FILE_LIMIT_BYTES, 'gzip has the file limit');
    archiveAssert($gzipSpec->timeoutSeconds === GitTreeArchive::LONG_TIMEOUT_SECONDS, 'gzip has the long timeout');

    archiveAssertFails(
        static fn () => (new GitTreeArchive(new RecordingProcessExecutor(ProcessResult::finished(1, '', "corrupt\n"))))->decompressGzipArchive($root . '/x.tar.gz', $gzipTarget),
        'core_assets_archive_decompress_failed:corrupt',
        'gunzip failure reports stderr',
    );
    archiveAssert(!is_file($gzipTarget), 'a failed gzip run removes the partial target');
    archiveAssertFails(
        static fn () => (new GitTreeArchive(new RecordingProcessExecutor(ProcessResult::notStarted())))->gzipArchive($root . '/x.tar', $gzipTarget),
        'core_assets_archive_compress_failed',
        'gzip that never started keeps the bare code',
    );
    archiveAssert(!is_file($gzipTarget), 'gzip that never started leaves no target');
} finally {
    putenv('GIT_INDEX_FILE');
    foreach (glob($root . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($root);
}

echo "GitTreeArchiveProcessTest passed\n";
