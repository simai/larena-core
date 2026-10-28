<?php

declare(strict_types=1);

namespace Larena\Core\Gateway;

use Larena\Core\Process\ProcessExecutor;
use Throwable;

/**
 * Runs one request through a gateway adapter and refuses everything outside its manifest: a command it does not
 * declare, a process that did not run to its own end or whose output was cut, an answer it cannot read, and an
 * answer in another protocol or protocol version.
 */
final readonly class GatewayRunner
{
    public function __construct(private ProcessExecutor $executor)
    {
    }

    /** @throws GatewayRejected */
    public function run(GatewayAdapter $adapter, GatewayRequest $request): GatewayAnswer
    {
        $manifest = $adapter->manifest();
        if (!$manifest->allows($request->command)) {
            throw new GatewayRejected(
                'gateway_command_not_allowed',
                "Gateway {$manifest->id} does not declare command {$request->command}.",
            );
        }

        $result = $this->executor->run($adapter->invocation($request));
        if (
            !$result->started
            || $result->stopReason !== null
            || $result->signaled
            || $result->exitCode === null
            || $result->stdoutTruncated
            || $result->stderrTruncated
        ) {
            throw new GatewayRejected('gateway_process_failed', "Gateway {$manifest->id} did not finish.", $result);
        }

        try {
            $answer = $adapter->answer($request, $result);
        } catch (GatewayRejected $exception) {
            throw $exception->process === null
                ? new GatewayRejected($exception->reasonCode, $exception->getMessage(), $result, $exception)
                : $exception;
        } catch (Throwable $exception) {
            throw new GatewayRejected('gateway_answer_invalid', "Gateway {$manifest->id} answer is unreadable.", $result, $exception);
        }

        if ($answer->protocol !== $manifest->protocol || $answer->protocolVersion !== $manifest->protocolVersion) {
            throw new GatewayRejected(
                'gateway_protocol_mismatch',
                "Gateway {$manifest->id} answered in {$answer->protocol} {$answer->protocolVersion}, expected "
                    ."{$manifest->protocol} {$manifest->protocolVersion}.",
                $result,
            );
        }

        return $answer;
    }
}
