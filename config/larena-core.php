<?php

declare(strict_types=1);

return [
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
