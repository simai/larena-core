<?php

declare(strict_types=1);

namespace Larena\Core\Gateway;

/**
 * What a gateway adapter declares about the external gateway it talks to: its id, the protocol and protocol version
 * it speaks, and the only commands it may be asked to run. The runner refuses any other command and any answer in
 * another protocol or version.
 */
final readonly class GatewayManifest
{
    public const string IDENTIFIER_PATTERN = '/\A[a-z0-9][a-z0-9._-]{0,127}\z/D';
    public const string COMMAND_PATTERN = '/\A[a-z0-9][a-z0-9._:-]{0,127}\z/D';

    /** @param list<string> $commands */
    public function __construct(
        public string $id,
        public string $protocol,
        public int $protocolVersion,
        public array $commands,
    ) {
        if (preg_match(self::IDENTIFIER_PATTERN, $id) !== 1) {
            throw new GatewayRejected('gateway_manifest_invalid', 'Gateway id is invalid.');
        }
        if (preg_match(self::IDENTIFIER_PATTERN, $protocol) !== 1) {
            throw new GatewayRejected('gateway_manifest_invalid', 'Gateway protocol name is invalid.');
        }
        if ($protocolVersion < 1) {
            throw new GatewayRejected('gateway_manifest_invalid', 'Gateway protocol version must be positive.');
        }
        if ($commands === [] || count(array_unique($commands)) !== count($commands)) {
            throw new GatewayRejected('gateway_manifest_invalid', 'Gateway commands must be a non-empty list without duplicates.');
        }
        foreach ($commands as $command) {
            if (preg_match(self::COMMAND_PATTERN, $command) !== 1) {
                throw new GatewayRejected('gateway_manifest_invalid', 'Gateway command name is invalid.');
            }
        }
    }

    public function allows(string $command): bool
    {
        return in_array($command, $this->commands, true);
    }

    /** @return array{id: string, protocol: string, protocol_version: int, commands: list<string>} */
    public function toArray(): array
    {
        $commands = $this->commands;
        sort($commands, SORT_STRING);

        return [
            'id' => $this->id,
            'protocol' => $this->protocol,
            'protocol_version' => $this->protocolVersion,
            'commands' => $commands,
        ];
    }
}
