<?php

declare(strict_types=1);

namespace Larena\Core\Diagnostics;

use InvalidArgumentException;
use Larena\Access\Contracts\AccessDecisionEngine;
use Larena\Access\Runtime\GrantAwareAccessDecisionEngine;
use Larena\Access\Runtime\InMemoryTargetGrantProvider;
use Larena\Access\Runtime\StaticAccessPolicyDescriptor;
use Larena\Audit\Contracts\AuditEvent;
use Larena\Audit\Contracts\AuditEventDescriptor;
use Larena\Audit\Enums\AuditRetentionClass;
use Larena\Audit\Enums\AuditSeverity;
use Larena\Audit\Runtime\AuditEventPipeline;
use Larena\Audit\Runtime\DefaultAuditRedactor;
use Larena\Audit\Runtime\InMemoryAuditSink;
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
use Larena\Licensing\Contracts\EntitlementSnapshot;
use Larena\Licensing\Contracts\LicensingRuntime;
use Larena\Licensing\Enums\EntitlementStatus;
use Larena\Licensing\Runtime\SnapshotLicensingRuntime;
use Larena\Licensing\Runtime\StaticCapability;
use Larena\Licensing\Runtime\StaticEntitlementSnapshot;
use RuntimeException;

final class RuntimeSecuritySmoke
{
    /**
     * @param array{
     *     base_path: string,
     *     laravel_version: string
     * } $applicationContext
     *
     * @return array<string, mixed>
     */
    public static function run(string $outputPath, array $applicationContext): array
    {
        $cases = [
            'allowed_operation' => self::runtime(entitled: true)->execute(self::descriptor('site.content.publish'), self::context('admin', 'laravel-smoke-allow')),
            'access_denied' => self::runtime(entitled: true)->execute(self::descriptor('site.content.publish'), self::context('viewer', 'laravel-smoke-access-deny')),
            'licensing_denied' => self::runtime(entitled: false)->execute(self::descriptor('site.content.publish'), self::context('admin', 'laravel-smoke-license-deny')),
            'handler_failed' => self::runtime(entitled: true, handlerShouldFail: true)->execute(self::descriptor('site.content.publish'), self::context('admin', 'laravel-smoke-handler-fail')),
        ];

        $pipeline = new AuditEventPipeline(new DefaultAuditRedactor(), [new InMemoryAuditSink()]);
        $auditDescriptor = new SmokeAuditDescriptor();

        $redactedEvent = $pipeline->route(
            $auditDescriptor,
            AuditEvent::create(
                sourcePackage: 'larena/core',
                category: 'operation_runtime',
                type: 'runtime_security_laravel_smoke',
                actor: 'admin',
                subject: 'audit_redaction',
                severity: AuditSeverity::Info,
                retentionClass: AuditRetentionClass::Operational,
                correlationId: 'laravel-smoke-redaction',
                payload: ['secret_value' => 'must-not-leak'],
            ),
        );

        $forbiddenPayloadFailedClosed = false;

        try {
            $pipeline->route(
                $auditDescriptor,
                AuditEvent::create(
                    sourcePackage: 'larena/core',
                    category: 'operation_runtime',
                    type: 'runtime_security_laravel_smoke',
                    actor: 'admin',
                    subject: 'audit_forbidden_payload',
                    severity: AuditSeverity::Info,
                    retentionClass: AuditRetentionClass::Operational,
                    correlationId: 'laravel-smoke-forbidden',
                    payload: ['raw_password' => 'must-fail'],
                ),
            );
        } catch (InvalidArgumentException) {
            $forbiddenPayloadFailedClosed = true;
        }

        $report = [
            'schema' => 'larena.runtime_security_laravel_smoke.v1',
            'status' => 'passed',
            'generated_at' => gmdate('c'),
            'laravel_version' => $applicationContext['laravel_version'],
            'package_sources' => [
                'core' => $applicationContext['base_path'] . '/../larena-workspace/packages/core',
                'access' => $applicationContext['base_path'] . '/../larena-workspace/packages/access',
                'licensing' => $applicationContext['base_path'] . '/../larena-workspace/packages/licensing',
                'audit' => $applicationContext['base_path'] . '/../larena-workspace/packages/audit',
            ],
            'cases' => array_map([self::class, 'summarize'], $cases),
            'audit_redaction' => [
                'redacted_payload' => $redactedEvent->payload,
                'redaction_passed' => ($redactedEvent->payload['secret_value'] ?? null) === DefaultAuditRedactor::REDACTED_VALUE,
                'forbidden_payload_failed_closed' => $forbiddenPayloadFailedClosed,
            ],
        ];

        $assertions = [
            ($report['cases']['allowed_operation']['decision_status'] ?? null) === 'allowed',
            ($report['cases']['allowed_operation']['handler_ran'] ?? null) === true,
            ($report['cases']['access_denied']['decision_status'] ?? null) === 'denied',
            ($report['cases']['access_denied']['handler_ran'] ?? null) === false,
            ($report['cases']['licensing_denied']['decision_status'] ?? null) === 'capability_locked',
            ($report['cases']['licensing_denied']['handler_ran'] ?? null) === false,
            ($report['cases']['handler_failed']['decision_reason'] ?? null) === 'handler_failed',
            $report['audit_redaction']['redaction_passed'] === true,
            $report['audit_redaction']['forbidden_payload_failed_closed'] === true,
        ];

        if (in_array(false, $assertions, true)) {
            $report['status'] = 'failed';
        }

        $directory = dirname($outputPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($outputPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

        return $report;
    }

    private static function descriptor(string $name): OperationDescriptor
    {
        return new OperationDescriptor(
            name: $name,
            executionMode: OperationExecutionMode::Sync,
            accessScope: 'site.manage',
            requiredCapability: 'runtime_security.manage',
            auditEvent: 'runtime_security_laravel_smoke',
        );
    }

    private static function context(string $actor, string $correlationId): OperationContext
    {
        return new OperationContext(
            actorId: $actor,
            correlationId: $correlationId,
            accessContext: ['target' => 'site:demo'],
            auditContext: ['secret_value' => 'must-not-leak'],
        );
    }

    private static function runtime(bool $entitled, bool $handlerShouldFail = false): SyncOperationRuntime
    {
        $access = new GrantAwareAccessDecisionEngine([
            new InMemoryTargetGrantProvider('site', [
                'admin' => ['demo' => ['site.manage']],
                'viewer' => ['demo' => ['site.view']],
            ]),
        ]);

        $snapshot = new StaticEntitlementSnapshot(
            status: EntitlementStatus::Active,
            keyId: 'local-laravel-smoke-key',
            signature: 'local-laravel-smoke-signature',
            capabilityKeys: $entitled ? ['runtime_security.manage'] : [],
        );

        return new SyncOperationRuntime(
            accessGate: new SmokeAccessGate($access),
            capabilityGate: new SmokeCapabilityGate(new SnapshotLicensingRuntime(), $snapshot),
            auditRecorder: new SmokeAuditRecorder(
                new AuditEventPipeline(new DefaultAuditRedactor(), [new InMemoryAuditSink()]),
                new SmokeAuditDescriptor(),
            ),
            handler: new SmokeHandler($handlerShouldFail),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function summarize(OperationResult $result): array
    {
        return [
            'decision_status' => $result->decision->status->value,
            'decision_reason' => $result->decision->reasonCode,
            'handler_ran' => $result->payload !== null,
            'successful' => $result->successful(),
            'payload' => $result->payload,
            'error' => $result->normalizedError,
            'audit_event_count' => count($result->auditEvents),
            'audit_events' => $result->auditEvents,
            'runtime_trace' => $result->runtimeTrace,
        ];
    }
}

final readonly class SmokeAccessGate implements OperationAccessGate
{
    public function __construct(private AccessDecisionEngine $engine)
    {
    }

    public function decideAccess(OperationDescriptor $descriptor, OperationContext $context): OperationDecision
    {
        $target = $context->accessContext['target'] ?? null;

        if (!is_string($target) || $target === '') {
            return OperationDecision::denied('target_missing', 'Access target is missing.');
        }

        $decision = $this->engine->decide(
            new StaticAccessPolicyDescriptor(
                operation: $descriptor->name,
                targetType: 'site',
                requiredGrants: [$descriptor->accessScope ?? 'unknown'],
            ),
            $context->actorId,
            $target,
            $context->accessContext,
        );

        if (!$decision->isAllowed()) {
            return OperationDecision::denied($decision->reasonCode, 'Access denied by Larena access runtime.');
        }

        return OperationDecision::allowed(OperationExecutionMode::Sync, $decision->reasonCode);
    }
}

final readonly class SmokeCapabilityGate implements OperationCapabilityGate
{
    public function __construct(
        private LicensingRuntime $runtime,
        private ?EntitlementSnapshot $snapshot,
    ) {
    }

    public function decideCapability(OperationDescriptor $descriptor, OperationContext $context): OperationDecision
    {
        if ($descriptor->requiredCapability === null) {
            return OperationDecision::allowed(OperationExecutionMode::Sync, 'capability_not_required');
        }

        $decision = $this->runtime->decide(
            new StaticCapability(
                key: $descriptor->requiredCapability,
                package: 'larena/core',
                freeByDefault: false,
                failClosedWhenUnknown: true,
            ),
            $this->snapshot,
        );

        if (!$decision->isAllowed()) {
            return OperationDecision::capabilityLocked(
                $decision->reasonCode(),
                'Capability denied by Larena licensing runtime.',
            );
        }

        return OperationDecision::allowed(OperationExecutionMode::Sync, $decision->reasonCode());
    }
}

final readonly class SmokeAuditDescriptor implements AuditEventDescriptor
{
    public function sourcePackage(): string
    {
        return 'larena/core';
    }

    public function category(): string
    {
        return 'operation_runtime';
    }

    public function type(): string
    {
        return 'runtime_security_laravel_smoke';
    }

    public function severity(): AuditSeverity
    {
        return AuditSeverity::Info;
    }

    public function retentionClass(): AuditRetentionClass
    {
        return AuditRetentionClass::Operational;
    }

    public function redactedPayloadFields(): array
    {
        return ['secret_value'];
    }

    public function forbiddenPayloadFields(): array
    {
        return ['raw_password'];
    }

    public function isExperimental(): bool
    {
        return true;
    }
}

final readonly class SmokeAuditRecorder implements OperationAuditRecorder
{
    public function __construct(
        private AuditEventPipeline $pipeline,
        private AuditEventDescriptor $descriptor,
    ) {
    }

    public function recordDecision(
        OperationDescriptor $descriptor,
        OperationContext $context,
        OperationDecision $decision,
        string $phase,
    ): array {
        return $this->record($descriptor, $context, $phase, [
            'decision_status' => $decision->status->value,
            'decision_reason' => $decision->reasonCode,
            'secret_value' => $context->auditContext['secret_value'] ?? null,
        ]);
    }

    public function recordResult(
        OperationDescriptor $descriptor,
        OperationContext $context,
        OperationResult $result,
        string $phase,
    ): array {
        return $this->record($descriptor, $context, $phase, [
            'result_status' => $result->decision->status->value,
            'result_reason' => $result->decision->reasonCode,
            'secret_value' => $context->auditContext['secret_value'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private function record(
        OperationDescriptor $descriptor,
        OperationContext $context,
        string $phase,
        array $payload,
    ): array {
        $event = AuditEvent::create(
            sourcePackage: 'larena/core',
            category: 'operation_runtime',
            type: $descriptor->auditEvent ?? 'runtime_security_laravel_smoke',
            actor: $context->actorId,
            subject: $descriptor->name,
            severity: AuditSeverity::Info,
            retentionClass: AuditRetentionClass::Operational,
            correlationId: $context->correlationId,
            payload: ['phase' => $phase] + $payload,
        );

        $redacted = $this->pipeline->route($this->descriptor, $event);

        return [
            'phase' => $phase,
            'type' => $redacted->type,
            'actor' => $redacted->actor,
            'subject' => $redacted->subject,
            'payload' => $redacted->payload,
        ];
    }
}

final readonly class SmokeHandler implements OperationHandler
{
    public function __construct(private bool $shouldFail = false)
    {
    }

    public function handle(OperationDescriptor $descriptor, OperationContext $context): array
    {
        if ($this->shouldFail) {
            throw new RuntimeException('simulated_handler_failure');
        }

        return [
            'handled' => true,
            'operation' => $descriptor->name,
            'actor_id' => $context->actorId,
        ];
    }
}
