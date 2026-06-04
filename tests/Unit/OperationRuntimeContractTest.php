<?php

declare(strict_types=1);

use Larena\Core\Contracts\OperationContext;
use Larena\Core\Contracts\OperationDecision;
use Larena\Core\Contracts\OperationDescriptor;
use Larena\Core\Contracts\OperationResult;
use Larena\Core\Contracts\OperationRuntime;
use Larena\Core\Enums\OperationDecisionStatus;
use Larena\Core\Enums\OperationExecutionMode;

require_once __DIR__ . '/../../src/Enums/OperationExecutionMode.php';
require_once __DIR__ . '/../../src/Enums/OperationDecisionStatus.php';
require_once __DIR__ . '/../../src/Contracts/OperationDecision.php';
require_once __DIR__ . '/../../src/Contracts/OperationDescriptor.php';
require_once __DIR__ . '/../../src/Contracts/OperationContext.php';
require_once __DIR__ . '/../../src/Contracts/OperationResult.php';
require_once __DIR__ . '/../../src/Contracts/OperationRuntime.php';

function assertContractTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$runtime = new class implements OperationRuntime {
    public function decide(OperationDescriptor $descriptor, OperationContext $context): OperationDecision
    {
        if ($descriptor->executionMode === OperationExecutionMode::Denied) {
            return OperationDecision::denied('mode_denied', 'Denied execution mode cannot run.');
        }

        if (($context->capabilityContext['locked'] ?? false) === true) {
            return OperationDecision::capabilityLocked('capability_locked');
        }

        return OperationDecision::allowed($descriptor->executionMode);
    }

    public function execute(OperationDescriptor $descriptor, OperationContext $context): OperationResult
    {
        $decision = $this->decide($descriptor, $context);

        return OperationResult::fromDecision(
            $decision,
            $decision->handlerMayRun ? ['handled' => false, 'contract_only' => true] : null,
            [['event' => $descriptor->auditEvent, 'correlation_id' => $context->correlationId]],
            ['mode' => $descriptor->executionMode->value, 'contract_only' => true],
        );
    }

    public function explain(OperationDescriptor $descriptor, OperationContext $context): array
    {
        return [
            'operation' => $descriptor->name,
            'actor_id' => $context->actorId,
            'correlation_id' => $context->correlationId,
            'access_scope' => $descriptor->accessScope,
            'required_capability' => $descriptor->requiredCapability,
            'execution_mode' => $descriptor->executionMode->value,
        ];
    }
};

$descriptor = new OperationDescriptor(
    name: 'core.operation_runtime.execute',
    executionMode: OperationExecutionMode::Queued,
    accessScope: 'core.operations.execute',
    requiredCapability: 'core.operation_runtime',
    auditEvent: 'core.operation.requested',
    idempotencyKey: 'operation-1',
);

$context = new OperationContext(
    actorId: 'user:1',
    correlationId: 'corr-1',
    accessContext: ['decision' => 'allowed'],
    capabilityContext: ['locked' => false],
    auditContext: ['request_id' => 'request-1'],
);

$decision = $runtime->decide($descriptor, $context);
$result = $runtime->execute($descriptor, $context);
$explain = $runtime->explain($descriptor, $context);

assertContractTrue($descriptor->requiresAccessDecision(), 'Descriptor must expose access decision slot.');
assertContractTrue($descriptor->requiresCapabilityDecision(), 'Descriptor must expose capability decision slot.');
assertContractTrue($descriptor->requiresAuditEvent(), 'Descriptor must expose audit event slot.');
assertContractTrue($decision->status === OperationDecisionStatus::Allowed, 'Decision must allow safe queued contract execution.');
assertContractTrue($decision->executionMode === OperationExecutionMode::Queued, 'Decision must preserve queued execution mode.');
assertContractTrue($result->successful(), 'Allowed contract result must be successful.');
assertContractTrue($result->auditEvents[0]['correlation_id'] === 'corr-1', 'Result must preserve audit correlation.');
assertContractTrue($explain['access_scope'] === 'core.operations.execute', 'Explain output must include access scope.');
assertContractTrue($explain['required_capability'] === 'core.operation_runtime', 'Explain output must include capability.');

$executionModeValues = array_map(
    static fn (OperationExecutionMode $mode): string => $mode->value,
    OperationExecutionMode::cases(),
);

sort($executionModeValues);

assertContractTrue(
    $executionModeValues === ['denied', 'queued', 'scheduled', 'sync'],
    'All supported execution modes must be represented by explicit enum values.',
);

echo "OperationRuntimeContractTest passed.\n";
