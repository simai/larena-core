<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use Larena\Core\Assets\VerifiedAssetBundlePublisher;

// Git exports the linked-worktree index path to commit hooks. The fixture
// repository must never inherit that path, otherwise `git add` below mutates
// the real package index instead of its disposable repository.
foreach (['GIT_INDEX_FILE', 'GIT_DIR', 'GIT_WORK_TREE', 'GIT_COMMON_DIR'] as $gitEnvironmentVariable) {
    putenv($gitEnvironmentVariable);
    unset($_ENV[$gitEnvironmentVariable], $_SERVER[$gitEnvironmentVariable]);
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = sys_get_temp_dir() . '/larena-core-assets-' . bin2hex(random_bytes(5));
$repo = $root . '/source';
$public = $root . '/public';
$state = $root . '/state/active.json';
mkdir($repo . '/distr/nested', 0775, true);
file_put_contents($repo . '/distr/runtime.js', 'runtime-v1');
file_put_contents($repo . '/distr/nested/runtime.css', 'runtime-css');

foreach ([['git', 'init', '-q'], ['git', 'config', 'user.email', 'test@example.test'], ['git', 'config', 'user.name', 'Test'], ['git', 'add', '.'], ['git', 'commit', '-qm', 'fixture']] as $command) {
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $repo);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('fixture_git_failed:' . $stderr);
    }
}
$commit = trim((string) shell_exec('git -C ' . escapeshellarg($repo) . ' rev-parse HEAD'));
$tar = $root . '/fixture.tar';
exec('git -C ' . escapeshellarg($repo) . ' archive --format=tar --output=' . escapeshellarg($tar) . ' ' . escapeshellarg($commit) . ' distr', $output, $exit);
assertTrue($exit === 0, 'fixture archive failed');
$checksum = hash_file('sha256', $tar);

$publisher = new VerifiedAssetBundlePublisher();
$source = ['repository' => $repo, 'commit' => $commit, 'tree' => 'distr', 'mount' => 'ui', 'sha256' => $checksum];
$first = $publisher->publish([$source], $public, $state, 'pair-v1');
assertTrue($first['active_bundle'] === 'pair-v1', 'pair-v1 not active');
assertTrue(is_file($public . '/pair-v1/ui/distr/runtime.js'), 'complete tree not extracted');
assertTrue($first['sources'][0]['file_count'] === 2, 'file count mismatch');

$second = $publisher->publish([$source], $public, $state, 'pair-v2');
assertTrue($second['previous_bundle'] === 'pair-v1', 'previous bundle not retained');
$rollback = $publisher->rollback($public, $state);
assertTrue($rollback['active_bundle'] === 'pair-v1', 'rollback did not restore pair-v1');

$failedClosed = false;
try {
    $publisher->publish([[...$source, 'sha256' => str_repeat('0', 64)]], $public, $state, 'pair-tampered');
} catch (RuntimeException $exception) {
    $failedClosed = str_contains($exception->getMessage(), 'checksum_mismatch');
}
assertTrue($failedClosed, 'checksum mismatch did not fail closed');
assertTrue(!is_dir($public . '/pair-tampered'), 'failed stage leaked into public root');

echo "VerifiedAssetBundlePublisherTest passed\n";
