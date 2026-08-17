<?php

declare(strict_types=1);

namespace Larena\Core\Diagnostics;

use Composer\InstalledVersions;
use InvalidArgumentException;
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

        $redactedPayload = SmokeAuditRecorder::sanitize(['secret_value' => 'must-not-leak']);

        $forbiddenPayloadFailedClosed = false;

        try {
            SmokeAuditRecorder::sanitize(['raw_password' => 'must-fail']);
        } catch (InvalidArgumentException) {
            $forbiddenPayloadFailedClosed = true;
        }

        $report = [
            'schema' => 'larena.runtime_security_laravel_smoke.v1',
            'status' => 'passed',
            'generated_at' => gmdate('c'),
            'laravel_version' => $applicationContext['laravel_version'],
            'package_sources' => self::packageSources($applicationContext['base_path']),
            'cases' => array_map([self::class, 'summarize'], $cases),
            'audit_redaction' => [
                'redacted_payload' => $redactedPayload,
                'redaction_passed' => ($redactedPayload['secret_value'] ?? null) === '[REDACTED]',
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
        return new SyncOperationRuntime(
            accessGate: new SmokeAccessGate(),
            capabilityGate: new SmokeCapabilityGate($entitled),
            auditRecorder: new SmokeAuditRecorder(),
            handler: new SmokeHandler($handlerShouldFail),
        );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function packageSources(string $basePath): array
    {
        $packages = ['core' => 'larena/core'];

        $sources = [];

        foreach ($packages as $key => $package) {
            $sources[$key] = self::packageSource($package, $basePath);
        }

        return $sources;
    }

    /**
     * @return array<string, mixed>
     */
    private static function packageSource(string $package, string $basePath): array
    {
        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($package)) {
            $installPath = InstalledVersions::getInstallPath($package);

            if (is_string($installPath) && $installPath !== '') {
                return [
                    'package' => $package,
                    'status' => 'resolved',
                    'source' => 'composer_installed_versions',
                    'install_path' => $installPath,
                ];
            }
        }

        $installedJsonPath = rtrim($basePath, '/') . '/vendor/composer/installed.json';
        $installedJsonPath = is_file($installedJsonPath) ? $installedJsonPath : null;
        $installedJsonPath = $installedJsonPath ?? rtrim($basePath, '/') . '/vendor/composer/installed.php';
        $installedPackage = is_file($installedJsonPath)
            ? self::packageFromInstalledManifest($installedJsonPath, $package)
            : null;

        if ($installedPackage !== null) {
            return $installedPackage;
        }

        return [
            'package' => $package,
            'status' => 'unresolved',
            'source' => 'composer_metadata_unavailable',
            'install_path' => null,
            'diagnostic_note' => 'Runtime security smoke could not resolve the Composer install path for this package.',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function packageFromInstalledManifest(string $installedPath, string $package): ?array
    {
        $manifest = str_ends_with($installedPath, '.php')
            ? include $installedPath
            : json_decode((string) file_get_contents($installedPath), true);

        if (!is_array($manifest)) {
            return null;
        }

        $packages = $manifest['packages'] ?? $manifest;

        if (!is_array($packages)) {
            return null;
        }

        foreach ($packages as $installedPackage) {
            if (!is_array($installedPackage) || ($installedPackage['name'] ?? null) !== $package) {
                continue;
            }

            $installPath = $installedPackage['install-path'] ?? $installedPackage['install_path'] ?? null;

            return [
                'package' => $package,
                'status' => is_string($installPath) && $installPath !== '' ? 'resolved' : 'unresolved',
                'source' => 'vendor_composer_installed_manifest',
                'install_path' => is_string($installPath) && $installPath !== '' ? $installPath : null,
                'manifest_path' => $installedPath,
            ];
        }

        return null;
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
    public function decideAccess(OperationDescriptor $descriptor, OperationContext $context): OperationDecision
    {
        $target = $context->accessContext['target'] ?? null;

        if ($target !== 'site:demo') {
            return OperationDecision::denied('target_unknown');
        }

        if ($context->actorId !== 'admin' || $descriptor->accessScope !== 'site.manage') {
            return OperationDecision::denied('access_denied');
        }

        return OperationDecision::allowed(OperationExecutionMode::Sync, 'access_allowed');
    }
}

final readonly class SmokeCapabilityGate implements OperationCapabilityGate
{
    public function __construct(private bool $entitled)
    {
    }

    public function decideCapability(OperationDescriptor $descriptor, OperationContext $context): OperationDecision
    {
        if ($descriptor->requiredCapability === null) {
            return OperationDecision::allowed(OperationExecutionMode::Sync, 'capability_not_required');
        }

        if (!$this->entitled) {
            return OperationDecision::capabilityLocked('capability_locked');
        }

        return OperationDecision::allowed(OperationExecutionMode::Sync, 'capability_allowed');
    }
}

final readonly class SmokeAuditRecorder implements OperationAuditRecorder
{
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
        $redacted = self::sanitize(['phase' => $phase] + $payload);

        return [
            'phase' => $phase,
            'type' => $descriptor->auditEvent ?? 'runtime_security_laravel_smoke',
            'actor' => $context->actorId,
            'subject' => $descriptor->name,
            'payload' => $redacted,
        ];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public static function sanitize(array $payload): array
    {
        if (array_key_exists('raw_password', $payload)) {
            throw new InvalidArgumentException('forbidden_audit_payload_field');
        }
        if (array_key_exists('secret_value', $payload)) {
            $payload['secret_value'] = '[REDACTED]';
        }
        return $payload;
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
