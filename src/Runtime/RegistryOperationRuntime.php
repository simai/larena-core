<?php

declare(strict_types=1);

namespace Larena\Core\Runtime;

use Larena\Core\Contracts\ConfirmationPolicy;
use Larena\Core\Contracts\OperationApproval;
use Larena\Core\Contracts\OperationContext;
use Larena\Core\Contracts\OperationDecision;
use Larena\Core\Contracts\OperationHandler;
use Larena\Core\Contracts\OperationProposalHandler;
use Larena\Core\Contracts\OperationReceipt;
use Larena\Core\Contracts\OperationRegistry;
use Larena\Core\Contracts\OperationResult;
use Larena\Core\Contracts\OperationRuntime;
use Larena\Core\Enums\OperationDecisionStatus;
use Larena\Core\Enums\OperationInvocationMode;
use Larena\Core\Exceptions\OperationProposalUnsupported;

/**
 * Entry point for a registered operation.
 *
 * One path serves both a proposal and the approved write: the same registry
 * lookup, the same descriptor, the same gates. Only the last step differs, and
 * that is the point — a preview that runs through different code is not a
 * preview of anything.
 */
final readonly class RegistryOperationRuntime
{
    public function __construct(
        private OperationRegistry $registry,
        private OperationRuntime $runtime,
        private OperationHandler $handler,
        private ConfirmationPolicy $confirmation = new RiskClassConfirmationPolicy(),
    ) {
    }

    /**
     * Describe the change without making it.
     */
    public function propose(string $operationName, OperationContext $context): OperationResult
    {
        if (!$this->registry->has($operationName)) {
            return $this->rejected('operation_not_registered', 'The operation is not registered.');
        }

        $declaration = $this->registry->describe($operationName);
        $descriptor = $this->registry->descriptorFor($operationName);

        $decision = $this->runtime->decide($descriptor, $context);
        if ($decision->status !== OperationDecisionStatus::Allowed) {
            return OperationResult::fromDecision($decision, null, [], $this->trace($operationName, $context, $decision));
        }

        $confirmationRequired = $this->confirmation->requiresConfirmation($declaration);
        $digest = ProposalDigest::forContext($operationName, $context);

        if ($declaration->isRead()) {
            // A read has nothing to propose: running it *is* the preview.
            $intendedChange = ['kind' => 'read', 'reads' => $operationName];
        } elseif ($this->handler instanceof OperationProposalHandler) {
            try {
                $intendedChange = $this->handler->propose($descriptor, $context);
            } catch (OperationProposalUnsupported) {
                return $this->rejected(
                    'proposal_unsupported',
                    'The handler for this operation cannot describe a change without making it.',
                );
            }
        } else {
            return $this->rejected(
                'proposal_unsupported',
                'The handler for this operation cannot describe a change without making it.',
            );
        }

        $receipt = new OperationReceipt(
            operation: $operationName,
            invocationMode: OperationInvocationMode::Propose,
            actorId: $context->actorId,
            correlationId: $context->correlationId,
            riskClass: $declaration->riskClass,
            reversible: $declaration->reversible,
            confirmationRequired: $confirmationRequired,
            proposalDigest: $digest,
            intendedChange: $intendedChange,
        );

        return OperationResult::fromDecision(
            $decision,
            ['receipt' => $receipt->toArray()],
            [],
            $this->trace($operationName, $context, $decision) + ['invocation_mode' => OperationInvocationMode::Propose->value],
        );
    }

    /**
     * Perform the change.
     *
     * An operation whose risk class requires confirmation executes only against
     * an approval whose digest matches the input handed in here.
     */
    public function execute(
        string $operationName,
        OperationContext $context,
        ?OperationApproval $approval = null,
    ): OperationResult {
        if (!$this->registry->has($operationName)) {
            return $this->rejected('operation_not_registered', 'The operation is not registered.');
        }

        $declaration = $this->registry->describe($operationName);
        $descriptor = $this->registry->descriptorFor($operationName);

        if ($approval !== null && $approval->operation !== $operationName) {
            return $this->rejected('approval_operation_mismatch', 'The approval was granted for another operation.');
        }

        if ($this->confirmation->requiresConfirmation($declaration)) {
            if ($approval === null) {
                return $this->rejected('approval_required', 'This operation executes only against an approved proposal.');
            }

            if (!hash_equals(ProposalDigest::forContext($operationName, $context), $approval->proposalDigest)) {
                return $this->rejected('approval_mismatch', 'The approval does not match the change being executed.');
            }
        }

        return $this->runtime->execute($descriptor, $context);
    }

    /**
     * What the registry knows about an operation, for `describe` and `explain`.
     *
     * @return array<string, mixed>
     */
    public function describe(string $operationName): array
    {
        $declaration = $this->registry->describe($operationName);

        return $declaration->toArray() + [
            'confirmation_required' => $this->confirmation->requiresConfirmation($declaration),
            'handler_ref' => $this->registry->handlerRefFor($operationName),
        ];
    }

    private function rejected(string $reasonCode, string $message): OperationResult
    {
        $decision = OperationDecision::invalid($reasonCode, $message);

        return OperationResult::fromDecision($decision, null, [], ['decision_reason' => $reasonCode]);
    }

    /**
     * @return array<string, mixed>
     */
    private function trace(string $operationName, OperationContext $context, OperationDecision $decision): array
    {
        return [
            'operation' => $operationName,
            'actor_id' => $context->actorId,
            'correlation_id' => $context->correlationId,
            'decision_status' => $decision->status->value,
            'decision_reason' => $decision->reasonCode,
        ];
    }
}
