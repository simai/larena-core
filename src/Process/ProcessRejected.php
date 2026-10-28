<?php

declare(strict_types=1);

namespace Larena\Core\Process;

use RuntimeException;

/**
 * A process specification was refused before anything was started. The reason code is part of the frozen contract;
 * the message is not. Reason codes: `process_argv_invalid`, `process_working_directory_invalid`,
 * `process_environment_invalid`, `process_stdin_invalid`, `process_stdout_sink_invalid`, `process_limit_invalid`,
 * `process_timeout_invalid`.
 */
final class ProcessRejected extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        string $message = '',
    ) {
        parent::__construct($message === '' ? 'Process refused: '.$reasonCode : $message);
    }
}
