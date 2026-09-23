<?php

declare(strict_types=1);

namespace Larena\Core\Runtime;

use Larena\Core\Contracts\OperationContext;
use Larena\Core\Contracts\OperationDescriptor;
use Larena\Core\Contracts\OperationHandler;
use Larena\Core\Contracts\SolutionManifest;
use Larena\Core\Enums\OperationExecutionMode;
use Larena\Core\Enums\OperationRiskClass;
use Larena\Core\Enums\SolutionConflictClass;
use Larena\Core\Enums\SolutionDistribution;
use Larena\Core\Exceptions\SolutionRejected;

/**
 * The five solution operations.
 *
 * All five are reads. Validating a manifest, planning an install, planning an
 * upgrade and checking a topology change nothing — which is why they carry no
 * audit event and no transaction. The operation that would actually install
 * something does not exist in this batch, and a read operation that installed
 * would be the worst kind of surprise.
 */
final readonly class SolutionOperationHandlers implements OperationHandler
{
    public function __construct(
        private SolutionManifestValidator $validator,
        private SolutionPlanner $planner,
    ) {
    }

    /**
     * @return array<string, OperationDescriptor>
     */
    public static function descriptors(): array
    {
        $read = 'core.solution.read';

        $indexed = [];

        foreach ([
            'core.solution.validate',
            'core.solution.plan_install',
            'core.solution.plan_upgrade',
            'core.solution.validate_topology',
            'core.solution.explain',
        ] as $name) {
            $indexed[$name] = new OperationDescriptor(
                name: $name,
                executionMode: OperationExecutionMode::Sync,
                accessScope: $read,
                riskClass: OperationRiskClass::Read,
                reversible: true,
            );
        }

        return $indexed;
    }

    /**
     * @return array<string, mixed>
     */
    public function handle(OperationDescriptor $descriptor, OperationContext $context): array
    {
        return match ($descriptor->name) {
            'core.solution.validate' => $this->validator->validate($this->manifest($context))->toArray(),
            'core.solution.plan_install' => $this->planner
                ->planInstall($this->validator->validate($this->manifest($context)), $this->installed($context))
                ->toArray(),
            'core.solution.plan_upgrade' => $this->planner
                ->planUpgrade($this->validator->validate($this->manifest($context)), $this->installed($context))
                ->toArray(),
            'core.solution.validate_topology' => $this->planner
                ->validateTopology($this->validator->validate($this->manifest($context))),
            'core.solution.explain' => self::explain(),
            default => throw new SolutionRejected(
                'unknown_operation',
                sprintf('Operation "%s" is not a solution operation.', $descriptor->name),
            ),
        };
    }

    /**
     * The contract, stated by the implementation rather than by a document that can
     * drift away from it.
     *
     * @return array<string, mixed>
     */
    public static function explain(): array
    {
        return [
            'schema' => SolutionManifest::SCHEMA,
            'top_level_keys' => SolutionManifest::TOP_LEVEL_KEYS,
            'required_keys' => SolutionManifest::REQUIRED_KEYS,
            'distributions' => array_map(
                static fn (SolutionDistribution $distribution): string => $distribution->value,
                SolutionDistribution::cases(),
            ),
            'conflict_classes' => array_map(
                static fn (SolutionConflictClass $class): string => $class->value,
                SolutionConflictClass::cases(),
            ),
            'edition_rule' => 'an edition names an enabled capability set and nothing else',
            'seed_rule' => 'seed data enters through SitePack with a dry run; raw SQL is refused',
            'offline' => true,
            'installs' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(OperationContext $context): array
    {
        $manifest = $context->metadata['manifest'] ?? null;

        if (!is_array($manifest)) {
            throw new SolutionRejected('invalid_input', 'A solution operation requires a "manifest" mapping.');
        }

        return $manifest;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function installed(OperationContext $context): array
    {
        $installed = $context->metadata['installed'] ?? [];

        if (!is_array($installed)) {
            throw new SolutionRejected('invalid_input', '"installed" must be a mapping of solution id to what it owns.');
        }

        $set = [];
        foreach ($installed as $solutionId => $owned) {
            if (!is_string($solutionId) || !is_array($owned)) {
                throw new SolutionRejected('invalid_input', 'Each installed entry is a solution id mapped to what it owns.');
            }

            $set[$solutionId] = $owned;
        }

        return $set;
    }
}
