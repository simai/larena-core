<?php

declare(strict_types=1);

namespace Larena\Core\Runtime;

use Larena\Core\Contracts\OperationAccessGate;
use Larena\Core\Contracts\OperationAuditRecorder;
use Larena\Core\Contracts\OperationCapabilityGate;
use Larena\Core\Contracts\OperationContext;
use Larena\Core\Contracts\OperationDecision;
use Larena\Core\Contracts\OperationDescriptor;
use Larena\Core\Contracts\OperationHandler;
use Larena\Core\Contracts\OperationResult;
use Larena\Core\Contracts\OperationRuntime;
use Larena\Core\Contracts\OperationTransactionBoundary;
use Larena\Core\Enums\OperationDecisionStatus;
use Larena\Core\Enums\OperationExecutionMode;
use Larena\Core\Exceptions\OperationTransactionAborted;
use Throwable;

final readonly class SyncOperationRuntime implements OperationRuntime
{
    public function __construct(
        private OperationAccessGate $accessGate,
        private OperationCapabilityGate $capabilityGate,
        private OperationAuditRecorder $auditRecorder,
        private OperationHandler $handler,
        private ?OperationTransactionBoundary $transactionBoundary = null,
    ) {
    }

    public function decide(OperationDescriptor $descriptor, OperationContext $context): OperationDecision
    {
        if ($descriptor->executionMode !== OperationExecutionMode::Sync) {
            return OperationDecision::invalid(
                'unsupported_execution_mode',
                'The synchronous operation runtime can execute only sync operations.',
            );
        }

        $accessDecision = $descriptor->requiresAccessDecision()
            ? $this->accessGate->decideAccess($descriptor, $context)
            : OperationDecision::allowed(OperationExecutionMode::Sync, 'access_not_required');

        if ($accessDecision->status !== OperationDecisionStatus::Allowed) {
            return $accessDecision;
        }

        $capabilityDecision = $descriptor->requiresCapabilityDecision()
            ? $this->capabilityGate->decideCapability($descriptor, $context)
            : OperationDecision::allowed(OperationExecutionMode::Sync, 'capability_not_required');

        if ($capabilityDecision->status !== OperationDecisionStatus::Allowed) {
            return $capabilityDecision;
        }

        return OperationDecision::allowed(OperationExecutionMode::Sync);
    }

    public function execute(OperationDescriptor $descriptor, OperationContext $context): OperationResult
    {
        if (!$descriptor->requiresTransactionBoundary()) {
            return $this->executeLifecycle($descriptor, $context, false);
        }

        if (!$this->transactionBoundary instanceof OperationTransactionBoundary) {
            $decision = OperationDecision::invalid(
                'transaction_boundary_missing',
                'A required operation transaction boundary is unavailable.',
            );

            return $this->auditedInfrastructureFailure($descriptor, $context, $decision);
        }

        try {
            /** @var OperationResult $result */
            $result = $this->transactionBoundary->run(
                fn (): OperationResult => $this->executeLifecycle($descriptor, $context, true),
            );

            return $result;
        } catch (OperationTransactionAborted $aborted) {
            $rolledBack = new OperationResult(
                decision: $aborted->result->decision,
                payload: null,
                normalizedError: $aborted->result->normalizedError,
                auditEvents: [],
                runtimeTrace: [
                    ...$aborted->result->runtimeTrace,
                    'transaction_rolled_back' => true,
                ],
            );
            $rollbackAudit = $this->recordResult($descriptor, $context, $rolledBack, 'rollback');
            if ($rollbackAudit['failed'] === true) {
                $failedDecision = OperationDecision::invalid(
                    'audit_result_failed',
                    (string) $rollbackAudit['message'],
                );

                return OperationResult::fromDecision(
                    $failedDecision,
                    null,
                    [],
                    [
                        ...$rolledBack->runtimeTrace,
                        'audit_failure_phase' => 'rollback',
                        'audit_failure_code' => 'audit_recording_failed',
                    ],
                );
            }

            return new OperationResult(
                decision: $rolledBack->decision,
                payload: null,
                normalizedError: $rolledBack->normalizedError,
                auditEvents: [$rollbackAudit['event']],
                runtimeTrace: $rolledBack->runtimeTrace,
            );
        } catch (Throwable) {
            $decision = OperationDecision::invalid(
                'transaction_failed',
                'The operation transaction failed safely.',
            );

            return OperationResult::fromDecision(
                $decision,
                null,
                [],
                [
                    ...$this->trace($descriptor, $context, $decision),
                    'transaction_outcome' => 'unknown',
                    'rollback_confirmed' => false,
                ],
            );
        }
    }

    private function executeLifecycle(
        OperationDescriptor $descriptor,
        OperationContext $context,
        bool $abortOnFailure,
    ): OperationResult
    {
        $decision = $this->decide($descriptor, $context);
        $decisionAudit = $this->recordDecision($descriptor, $context, $decision);

        if ($decisionAudit['failed'] === true) {
            $failedDecision = OperationDecision::invalid('audit_decision_failed', (string) $decisionAudit['message']);

            $result = OperationResult::fromDecision(
                $failedDecision,
                null,
                [],
                $this->auditFailureTrace($descriptor, $context, $failedDecision, 'decision'),
            );

            if ($abortOnFailure) {
                throw new OperationTransactionAborted($result, $decisionAudit['cause'] ?? null);
            }

            return $result;
        }

        $auditEvents = [$decisionAudit['event']];

        if (!$decision->handlerMayRun) {
            $result = OperationResult::fromDecision($decision, null, $auditEvents, $this->trace($descriptor, $context, $decision));
            $resultAudit = $this->recordResult($descriptor, $context, $result);

            if ($resultAudit['failed'] === true) {
                $failedDecision = OperationDecision::invalid('audit_result_failed', (string) $resultAudit['message']);

                $failed = OperationResult::fromDecision(
                    $failedDecision,
                    null,
                    $auditEvents,
                    $this->auditFailureTrace($descriptor, $context, $failedDecision, 'result'),
                );

                if ($abortOnFailure) {
                    throw new OperationTransactionAborted($failed, $resultAudit['cause'] ?? null);
                }

                return $failed;
            }

            $auditEvents[] = $resultAudit['event'];

            return OperationResult::fromDecision($decision, null, $auditEvents, $this->trace($descriptor, $context, $decision));
        }

        try {
            $payload = $this->handler->handle($descriptor, $context);
            $result = OperationResult::fromDecision($decision, $payload, $auditEvents, $this->trace($descriptor, $context, $decision));
        } catch (Throwable $failure) {
            $failedDecision = OperationDecision::invalid('handler_failed', 'The operation handler failed safely.');
            $result = OperationResult::fromDecision(
                $failedDecision,
                null,
                $auditEvents,
                $this->trace($descriptor, $context, $failedDecision),
            );

            // A database deadlock may already have rolled the outer
            // transaction back before control returns from the handler. Do not
            // risk persisting a result event in autocommit mode: the caller
            // records exactly one rollback event only after the boundary has
            // confirmed the rollback outcome.
            if ($abortOnFailure) {
                throw new OperationTransactionAborted($result, $failure);
            }
        }

        $resultAudit = $this->recordResult($descriptor, $context, $result);

        if ($resultAudit['failed'] === true) {
            $failedDecision = OperationDecision::invalid('audit_result_failed', (string) $resultAudit['message']);

            $failed = OperationResult::fromDecision(
                $failedDecision,
                null,
                $auditEvents,
                $this->auditFailureTrace($descriptor, $context, $failedDecision, 'result'),
            );

            if ($abortOnFailure) {
                throw new OperationTransactionAborted($failed, $resultAudit['cause'] ?? null);
            }

            return $failed;
        }

        $auditEvents[] = $resultAudit['event'];

        return OperationResult::fromDecision($result->decision, $result->payload, $auditEvents, $result->runtimeTrace);
    }

    public function explain(OperationDescriptor $descriptor, OperationContext $context): array
    {
        $decision = $this->decide($descriptor, $context);

        return [
            'operation' => $descriptor->name,
            'actor_id' => $context->actorId,
            'correlation_id' => $context->correlationId,
            'access_scope' => $descriptor->accessScope,
            'required_capability' => $descriptor->requiredCapability,
            'execution_mode' => $descriptor->executionMode->value,
            'decision_status' => $decision->status->value,
            'decision_reason' => $decision->reasonCode,
            'handler_may_run' => $decision->handlerMayRun,
        ];
    }

    private function auditedInfrastructureFailure(
        OperationDescriptor $descriptor,
        OperationContext $context,
        OperationDecision $decision,
    ): OperationResult {
        $decisionAudit = $this->recordDecision($descriptor, $context, $decision);
        if ($decisionAudit['failed'] === true) {
            $auditDecision = OperationDecision::invalid(
                'audit_decision_failed',
                (string) $decisionAudit['message'],
            );

            return OperationResult::fromDecision(
                $auditDecision,
                null,
                [],
                $this->auditFailureTrace($descriptor, $context, $auditDecision, 'decision'),
            );
        }

        $auditEvents = [$decisionAudit['event']];
        $result = OperationResult::fromDecision(
            $decision,
            null,
            $auditEvents,
            $this->trace($descriptor, $context, $decision),
        );
        $resultAudit = $this->recordResult($descriptor, $context, $result);
        if ($resultAudit['failed'] === true) {
            $auditDecision = OperationDecision::invalid(
                'audit_result_failed',
                (string) $resultAudit['message'],
            );

            return OperationResult::fromDecision(
                $auditDecision,
                null,
                $auditEvents,
                $this->auditFailureTrace($descriptor, $context, $auditDecision, 'result'),
            );
        }

        $auditEvents[] = $resultAudit['event'];

        return OperationResult::fromDecision(
            $decision,
            null,
            $auditEvents,
            $this->trace($descriptor, $context, $decision),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function trace(OperationDescriptor $descriptor, OperationContext $context, OperationDecision $decision): array
    {
        return [
            'operation' => $descriptor->name,
            'actor_id' => $context->actorId,
            'correlation_id' => $context->correlationId,
            'execution_mode' => $descriptor->executionMode->value,
            'decision_status' => $decision->status->value,
            'decision_reason' => $decision->reasonCode,
        ];
    }

    /**
     * @return array{failed: bool, event?: array<string, mixed>, message?: string, cause?: Throwable}
     */
    private function recordDecision(OperationDescriptor $descriptor, OperationContext $context, OperationDecision $decision): array
    {
        try {
            return [
                'failed' => false,
                'event' => $this->auditRecorder->recordDecision($descriptor, $context, $decision, 'decision'),
            ];
        } catch (Throwable $failure) {
            return $this->auditFailure($failure);
        }
    }

    /**
     * @return array{failed: bool, event?: array<string, mixed>, message?: string, cause?: Throwable}
     */
    private function recordResult(
        OperationDescriptor $descriptor,
        OperationContext $context,
        OperationResult $result,
        string $phase = 'result',
    ): array
    {
        try {
            return [
                'failed' => false,
                'event' => $this->auditRecorder->recordResult($descriptor, $context, $result, $phase),
            ];
        } catch (Throwable $failure) {
            return $this->auditFailure($failure);
        }
    }

    /**
     * @return array{failed: true, message: string, cause: Throwable}
     */
    private function auditFailure(Throwable $failure): array
    {
        return [
            'failed' => true,
            'message' => 'Audit recording failed safely.',
            'cause' => $failure,
        ];
    }

    /** @return array<string, mixed> */
    private function auditFailureTrace(
        OperationDescriptor $descriptor,
        OperationContext $context,
        OperationDecision $decision,
        string $phase,
    ): array {
        return [
            ...$this->trace($descriptor, $context, $decision),
            'audit_failure_phase' => $phase,
            'audit_failure_code' => 'audit_recording_failed',
        ];
    }

}
