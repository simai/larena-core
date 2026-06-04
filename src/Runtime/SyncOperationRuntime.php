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
        $auditEvents = [
            $this->auditRecorder->recordDecision($descriptor, $context, $decision, 'decision'),
        ];

        if (!$decision->handlerMayRun) {
            $result = OperationResult::fromDecision($decision, null, $auditEvents, $this->trace($descriptor, $context, $decision));
            $auditEvents[] = $this->auditRecorder->recordResult($descriptor, $context, $result, 'result');

            return OperationResult::fromDecision($decision, null, $auditEvents, $this->trace($descriptor, $context, $decision));
        }

        try {
            $payload = $this->handler->handle($descriptor, $context);
            $result = OperationResult::fromDecision($decision, $payload, $auditEvents, $this->trace($descriptor, $context, $decision));
        } catch (Throwable $throwable) {
            $failedDecision = OperationDecision::invalid('handler_failed', $throwable->getMessage());
            $result = OperationResult::fromDecision($failedDecision, null, $auditEvents, $this->trace($descriptor, $context, $failedDecision));
        }

        $auditEvents[] = $this->auditRecorder->recordResult($descriptor, $context, $result, 'result');

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
}
