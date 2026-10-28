<?php

declare(strict_types=1);

namespace Larena\Core\Gateway;

/** One command the caller asks a gateway to run, with its arguments as plain data. */
final readonly class GatewayRequest
{
    /** @param array<string, mixed> $arguments */
    public function __construct(
        public string $command,
        public array $arguments = [],
    ) {
        if (preg_match(GatewayManifest::COMMAND_PATTERN, $command) !== 1) {
            throw new GatewayRejected('gateway_request_invalid', 'Gateway command name is invalid.');
        }
    }
}
