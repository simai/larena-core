<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Larena\Core\Process\ProcessRejected;
use Larena\Core\Process\ProcessResult;
use Larena\Core\Process\ProcessSpecification;
use Larena\Core\Process\ProcessStopReason;
use Larena\Core\Process\ProcOpenProcessExecutor;
use Larena\Core\Process\RecordingProcessExecutor;

function processAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

/** @param callable():mixed $operation */
function processAssertRejected(callable $operation, string $reasonCode, string $message): void
{
    try {
        $operation();
    } catch (ProcessRejected $exception) {
        processAssert($exception->reasonCode === $reasonCode, $message . ': expected ' . $reasonCode . ', got ' . $exception->reasonCode);

        return;
    }

    processAssert(false, $message . ': specification was unexpectedly accepted');
}

/** A value whose type only shows at run time, as it would from an untyped caller. */
function processUntyped(string $json): mixed
{
    return json_decode($json, false, 4, JSON_THROW_ON_ERROR);
}

$root = sys_get_temp_dir() . '/larena-core-process-' . bin2hex(random_bytes(6));
processAssert(mkdir($root, 0700), 'temporary root must be created');
$root = (string) realpath($root);
$executor = new ProcOpenProcessExecutor();

try {
    // argv reaches the program literally: no shell ever sees `;`, `$()`, quotes or globs.
    $sentinel = $root . '/shell-was-used';
    $arguments = [';touch ' . $sentinel, '$(touch ' . $sentinel . ')', '`touch ' . $sentinel . '`', '"quoted" *'];
    $echo = $executor->run(new ProcessSpecification(['/bin/echo', ...$arguments], $root));
    processAssert($echo->successful(), 'echo must succeed');
    processAssert($echo->stdout === implode(' ', $arguments) . "\n", 'argv must reach the child literally');
    processAssert(!file_exists($sentinel), 'a shell interpreted an argv metacharacter');
    processAssert($echo->stdoutSha256 === hash('sha256', $echo->stdout), 'stdout digest covers the captured bytes');
    processAssert($echo->exitCode === 0 && !$echo->timedOut && $echo->stopReason === null, 'clean exit is reported');
    processAssert($echo->durationSeconds > 0.0 && $echo->durationSeconds < 5.0, 'duration is measured');

    // The working directory is the one given.
    $pwd = $executor->run(new ProcessSpecification(['/bin/pwd'], $root));
    processAssert($pwd->successful() && trim($pwd->stdout) === $root, 'working directory must be the one given');

    // The child sees exactly the given environment and nothing the parent holds.
    putenv('LARENA_PROCESS_SECRET=must-not-leak');
    $_ENV['LARENA_PROCESS_SECRET'] = 'must-not-leak';
    $env = $executor->run(new ProcessSpecification(['/usr/bin/env'], $root, ['LANG' => 'C', 'LARENA_ONLY' => 'yes']));
    $lines = array_values(array_filter(explode("\n", $env->stdout), static fn (string $line): bool => $line !== ''));
    sort($lines);
    processAssert($env->successful() && $lines === ['LANG=C', 'LARENA_ONLY=yes'], 'child environment must be exactly the given one');
    $empty = $executor->run(new ProcessSpecification(['/usr/bin/env'], $root));
    processAssert($empty->successful() && $empty->stdout === '', 'an empty environment must stay empty');
    putenv('LARENA_PROCESS_SECRET');
    unset($_ENV['LARENA_PROCESS_SECRET']);

    // Stdin as bytes, including a NUL, and as a stream larger than one pipe buffer.
    $bytes = "first line\nsecond\0line";
    $cat = $executor->run(new ProcessSpecification(['/bin/cat'], $root, stdin: $bytes));
    processAssert($cat->successful() && $cat->stdout === $bytes && $cat->stdinComplete, 'stdin bytes must reach the child intact');
    $large = random_bytes(300_000);
    $stream = fopen('php://temp', 'w+b');
    processAssert(is_resource($stream), 'stdin stream must open');
    fwrite($stream, $large);
    rewind($stream);
    $streamed = $executor->run(new ProcessSpecification(['/bin/cat'], $root, stdin: $stream, stdoutLimitBytes: 300_000));
    fclose($stream);
    processAssert($streamed->successful() && $streamed->stdout === $large, 'stdin stream must reach the child intact');
    processAssert(!$streamed->stdoutTruncated && $streamed->stdoutBytes === 300_000, 'an output exactly at the limit is not truncated');

    // An output above the cap is cut at the cap, flagged, and the child is stopped.
    $capped = $executor->run(new ProcessSpecification(['/bin/cat'], $root, stdin: str_repeat('x', 100_000), stdoutLimitBytes: 1_000, timeoutSeconds: 5.0, terminationGraceSeconds: 0.2));
    processAssert(!$capped->successful(), 'a truncated run is not successful');
    processAssert($capped->stdoutTruncated && $capped->stopReason === ProcessStopReason::StdoutLimit, 'stdout overflow must be flagged');
    processAssert($capped->stdout === str_repeat('x', 1_000) && $capped->stdoutBytes > 1_000, 'stdout keeps exactly the capped prefix');
    processAssert($capped->stdoutSha256 === hash('sha256', $capped->stdout), 'digest covers only the kept prefix');
    $stderrCapped = $executor->run(new ProcessSpecification(['/bin/cat', $root . '/missing-' . str_repeat('m', 200)], $root, stderrLimitBytes: 16, terminationGraceSeconds: 0.2));
    processAssert($stderrCapped->stderrTruncated && strlen($stderrCapped->stderr) === 16, 'stderr is capped too');
    processAssert($stderrCapped->stopReason === ProcessStopReason::StderrLimit, 'stderr overflow must be flagged');

    // A child that outlives its timeout is terminated and reaped.
    $sleep = $executor->run(new ProcessSpecification(['/bin/sleep', '5'], $root, timeoutSeconds: 0.2, terminationGraceSeconds: 0.2));
    processAssert($sleep->timedOut && $sleep->stopReason === ProcessStopReason::Timeout, 'timeout must be reported');
    processAssert($sleep->signaled && $sleep->exitCode === null && !$sleep->successful(), 'a stopped child reports its signal');
    processAssert($sleep->durationSeconds < 2.0, 'timeout must stop the child promptly');

    // A child that ignores SIGTERM is killed after the grace time.
    $stubborn = $executor->run(new ProcessSpecification(['/bin/sh', '-c', 'trap "" TERM; while :; do :; done'], $root, timeoutSeconds: 0.2, terminationGraceSeconds: 0.2));
    processAssert($stubborn->timedOut && $stubborn->signaled && $stubborn->durationSeconds < 3.0, 'SIGTERM-ignoring child must be killed');
    processAssert($executor->ownedProcessIds() === [], 'every child must be reaped');

    // A non-zero exit is reported and is not success.
    $exit = $executor->run(new ProcessSpecification(['/bin/sh', '-c', 'exit 3'], $root));
    processAssert($exit->started && $exit->exitCode === 3 && $exit->stopReason === null && !$exit->successful(), 'exit code must be reported');

    // A child that ends before taking all of its stdin is not success.
    $early = $executor->run(new ProcessSpecification(['/bin/sh', '-c', 'exit 0'], $root, stdin: str_repeat('i', 1_048_576)));
    processAssert($early->exitCode === 0 && !$early->stdinComplete && !$early->successful(), 'unconsumed stdin must fail closed');

    // Stdout can stream into a sink without being held in memory.
    $sink = fopen('php://temp', 'w+b');
    processAssert(is_resource($sink), 'sink must open');
    $sunk = $executor->run(new ProcessSpecification(['/bin/echo', 'payload'], $root, stdoutSink: $sink, captureStdout: false));
    rewind($sink);
    processAssert($sunk->successful() && $sunk->stdout === '' && stream_get_contents($sink) === "payload\n", 'stdout must stream into the sink only');
    processAssert($sunk->stdoutSha256 === hash('sha256', "payload\n"), 'digest covers the streamed bytes');
    fclose($sink);
    $readOnly = fopen('php://memory', 'rb');
    processAssert(is_resource($readOnly), 'read-only sink must open');
    $sinkFailed = $executor->run(new ProcessSpecification(['/bin/echo', 'payload'], $root, stdoutSink: $readOnly, captureStdout: false, terminationGraceSeconds: 0.2));
    fclose($readOnly);
    processAssert($sinkFailed->stopReason === ProcessStopReason::StdoutSinkFailed && !$sinkFailed->successful(), 'sink write failure must be reported');

    // Specifications are refused before anything starts.
    processAssertRejected(static fn () => new ProcessSpecification([], $root), 'process_argv_invalid', 'empty argv');
    processAssertRejected(static fn () => new ProcessSpecification(['/bin/echo', ''], $root), 'process_argv_invalid', 'empty argument');
    processAssertRejected(static fn () => new ProcessSpecification(['/bin/echo', "a\0b"], $root), 'process_argv_invalid', 'NUL argument');
    processAssertRejected(static fn () => new ProcessSpecification(['/bin/echo'], 'relative'), 'process_working_directory_invalid', 'relative directory');
    processAssertRejected(static fn () => new ProcessSpecification(['/bin/echo'], $root, ['A=B' => 'c']), 'process_environment_invalid', 'environment name with =');
    // Values from untyped callers, so the runtime check is what refuses them.
    $number = processUntyped('42');
    $path = processUntyped('"file"');
    processAssertRejected(static fn () => new ProcessSpecification(['/bin/echo'], $root, stdin: $number), 'process_stdin_invalid', 'stdin of a wrong type');
    processAssertRejected(static fn () => new ProcessSpecification(['/bin/echo'], $root, stdoutSink: $path), 'process_stdout_sink_invalid', 'sink that is not a stream');
    processAssertRejected(static fn () => new ProcessSpecification(['/bin/echo'], $root, stdoutLimitBytes: -1), 'process_limit_invalid', 'negative limit');
    processAssertRejected(static fn () => new ProcessSpecification(['/bin/echo'], $root, timeoutSeconds: 0.0), 'process_timeout_invalid', 'zero timeout');

    // The recording executor starts nothing and answers in order.
    $fake = new RecordingProcessExecutor(ProcessResult::finished(0, "ok\n"), ProcessResult::finished(2, '', 'bad'));
    $first = $fake->run(new ProcessSpecification(['/usr/bin/true', 'one'], $root));
    $second = $fake->run(new ProcessSpecification(['/usr/bin/true', 'two'], $root));
    processAssert($first->successful() && $first->stdout === "ok\n" && $second->exitCode === 2 && !$second->successful(), 'fake answers in order');
    processAssert(array_map(static fn (ProcessSpecification $spec): string => $spec->argv[1], $fake->recorded()) === ['one', 'two'], 'fake records every specification');
    $answering = RecordingProcessExecutor::answering(static fn (ProcessSpecification $spec): ProcessResult => ProcessResult::finished(0, implode(' ', $spec->argv)));
    processAssert($answering->run(new ProcessSpecification(['/bin/x', 'y'], $root))->stdout === '/bin/x y', 'fake responder answers');
    processAssert(!ProcessResult::notStarted()->successful(), 'a run that never started is not successful');
} finally {
    foreach (glob($root . '/*') ?: [] as $entry) {
        @unlink($entry);
    }
    @rmdir($root);
}

echo "Process executor passed.\n";
