<?php

declare(strict_types=1);

namespace Larena\Core\Process;

use Throwable;

/**
 * Runs one directly owned child with proc_open(argv) and no shell.
 *
 * Stdin, stdout and stderr are pumped without blocking in one loop, so a child that fills one pipe while waiting on
 * another cannot deadlock the parent. A child that runs past its timeout, overflows an output limit or loses its
 * stdin or stdout sink gets SIGTERM, then SIGKILL after the grace time, and is always reaped. Only the direct child is
 * controlled; its own descendants are outside this contract.
 */
final class ProcOpenProcessExecutor implements ProcessExecutor
{
    private const int CHUNK_BYTES = 65_536;
    private const int POLL_MICROSECONDS = 20_000;

    /** @var array<int, true> */
    private array $ownedProcessIds = [];

    public function run(ProcessSpecification $specification): ProcessResult
    {
        $pipes = [];
        $startedAt = hrtime(true);

        try {
            $process = @proc_open(
                $specification->argv,
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                $specification->workingDirectory,
                $specification->environment,
                ['bypass_shell' => true, 'suppress_errors' => true],
            );
        } catch (Throwable) {
            $process = false;
        }

        if (!is_resource($process)) {
            return ProcessResult::notStarted();
        }

        $pid = proc_get_status($process)['pid'];
        if ($pid > 0) {
            $this->ownedProcessIds[$pid] = true;
        }

        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }

        $stdout = new OutputCapture(
            $specification->stdoutLimitBytes,
            $specification->captureStdout,
            $specification->stdoutSink,
            ProcessStopReason::StdoutLimit,
        );
        $stderr = new OutputCapture($specification->stderrLimitBytes, true, null, ProcessStopReason::StderrLimit);

        // A string stdin is buffered whole up front; a stream is read in chunks as the child consumes it.
        $stdinSource = is_string($specification->stdin) ? null : $specification->stdin;
        $stdinBuffer = is_string($specification->stdin) ? $specification->stdin : '';
        $stdinEof = $stdinSource === null;
        $stdinFailed = false;
        $stdinOpen = isset($pipes[0]) && is_resource($pipes[0]);
        $stdoutOpen = isset($pipes[1]) && is_resource($pipes[1]);
        $stderrOpen = isset($pipes[2]) && is_resource($pipes[2]);

        if ($stdinOpen && $specification->stdin === null) {
            fclose($pipes[0]);
            $stdinOpen = false;
        }

        $stopReason = null;
        $timedOut = false;
        $signaled = false;
        $exitCode = null;
        $terminationStartedAt = null;
        $killSent = false;

        try {
            while (true) {
                $now = hrtime(true);

                if ($stopReason === null && ($now - $startedAt) / 1_000_000_000 >= $specification->timeoutSeconds) {
                    $stopReason = ProcessStopReason::Timeout;
                    $timedOut = true;
                }

                if ($stopReason !== null && $terminationStartedAt === null) {
                    $terminationStartedAt = $now;
                    @proc_terminate($process, 15);
                }

                if (
                    !$killSent
                    && $terminationStartedAt !== null
                    && ($now - $terminationStartedAt) / 1_000_000_000 >= $specification->terminationGraceSeconds
                ) {
                    @proc_terminate($process, 9);
                    $killSent = true;
                }

                if ($stdinOpen && !$stdinEof && $stdinSource !== null && strlen($stdinBuffer) < self::CHUNK_BYTES) {
                    $chunk = fread($stdinSource, self::CHUNK_BYTES - strlen($stdinBuffer));
                    if ($chunk === false) {
                        $stopReason ??= ProcessStopReason::StdinReadFailed;
                        $stdinFailed = true;
                    } elseif ($chunk === '') {
                        $stdinEof = feof($stdinSource);
                    } else {
                        $stdinBuffer .= $chunk;
                    }
                }

                $read = [];
                if ($stdoutOpen) {
                    $read[] = $pipes[1];
                }
                if ($stderrOpen) {
                    $read[] = $pipes[2];
                }
                $write = [];
                if ($stdinOpen && $stdinBuffer !== '') {
                    $write[] = $pipes[0];
                }
                $except = null;

                if ($read !== [] || $write !== []) {
                    if (@stream_select($read, $write, $except, 0, self::POLL_MICROSECONDS) === false) {
                        $read = [];
                        $write = [];
                    }
                } else {
                    usleep(self::POLL_MICROSECONDS);
                }

                if ($stdinOpen && $stdinEof && $stdinBuffer === '') {
                    fclose($pipes[0]);
                    $stdinOpen = false;
                } elseif ($write !== [] && $stdinOpen && $stdinBuffer !== '') {
                    $written = @fwrite($pipes[0], substr($stdinBuffer, 0, self::CHUNK_BYTES));
                    if ($written === false) {
                        $stopReason ??= ProcessStopReason::StdinWriteFailed;
                        $stdinFailed = true;
                        fclose($pipes[0]);
                        $stdinOpen = false;
                        $stdinBuffer = '';
                    } elseif ($written > 0) {
                        $stdinBuffer = substr($stdinBuffer, $written);
                    }
                }

                foreach ($read as $readable) {
                    $chunk = @fread($readable, self::CHUNK_BYTES);
                    if ($chunk === false || $chunk === '') {
                        continue;
                    }
                    $reason = $readable === $pipes[1] ? $stdout->accept($chunk) : $stderr->accept($chunk);
                    $stopReason ??= $reason;
                }

                $status = proc_get_status($process);
                if ($status['running']) {
                    continue;
                }

                if ($status['exitcode'] >= 0) {
                    $exitCode = $status['exitcode'];
                }
                $signaled = $status['signaled'];

                $stopReason ??= $this->drain($stdoutOpen ? $pipes[1] : null, $stdout);
                $stopReason ??= $this->drain($stderrOpen ? $pipes[2] : null, $stderr);
                break;
            }
        } catch (Throwable) {
            $stopReason ??= ProcessStopReason::ExecutorFailed;
            @proc_terminate($process, 15);
            usleep((int) min(1_000_000, $specification->terminationGraceSeconds * 1_000_000));
            if (proc_get_status($process)['running']) {
                @proc_terminate($process, 9);
            }
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }

            $closedExitCode = @proc_close($process);
            if ($exitCode === null && $closedExitCode >= 0 && !$signaled) {
                $exitCode = $closedExitCode;
            }
            unset($this->ownedProcessIds[$pid]);
        }

        return new ProcessResult(
            started: true,
            pid: $pid,
            exitCode: $exitCode,
            signaled: $signaled,
            timedOut: $timedOut,
            stopReason: $stopReason,
            stdinComplete: $specification->stdin === null
                || (!$stdinFailed && $stdinEof && $stdinBuffer === '' && !$stdinOpen),
            stdout: $stdout->captured,
            stderr: $stderr->captured,
            stdoutBytes: $stdout->bytes,
            stderrBytes: $stderr->bytes,
            stdoutTruncated: $stdout->truncated,
            stderrTruncated: $stderr->truncated,
            stdoutSha256: $stdout->sha256(),
            stderrSha256: $stderr->sha256(),
            durationSeconds: (hrtime(true) - $startedAt) / 1_000_000_000,
        );
    }

    public function ownedProcessIds(): array
    {
        $ids = array_keys($this->ownedProcessIds);
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /**
     * Reads what an ended child left in a pipe.
     *
     * @param resource|null $pipe
     */
    private function drain(mixed $pipe, OutputCapture $capture): ?ProcessStopReason
    {
        if (!is_resource($pipe)) {
            return null;
        }

        $stopReason = null;
        stream_set_blocking($pipe, true);
        while (!feof($pipe)) {
            $chunk = fread($pipe, self::CHUNK_BYTES);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $stopReason ??= $capture->accept($chunk);
        }

        return $stopReason;
    }
}
