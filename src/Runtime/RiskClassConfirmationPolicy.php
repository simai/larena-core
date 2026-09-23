<?php

declare(strict_types=1);

namespace Larena\Core\Runtime;

use Larena\Core\Contracts\ConfirmationPolicy;
use Larena\Core\Contracts\OperationDeclaration;
use Larena\Core\Enums\OperationRiskClass;

/**
 * Confirmation decided by risk class, per the owner decision of 2026-09-23.
 *
 * A read never asks. Bulk, irreversible and external always ask. An ordinary
 * reversible change does not, because asking about everything trains people to
 * approve without reading, which is worse than not asking.
 */
final class RiskClassConfirmationPolicy implements ConfirmationPolicy
{
    public function requiresConfirmation(OperationDeclaration $declaration): bool
    {
        if ($declaration->riskClass->isRead()) {
            return false;
        }

        if ($declaration->riskClass->alwaysRequiresConfirmation()) {
            return true;
        }

        return !$declaration->reversible;
    }

    /**
     * Why the policy answered as it did, for an explain payload.
     */
    public function reasonCode(OperationDeclaration $declaration): string
    {
        return match (true) {
            $declaration->riskClass->isRead() => 'read_needs_no_confirmation',
            $declaration->riskClass === OperationRiskClass::Bulk => 'bulk_always_confirms',
            $declaration->riskClass === OperationRiskClass::Irreversible => 'irreversible_always_confirms',
            $declaration->riskClass === OperationRiskClass::External => 'external_always_confirms',
            !$declaration->reversible => 'non_reversible_change_confirms',
            default => 'reversible_change_needs_no_confirmation',
        };
    }
}
