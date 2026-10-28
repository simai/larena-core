<?php

declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';

use Larena\Core\Gateway\GatewayAdapter;
use Larena\Core\Gateway\GatewayAnswer;
use Larena\Core\Gateway\GatewayManifest;
use Larena\Core\Gateway\GatewayRejected;
use Larena\Core\Gateway\GatewayRequest;
use Larena\Core\Gateway\GatewayRunner;
use Larena\Core\Process\ProcessResult;
use Larena\Core\Process\ProcessSpecification;
use Larena\Core\Process\ProcOpenProcessExecutor;
use Larena\Core\Process\RecordingProcessExecutor;

function gatewayAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

/** @param callable():mixed $operation */
function gatewayAssertRejected(callable $operation, string $reasonCode, string $message): ?GatewayRejected
{
    try {
        $operation();
    } catch (GatewayRejected $exception) {
        gatewayAssert($exception->reasonCode === $reasonCode, $message . ': expected ' . $reasonCode . ', got ' . $exception->reasonCode);

        return $exception;
    }

    gatewayAssert(false, $message . ': call was unexpectedly accepted');

    return null;
}

/**
 * A gateway stand-in: /bin/echo prints the answer the test wants the gateway to give, so every case runs a real
 * short-lived process through the runner.
 */
final class EchoGatewayAdapter implements GatewayAdapter
{
    /** @param list<string> $argvOverride */
    public function __construct(
        private readonly GatewayManifest $manifest,
        private readonly string $answer,
        private readonly array $argvOverride = [],
    ) {
    }

    public function manifest(): GatewayManifest
    {
        return $this->manifest;
    }

    public function invocation(GatewayRequest $request): ProcessSpecification
    {
        return new ProcessSpecification(
            $this->argvOverride !== [] ? $this->argvOverride : ['/bin/echo', $this->answer],
            sys_get_temp_dir(),
            ['LANG' => 'C'],
            timeoutSeconds: 0.5,
            terminationGraceSeconds: 0.2,
        );
    }

    public function answer(GatewayRequest $request, ProcessResult $result): GatewayAnswer
    {
        return GatewayAnswer::fromJson($result->stdout);
    }
}

$manifest = new GatewayManifest('larena.test-gateway', 'larena.test-gate', 2, ['status', 'site.list']);
$runner = new GatewayRunner(new ProcOpenProcessExecutor());
$status = new GatewayRequest('status');

// A matching answer passes with its payload, protocol fields removed.
$good = new EchoGatewayAdapter($manifest, '{"protocol":"larena.test-gate","protocol_version":2,"result":{"ok":true}}');
$answer = $runner->run($good, $status);
gatewayAssert($answer->protocol === 'larena.test-gate' && $answer->protocolVersion === 2, 'matching protocol must pass');
gatewayAssert($answer->payload === ['result' => ['ok' => true]], 'payload must exclude the protocol fields');

// Another protocol name or version is refused.
$otherProtocol = new EchoGatewayAdapter($manifest, '{"protocol":"other.gate","protocol_version":2}');
$refused = gatewayAssertRejected(static fn () => $runner->run($otherProtocol, $status), 'gateway_protocol_mismatch', 'protocol name mismatch');
gatewayAssert($refused?->process?->exitCode === 0, 'the refusal carries the process result');
$otherVersion = new EchoGatewayAdapter($manifest, '{"protocol":"larena.test-gate","protocol_version":3}');
gatewayAssertRejected(static fn () => $runner->run($otherVersion, $status), 'gateway_protocol_mismatch', 'protocol version mismatch');
$stringVersion = new EchoGatewayAdapter($manifest, '{"protocol":"larena.test-gate","protocol_version":"2"}');
gatewayAssertRejected(static fn () => $runner->run($stringVersion, $status), 'gateway_answer_invalid', 'version must be an integer');

// An unreadable answer is refused.
$notJson = new EchoGatewayAdapter($manifest, 'plain text');
gatewayAssertRejected(static fn () => $runner->run($notJson, $status), 'gateway_answer_invalid', 'non-JSON answer');
$list = new EchoGatewayAdapter($manifest, '["larena.test-gate",2]');
gatewayAssertRejected(static fn () => $runner->run($list, $status), 'gateway_answer_invalid', 'JSON list answer');

// A command outside the manifest never starts a process.
$recording = new RecordingProcessExecutor(ProcessResult::finished(0, '{"protocol":"larena.test-gate","protocol_version":2}'));
$recordingRunner = new GatewayRunner($recording);
gatewayAssertRejected(static fn () => $recordingRunner->run($good, new GatewayRequest('site.delete')), 'gateway_command_not_allowed', 'undeclared command');
gatewayAssert($recording->recorded() === [], 'an undeclared command must not reach the executor');
$recordingRunner->run($good, new GatewayRequest('site.list', ['owner' => 'demo']));
gatewayAssert(count($recording->recorded()) === 1, 'a declared command reaches the executor once');

// A gateway that does not finish is refused before its answer is read.
$hanging = new EchoGatewayAdapter($manifest, '', ['/bin/sleep', '5']);
$timedOut = gatewayAssertRejected(static fn () => $runner->run($hanging, $status), 'gateway_process_failed', 'hanging gateway');
gatewayAssert($timedOut?->process?->timedOut === true, 'the process failure carries the timeout');
$neverStarted = new GatewayRunner(new RecordingProcessExecutor(ProcessResult::notStarted()));
gatewayAssertRejected(static fn () => $neverStarted->run($good, $status), 'gateway_process_failed', 'gateway that never started');

// Manifests and requests are validated.
gatewayAssertRejected(static fn () => new GatewayManifest('Bad Id', 'p', 1, ['status']), 'gateway_manifest_invalid', 'invalid id');
gatewayAssertRejected(static fn () => new GatewayManifest('gateway', 'p', 0, ['status']), 'gateway_manifest_invalid', 'zero version');
gatewayAssertRejected(static fn () => new GatewayManifest('gateway', 'p', 1, []), 'gateway_manifest_invalid', 'no commands');
gatewayAssertRejected(static fn () => new GatewayManifest('gateway', 'p', 1, ['status', 'status']), 'gateway_manifest_invalid', 'duplicate commands');
gatewayAssertRejected(static fn () => new GatewayManifest('gateway', 'p', 1, ['rm -rf']), 'gateway_manifest_invalid', 'command with a space');
gatewayAssertRejected(static fn () => new GatewayRequest('status; reboot'), 'gateway_request_invalid', 'request command with shell syntax');
gatewayAssert($manifest->toArray() === [
    'id' => 'larena.test-gateway',
    'protocol' => 'larena.test-gate',
    'protocol_version' => 2,
    'commands' => ['site.list', 'status'],
], 'manifest serializes sorted');

echo "Gateway runner passed.\n";
