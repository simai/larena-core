<?php

declare(strict_types=1);

namespace Larena\Core\Runtime;

use Larena\Core\Contracts\EnvironmentProfile;
use Larena\Core\Contracts\OperationContext;
use Larena\Core\Contracts\OperationDescriptor;
use Larena\Core\Contracts\OperationHandler;
use Larena\Core\Enums\EnvironmentCapability;
use Larena\Core\Enums\OperationExecutionMode;
use Larena\Core\Enums\OperationRiskClass;
use Larena\Core\Exceptions\EnvironmentProfileRejected;

/**
 * The four environment operations.
 *
 * `declare` is the only change among them, and it is a change to a document rather
 * than to a host: nothing here installs or configures anything. `detect` is a read
 * even though it looks at the machine, because looking is not changing.
 */
final readonly class EnvironmentOperationHandlers implements OperationHandler
{
    public function __construct(
        private EnvironmentProfile $profile,
        private HostEnvironmentDetector $detector,
    ) {
    }

    /**
     * @return array<string, OperationDescriptor>
     */
    public static function descriptors(): array
    {
        $read = 'core.environment.read';

        $descriptors = [
            new OperationDescriptor(
                name: 'core.environment.declare',
                executionMode: OperationExecutionMode::Sync,
                accessScope: 'core.environment.declare',
                auditEvent: 'core.environment.declared',
                idempotencyKey: 'profile_id',
                transactional: false,
                riskClass: OperationRiskClass::Change,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'core.environment.detect',
                executionMode: OperationExecutionMode::Sync,
                accessScope: $read,
                riskClass: OperationRiskClass::Read,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'core.environment.verify',
                executionMode: OperationExecutionMode::Sync,
                accessScope: $read,
                riskClass: OperationRiskClass::Read,
                reversible: true,
            ),
            new OperationDescriptor(
                name: 'core.environment.explain',
                executionMode: OperationExecutionMode::Sync,
                accessScope: $read,
                riskClass: OperationRiskClass::Read,
                reversible: true,
            ),
        ];

        $indexed = [];
        foreach ($descriptors as $descriptor) {
            $indexed[$descriptor->name] = $descriptor;
        }

        return $indexed;
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(OperationDescriptor $descriptor, OperationContext $context): array
    {
        return match ($descriptor->name) {
            'core.environment.declare' => $this->declare($context),
            'core.environment.detect' => $this->detector->report($this->profile),
            'core.environment.verify' => $this->verify($context),
            'core.environment.explain' => $this->explain(),
            default => throw new EnvironmentProfileRejected(
                'unknown_operation',
                sprintf('Operation "%s" is not an environment operation.', $descriptor->name),
            ),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function declare(OperationContext $context): array
    {
        $capabilities = $context->metadata['capabilities'] ?? [];
        if (!is_array($capabilities)) {
            throw new EnvironmentProfileRejected('invalid_input', '"capabilities" must be a mapping.');
        }

        $declared = DeclaredEnvironmentProfile::declare(
            $this->string($context, 'profile_id'),
            is_int($context->metadata['version'] ?? null) ? (int) $context->metadata['version'] : 1,
            $capabilities,
            $context->actorId,
            is_string($context->metadata['notes'] ?? null) ? (string) $context->metadata['notes'] : null,
        );

        // The operation returns the document it validated. Persisting it is the
        // caller's decision, because where a declaration lives is an application
        // concern and core does not own a table for it.
        return ['profile' => $declared->toArray(), 'fingerprint' => EnvironmentFingerprint::of($declared)];
    }

    /**
     * @return array<string, mixed>
     */
    private function verify(OperationContext $context): array
    {
        $required = [];
        foreach ($this->stringList($context, 'required') as $key) {
            $capability = EnvironmentCapability::tryFrom($key);
            if ($capability === null) {
                throw new EnvironmentProfileRejected('unknown_environment_capability', 'Unknown capability: ' . $key);
            }

            $required[] = $capability;
        }

        return $this->profile->verify($this->string($context, 'required_by'), $required)->toArray();
    }

    /**
     * @return array<string, mixed>
     */
    private function explain(): array
    {
        return $this->profile->toArray() + [
            'fingerprint' => EnvironmentFingerprint::of($this->profile),
            'unknown_is_absent' => true,
            'note' => 'a capability nobody could detect is not available',
        ];
    }

    private function string(OperationContext $context, string $key): string
    {
        $value = $context->metadata[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new EnvironmentProfileRejected('invalid_input', sprintf('Environment operations require a non-empty "%s".', $key));
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private function stringList(OperationContext $context, string $key): array
    {
        $value = $context->metadata[$key] ?? [];
        if (!is_array($value) || !array_is_list($value)) {
            throw new EnvironmentProfileRejected('invalid_input', sprintf('"%s" must be a list.', $key));
        }

        $entries = [];
        foreach ($value as $entry) {
            if (!is_string($entry)) {
                throw new EnvironmentProfileRejected('invalid_input', sprintf('Every member of "%s" must be a string.', $key));
            }

            $entries[] = $entry;
        }

        return $entries;
    }
}
