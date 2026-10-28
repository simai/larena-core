<?php

declare(strict_types=1);

namespace Larena\Core\Process;

/**
 * Why the executor stopped a child before it finished on its own. Only the first reason is kept: a child stopped for
 * its timeout that then floods stdout still reports the timeout.
 */
enum ProcessStopReason: string
{
    case Timeout = 'timeout';
    case StdoutLimit = 'stdout_limit';
    case StderrLimit = 'stderr_limit';
    case StdinReadFailed = 'stdin_read_failed';
    case StdinWriteFailed = 'stdin_write_failed';
    case StdoutSinkFailed = 'stdout_sink_failed';
    case ExecutorFailed = 'executor_failed';
}
