<?php

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Larena\Core\Console\Support\CommandReportPresenter;

$passed = CommandReportPresenter::summaryLines('Larena test', [
    'status' => 'passed',
    'evidence_path' => '/tmp/evidence.json',
    'checks' => [
        'required_packages' => ['status' => 'passed'],
    ],
]);

if (!in_array('Status: PASS', $passed, true)) {
    fwrite(STDERR, 'Passed report must render PASS status.' . PHP_EOL);
    exit(1);
}

if (!in_array('PASS                     required_packages', $passed, true)) {
    fwrite(STDERR, 'Passed check must render as PASS.' . PHP_EOL);
    exit(1);
}

$degraded = CommandReportPresenter::summaryLines('Larena packages', [
    'status' => 'missing',
    'reason' => 'package_registry_file_missing',
]);

if (!in_array('Status: DEGRADED_ACTION_REQUIRED', $degraded, true)) {
    fwrite(STDERR, 'Missing package registry must render degraded action required.' . PHP_EOL);
    exit(1);
}

$guard = CommandReportPresenter::summaryLines('Larena install guard', [
    'status' => 'blocked',
    'reason' => 'actual_install_requires_launch_record_and_guarded_transition',
    'transition_required' => 'install_apply_launch_record',
    'safe_command' => 'php artisan larena:install --dry-run',
]);

if (!in_array('Status: EXPECTED_GUARD', $guard, true)) {
    fwrite(STDERR, 'Install without launch record must render expected guard.' . PHP_EOL);
    exit(1);
}

if (!in_array('EXPECTED_GUARD: install apply requires a launch record and explicit confirmation.', $guard, true)) {
    fwrite(STDERR, 'Expected guard summary must explain the launch record requirement.' . PHP_EOL);
    exit(1);
}

$database = CommandReportPresenter::summaryLines('Larena doctor', [
    'status' => 'passed',
    'checks' => [
        'database_connection' => [
            'status' => 'degraded',
            'reason' => 'database_credentials_rejected',
            'safe_message' => 'Database credentials were rejected. Password is not printed; check local .env values.',
            'action' => 'Check DB_CONNECTION and DB_USERNAME in .env.',
        ],
    ],
]);

if (!in_array('DEGRADED_ACTION_REQUIRED database_connection (database_credentials_rejected)', $database, true)) {
    fwrite(STDERR, 'Database credentials failure must render as degraded action required.' . PHP_EOL);
    exit(1);
}

if (!in_array('                         Database credentials were rejected. Password is not printed; check local .env values.', $database, true)) {
    fwrite(STDERR, 'Database credentials failure must render safe message without secrets.' . PHP_EOL);
    exit(1);
}

if (!in_array('                         action: Check DB_CONNECTION and DB_USERNAME in .env.', $database, true)) {
    fwrite(STDERR, 'Database credentials failure must render next action.' . PHP_EOL);
    exit(1);
}

echo 'CommandReportPresenterTest passed.' . PHP_EOL;
