<?php

declare(strict_types=1);

use Larena\Core\Contracts\OperationAccessGate;
use Larena\Core\Contracts\OperationAuditRecorder;
use Larena\Core\Contracts\OperationCapabilityGate;
use Larena\Core\Contracts\OperationContext;
use Larena\Core\Contracts\OperationDecision;
use Larena\Core\Contracts\OperationDescriptor;
use Larena\Core\Contracts\OperationHandler;
use Larena\Core\Contracts\OperationResult;
use Larena\Core\Enums\OperationDecisionStatus;
use Larena\Core\Enums\OperationExecutionMode;
use Larena\Core\Runtime\SyncOperationRuntime;

require_once __DIR__ . '/../../src/Enums/OperationExecutionMode.php';
require_once __DIR__ . '/../../src/Enums/OperationDecisionStatus.php';
require_once __DIR__ . '/../../src/Contracts/OperationDecision.php';
require_once __DIR__ . '/../../src/Contracts/OperationDescriptor.php';
require_once __DIR__ . '/../../src/Contracts/OperationContext.php';
require_once __DIR__ . '/../../src/Contracts/OperationResult.php';
require_once __DIR__ . '/../../src/Contracts/OperationRuntime.php';
require_once __DIR__ . '/../../src/Contracts/OperationAccessGate.php';
require_once __DIR__ . '/../../src/Contracts/OperationCapabilityGate.php';
require_once __DIR__ . '/../../src/Contracts/OperationAuditRecorder.php';
require_once __DIR__ . '/../../src/Contracts/OperationHandler.php';
require_once __DIR__ . '/../../src/Runtime/SyncOperationRuntime.php';

function assertSyncRuntimeTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class SyncRuntimeRecordingAuditRecorder implements OperationAuditRecorder
{
    /**
     * @var list<array<string, mixed>>
     */
    public array $events = [];

    public function recordDecision(OperationDescriptor $descriptor, OperationContext $context, OperationDecision $decision, string $phase): array
    {
        return $this->record($descriptor, $context, $decision->status->value, $phase);
    }

    public function recordResult(OperationDescriptor $descriptor, OperationContext $context, OperationResult $result, string $phase): array
    {
        return $this->record($descriptor, $context, $result->decision->status->value, $phase);
    }

    /**
     * @return array<string, mixed>
     */
    private function record(OperationDescriptor $descriptor, OperationContext $context, string $status, string $phase): array
    {
        $event = [
            'operation' => $descriptor->name,
            'correlation_id' => $context->correlationId,
            'phase' => $phase,
            'status' => $status,
        ];
        $this->events[] = $event;

        return $event;
    }
}

$auditRecorder = new SyncRuntimeRecordingAuditRecorder();
$handlerCalls = 0;

$runtime = new SyncOperationRuntime(
    new class implements OperationAccessGate {
        public function decideAccess(OperationDescriptor $descriptor, OperationContext $context): OperationDecision
        {
            return OperationDecision::allowed(OperationExecutionMode::Sync, 'access_allowed');
        }
    },
    new class implements OperationCapabilityGate {
        public function decideCapability(OperationDescriptor $descriptor, OperationContext $context): OperationDecision
        {
            return OperationDecision::allowed(OperationExecutionMode::Sync, 'capability_allowed');
        }
    },
    $auditRecorder,
    new class($handlerCalls) implements OperationHandler {
        public function __construct(private int &$calls)
        {
        }

        public function handle(OperationDescriptor $descriptor, OperationContext $context): array
        {
            $this->calls++;

            return ['handled' => true, 'operation' => $descriptor->name];
        }
    },
);

$descriptor = new OperationDescriptor(
    name: 'core.operation_runtime.sync',
    executionMode: OperationExecutionMode::Sync,
    accessScope: 'core.operations.execute',
    requiredCapability: 'core.operation_runtime',
    auditEvent: 'core.operation.requested',
);

$context = new OperationContext(
    actorId: 'user:1',
    correlationId: 'corr-sync-1',
);

$decision = $runtime->decide($descriptor, $context);
$result = $runtime->execute($descriptor, $context);
$explain = $runtime->explain($descriptor, $context);

assertSyncRuntimeTrue($decision->status === OperationDecisionStatus::Allowed, 'Permitted sync operation must be allowed.');
assertSyncRuntimeTrue($result->successful(), 'Permitted sync operation must succeed.');
assertSyncRuntimeTrue($handlerCalls === 1, 'Permitted sync operation must execute handler exactly once.');
assertSyncRuntimeTrue($result->payload === ['handled' => true, 'operation' => 'core.operation_runtime.sync'], 'Runtime must return handler payload.');
assertSyncRuntimeTrue(count($auditRecorder->events) === 2, 'Runtime must emit decision and result audit events.');
assertSyncRuntimeTrue($auditRecorder->events[0]['phase'] === 'decision', 'First audit event must record decision phase.');
assertSyncRuntimeTrue($auditRecorder->events[1]['phase'] === 'result', 'Second audit event must record result phase.');
assertSyncRuntimeTrue($result->runtimeTrace['correlation_id'] === 'corr-sync-1', 'Runtime trace must preserve correlation id.');
assertSyncRuntimeTrue($explain['handler_may_run'] === true, 'Explain output must show handler execution decision.');

echo "SyncOperationRuntimeTest passed.\n";
