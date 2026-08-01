<?php

declare(strict_types=1);

return [
    'web_install' => [
        'test_faults_enabled' => env('LARENA_CORE_WEB_INSTALL_TEST_FAULTS_ENABLED', false),
        'test_fault_checkpoint' => env('LARENA_CORE_WEB_INSTALL_TEST_FAULT_CHECKPOINT'),
    ],
    'operation_runtime' => [
        'default_execution_mode' => 'sync',
        'fail_closed_status' => 'invalid',
        'supported_execution_modes' => [
            'sync',
            'queued',
            'scheduled',
            'denied',
        ],
        'supported_decision_statuses' => [
            'allowed',
            'denied',
            'capability_locked',
            'invalid',
        ],
        'production_executor_enabled' => false,
    ],
];
