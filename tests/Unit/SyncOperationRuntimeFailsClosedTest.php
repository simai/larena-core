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

function assertSyncFailClosedTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class RecordingAuditRecorder implements OperationAuditRecorder
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

final readonly class FailingAuditRecorder implements OperationAuditRecorder
{
    public function __construct(private string $failurePhase)
    {
    }

    public function recordDecision(OperationDescriptor $descriptor, OperationContext $context, OperationDecision $decision, string $phase): array
    {
        if ($this->failurePhase === 'decision') {
            throw new RuntimeException('decision audit unavailable');
        }

        return ['phase' => $phase, 'status' => $decision->status->value];
    }

    public function recordResult(OperationDescriptor $descriptor, OperationContext $context, OperationResult $result, string $phase): array
    {
        if ($this->failurePhase === 'result') {
            throw new RuntimeException('result audit unavailable');
        }

        return ['phase' => $phase, 'status' => $result->decision->status->value];
    }
}

$descriptor = new OperationDescriptor(
    name: 'core.operation_runtime.sync',
    executionMode: OperationExecutionMode::Sync,
    accessScope: 'core.operations.execute',
    requiredCapability: 'core.operation_runtime',
    auditEvent: 'core.operation.requested',
);
$context = new OperationContext('user:1', 'corr-denied-1');

$accessAudit = new RecordingAuditRecorder();
$accessHandlerCalls = 0;
$accessDeniedRuntime = new SyncOperationRuntime(
    new class implements OperationAccessGate {
        public function decideAccess(OperationDescriptor $descriptor, OperationContext $context): OperationDecision
        {
            return OperationDecision::denied('access_denied');
        }
    },
    new class implements OperationCapabilityGate {
        public function decideCapability(OperationDescriptor $descriptor, OperationContext $context): OperationDecision
        {
            return OperationDecision::allowed(OperationExecutionMode::Sync, 'capability_allowed');
        }
    },
    $accessAudit,
    new class($accessHandlerCalls) implements OperationHandler {
        public function __construct(private int &$calls)
        {
        }

        public function handle(OperationDescriptor $descriptor, OperationContext $context): array
        {
            $this->calls++;

            return ['unexpected' => true];
        }
    },
);

$accessDeniedResult = $accessDeniedRuntime->execute($descriptor, $context);

assertSyncFailClosedTrue($accessDeniedResult->successful() === false, 'Access-denied result must fail closed.');
assertSyncFailClosedTrue($accessDeniedResult->normalizedError['code'] === 'access_denied', 'Access-denied result must expose normalized error.');
assertSyncFailClosedTrue($accessHandlerCalls === 0, 'Access-denied operation must not execute handler.');
assertSyncFailClosedTrue(count($accessAudit->events) === 2, 'Access-denied operation must still emit decision and result audit events.');

$capabilityAudit = new RecordingAuditRecorder();
$capabilityHandlerCalls = 0;
$capabilityDeniedRuntime = new SyncOperationRuntime(
    new class implements OperationAccessGate {
        public function decideAccess(OperationDescriptor $descriptor, OperationContext $context): OperationDecision
        {
            return OperationDecision::allowed(OperationExecutionMode::Sync, 'access_allowed');
        }
    },
    new class implements OperationCapabilityGate {
        public function decideCapability(OperationDescriptor $descriptor, OperationContext $context): OperationDecision
        {
            return OperationDecision::capabilityLocked('capability_locked');
        }
    },
    $capabilityAudit,
    new class($capabilityHandlerCalls) implements OperationHandler {
        public function __construct(private int &$calls)
        {
        }

        public function handle(OperationDescriptor $descriptor, OperationContext $context): array
        {
            $this->calls++;

            return ['unexpected' => true];
        }
    },
);

$capabilityDeniedResult = $capabilityDeniedRuntime->execute($descriptor, $context);

assertSyncFailClosedTrue($capabilityDeniedResult->successful() === false, 'Capability-denied result must fail closed.');
assertSyncFailClosedTrue($capabilityDeniedResult->normalizedError['code'] === 'capability_locked', 'Capability-denied result must expose normalized error.');
assertSyncFailClosedTrue($capabilityHandlerCalls === 0, 'Capability-denied operation must not execute handler.');
assertSyncFailClosedTrue(count($capabilityAudit->events) === 2, 'Capability-denied operation must still emit decision and result audit events.');

$unsupportedRuntime = new SyncOperationRuntime(
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
    new RecordingAuditRecorder(),
    new class implements OperationHandler {
        public function handle(OperationDescriptor $descriptor, OperationContext $context): array
        {
            return ['unexpected' => true];
        }
    },
);

$unsupported = $unsupportedRuntime->execute(new OperationDescriptor('core.operation_runtime.queued', OperationExecutionMode::Queued), $context);

assertSyncFailClosedTrue($unsupported->successful() === false, 'Unsupported execution mode must fail closed.');
assertSyncFailClosedTrue($unsupported->normalizedError['code'] === 'unsupported_execution_mode', 'Unsupported execution mode must expose normalized error.');

$auditDecisionHandlerCalls = 0;
$auditDecisionFailureRuntime = new SyncOperationRuntime(
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
    new FailingAuditRecorder('decision'),
    new class($auditDecisionHandlerCalls) implements OperationHandler {
        public function __construct(private int &$calls)
        {
        }

        public function handle(OperationDescriptor $descriptor, OperationContext $context): array
        {
            $this->calls++;

            return ['unexpected' => true];
        }
    },
);

$auditDecisionFailure = $auditDecisionFailureRuntime->execute($descriptor, $context);

assertSyncFailClosedTrue($auditDecisionFailure->successful() === false, 'Audit decision failure must fail closed.');
assertSyncFailClosedTrue($auditDecisionFailure->normalizedError['code'] === 'audit_decision_failed', 'Audit decision failure must expose normalized reason.');
assertSyncFailClosedTrue($auditDecisionHandlerCalls === 0, 'Audit decision failure must stop before handler execution.');
assertSyncFailClosedTrue($auditDecisionFailure->runtimeTrace['audit_failure_phase'] === 'decision', 'Audit decision failure trace must expose failure phase.');
assertSyncFailClosedTrue(count($auditDecisionFailure->auditEvents) === 0, 'Audit decision failure cannot claim recorded audit events.');

$auditResultHandlerCalls = 0;
$auditResultFailureRuntime = new SyncOperationRuntime(
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
    new FailingAuditRecorder('result'),
    new class($auditResultHandlerCalls) implements OperationHandler {
        public function __construct(private int &$calls)
        {
        }

        public function handle(OperationDescriptor $descriptor, OperationContext $context): array
        {
            $this->calls++;

            return ['handled' => true];
        }
    },
);

$auditResultFailure = $auditResultFailureRuntime->execute($descriptor, $context);

assertSyncFailClosedTrue($auditResultFailure->successful() === false, 'Audit result failure must fail closed.');
assertSyncFailClosedTrue($auditResultFailure->normalizedError['code'] === 'audit_result_failed', 'Audit result failure must expose normalized reason.');
assertSyncFailClosedTrue($auditResultHandlerCalls === 1, 'Audit result failure occurs after handler execution.');
assertSyncFailClosedTrue($auditResultFailure->runtimeTrace['audit_failure_phase'] === 'result', 'Audit result failure trace must expose failure phase.');
assertSyncFailClosedTrue(count($auditResultFailure->auditEvents) === 1, 'Audit result failure must preserve the decision audit event.');

echo "SyncOperationRuntimeFailsClosedTest passed.\n";
