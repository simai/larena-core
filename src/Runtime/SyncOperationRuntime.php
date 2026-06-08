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
use Larena\Core\Enums\OperationDecisionStatus;
use Larena\Core\Enums\OperationExecutionMode;
use Throwable;

final readonly class SyncOperationRuntime implements OperationRuntime
{
    public function __construct(
        private OperationAccessGate $accessGate,
        private OperationCapabilityGate $capabilityGate,
        private OperationAuditRecorder $auditRecorder,
        private OperationHandler $handler,
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
        $decision = $this->decide($descriptor, $context);
        $decisionAudit = $this->recordDecision($descriptor, $context, $decision);

        if ($decisionAudit['failed'] === true) {
            $failedDecision = OperationDecision::invalid('audit_decision_failed', (string) $decisionAudit['message']);

            return OperationResult::fromDecision(
                $failedDecision,
                null,
                [],
                $this->auditFailureTrace($descriptor, $context, $failedDecision, 'decision', $decisionAudit),
            );
        }

        $auditEvents = [$decisionAudit['event']];

        if (!$decision->handlerMayRun) {
            $result = OperationResult::fromDecision($decision, null, $auditEvents, $this->trace($descriptor, $context, $decision));
            $resultAudit = $this->recordResult($descriptor, $context, $result);

            if ($resultAudit['failed'] === true) {
                $failedDecision = OperationDecision::invalid('audit_result_failed', (string) $resultAudit['message']);

                return OperationResult::fromDecision(
                    $failedDecision,
                    null,
                    $auditEvents,
                    $this->auditFailureTrace($descriptor, $context, $failedDecision, 'result', $resultAudit),
                );
            }

            $auditEvents[] = $resultAudit['event'];

            return OperationResult::fromDecision($decision, null, $auditEvents, $this->trace($descriptor, $context, $decision));
        }

        try {
            $payload = $this->handler->handle($descriptor, $context);
            $result = OperationResult::fromDecision($decision, $payload, $auditEvents, $this->trace($descriptor, $context, $decision));
        } catch (Throwable $throwable) {
            $failedDecision = OperationDecision::invalid('handler_failed', $throwable->getMessage());
            $result = OperationResult::fromDecision($failedDecision, null, $auditEvents, $this->trace($descriptor, $context, $failedDecision));
        }

        $resultAudit = $this->recordResult($descriptor, $context, $result);

        if ($resultAudit['failed'] === true) {
            $failedDecision = OperationDecision::invalid('audit_result_failed', (string) $resultAudit['message']);

            return OperationResult::fromDecision(
                $failedDecision,
                null,
                $auditEvents,
                $this->auditFailureTrace($descriptor, $context, $failedDecision, 'result', $resultAudit),
            );
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
     * @return array{failed: bool, event?: array<string, mixed>, message?: string, exception_class?: string}
     */
    private function recordDecision(OperationDescriptor $descriptor, OperationContext $context, OperationDecision $decision): array
    {
        try {
            return [
                'failed' => false,
                'event' => $this->auditRecorder->recordDecision($descriptor, $context, $decision, 'decision'),
            ];
        } catch (Throwable $throwable) {
            return $this->auditFailure($throwable);
        }
    }

    /**
     * @return array{failed: bool, event?: array<string, mixed>, message?: string, exception_class?: string}
     */
    private function recordResult(OperationDescriptor $descriptor, OperationContext $context, OperationResult $result): array
    {
        try {
            return [
                'failed' => false,
                'event' => $this->auditRecorder->recordResult($descriptor, $context, $result, 'result'),
            ];
        } catch (Throwable $throwable) {
            return $this->auditFailure($throwable);
        }
    }

    /**
     * @return array{failed: true, message: string, exception_class: class-string<Throwable>}
     */
    private function auditFailure(Throwable $throwable): array
    {
        return [
            'failed' => true,
            'message' => 'Audit recording failed: ' . $throwable->getMessage(),
            'exception_class' => $throwable::class,
        ];
    }

    /**
     * @param array<string, mixed> $failure
     *
     * @return array<string, mixed>
     */
    private function auditFailureTrace(
        OperationDescriptor $descriptor,
        OperationContext $context,
        OperationDecision $decision,
        string $phase,
        array $failure,
    ): array {
        return [
            ...$this->trace($descriptor, $context, $decision),
            'audit_failure_phase' => $phase,
            'audit_failure_message' => $failure['message'] ?? 'Audit recording failed.',
            'audit_failure_exception_class' => $failure['exception_class'] ?? null,
        ];
    }
}
