<?php

declare(strict_types=1);

namespace Larena\Core\Gateway;

use JsonException;

/**
 * What a gateway answered. The answer names the protocol and version it was written in; the runner compares them
 * with the adapter's manifest before the caller sees the payload.
 */
final readonly class GatewayAnswer
{
    /** @param array<string, mixed> $payload everything in the answer besides the protocol fields */
    public function __construct(
        public string $protocol,
        public int $protocolVersion,
        public array $payload = [],
    ) {
    }

    /**
     * Reads the common answer shape: one JSON object with `protocol` and `protocol_version`, the rest is payload.
     *
     * @param int<1, max> $maxDepth
     */
    public static function fromJson(string $json, int $maxDepth = 64): self
    {
        try {
            $document = json_decode($json, true, $maxDepth, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new GatewayRejected('gateway_answer_invalid', 'Gateway answer is not JSON.', previous: $exception);
        }

        if (!is_array($document) || array_is_list($document)) {
            throw new GatewayRejected('gateway_answer_invalid', 'Gateway answer is not a JSON object.');
        }

        $protocol = $document['protocol'] ?? null;
        $version = $document['protocol_version'] ?? null;
        if (!is_string($protocol) || !is_int($version)) {
            throw new GatewayRejected('gateway_answer_invalid', 'Gateway answer does not name its protocol and version.');
        }
        unset($document['protocol'], $document['protocol_version']);

        /** @var array<string, mixed> $document */
        return new self($protocol, $version, $document);
    }
}
