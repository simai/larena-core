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
use Larena\Core\Contracts\OperationTransactionBoundary;
use Larena\Core\Enums\OperationExecutionMode;
use Larena\Core\Exceptions\OperationTransactionAborted;
use Larena\Core\Runtime\SyncOperationRuntime;

require_once __DIR__.'/../../src/Enums/OperationExecutionMode.php';
require_once __DIR__.'/../../src/Enums/OperationDecisionStatus.php';
require_once __DIR__.'/../../src/Contracts/OperationDecision.php';
require_once __DIR__.'/../../src/Contracts/OperationDescriptor.php';
require_once __DIR__.'/../../src/Contracts/OperationContext.php';
require_once __DIR__.'/../../src/Contracts/OperationResult.php';
require_once __DIR__.'/../../src/Contracts/OperationRuntime.php';
require_once __DIR__.'/../../src/Contracts/OperationAccessGate.php';
require_once __DIR__.'/../../src/Contracts/OperationCapabilityGate.php';
require_once __DIR__.'/../../src/Contracts/OperationAuditRecorder.php';
require_once __DIR__.'/../../src/Contracts/OperationHandler.php';
require_once __DIR__.'/../../src/Contracts/OperationTransactionBoundary.php';
require_once __DIR__.'/../../src/Exceptions/OperationTransactionAborted.php';
require_once __DIR__.'/../../src/Runtime/SyncOperationRuntime.php';

function assertTransactionRuntime(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class TransactionRuntimeState
{
    public int $writes = 0;
}

final class RecordingTransactionBoundary implements OperationTransactionBoundary
{
    public int $commits = 0;
    public int $rollbacks = 0;
    public ?Throwable $lastFailure = null;

    public function __construct(private readonly TransactionRuntimeState $state) {}

    public function run(callable $operation): mixed
    {
        $snapshot = $this->state->writes;

        try {
            $result = $operation();
            $this->commits++;

            return $result;
        } catch (Throwable $throwable) {
            $this->state->writes = $snapshot;
            $this->rollbacks++;
            $this->lastFailure = $throwable;

            throw $throwable;
        }
    }
}

final class ExplodingTransactionBoundary implements OperationTransactionBoundary
{
    public function run(callable $operation): mixed
    {
        throw new RuntimeException('database_password_boundary_canary');
    }
}

final class TransactionRuntimeAudit implements OperationAuditRecorder
{
    public int $decisionCalls = 0;

    public int $resultCalls = 0;

    public function __construct(private ?string $failurePhase = null) {}

    public function recordDecision(OperationDescriptor $descriptor, OperationContext $context, OperationDecision $decision, string $phase): array
    {
        $this->decisionCalls++;

        if ($this->failurePhase === 'decision') {
            throw new RuntimeException('database details must not escape');
        }

        return ['phase' => $phase, 'status' => $decision->status->value];
    }

    public function recordResult(OperationDescriptor $descriptor, OperationContext $context, OperationResult $result, string $phase): array
    {
        $this->resultCalls++;

        if ($this->failurePhase === 'result') {
            throw new RuntimeException('database details must not escape');
        }

        return ['phase' => $phase, 'status' => $result->decision->status->value];
    }
}

function transactionRuntime(
    TransactionRuntimeState $state,
    RecordingTransactionBoundary $boundary,
    int &$handlerCalls,
    ?string $auditFailure = null,
    bool $handlerFailure = false,
    ?TransactionRuntimeAudit $auditRecorder = null,
): SyncOperationRuntime {
    return new SyncOperationRuntime(
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
        $auditRecorder ?? new TransactionRuntimeAudit($auditFailure),
        new class($state, $handlerCalls, $handlerFailure) implements OperationHandler {
            public function __construct(
                private readonly TransactionRuntimeState $state,
                private int &$handlerCalls,
                private readonly bool $handlerFailure,
            ) {}

            public function handle(OperationDescriptor $descriptor, OperationContext $context): array
            {
                $this->handlerCalls++;
                $this->state->writes++;

                if ($this->handlerFailure) {
                    throw new RuntimeException('database_password_must_not_escape');
                }

                return ['written' => true];
            }
        },
        $boundary,
    );
}

$descriptor = new OperationDescriptor(
    name: 'auth.administrator.suspend',
    executionMode: OperationExecutionMode::Sync,
    accessScope: 'auth.user.suspend',
    requiredCapability: 'rest.request.schema',
    auditEvent: 'rest.operation.result',
    transactional: true,
);
$context = new OperationContext('user:admin_identity:1', 'rest-correlation-1');

$successState = new TransactionRuntimeState();
$successBoundary = new RecordingTransactionBoundary($successState);
$successCalls = 0;
$success = transactionRuntime($successState, $successBoundary, $successCalls)->execute($descriptor, $context);
assertTransactionRuntime($success->successful(), 'Transactional success must return success.');
assertTransactionRuntime($successState->writes === 1, 'Transactional success must retain the handler write.');
assertTransactionRuntime($successBoundary->commits === 1 && $successBoundary->rollbacks === 0, 'Transactional success must commit exactly once.');
assertTransactionRuntime($successCalls === 1, 'Transactional success must call the handler once.');

$resultFailureState = new TransactionRuntimeState();
$resultFailureBoundary = new RecordingTransactionBoundary($resultFailureState);
$resultFailureCalls = 0;
$resultFailure = transactionRuntime($resultFailureState, $resultFailureBoundary, $resultFailureCalls, 'result')->execute($descriptor, $context);
assertTransactionRuntime(!$resultFailure->successful(), 'Result Audit failure must fail closed.');
assertTransactionRuntime($resultFailure->normalizedError['code'] === 'audit_result_failed', 'Result Audit failure must retain a stable error code.');
assertTransactionRuntime($resultFailureState->writes === 0, 'Result Audit failure must roll the handler write back.');
assertTransactionRuntime($resultFailureBoundary->rollbacks === 1 && $resultFailureBoundary->commits === 0, 'Result Audit failure must roll back exactly once.');
assertTransactionRuntime($resultFailureCalls === 1, 'Result Audit failure occurs after one handler call.');
assertTransactionRuntime(($resultFailure->runtimeTrace['transaction_rolled_back'] ?? false) === true, 'Rollback must be explicit in the runtime trace.');
assertTransactionRuntime($resultFailure->auditEvents === [], 'Rolled-back Audit events must not be reported as persisted.');
assertTransactionRuntime(!str_contains(json_encode($resultFailure, JSON_THROW_ON_ERROR), 'database details'), 'Audit exception details must not escape.');
assertTransactionRuntime(
    $resultFailureBoundary->lastFailure instanceof OperationTransactionAborted
        && $resultFailureBoundary->lastFailure->getPrevious() instanceof RuntimeException
        && $resultFailureBoundary->lastFailure->getPrevious()->getMessage() === 'database details must not escape',
    'The internal transaction abort must retain a result-Audit failure for boundary-level retry classification.',
);

$decisionFailureState = new TransactionRuntimeState();
$decisionFailureBoundary = new RecordingTransactionBoundary($decisionFailureState);
$decisionFailureCalls = 0;
$decisionFailure = transactionRuntime($decisionFailureState, $decisionFailureBoundary, $decisionFailureCalls, 'decision')->execute($descriptor, $context);
assertTransactionRuntime(!$decisionFailure->successful(), 'Decision Audit failure must fail closed.');
assertTransactionRuntime($decisionFailure->normalizedError['code'] === 'audit_decision_failed', 'Decision Audit failure must retain a stable error code.');
assertTransactionRuntime($decisionFailureCalls === 0 && $decisionFailureState->writes === 0, 'Decision Audit failure must stop before the handler.');
assertTransactionRuntime($decisionFailureBoundary->rollbacks === 1, 'Decision Audit failure must close the transaction by rollback.');
assertTransactionRuntime(
    count($decisionFailure->auditEvents) === 1 && ($decisionFailure->auditEvents[0]['phase'] ?? null) === 'rollback',
    'Confirmed decision rollback must retain only its post-transaction rollback Audit event.',
);
assertTransactionRuntime(
    $decisionFailureBoundary->lastFailure instanceof OperationTransactionAborted
        && $decisionFailureBoundary->lastFailure->getPrevious() instanceof RuntimeException
        && $decisionFailureBoundary->lastFailure->getPrevious()->getMessage() === 'database details must not escape',
    'The internal transaction abort must retain a decision-Audit failure for boundary-level retry classification.',
);

$handlerFailureState = new TransactionRuntimeState();
$handlerFailureBoundary = new RecordingTransactionBoundary($handlerFailureState);
$handlerFailureCalls = 0;
$handlerFailureAudit = new TransactionRuntimeAudit();
$handlerFailure = transactionRuntime($handlerFailureState, $handlerFailureBoundary, $handlerFailureCalls, null, true, $handlerFailureAudit)->execute($descriptor, $context);
assertTransactionRuntime(!$handlerFailure->successful(), 'Handler failure must fail closed.');
assertTransactionRuntime($handlerFailure->normalizedError['code'] === 'handler_failed', 'Handler failure must retain a stable error code.');
assertTransactionRuntime($handlerFailureCalls === 1, 'Handler failure occurs after one handler call.');
assertTransactionRuntime($handlerFailureState->writes === 0, 'Handler failure must roll partial writes back.');
assertTransactionRuntime($handlerFailureBoundary->rollbacks === 1 && $handlerFailureBoundary->commits === 0, 'Handler failure must roll back exactly once.');
assertTransactionRuntime($handlerFailureAudit->resultCalls === 1, 'Handler failure must emit only the post-confirmed-rollback Audit event, including when the database already rolled back after a deadlock.');
assertTransactionRuntime(
    count($handlerFailure->auditEvents) === 1 && ($handlerFailure->auditEvents[0]['phase'] ?? null) === 'rollback',
    'Only the post-transaction rollback Audit event may be reported as persisted.',
);
assertTransactionRuntime(($handlerFailure->runtimeTrace['transaction_rolled_back'] ?? false) === true, 'Handler failure rollback must be explicit in the runtime trace.');
assertTransactionRuntime(!str_contains(json_encode($handlerFailure, JSON_THROW_ON_ERROR), 'database_password'), 'Handler exception details must not escape.');
assertTransactionRuntime(
    $handlerFailureBoundary->lastFailure instanceof OperationTransactionAborted
        && $handlerFailureBoundary->lastFailure->getPrevious() instanceof RuntimeException
        && $handlerFailureBoundary->lastFailure->getPrevious()->getMessage() === 'database_password_must_not_escape',
    'The internal transaction abort must retain the original handler failure for boundary-level retry classification.',
);
assertTransactionRuntime(
    $handlerFailureBoundary->lastFailure instanceof OperationTransactionAborted
        && !str_contains(json_encode($handlerFailureBoundary->lastFailure->result, JSON_THROW_ON_ERROR), 'database_password'),
    'The retained internal failure cause must not leak into the normalized operation result.',
);

$missingBoundaryAudit = new TransactionRuntimeAudit();
$missingBoundary = new SyncOperationRuntime(
    new class implements OperationAccessGate {
        public function decideAccess(OperationDescriptor $descriptor, OperationContext $context): OperationDecision
        {
            return OperationDecision::allowed(OperationExecutionMode::Sync);
        }
    },
    new class implements OperationCapabilityGate {
        public function decideCapability(OperationDescriptor $descriptor, OperationContext $context): OperationDecision
        {
            return OperationDecision::allowed(OperationExecutionMode::Sync);
        }
    },
    $missingBoundaryAudit,
    new class implements OperationHandler {
        public function handle(OperationDescriptor $descriptor, OperationContext $context): array
        {
            throw new RuntimeException('handler_must_not_run');
        }
    },
);
$missing = $missingBoundary->execute($descriptor, $context);
assertTransactionRuntime($missing->normalizedError['code'] === 'transaction_boundary_missing', 'Required transaction without a boundary must fail closed.');
assertTransactionRuntime($missingBoundaryAudit->decisionCalls === 1 && $missingBoundaryAudit->resultCalls === 1, 'Missing transaction infrastructure must be audited without calling the handler.');

$boundaryFailureAudit = new TransactionRuntimeAudit();
$boundaryFailure = new SyncOperationRuntime(
    new class implements OperationAccessGate {
        public function decideAccess(OperationDescriptor $descriptor, OperationContext $context): OperationDecision
        {
            return OperationDecision::allowed(OperationExecutionMode::Sync);
        }
    },
    new class implements OperationCapabilityGate {
        public function decideCapability(OperationDescriptor $descriptor, OperationContext $context): OperationDecision
        {
            return OperationDecision::allowed(OperationExecutionMode::Sync);
        }
    },
    $boundaryFailureAudit,
    new class implements OperationHandler {
        public function handle(OperationDescriptor $descriptor, OperationContext $context): array
        {
            throw new RuntimeException('handler_must_not_run');
        }
    },
    new ExplodingTransactionBoundary(),
);
$unknown = $boundaryFailure->execute($descriptor, $context);
assertTransactionRuntime($unknown->normalizedError['code'] === 'transaction_failed', 'Boundary failure must return a stable failure code.');
assertTransactionRuntime(($unknown->runtimeTrace['transaction_outcome'] ?? null) === 'unknown', 'Boundary failure must not claim a confirmed rollback.');
assertTransactionRuntime(($unknown->runtimeTrace['rollback_confirmed'] ?? null) === false, 'Boundary failure must explicitly mark rollback as unconfirmed.');
assertTransactionRuntime(!str_contains(json_encode($unknown, JSON_THROW_ON_ERROR), 'database_password'), 'Boundary exception details must not escape.');

echo "SyncOperationRuntimeTransactionBoundaryTest passed.\n";
