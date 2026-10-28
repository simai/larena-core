<?php

declare(strict_types=1);

namespace Larena\Core\Process;

/**
 * What one child process did. Byte counts are everything the child wrote, so a count above the limit shows by how
 * much it overflowed; the captured output and its digest cover only the bytes kept within the limit.
 */
final readonly class ProcessResult
{
    /**
     * @param bool $started false when the child could not be spawned at all
     * @param ?int $exitCode null when the child was killed by a signal or could not be reaped
     * @param ?ProcessStopReason $stopReason the first reason the executor stopped the child, null when it ended alone
     * @param bool $stdinComplete false when the child ended before every stdin byte was written
     */
    public function __construct(
        public bool $started,
        public ?int $pid,
        public ?int $exitCode,
        public bool $signaled,
        public bool $timedOut,
        public ?ProcessStopReason $stopReason,
        public bool $stdinComplete,
        public string $stdout,
        public string $stderr,
        public int $stdoutBytes,
        public int $stderrBytes,
        public bool $stdoutTruncated,
        public bool $stderrTruncated,
        public string $stdoutSha256,
        public string $stderrSha256,
        public float $durationSeconds,
    ) {
    }

    /** The child was not spawned; nothing ran. */
    public static function notStarted(): self
    {
        $empty = hash('sha256', '');

        return new self(false, null, null, false, false, null, false, '', '', 0, 0, false, false, $empty, $empty, 0.0);
    }

    /** A child that ended on its own with this exit code and output, for fakes and fixtures. */
    public static function finished(int $exitCode, string $stdout = '', string $stderr = '', float $durationSeconds = 0.0): self
    {
        return new self(
            started: true,
            pid: null,
            exitCode: $exitCode,
            signaled: false,
            timedOut: false,
            stopReason: null,
            stdinComplete: true,
            stdout: $stdout,
            stderr: $stderr,
            stdoutBytes: strlen($stdout),
            stderrBytes: strlen($stderr),
            stdoutTruncated: false,
            stderrTruncated: false,
            stdoutSha256: hash('sha256', $stdout),
            stderrSha256: hash('sha256', $stderr),
            durationSeconds: $durationSeconds,
        );
    }

    /** The child ran to its own end, was not signaled, fed all its input and exited with zero. */
    public function successful(): bool
    {
        return $this->started
            && $this->stopReason === null
            && !$this->signaled
            && $this->stdinComplete
            && $this->exitCode === 0;
    }

    /** @return array<string, mixed> a log-safe summary without the output bytes */
    public function summary(): array
    {
        return [
            'started' => $this->started,
            'exit_code' => $this->exitCode,
            'signaled' => $this->signaled,
            'timed_out' => $this->timedOut,
            'stop_reason' => $this->stopReason?->value,
            'stdin_complete' => $this->stdinComplete,
            'stdout_bytes' => $this->stdoutBytes,
            'stderr_bytes' => $this->stderrBytes,
            'stdout_truncated' => $this->stdoutTruncated,
            'stderr_truncated' => $this->stderrTruncated,
            'stdout_sha256' => $this->stdoutSha256,
            'stderr_sha256' => $this->stderrSha256,
            'duration_seconds' => $this->durationSeconds,
        ];
    }
}
