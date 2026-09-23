<?php

declare(strict_types=1);

namespace Larena\Core\Registry;

use Larena\Core\Contracts\OperationDeclaration;
use Larena\Core\Contracts\OperationDescriptor;
use Larena\Core\Enums\OperationExecutionMode;

/**
 * The descriptor v2 policy, enforced where registration happens.
 *
 * OperationDescriptor keeps its permissive constructor on purpose: existing call
 * sites and package tests build descriptors without an autoloader, and an
 * undeclared risk class already resolves fail-safe to a non-reversible change.
 * Completeness is required of a registered operation, which is a different and
 * later moment than construction.
 */
final class DescriptorPolicy
{
    /**
     * @return list<array{reason_code: string, detail: string}> empty when the pair satisfies the policy
     */
    public function violations(OperationDeclaration $declaration, OperationDescriptor $descriptor): array
    {
        $violations = [];

        if ($descriptor->riskClass === null) {
            $violations[] = [
                'reason_code' => 'risk_class_undeclared',
                'detail' => 'a registered operation must declare its risk class explicitly',
            ];
        }

        if (!$declaration->isRead() && $declaration->auditEvent === null) {
            $violations[] = [
                'reason_code' => 'audit_event_missing_for_mutation',
                'detail' => 'every operation that changes state must name its audit event',
            ];
        }

        if ($declaration->transactional && $declaration->idempotencyKey === null) {
            $violations[] = [
                'reason_code' => 'idempotency_key_missing_for_transactional',
                'detail' => 'a transactional operation must name the input field that makes a retry safe',
            ];
        }

        if ($declaration->inputSchema === []) {
            $violations[] = ['reason_code' => 'input_schema_missing', 'detail' => 'the input schema must not be empty'];
        }

        if ($declaration->outputSchema === []) {
            $violations[] = ['reason_code' => 'output_schema_missing', 'detail' => 'the output schema must not be empty'];
        }

        if (!$declaration->isRead() && ($declaration->receiptSchema === null || $declaration->receiptSchema === [])) {
            $violations[] = [
                'reason_code' => 'receipt_schema_missing_for_mutation',
                'detail' => 'a mutation must declare the receipt its proposal returns',
            ];
        }

        $mismatch = $this->firstMismatch($declaration, $descriptor);
        if ($mismatch !== null) {
            $violations[] = [
                'reason_code' => 'descriptor_declaration_mismatch',
                'detail' => 'the descriptor and the declaration disagree on ' . $mismatch,
            ];
        }

        return $violations;
    }

    /**
     * The first shared field on which the two sources disagree.
     *
     * Naming the field is the whole point: "they disagree" sends a reader to
     * read both files, "they disagree on audit_event" sends them to one line.
     */
    public function firstMismatch(OperationDeclaration $declaration, OperationDescriptor $descriptor): ?string
    {
        if ($declaration->name !== $descriptor->name) {
            return 'name';
        }

        if ($declaration->executionMode !== $descriptor->executionMode) {
            return 'execution_mode';
        }

        if ($descriptor->riskClass !== null && $declaration->riskClass !== $descriptor->riskClass) {
            return 'risk';
        }

        if ($declaration->reversible !== $descriptor->reversible) {
            return 'reversible';
        }

        if ($declaration->accessScope !== $descriptor->accessScope) {
            return 'access_scope';
        }

        if ($declaration->auditEvent !== $descriptor->auditEvent) {
            return 'audit_event';
        }

        if ($declaration->idempotencyKey !== $descriptor->idempotencyKey) {
            return 'idempotency_key';
        }

        if ($declaration->transactional !== $descriptor->transactional) {
            return 'transactional';
        }

        if ($this->modeValues($declaration->allowedExecutionModes()) !== $this->modeValues($descriptor->allowedExecutionModes())) {
            return 'allowed_execution_modes';
        }

        return null;
    }

    /**
     * @param list<OperationExecutionMode> $modes
     * @return list<string>
     */
    private function modeValues(array $modes): array
    {
        $values = array_map(static fn (OperationExecutionMode $mode): string => $mode->value, $modes);
        sort($values);

        return $values;
    }
}
