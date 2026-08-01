<?php

declare(strict_types=1);

use Larena\Core\WebInstall\WebInstallException;
use Larena\Core\WebInstall\WebInstallStateStore;

require_once __DIR__.'/../../vendor/autoload.php';

$root = sys_get_temp_dir().'/larena-web-install-state-'.bin2hex(random_bytes(8));
$store = new WebInstallStateStore($root, 'test-signing-key');
$store->write(['status' => 'ready', 'checkpoint' => 'capability_claimed']);
$state = $store->read();
assert(($state['status'] ?? null) === 'ready');
assert(($state['checkpoint'] ?? null) === 'capability_claimed');
assert(!str_contains((string) file_get_contents($store->statePath()), 'test-signing-key'));

$store->writeCandidate([
    'connection' => 'mysql', 'host' => '127.0.0.1', 'port' => 3306,
    'database' => 'owned_schema', 'username' => 'owner', 'password' => 'runtime-only',
]);
assert(($store->readCandidate()['database'] ?? null) === 'owned_schema');
$store->activateCandidate();
assert(is_file($store->configurationPath()));
assert(!is_file($store->candidatePath()));
$store->discardConfiguration();
assert(!is_file($store->configurationPath()));

$tampered = json_decode((string) file_get_contents($store->statePath()), true, 32, JSON_THROW_ON_ERROR);
$tampered['status'] = 'completed';
file_put_contents($store->statePath(), json_encode($tampered, JSON_THROW_ON_ERROR));
$failedClosed = false;
try {
    $store->read();
} catch (WebInstallException $exception) {
    $failedClosed = $exception->getMessage() === 'web_install_state_tampered';
}
assert($failedClosed);

foreach ([$store->candidatePath(), $store->configurationPath(), $store->statePath(), $root.'/install.lock'] as $path) {
    if (is_file($path)) {
        unlink($path);
    }
}
rmdir($root);

echo "WebInstallStateStoreTest passed.\n";
