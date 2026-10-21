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
    // SitePack archives are streamed; these bound hostile or broken archives only.
    'sitepack' => [
        'max_total_bytes' => env('LARENA_SITEPACK_MAX_TOTAL_BYTES', 2_147_483_648),
        'max_entries' => env('LARENA_SITEPACK_MAX_ENTRIES', 200_000),
        'max_line_bytes' => env('LARENA_SITEPACK_MAX_LINE_BYTES', 1_048_576),
        'max_json_depth' => env('LARENA_SITEPACK_MAX_JSON_DEPTH', 64),
        'max_expansion_ratio' => env('LARENA_SITEPACK_MAX_EXPANSION_RATIO', 200),
        'max_document_bytes' => env('LARENA_SITEPACK_MAX_DOCUMENT_BYTES', 67_108_864),
    ],
];
