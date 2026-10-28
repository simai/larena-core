<?php

declare(strict_types=1);

namespace Larena\Core\Process;

/**
 * Immutable, shell-free description of one external child process.
 *
 * The program and its arguments are an argv list and never a shell string, so `;`, `$()` or quotes reach the child
 * literally. The child sees exactly the given environment: nothing of the parent's environment is inherited, so a
 * secret reaches it only when the caller passes it here. Standard input is either the given bytes or a stream the
 * executor reads; standard output can be streamed to a sink instead of being held in memory.
 */
final readonly class ProcessSpecification
{
    public const int DEFAULT_OUTPUT_LIMIT_BYTES = 65_536;

    /**
     * @param list<string> $argv the program path first, then its arguments
     * @param array<string, string> $environment the whole environment of the child
     * @param string|resource|null $stdin bytes to write, a readable stream, or null for an immediately closed stdin
     * @param resource|null $stdoutSink a writable stream that receives stdout up to the limit
     * @param int $stdoutLimitBytes stdout bytes kept; one more byte stops the child and marks stdout truncated
     * @param int $stderrLimitBytes stderr bytes kept; one more byte stops the child and marks stderr truncated
     * @param float $timeoutSeconds wall time before the child is stopped
     * @param float $terminationGraceSeconds time between SIGTERM and SIGKILL when the child is stopped
     * @param bool $captureStdout false keeps stdout out of the result, for payloads that go only to the sink
     */
    public function __construct(
        public array $argv,
        public string $workingDirectory,
        public array $environment = [],
        public mixed $stdin = null,
        public mixed $stdoutSink = null,
        public int $stdoutLimitBytes = self::DEFAULT_OUTPUT_LIMIT_BYTES,
        public int $stderrLimitBytes = self::DEFAULT_OUTPUT_LIMIT_BYTES,
        public float $timeoutSeconds = 30.0,
        public float $terminationGraceSeconds = 1.0,
        public bool $captureStdout = true,
    ) {
        if ($argv === []) {
            throw new ProcessRejected('process_argv_invalid', 'Process argv must be a non-empty list.');
        }
        foreach ($argv as $argument) {
            if ($argument === '' || str_contains($argument, "\0")) {
                throw new ProcessRejected('process_argv_invalid', 'Process argv contains an invalid argument.');
            }
        }

        if ($workingDirectory === '' || !str_starts_with($workingDirectory, '/') || str_contains($workingDirectory, "\0")) {
            throw new ProcessRejected('process_working_directory_invalid', 'Process working directory must be absolute.');
        }

        foreach ($environment as $name => $value) {
            if ($name === '' || str_contains($name, '=') || str_contains($name, "\0") || str_contains($value, "\0")) {
                throw new ProcessRejected('process_environment_invalid', 'Process environment contains an invalid entry.');
            }
        }

        if ($stdin !== null && !is_string($stdin) && !self::isStream($stdin)) {
            throw new ProcessRejected('process_stdin_invalid', 'Process stdin must be bytes or a stream.');
        }

        if ($stdoutSink !== null && !self::isStream($stdoutSink)) {
            throw new ProcessRejected('process_stdout_sink_invalid', 'Process stdout sink must be a stream.');
        }

        if ($stdoutLimitBytes < 0 || $stderrLimitBytes < 0) {
            throw new ProcessRejected('process_limit_invalid', 'Process output limits must not be negative.');
        }

        if ($timeoutSeconds <= 0.0 || $terminationGraceSeconds <= 0.0) {
            throw new ProcessRejected('process_timeout_invalid', 'Process timeouts must be positive.');
        }
    }

    private static function isStream(mixed $value): bool
    {
        return is_resource($value) && get_resource_type($value) === 'stream';
    }
}
