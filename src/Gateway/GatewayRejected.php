<?php

declare(strict_types=1);

namespace Larena\Core\Gateway;

use Larena\Core\Process\ProcessResult;
use RuntimeException;
use Throwable;

/**
 * A gateway call was refused. The reason code is part of the frozen contract; the message is not. Reason codes:
 * `gateway_manifest_invalid`, `gateway_request_invalid`, `gateway_command_not_allowed`, `gateway_process_failed`,
 * `gateway_answer_invalid`, `gateway_protocol_mismatch`. When the gateway process ran, its result is attached for
 * diagnostics; its output may hold data the gateway did not mean to show, so log only its summary.
 */
final class GatewayRejected extends RuntimeException
{
    public function __construct(
        public readonly string $reasonCode,
        string $message = '',
        public readonly ?ProcessResult $process = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message === '' ? 'Gateway refused: '.$reasonCode : $message, 0, $previous);
    }
}
