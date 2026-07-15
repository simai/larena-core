<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/src/Assets/VerifiedAssetBundlePublisher.php';
require_once dirname(__DIR__, 2) . '/src/Assets/VerifiedAssetBundleInspector.php';

use Larena\Core\Assets\VerifiedAssetBundleInspector;
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

/** @param callable():mixed $operation */
function assertFailsWith(callable $operation, string $expectedMessage, string $message): void
{
    try {
        $operation();
    } catch (Throwable $exception) {
        assertTrue(str_contains($exception->getMessage(), $expectedMessage), $message . ': ' . $exception->getMessage());

        return;
    }

    throw new RuntimeException($message . ': operation unexpectedly succeeded');
}

/** @param list<string> $command */
function runTestCommand(array $command, ?string $workingDirectory = null): void
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $workingDirectory);
    if (!is_resource($process)) {
        throw new RuntimeException('test_command_start_failed');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('test_command_failed:' . trim((string) ($stderr ?: $stdout)));
    }
}

function copyTestTree(string $source, string $destination): void
{
    mkdir($destination, 0775, true);
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($items as $item) {
        $target = $destination . '/' . substr($item->getPathname(), strlen($source) + 1);
        if ($item->isDir()) {
            mkdir($target, 0775, true);
        } elseif (!copy($item->getPathname(), $target)) {
            throw new RuntimeException('test_tree_copy_failed');
        }
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
assertTrue($first['bundle_action'] === 'created', 'new Git bundle action mismatch');
assertTrue(is_file($public . '/pair-v1/ui/distr/runtime.js'), 'complete tree not extracted');
assertTrue(is_file($public . '/pair-v1/ui/distr/nested/' . $longAsset), 'long archive path not extracted');
assertTrue(
    hash_file('sha256', $public . '/pair-v1/ui/distr/nested/' . $longAsset) === hash('sha256', $longAssetContents),
    'long archive path content mismatch',
);
assertTrue(glob($public . '/pair-v1/ui/*.data') === [], 'archive extractor leaked a blob-named .data file');
assertTrue($first['sources'][0]['file_count'] === 3, 'file count mismatch');
assertTrue($first['publication_profile'] === VerifiedAssetBundlePublisher::PUBLICATION_PROFILE, 'publication profile missing');
$firstMarker = json_decode((string) file_get_contents($public . '/pair-v1/.larena-bundle.json'), true, 512, JSON_THROW_ON_ERROR);
assertTrue($firstMarker['schema'] === VerifiedAssetBundlePublisher::BUNDLE_SCHEMA, 'bundle schema mismatch');
$firstState = json_decode((string) file_get_contents($state), true, 512, JSON_THROW_ON_ERROR);
assertTrue($firstState['schema'] === 'larena.core_assets.activation_state.v2', 'activation state schema mismatch');
assertTrue($firstState['previous_bundle'] === null, 'untrusted legacy rollback target retained');
assertTrue(
    $firstState['active_bundle_manifest_sha256'] === hash_file('sha256', $public . '/pair-v1/.larena-bundle.json'),
    'active manifest checksum not pinned outside bundle',
);

$second = $publisher->publish([$source], $public, $state, 'pair-v2');
assertTrue($second['bundle_action'] === 'created', 'second Git bundle action mismatch');
assertTrue($second['previous_bundle'] === 'pair-v1', 'previous bundle not retained');
$rollback = $publisher->rollback($public, $state);
assertTrue($rollback['active_bundle'] === 'pair-v1', 'rollback did not restore pair-v1');

$stateBeforeTamper = (string) file_get_contents($state);
file_put_contents($public . '/pair-v2/ui/distr/runtime.js', 'runtime-v2-tampered');
$tamperedMarkerPath = $public . '/pair-v2/.larena-bundle.json';
$tamperedMarker = json_decode((string) file_get_contents($tamperedMarkerPath), true, 512, JSON_THROW_ON_ERROR);
$tamperedMarker['sources'][0]['tree_fingerprint_sha256'] = str_repeat('0', 64);
file_put_contents($tamperedMarkerPath, json_encode($tamperedMarker, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
$existingTamperFailedClosed = false;
try {
    $publisher->publish([$source], $public, $state, 'pair-v2');
} catch (RuntimeException $exception) {
    $existingTamperFailedClosed = str_contains($exception->getMessage(), 'extracted_entry_content_mismatch');
}
assertTrue($existingTamperFailedClosed, 'tampered existing bundle did not fail closed');
assertTrue((string) file_get_contents($state) === $stateBeforeTamper, 'tampered existing bundle changed activation state');
$tamperedRollbackFailedClosed = false;
try {
    $publisher->rollback($public, $state);
} catch (RuntimeException $exception) {
    $tamperedRollbackFailedClosed = str_contains($exception->getMessage(), 'previous_bundle_manifest_checksum_mismatch');
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

$artifactDirectory = $root . '/artifact-v1';
$artifactDirectoryCopy = $root . '/artifact-v1-copy';
$artifactPublic = $root . '/artifact-public';
$artifactState = $root . '/artifact-state/active.json';
$artifactContract = [
    'schema' => 'larena.test.frontend_runtime_artifact.v1',
    'runtime' => 'simai-framework',
    'publication_profile' => VerifiedAssetBundlePublisher::PUBLICATION_PROFILE,
    'bundle_id' => 'artifact-v1',
    'sources' => [[
        'repository' => $repo,
        'commit' => $commit,
        'tree' => 'distr',
        'mount' => 'ui',
        'archive_sha256' => $checksum,
        'files' => 3,
    ]],
];

$build = $publisher->buildArtifactDirectory($artifactContract, $artifactDirectory);
assertTrue($build['schema'] === 'larena.core_assets.artifact_build_receipt.v1', 'artifact build receipt schema mismatch');
assertTrue(
    $build['artifact_contract_schema'] === $artifactContract['schema'],
    'artifact contract schema missing from build receipt',
);
$artifactManifest = json_decode(
    (string) file_get_contents($artifactDirectory . '/manifest.json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
);
assertTrue($artifactManifest['schema'] === $artifactContract['schema'], 'artifact manifest schema mismatch');
assertTrue(
    $artifactManifest['sources'][0]['archive_sha256'] === $checksum,
    'canonical archive checksum missing from artifact manifest',
);
assertTrue(!isset($artifactManifest['sources'][0]['sha256']), 'artifact manifest leaked legacy checksum key');
assertTrue(is_file($artifactDirectory . '/sources/ui.tar.gz'), 'portable source archive missing');

$reusedBuild = $publisher->buildArtifactDirectory($artifactContract, $artifactDirectory);
assertTrue($reusedBuild['reused'] === true, 'verified artifact was not reused');
$publisher->buildArtifactDirectory($artifactContract, $artifactDirectoryCopy);
assertTrue(
    hash_file('sha256', $artifactDirectory . '/sources/ui.tar.gz')
        === hash_file('sha256', $artifactDirectoryCopy . '/sources/ui.tar.gz'),
    'artifact source archive is not reproducible',
);
assertTrue(
    hash_file('sha256', $artifactDirectory . '/manifest.json')
        === hash_file('sha256', $artifactDirectoryCopy . '/manifest.json'),
    'artifact manifest is not reproducible',
);

$publicationContract = $artifactContract;
unset($publicationContract['sources'][0]['repository']);
$artifactPublication = $publisher->publishArtifactDirectory(
    $publicationContract,
    $artifactDirectory,
    $artifactPublic,
    $artifactState,
);
assertTrue($artifactPublication['active_bundle'] === 'artifact-v1', 'artifact bundle not activated');
assertTrue($artifactPublication['bundle_action'] === 'created', 'new artifact bundle action mismatch');
assertTrue(is_file($artifactPublic . '/artifact-v1/ui/distr/runtime.js'), 'artifact tree not published');
$artifactMarker = json_decode(
    (string) file_get_contents($artifactPublic . '/artifact-v1/.larena-bundle.json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
);
assertTrue(
    $artifactMarker['artifact_contract_schema'] === $publicationContract['schema'],
    'artifact contract schema not pinned in immutable bundle',
);
assertTrue($artifactMarker['sources'][0]['sha256'] === $checksum, 'immutable receipt checksum mismatch');
$reusedArtifactPublication = $publisher->publishArtifactDirectory(
    $publicationContract,
    $artifactDirectory,
    $artifactPublic,
    $artifactState,
);
assertTrue($reusedArtifactPublication['bundle_action'] === 'reused', 'verified artifact bundle was not reused');

$inspector = new VerifiedAssetBundleInspector();
$requestedFiles = ['ui/distr/nested/runtime.css', 'ui/distr/runtime.js'];
$inspection = $inspector->inspect($publicationContract, $requestedFiles, $artifactPublic, $artifactState);
assertTrue($inspection['schema'] === VerifiedAssetBundleInspector::INSPECTION_SCHEMA, 'inspection schema mismatch');
assertTrue($inspection['status'] === 'verified', 'published artifact did not inspect as verified');
assertTrue($inspection['physical_publication_ready'] === true, 'verified artifact not physically ready');
assertTrue($inspection['verified_files'] === $requestedFiles, 'inspection did not verify exact requested graph');
assertTrue(
    $inspection['required_file_set_sha256'] === VerifiedAssetBundleInspector::requiredFileSetSha256($requestedFiles),
    'requested graph checksum mismatch',
);
assertTrue($inspection['missing_or_invalid'] === [], 'verified inspection contains problems');

$partialInspection = $inspector->inspect(
    $publicationContract,
    ['ui/distr/runtime.js'],
    $artifactPublic,
    $artifactState,
);
assertTrue($partialInspection['status'] === 'verified', 'readiness was not scoped to requested graph');
assertTrue($partialInspection['verified_files'] === ['ui/distr/runtime.js'], 'partial graph receipt is not exact');

$missingInspection = $inspector->inspect(
    $publicationContract,
    ['ui/distr/missing.js'],
    $artifactPublic,
    $artifactState,
);
assertTrue($missingInspection['status'] === 'not_ready', 'missing requested file did not fail closed');
assertTrue(
    in_array('requested_file_missing:ui/distr/missing.js', $missingInspection['missing_or_invalid'], true),
    'missing requested file was not identified',
);

$missingStateInspection = $inspector->inspect(
    $publicationContract,
    ['ui/distr/runtime.js'],
    $artifactPublic,
    $root . '/missing-state.json',
);
assertTrue($missingStateInspection['status'] === 'not_ready', 'missing activation state did not fail closed');
$linkedState = $root . '/linked-state.json';
symlink($artifactState, $linkedState);
$linkedStateInspection = $inspector->inspect(
    $publicationContract,
    ['ui/distr/runtime.js'],
    $artifactPublic,
    $linkedState,
);
assertTrue($linkedStateInspection['status'] === 'not_ready', 'symlinked activation state was trusted');
assertTrue(
    $linkedStateInspection['missing_or_invalid'] === ['state_untrusted_symlink'],
    'symlinked state rejection was not explicit',
);

$runtimePath = $artifactPublic . '/artifact-v1/ui/distr/runtime.js';
$runtimeOriginal = (string) file_get_contents($runtimePath);
$artifactStateBeforeRepair = (string) file_get_contents($artifactState);
file_put_contents($runtimePath, 'artifact-runtime-tampered');
$tamperedInspection = $inspector->inspect(
    $publicationContract,
    ['ui/distr/runtime.js'],
    $artifactPublic,
    $artifactState,
);
assertTrue($tamperedInspection['status'] === 'not_ready', 'tampered bundle file did not fail inspection');
assertTrue(
    in_array('mount_fingerprint_mismatch:ui', $tamperedInspection['missing_or_invalid'], true),
    'tampered mount fingerprint was not identified',
);
assertFailsWith(
    static fn () => $publisher->publishArtifactDirectory(
        $publicationContract,
        $artifactDirectory,
        $artifactPublic,
        $artifactState,
    ),
    'existing_bundle_tree_mismatch:ui',
    'ordinary publication repaired a damaged immutable bundle',
);
assertTrue((string) file_get_contents($runtimePath) === 'artifact-runtime-tampered', 'ordinary publication changed damage');
assertTrue((string) file_get_contents($artifactState) === $artifactStateBeforeRepair, 'ordinary failure changed state');
$repairedArtifactPublication = $publisher->repairArtifactDirectory(
    $publicationContract,
    $artifactDirectory,
    $artifactPublic,
    $artifactState,
);
assertTrue($repairedArtifactPublication['bundle_action'] === 'repaired', 'damaged artifact bundle was not repaired');
assertTrue(
    $repairedArtifactPublication['repair_reason'] === 'core_assets_existing_bundle_tree_mismatch:ui',
    'repair reason mismatch',
);
assertTrue($repairedArtifactPublication['repair_cleanup'] === 'complete', 'successful repair cleanup incomplete');
assertTrue((string) file_get_contents($runtimePath) === $runtimeOriginal, 'repair did not restore exact artifact bytes');
assertTrue((string) file_get_contents($artifactState) === $artifactStateBeforeRepair, 'same-bundle repair changed activation history');
assertTrue(glob($artifactPublic . '/.artifact-v1.stage-*') === [], 'successful repair leaked a stage');
assertTrue(glob($artifactPublic . '/.artifact-v1.repair-backup-*') === [], 'successful repair leaked a backup');
assertTrue(glob($artifactPublic . '/.artifact-v1.repair-failed-*') === [], 'successful repair leaked quarantine');
assertTrue(
    $inspector->inspect($publicationContract, ['ui/distr/runtime.js'], $artifactPublic, $artifactState)['status'] === 'verified',
    'repaired bundle did not recover verification',
);

$artifactMarkerBeforeIdentityDamage = (string) file_get_contents(
    $artifactPublic . '/artifact-v1/.larena-bundle.json',
);
$identityDamagedMarker = json_decode($artifactMarkerBeforeIdentityDamage, true, 512, JSON_THROW_ON_ERROR);
$identityDamagedMarker['sources'][0]['commit'] = str_repeat('0', 40);
file_put_contents(
    $artifactPublic . '/artifact-v1/.larena-bundle.json',
    json_encode($identityDamagedMarker, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
);
$identityDamagedMarkerBytes = (string) file_get_contents($artifactPublic . '/artifact-v1/.larena-bundle.json');
assertFailsWith(
    static fn () => $publisher->repairArtifactDirectory(
        $publicationContract,
        $artifactDirectory,
        $artifactPublic,
        $artifactState,
    ),
    'existing_bundle_source_mismatch:commit',
    'repair replaced a bundle whose exact identity did not match',
);
assertTrue(
    (string) file_get_contents($artifactPublic . '/artifact-v1/.larena-bundle.json') === $identityDamagedMarkerBytes,
    'identity mismatch changed the existing marker',
);
assertTrue((string) file_get_contents($artifactState) === $artifactStateBeforeRepair, 'identity mismatch changed state');
assertTrue(glob($artifactPublic . '/.artifact-v1.stage-*') === [], 'identity mismatch leaked a stage');
assertTrue(glob($artifactPublic . '/.artifact-v1.repair-backup-*') === [], 'identity mismatch leaked a backup');
assertTrue(glob($artifactPublic . '/.artifact-v1.repair-failed-*') === [], 'identity mismatch leaked quarantine');
file_put_contents($artifactPublic . '/artifact-v1/.larena-bundle.json', $artifactMarkerBeforeIdentityDamage);

unlink($runtimePath);
symlink('nested/runtime.css', $runtimePath);
$symlinkInspection = $inspector->inspect(
    $publicationContract,
    ['ui/distr/runtime.js'],
    $artifactPublic,
    $artifactState,
);
assertTrue($symlinkInspection['status'] === 'not_ready', 'symlinked bundle file did not fail inspection');
assertTrue(
    in_array('mount_tree_invalid:ui', $symlinkInspection['missing_or_invalid'], true),
    'symlinked bundle file rejection was not explicit',
);
unlink($runtimePath);
file_put_contents($runtimePath, $runtimeOriginal);

$compressedTamperArtifact = $root . '/artifact-compressed-tamper';
copyTestTree($artifactDirectory, $compressedTamperArtifact);
file_put_contents($compressedTamperArtifact . '/sources/ui.tar.gz', 'tamper', FILE_APPEND);
$artifactStateBeforeFailure = (string) file_get_contents($artifactState);
assertFailsWith(
    static fn () => $publisher->publishArtifactDirectory(
        $publicationContract,
        $compressedTamperArtifact,
        $artifactPublic,
        $artifactState,
    ),
    'compressed_checksum_mismatch',
    'tampered portable archive did not fail closed',
);
assertTrue((string) file_get_contents($artifactState) === $artifactStateBeforeFailure, 'failed import changed state');
assertFailsWith(
    static fn () => $publisher->repairArtifactDirectory(
        $publicationContract,
        $compressedTamperArtifact,
        $artifactPublic,
        $artifactState,
    ),
    'compressed_checksum_mismatch',
    'repair touched the bundle before verifying the portable artifact',
);
assertTrue((string) file_get_contents($runtimePath) === $runtimeOriginal, 'invalid repair artifact changed bundle bytes');
assertTrue((string) file_get_contents($artifactState) === $artifactStateBeforeFailure, 'invalid repair artifact changed state');

$unexpectedBundleEntry = $artifactPublic . '/artifact-v1/unexpected.txt';
file_put_contents($unexpectedBundleEntry, 'unexpected');
assertFailsWith(
    static fn () => $publisher->publishArtifactDirectory(
        $publicationContract,
        $artifactDirectory,
        $artifactPublic,
        $artifactState,
    ),
    'root_shape_invalid',
    'existing immutable bundle accepted an unexpected root entry',
);
assertTrue((string) file_get_contents($artifactState) === $artifactStateBeforeFailure, 'invalid existing bundle changed state');
$rootShapeRepair = $publisher->repairArtifactDirectory(
    $publicationContract,
    $artifactDirectory,
    $artifactPublic,
    $artifactState,
);
assertTrue($rootShapeRepair['bundle_action'] === 'repaired', 'invalid root shape was not explicitly repaired');
assertTrue($rootShapeRepair['repair_cleanup'] === 'complete', 'root-shape repair cleanup incomplete');
assertTrue(!file_exists($unexpectedBundleEntry), 'root-shape repair retained unexpected bundle entry');
assertTrue((string) file_get_contents($artifactState) === $artifactStateBeforeFailure, 'root-shape repair changed activation history');

$outsideRepairTree = $root . '/outside-repair-tree';
mkdir($outsideRepairTree, 0775, true);
file_put_contents($outsideRepairTree . '/sentinel.txt', 'must-survive');
$unexpectedBundleLink = $artifactPublic . '/artifact-v1/unexpected-link';
symlink($outsideRepairTree, $unexpectedBundleLink);
$linkedRootShapeRepair = $publisher->repairArtifactDirectory(
    $publicationContract,
    $artifactDirectory,
    $artifactPublic,
    $artifactState,
);
assertTrue($linkedRootShapeRepair['bundle_action'] === 'repaired', 'linked root-shape damage was not repaired');
assertTrue($linkedRootShapeRepair['repair_cleanup'] === 'complete', 'linked root-shape cleanup incomplete');
assertTrue(is_file($outsideRepairTree . '/sentinel.txt'), 'repair cleanup followed an unexpected symlink');
assertTrue(!file_exists($unexpectedBundleLink) && !is_link($unexpectedBundleLink), 'repair retained unexpected symlink');

$mountPath = $artifactPublic . '/artifact-v1/ui';
$damagedMountPath = $artifactPublic . '/artifact-v1/ui-damaged';
rename($mountPath, $damagedMountPath);
$mountRepair = $publisher->repairArtifactDirectory(
    $publicationContract,
    $artifactDirectory,
    $artifactPublic,
    $artifactState,
);
assertTrue($mountRepair['bundle_action'] === 'repaired', 'missing exact mount was not explicitly repaired');
assertTrue(is_dir($mountPath) && !file_exists($damagedMountPath), 'mount repair did not restore exact root shape');

file_put_contents($runtimePath, 'artifact-runtime-rollback-fixture');
$rollbackFixtureContents = (string) file_get_contents($runtimePath);
$failingStateFile = $root . '/state/' . str_repeat('x', 300);
assertFailsWith(
    static fn () => $publisher->repairArtifactDirectory(
        $publicationContract,
        $artifactDirectory,
        $artifactPublic,
        $failingStateFile,
    ),
    'state_write_failed',
    'repair unexpectedly committed after activation-state write failure',
);
assertTrue(
    (string) file_get_contents($runtimePath) === $rollbackFixtureContents,
    'failed repair did not restore the original damaged bundle',
);
assertTrue((string) file_get_contents($artifactState) === $artifactStateBeforeFailure, 'failed repair changed trusted state');
assertTrue(!file_exists($failingStateFile), 'failed repair leaked activation state');
assertTrue(glob($artifactPublic . '/.artifact-v1.stage-*') === [], 'failed repair leaked a stage');
assertTrue(glob($artifactPublic . '/.artifact-v1.repair-backup-*') === [], 'failed repair leaked a backup');
assertTrue(glob($artifactPublic . '/.artifact-v1.repair-failed-*') === [], 'failed repair leaked quarantine');
$publisher->repairArtifactDirectory($publicationContract, $artifactDirectory, $artifactPublic, $artifactState);
assertTrue((string) file_get_contents($runtimePath) === $runtimeOriginal, 'post-rollback repair did not recover bundle');

$checksumConflict = $artifactContract;
$checksumConflict['sources'][0]['sha256'] = str_repeat('0', 64);
assertFailsWith(
    static fn () => $publisher->buildArtifactDirectory($checksumConflict, $root . '/checksum-conflict'),
    'checksum_conflict',
    'conflicting checksum aliases were accepted',
);
$schemaConflict = $artifactContract;
$schemaConflict['artifact_schema'] = 'larena.test.conflicting_schema.v1';
assertFailsWith(
    static fn () => $publisher->buildArtifactDirectory($schemaConflict, $root . '/schema-conflict'),
    'artifact_schema_conflict',
    'conflicting schema aliases were accepted',
);
$unsafeExpectedPath = $publicationContract;
$unsafeExpectedPath['sources'][0]['tree'] = '../distr';
assertFailsWith(
    static fn () => $publisher->publishArtifactDirectory(
        $unsafeExpectedPath,
        $artifactDirectory,
        $artifactPublic,
        $artifactState,
    ),
    'source_tree_invalid',
    'unsafe expected source path was accepted',
);
assertFailsWith(
    static fn () => $inspector->inspect(
        $publicationContract,
        ['../outside.js'],
        $artifactPublic,
        $artifactState,
    ),
    'requested_file_path_invalid',
    'unsafe requested file path was accepted',
);

$unsafeFixture = $root . '/unsafe-fixture';
$unsafeArtifact = $root . '/unsafe-artifact';
$unsafePublic = $root . '/unsafe-public';
$unsafeState = $root . '/unsafe-state/active.json';
mkdir($unsafeFixture . '/payload', 0775, true);
mkdir($unsafeArtifact . '/sources', 0775, true);
file_put_contents($unsafeFixture . '/payload/file.js', 'unsafe');
$unsafeTar = $unsafeFixture . '/unsafe.tar';
runTestCommand(['tar', '-cf', $unsafeTar, '-s', '#^payload#../escape#', 'payload'], $unsafeFixture);
$unsafeRawHash = hash_file('sha256', $unsafeTar);
$unsafeArchive = $unsafeArtifact . '/sources/unsafe.tar.gz';
file_put_contents($unsafeArchive, gzencode((string) file_get_contents($unsafeTar), 9, ZLIB_ENCODING_GZIP));
$unsafeCompressedHash = hash_file('sha256', $unsafeArchive);
$unsafeContract = [
    'schema' => 'larena.test.unsafe_artifact.v1',
    'publication_profile' => VerifiedAssetBundlePublisher::PUBLICATION_PROFILE,
    'bundle_id' => 'unsafe-v1',
    'sources' => [[
        'commit' => $commit,
        'tree' => 'distr',
        'mount' => 'unsafe',
        'archive_sha256' => $unsafeRawHash,
        'files' => 1,
    ]],
];
file_put_contents(
    $unsafeArtifact . '/manifest.json',
    json_encode([
        'schema' => $unsafeContract['schema'],
        'publication_profile' => $unsafeContract['publication_profile'],
        'bundle_id' => $unsafeContract['bundle_id'],
        'sources' => [[
            ...$unsafeContract['sources'][0],
            'archive' => 'sources/unsafe.tar.gz',
            'compressed_sha256' => $unsafeCompressedHash,
            'tree_fingerprint_sha256' => str_repeat('0', 64),
            'object_format' => 'sha1',
            'file_count' => 1,
        ]],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
);
assertFailsWith(
    static fn () => $publisher->publishArtifactDirectory(
        $unsafeContract,
        $unsafeArtifact,
        $unsafePublic,
        $unsafeState,
    ),
    'archive_entry_unsafe',
    'unsafe archive entry was extracted',
);
assertTrue(!file_exists($unsafePublic . '/escape'), 'unsafe archive escaped the staging directory');
assertTrue(!is_file($unsafeState), 'unsafe archive changed activation state');

$artifactContractV2 = $artifactContract;
$artifactContractV2['bundle_id'] = 'artifact-v2';
$artifactDirectoryV2 = $root . '/artifact-v2';
$publisher->buildArtifactDirectory($artifactContractV2, $artifactDirectoryV2);
$publicationContractV2 = $artifactContractV2;
unset($publicationContractV2['sources'][0]['repository']);
$secondArtifactPublication = $publisher->publishArtifactDirectory(
    $publicationContractV2,
    $artifactDirectoryV2,
    $artifactPublic,
    $artifactState,
);
assertTrue($secondArtifactPublication['previous_bundle'] === 'artifact-v1', 'artifact rollback target not retained');
$artifactRollback = $publisher->rollback($artifactPublic, $artifactState);
assertTrue($artifactRollback['active_bundle'] === 'artifact-v1', 'artifact rollback did not restore prior bundle');
assertTrue(
    $inspector->inspect($publicationContract, $requestedFiles, $artifactPublic, $artifactState)['status'] === 'verified',
    'rolled back artifact did not inspect as verified',
);

echo "VerifiedAssetBundlePublisherTest passed\n";
