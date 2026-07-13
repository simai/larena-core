<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/Assets/VerifiedAssetBundlePublisher.php';

use Larena\Core\Assets\VerifiedAssetBundlePublisher;

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
$longAsset = 'vendors-node_modules_lodash_lodash_js-node_modules_axios_lib_axios_js-node_modules_lit_index_js.js.gz';
$longAssetContents = gzencode('long-path-runtime');
file_put_contents($repo . '/distr/nested/' . $longAsset, $longAssetContents);

$isolatedGit = ['/usr/bin/env', '-u', 'GIT_INDEX_FILE', '-u', 'GIT_DIR', '-u', 'GIT_WORK_TREE', '-u', 'GIT_PREFIX', 'git'];
foreach ([['git', 'init', '-q'], ['git', 'config', 'user.email', 'test@example.test'], ['git', 'config', 'user.name', 'Test'], ['git', 'add', '.'], ['git', 'commit', '-qm', 'fixture']] as $command) {
    $process = proc_open([...$isolatedGit, ...array_slice($command, 1)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $repo);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('fixture_git_failed:' . $stderr);
    }
}
$isolatedGitCommand = implode(' ', array_map('escapeshellarg', $isolatedGit));
$commit = trim((string) shell_exec($isolatedGitCommand . ' -C ' . escapeshellarg($repo) . ' rev-parse HEAD'));
$tar = $root . '/fixture.tar';
exec($isolatedGitCommand . ' -C ' . escapeshellarg($repo) . ' archive --format=tar --output=' . escapeshellarg($tar) . ' ' . escapeshellarg($commit) . ' distr', $output, $exit);
assertTrue($exit === 0, 'fixture archive failed');
$checksum = hash_file('sha256', $tar);

$publisher = new VerifiedAssetBundlePublisher();
$source = ['repository' => $repo, 'commit' => $commit, 'tree' => 'distr', 'mount' => 'ui', 'sha256' => $checksum];
$first = $publisher->publish([$source], $public, $state, 'pair-v1');
assertTrue($first['active_bundle'] === 'pair-v1', 'pair-v1 not active');
assertTrue(is_file($public . '/pair-v1/ui/distr/runtime.js'), 'complete tree not extracted');
assertTrue(is_file($public . '/pair-v1/ui/distr/nested/' . $longAsset), 'long archive path not extracted');
assertTrue(
    hash_file('sha256', $public . '/pair-v1/ui/distr/nested/' . $longAsset) === hash('sha256', $longAssetContents),
    'long archive path content mismatch',
);
assertTrue(glob($public . '/pair-v1/ui/*.data') === [], 'archive extractor leaked a blob-named .data file');
assertTrue($first['sources'][0]['file_count'] === 3, 'file count mismatch');
assertTrue($first['publication_profile'] === VerifiedAssetBundlePublisher::PUBLICATION_PROFILE, 'publication profile missing');

$second = $publisher->publish([$source], $public, $state, 'pair-v2');
assertTrue($second['previous_bundle'] === 'pair-v1', 'previous bundle not retained');
$rollback = $publisher->rollback($public, $state);
assertTrue($rollback['active_bundle'] === 'pair-v1', 'rollback did not restore pair-v1');

$stateBeforeTamper = (string) file_get_contents($state);
file_put_contents($public . '/pair-v2/ui/distr/runtime.js', 'runtime-v2-tampered');
$existingTamperFailedClosed = false;
try {
    $publisher->publish([$source], $public, $state, 'pair-v2');
} catch (RuntimeException $exception) {
    $existingTamperFailedClosed = str_contains($exception->getMessage(), 'existing_bundle_verification_mismatch');
}
assertTrue($existingTamperFailedClosed, 'tampered existing bundle did not fail closed');
assertTrue((string) file_get_contents($state) === $stateBeforeTamper, 'tampered existing bundle changed activation state');
$tamperedRollbackFailedClosed = false;
try {
    $publisher->rollback($public, $state);
} catch (RuntimeException $exception) {
    $tamperedRollbackFailedClosed = str_contains($exception->getMessage(), 'existing_bundle_tree_mismatch');
}
assertTrue($tamperedRollbackFailedClosed, 'rollback activated a tampered previous bundle');
assertTrue((string) file_get_contents($state) === $stateBeforeTamper, 'tampered rollback changed activation state');

$failedClosed = false;
try {
    $publisher->publish([[...$source, 'sha256' => str_repeat('0', 64)]], $public, $state, 'pair-tampered');
} catch (RuntimeException $exception) {
    $failedClosed = str_contains($exception->getMessage(), 'checksum_mismatch');
}
assertTrue($failedClosed, 'checksum mismatch did not fail closed');
assertTrue(!is_dir($public . '/pair-tampered'), 'failed stage leaked into public root');

echo "VerifiedAssetBundlePublisherTest passed\n";
