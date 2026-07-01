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

require_once __DIR__ . '/../bootstrap.php';

function assertRuntimeSecurityCompositionTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

final class CompositionTrace
{
    /**
     * @var list<string>
     */
    public array $steps = [];
}

final readonly class AllowingCompositionAccessGate implements OperationAccessGate
{
    public function __construct(private CompositionTrace $trace)
    {
    }

    public function decideAccess(OperationDescriptor $descriptor, OperationContext $context): OperationDecision
    {
        $this->trace->steps[] = 'access';

        return OperationDecision::allowed(OperationExecutionMode::Sync, 'access_allowed');
    }
}

final readonly class AllowingCompositionCapabilityGate implements OperationCapabilityGate
{
    public function __construct(private CompositionTrace $trace)
    {
    }

    public function decideCapability(OperationDescriptor $descriptor, OperationContext $context): OperationDecision
    {
        $this->trace->steps[] = 'capability';

        return OperationDecision::allowed(OperationExecutionMode::Sync, 'capability_allowed');
    }
}

final readonly class CompositionHandler implements OperationHandler
{
    public function __construct(private CompositionTrace $trace)
    {
    }

    public function handle(OperationDescriptor $descriptor, OperationContext $context): array
    {
        $this->trace->steps[] = 'handler';

        return [
            'handled' => true,
            'operation' => $descriptor->name,
        ];
    }
}

final readonly class CompositionAuditRecorder implements OperationAuditRecorder
{
    public function __construct(private CompositionTrace $trace)
    {
    }

    public function recordDecision(OperationDescriptor $descriptor, OperationContext $context, OperationDecision $decision, string $phase): array
    {
        $this->trace->steps[] = 'audit:' . $phase;

        return $this->event($descriptor, $context, $decision->status->value, $phase);
    }

    public function recordResult(OperationDescriptor $descriptor, OperationContext $context, OperationResult $result, string $phase): array
    {
        $this->trace->steps[] = 'audit:' . $phase;

        return $this->event($descriptor, $context, $result->decision->status->value, $phase);
    }

    /**
     * @return array<string, mixed>
     */
    private function event(OperationDescriptor $descriptor, OperationContext $context, string $status, string $phase): array
    {
        return [
            'operation' => $descriptor->name,
            'correlation_id' => $context->correlationId,
            'phase' => $phase,
            'status' => $status,
        ];
    }
}

$trace = new CompositionTrace();
$runtime = new SyncOperationRuntime(
    new AllowingCompositionAccessGate($trace),
    new AllowingCompositionCapabilityGate($trace),
    new CompositionAuditRecorder($trace),
    new CompositionHandler($trace),
);

$descriptor = new OperationDescriptor(
    name: 'core.runtime_security.compose',
    executionMode: OperationExecutionMode::Sync,
    accessScope: 'core.operations.execute',
    requiredCapability: 'core.operation_runtime',
    auditEvent: 'core.operation.executed',
);
$context = new OperationContext('user:1', 'corr-runtime-security-1');

$result = $runtime->execute($descriptor, $context);

assertRuntimeSecurityCompositionTrue($result->decision->status === OperationDecisionStatus::Allowed, 'Runtime-security composition must allow positive flow.');
assertRuntimeSecurityCompositionTrue($result->successful(), 'Runtime-security composition must return successful result.');
assertRuntimeSecurityCompositionTrue($result->payload === ['handled' => true, 'operation' => 'core.runtime_security.compose'], 'Runtime-security composition must return handler payload.');
assertRuntimeSecurityCompositionTrue(count($result->auditEvents) === 2, 'Runtime-security composition must record decision and result audit events.');
assertRuntimeSecurityCompositionTrue($trace->steps === ['access', 'capability', 'audit:decision', 'handler', 'audit:result'], 'Runtime-security composition must preserve access -> capability -> audit decision -> handler -> audit result order.');

echo "RuntimeSecurityCompositionTest passed.\n";
