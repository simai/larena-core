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
use Larena\Core\Enums\OperationExecutionMode;
use Larena\Core\Runtime\SyncOperationRuntime;

require_once __DIR__ . '/../bootstrap.php';

function assertRuntimeSecurityFailsClosedTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

final class FailsClosedTrace
{
    /**
     * @var list<string>
     */
    public array $steps = [];
}

final readonly class FailsClosedAuditRecorder implements OperationAuditRecorder
{
    public function __construct(private FailsClosedTrace $trace)
    {
    }

    public function recordDecision(OperationDescriptor $descriptor, OperationContext $context, OperationDecision $decision, string $phase): array
    {
        $this->trace->steps[] = 'audit:' . $phase;

        return ['phase' => $phase, 'status' => $decision->status->value];
    }

    public function recordResult(OperationDescriptor $descriptor, OperationContext $context, OperationResult $result, string $phase): array
    {
        $this->trace->steps[] = 'audit:' . $phase;

        return ['phase' => $phase, 'status' => $result->decision->status->value];
    }
}

final readonly class TracedHandler implements OperationHandler
{
    public function __construct(private FailsClosedTrace $trace, private bool $throw = false)
    {
    }

    public function handle(OperationDescriptor $descriptor, OperationContext $context): array
    {
        $this->trace->steps[] = 'handler';

        if ($this->throw) {
            throw new RuntimeException('handler boom');
        }

        return ['handled' => true];
    }
}

function runtimeSecurityDescriptor(): OperationDescriptor
{
    return new OperationDescriptor(
        name: 'core.runtime_security.compose',
        executionMode: OperationExecutionMode::Sync,
        accessScope: 'core.operations.execute',
        requiredCapability: 'core.operation_runtime',
        auditEvent: 'core.operation.executed',
    );
}

$context = new OperationContext('user:1', 'corr-runtime-security-denied');

$accessTrace = new FailsClosedTrace();
$accessDeniedRuntime = new SyncOperationRuntime(
    new class($accessTrace) implements OperationAccessGate {
        public function __construct(private FailsClosedTrace $trace)
        {
        }

        public function decideAccess(OperationDescriptor $descriptor, OperationContext $context): OperationDecision
        {
            $this->trace->steps[] = 'access';

            return OperationDecision::denied('access_denied');
        }
    },
    new class($accessTrace) implements OperationCapabilityGate {
        public function __construct(private FailsClosedTrace $trace)
        {
        }

        public function decideCapability(OperationDescriptor $descriptor, OperationContext $context): OperationDecision
        {
            $this->trace->steps[] = 'capability';

            return OperationDecision::allowed(OperationExecutionMode::Sync, 'capability_allowed');
        }
    },
    new FailsClosedAuditRecorder($accessTrace),
    new TracedHandler($accessTrace),
);

$accessDenied = $accessDeniedRuntime->execute(runtimeSecurityDescriptor(), $context);

assertRuntimeSecurityFailsClosedTrue(!$accessDenied->successful(), 'Access denied runtime must fail closed.');
assertRuntimeSecurityFailsClosedTrue($accessDenied->normalizedError['code'] === 'access_denied', 'Access denial must expose normalized reason.');
assertRuntimeSecurityFailsClosedTrue($accessTrace->steps === ['access', 'audit:decision', 'audit:result'], 'Access denial must stop before capability and handler while still auditing.');

$capabilityTrace = new FailsClosedTrace();
$capabilityDeniedRuntime = new SyncOperationRuntime(
    new class($capabilityTrace) implements OperationAccessGate {
        public function __construct(private FailsClosedTrace $trace)
        {
        }

        public function decideAccess(OperationDescriptor $descriptor, OperationContext $context): OperationDecision
        {
            $this->trace->steps[] = 'access';

            return OperationDecision::allowed(OperationExecutionMode::Sync, 'access_allowed');
        }
    },
    new class($capabilityTrace) implements OperationCapabilityGate {
        public function __construct(private FailsClosedTrace $trace)
        {
        }

        public function decideCapability(OperationDescriptor $descriptor, OperationContext $context): OperationDecision
        {
            $this->trace->steps[] = 'capability';

            return OperationDecision::capabilityLocked('capability_locked');
        }
    },
    new FailsClosedAuditRecorder($capabilityTrace),
    new TracedHandler($capabilityTrace),
);

$capabilityDenied = $capabilityDeniedRuntime->execute(runtimeSecurityDescriptor(), $context);

assertRuntimeSecurityFailsClosedTrue(!$capabilityDenied->successful(), 'Capability denied runtime must fail closed.');
assertRuntimeSecurityFailsClosedTrue($capabilityDenied->normalizedError['code'] === 'capability_locked', 'Capability denial must expose normalized reason.');
assertRuntimeSecurityFailsClosedTrue($capabilityTrace->steps === ['access', 'capability', 'audit:decision', 'audit:result'], 'Capability denial must stop before handler while still auditing.');

$handlerFailureTrace = new FailsClosedTrace();
$handlerFailureRuntime = new SyncOperationRuntime(
    new class($handlerFailureTrace) implements OperationAccessGate {
        public function __construct(private FailsClosedTrace $trace)
        {
        }

        public function decideAccess(OperationDescriptor $descriptor, OperationContext $context): OperationDecision
        {
            $this->trace->steps[] = 'access';

            return OperationDecision::allowed(OperationExecutionMode::Sync, 'access_allowed');
        }
    },
    new class($handlerFailureTrace) implements OperationCapabilityGate {
        public function __construct(private FailsClosedTrace $trace)
        {
        }

        public function decideCapability(OperationDescriptor $descriptor, OperationContext $context): OperationDecision
        {
            $this->trace->steps[] = 'capability';

            return OperationDecision::allowed(OperationExecutionMode::Sync, 'capability_allowed');
        }
    },
    new FailsClosedAuditRecorder($handlerFailureTrace),
    new TracedHandler($handlerFailureTrace, throw: true),
);

$handlerFailure = $handlerFailureRuntime->execute(runtimeSecurityDescriptor(), $context);

assertRuntimeSecurityFailsClosedTrue(!$handlerFailure->successful(), 'Handler exception must be surfaced as failed operation.');
assertRuntimeSecurityFailsClosedTrue($handlerFailure->normalizedError['code'] === 'handler_failed', 'Handler exception must expose handler_failed normalized reason.');
assertRuntimeSecurityFailsClosedTrue($handlerFailureTrace->steps === ['access', 'capability', 'audit:decision', 'handler', 'audit:result'], 'Handler failure must preserve audit result recording.');

echo "RuntimeSecurityCompositionFailsClosedTest passed.\n";
