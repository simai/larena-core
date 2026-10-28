<?php

declare(strict_types=1);

namespace Larena\Core\Process;

use HashContext;

/**
 * One output stream of a running child: counts every byte, keeps and hashes the bytes within the limit, copies them
 * to the sink, and reports the first reason the child must be stopped.
 *
 * @internal used by ProcOpenProcessExecutor only
 */
final class OutputCapture
{
    public int $bytes = 0;
    public string $captured = '';
    public bool $truncated = false;
    private HashContext $hash;

    /** @param resource|null $sink */
    public function __construct(
        private readonly int $limitBytes,
        private readonly bool $capture,
        private readonly mixed $sink,
        private readonly ProcessStopReason $overflowReason,
    ) {
        $this->hash = hash_init('sha256');
    }

    /** @return ?ProcessStopReason the reason to stop the child, or null to let it run */
    public function accept(string $chunk): ?ProcessStopReason
    {
        $room = max(0, $this->limitBytes - $this->bytes);
        $this->bytes += strlen($chunk);
        $kept = strlen($chunk) > $room ? substr($chunk, 0, $room) : $chunk;
        $stop = null;

        if ($kept !== '') {
            hash_update($this->hash, $kept);
            if ($this->sink !== null && !$this->writeAll($this->sink, $kept)) {
                $stop = ProcessStopReason::StdoutSinkFailed;
            }
            if ($this->capture) {
                $this->captured .= $kept;
            }
        }

        if (strlen($kept) < strlen($chunk)) {
            $this->truncated = true;
            $stop ??= $this->overflowReason;
        }

        return $stop;
    }

    public function sha256(): string
    {
        return hash_final(hash_copy($this->hash));
    }

    /** @param resource $stream */
    private function writeAll(mixed $stream, string $payload): bool
    {
        $offset = 0;
        $length = strlen($payload);
        while ($offset < $length) {
            $written = @fwrite($stream, substr($payload, $offset));
            if ($written === false || $written === 0) {
                return false;
            }
            $offset += $written;
        }

        return true;
    }
}
