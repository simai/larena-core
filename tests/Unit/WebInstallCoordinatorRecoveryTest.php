<?php

declare(strict_types=1);

use Larena\Core\WebInstall\WebInstallCoordinator;
use Larena\Core\WebInstall\WebInstallDatabaseConfiguration;
use Larena\Core\WebInstall\WebInstallDatabaseLifecycle;
use Larena\Core\WebInstall\WebInstallException;
use Larena\Core\WebInstall\WebInstallPreflightReport;
use Larena\Core\WebInstall\WebInstallStateStore;

require_once __DIR__.'/../../vendor/autoload.php';

final readonly class FakeWebInstallDatabaseLifecycle implements WebInstallDatabaseLifecycle
{
    public function __construct(private string $path, private bool $failPrepare = false)
    {
    }

    public function inspect(WebInstallDatabaseConfiguration $database): WebInstallPreflightReport
    {
        return new WebInstallPreflightReport([['id' => 'fake.mysql', 'passed' => true, 'reason' => 'ready']]);
    }

    public function prepare(WebInstallDatabaseConfiguration $database, string $operationId): array
    {
        if ($this->failPrepare) {
            throw new WebInstallException('fake_prepare_failed');
        }
        $ledger = hash('sha256', '["001_core","002_auth"]');
        file_put_contents($this->path, json_encode([
            'operation_sha256' => hash('sha256', $operationId),
            'migration_ledger_sha256' => $ledger,
            'administrator_count' => 0,
            'site_count' => 0,
            'starter_page_count' => 0,
        ], JSON_THROW_ON_ERROR));
        return ['migration_count' => 2, 'migration_ledger_sha256' => $ledger];
    }

    public function isPrepared(WebInstallDatabaseConfiguration $database, string $operationId, ?string $migrationLedgerSha256): bool
    {
        if (!is_file($this->path)) {
            return false;
        }
        $value = json_decode((string) file_get_contents($this->path), true, 8, JSON_THROW_ON_ERROR);
        return is_array($value)
            && hash_equals((string) ($value['operation_sha256'] ?? ''), hash('sha256', $operationId))
            && $migrationLedgerSha256 !== null
            && hash_equals((string) ($value['migration_ledger_sha256'] ?? ''), $migrationLedgerSha256);
    }

    public function rollback(WebInstallDatabaseConfiguration $database): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }
}

function expectRecovery(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$providerSource = (string) file_get_contents(__DIR__.'/../../src/Providers/CoreServiceProvider.php');
expectRecovery(str_contains($providerSource, "environment(['local', 'testing'])"), 'fault hook is not environment-bounded');
expectRecovery(str_contains($providerSource, "getenv('LARENA_CORE_WEB_INSTALL_TEST_FAULTS_ENABLED')"), 'fault hook is not explicitly enabled');
expectRecovery(str_contains($providerSource, "getenv('LARENA_CORE_WEB_INSTALL_TEST_FAULT_CHECKPOINT')"), 'fault checkpoint is not process-bound');

function coordinator(string $root, ?string $fault = null, bool $failPrepare = false): WebInstallCoordinator
{
    return new WebInstallCoordinator(
        new FakeWebInstallDatabaseLifecycle($root.'/database-state.json', $failPrepare),
        new WebInstallStateStore($root.'/state', 'test-signing-key'),
        'test-signing-key',
        $fault === null ? null : static function (string $checkpoint) use ($fault): void {
            if ($checkpoint === $fault) {
                exit(91);
            }
        },
    );
}

function databaseConfiguration(): WebInstallDatabaseConfiguration
{
    return new WebInstallDatabaseConfiguration('127.0.0.1', 3306, 'marker_owned', 'owner', 'runtime-only');
}

function makeOwnedRoot(string $label): string
{
    $root = sys_get_temp_dir().'/larena-web-install-'.$label.'-'.bin2hex(random_bytes(8));
    mkdir($root, 0700, true);
    file_put_contents($root.'/.larena-owned', "larena.web-install-test-owned.v1\n");
    return $root;
}

function cleanupOwnedRoot(string $root): void
{
    expectRecovery(str_starts_with($root, sys_get_temp_dir().'/larena-web-install-'), 'cleanup path escaped test prefix');
    expectRecovery(trim((string) file_get_contents($root.'/.larena-owned')) === 'larena.web-install-test-owned.v1', 'cleanup marker missing');
    foreach ([
        $root.'/database-state.json',
        $root.'/state/database.candidate.json',
        $root.'/state/database.json',
        $root.'/state/state.json',
        $root.'/state/install.lock',
    ] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    if (is_dir($root.'/state')) {
        rmdir($root.'/state');
    }
    unlink($root.'/.larena-owned');
    rmdir($root);
}

if (($argv[1] ?? null) === '--child') {
    $root = (string) ($argv[2] ?? '');
    $fault = (string) ($argv[3] ?? '');
    coordinator($root, $fault)->apply('session', str_repeat('c', 32), databaseConfiguration());
    exit(0);
}

foreach ([
    'before_configuration_activation',
    'after_configuration_activation',
    'before_completed_state_persistence',
    'after_completed_state_persistence',
] as $fault) {
    $root = makeOwnedRoot('recovery');
    coordinator($root)->claim('session', str_repeat('c', 32));
    $command = [PHP_BINARY, __FILE__, '--child', $root, $fault];
    $process = proc_open($command, [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    expectRecovery(is_resource($process), 'child process did not start');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    expectRecovery($exit === 91, $fault.' did not terminate at the requested process-death point: '.$stdout.$stderr);

    $availability = coordinator($root)->availability();
    expectRecovery($availability === ['status' => 'closed', 'reason' => 'web_install_completed'], $fault.' did not resume to completed');
    $state = (new WebInstallStateStore($root.'/state', 'test-signing-key'))->read();
    expectRecovery(($state['status'] ?? null) === 'completed', $fault.' state is not completed');
    expectRecovery(($state['migration_count'] ?? null) === 2, $fault.' lost migration count');
    $database = json_decode((string) file_get_contents($root.'/database-state.json'), true, 8, JSON_THROW_ON_ERROR);
    expectRecovery(($database['administrator_count'] ?? -1) === 0, 'web install created an administrator');
    expectRecovery(($database['site_count'] ?? -1) === 0, 'web install created a site');
    expectRecovery(($database['starter_page_count'] ?? -1) === 0, 'web install created starter content');
    expectRecovery(!str_contains(json_encode($state, JSON_THROW_ON_ERROR), 'runtime-only'), 'state receipt leaked database secret');
    $repeated = false;
    try {
        coordinator($root)->apply('session', str_repeat('c', 32), databaseConfiguration());
    } catch (WebInstallException $exception) {
        $repeated = $exception->getMessage() === 'web_install_completed';
    }
    expectRecovery($repeated, $fault.' repeated submit did not fail closed');
    cleanupOwnedRoot($root);
}

$rollbackRoot = makeOwnedRoot('rollback');
coordinator($rollbackRoot)->claim('session', str_repeat('c', 32));
$process = proc_open(
    [PHP_BINARY, __FILE__, '--child', $rollbackRoot, 'before_configuration_activation'],
    [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
    $pipes,
);
expectRecovery(is_resource($process), 'rollback child process did not start');
foreach ($pipes as $pipe) {
    fclose($pipe);
}
expectRecovery(proc_close($process) === 91, 'rollback child did not reach fault point');
$databaseState = json_decode((string) file_get_contents($rollbackRoot.'/database-state.json'), true, 8, JSON_THROW_ON_ERROR);
$databaseState['operation_sha256'] = str_repeat('0', 64);
file_put_contents($rollbackRoot.'/database-state.json', json_encode($databaseState, JSON_THROW_ON_ERROR));
$availability = coordinator($rollbackRoot)->availability();
expectRecovery($availability === ['status' => 'available', 'reason' => 'web_install_interrupted_apply_rolled_back'], 'invalid prepared marker did not roll back');
expectRecovery(!is_file($rollbackRoot.'/database-state.json'), 'rollback retained database marker');
$rollbackStore = new WebInstallStateStore($rollbackRoot.'/state', 'test-signing-key');
expectRecovery(!$rollbackStore->candidateExists() && !$rollbackStore->configurationExists(), 'rollback retained private configuration');
expectRecovery(($rollbackStore->read()['status'] ?? null) === 'ready', 'rollback did not restore ready state');
cleanupOwnedRoot($rollbackRoot);

$failedRoot = makeOwnedRoot('failed');
coordinator($failedRoot)->claim('session', str_repeat('c', 32));
$failed = false;
try {
    coordinator($failedRoot, null, true)->apply('session', str_repeat('c', 32), databaseConfiguration());
} catch (WebInstallException $exception) {
    $failed = $exception->getMessage() === 'fake_prepare_failed';
}
expectRecovery($failed, 'failed prepare did not fail closed');
expectRecovery((new WebInstallStateStore($failedRoot.'/state', 'test-signing-key'))->read()['status'] === 'ready', 'failed prepare did not restore ready state');
cleanupOwnedRoot($failedRoot);

$sessionRoot = makeOwnedRoot('session');
coordinator($sessionRoot)->claim('session', str_repeat('c', 32));
$invalidSession = false;
try {
    coordinator($sessionRoot)->apply('stale-session', str_repeat('c', 32), databaseConfiguration());
} catch (WebInstallException $exception) {
    $invalidSession = $exception->getMessage() === 'web_install_capability_invalid';
}
expectRecovery($invalidSession, 'stale session did not fail closed');
$sessionStore = new WebInstallStateStore($sessionRoot.'/state', 'test-signing-key');
$expiredState = $sessionStore->read();
$sessionStore->write([...$expiredState, 'expires_at' => time() - 1]);
$expired = false;
try {
    coordinator($sessionRoot)->apply('session', str_repeat('c', 32), databaseConfiguration());
} catch (WebInstallException $exception) {
    $expired = $exception->getMessage() === 'web_install_capability_expired';
}
expectRecovery($expired, 'expired capability did not fail closed');
cleanupOwnedRoot($sessionRoot);

$concurrentRoot = makeOwnedRoot('concurrent');
coordinator($concurrentRoot)->claim('session', str_repeat('c', 32));
$concurrent = false;
$concurrentStore = new WebInstallStateStore($concurrentRoot.'/state', 'test-signing-key');
$concurrentStore->withLock(function () use ($concurrentRoot, &$concurrent): void {
    try {
        coordinator($concurrentRoot)->apply('session', str_repeat('c', 32), databaseConfiguration());
    } catch (WebInstallException $exception) {
        $concurrent = $exception->getMessage() === 'web_install_concurrent_apply';
    }
});
expectRecovery($concurrent, 'concurrent submit did not fail closed');
cleanupOwnedRoot($concurrentRoot);

echo "WebInstallCoordinatorRecoveryTest passed.\n";
