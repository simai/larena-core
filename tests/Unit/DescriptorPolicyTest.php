<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/operation-registry-fixtures.php';

use Larena\Core\Contracts\OperationDescriptor;
use Larena\Core\Enums\OperationExecutionMode;
use Larena\Core\Enums\OperationRiskClass;
use Larena\Core\Exceptions\OperationRegistrationRejected;
use Larena\Core\Registry\DeclaredOperationRegistry;
use Larena\Core\Registry\DescriptorPolicy;

$policy = new DescriptorPolicy();
$declaration = larena_test_declaration();

assert($policy->violations($declaration, larena_test_descriptor_for($declaration)) === []);

/**
 * @param array<string, mixed> $declarationOverrides
 * @param array<string, mixed> $descriptorOverrides
 */
function larena_policy_reject(
    DescriptorPolicy $policy,
    array $declarationOverrides,
    array $descriptorOverrides,
    string $expectedReason,
): void {
    $declaration = larena_test_declaration($declarationOverrides);
    $descriptor = larena_test_descriptor_for($declaration, $descriptorOverrides);
    $violations = $policy->violations($declaration, $descriptor);

    assert($violations !== [], 'expected a violation: ' . $expectedReason);
    assert(
        $violations[0]['reason_code'] === $expectedReason,
        'expected ' . $expectedReason . ', got ' . $violations[0]['reason_code'],
    );
}

// The v2 policy: completeness is required of a registered operation.
larena_policy_reject($policy, [], ['riskClass' => null], 'risk_class_undeclared');
larena_policy_reject($policy, ['auditEvent' => null], ['auditEvent' => null], 'audit_event_missing_for_mutation');
larena_policy_reject(
    $policy,
    ['transactional' => true, 'idempotencyKey' => null],
    ['transactional' => true, 'idempotencyKey' => null],
    'idempotency_key_missing_for_transactional',
);
larena_policy_reject($policy, ['inputSchema' => []], [], 'input_schema_missing');
larena_policy_reject($policy, ['outputSchema' => []], [], 'output_schema_missing');
larena_policy_reject($policy, ['receiptSchema' => null], [], 'receipt_schema_missing_for_mutation');

// A read needs no audit event and no receipt.
$read = larena_test_declaration([
    'name' => 'test.thing.read',
    'riskClass' => OperationRiskClass::Read,
    'auditEvent' => null,
    'receiptSchema' => null,
]);
assert($policy->violations($read, larena_test_descriptor_for($read)) === []);

// Every shared field is compared, and the message names the field.
foreach ([
    ['execution_mode', ['executionMode' => OperationExecutionMode::Queued]],
    ['reversible', ['reversible' => false]],
    ['access_scope', ['accessScope' => 'test.other.manage']],
    ['audit_event', ['auditEvent' => 'test.thing.other']],
    ['idempotency_key', ['idempotencyKey' => 'id']],
    ['transactional', ['transactional' => true]],
    ['risk', ['riskClass' => OperationRiskClass::Bulk]],
] as [$field, $descriptorOverride]) {
    $descriptor = larena_test_descriptor_for($declaration, $descriptorOverride);
    assert(
        $policy->firstMismatch($declaration, $descriptor) === $field,
        'expected a mismatch on ' . $field . ', got ' . var_export($policy->firstMismatch($declaration, $descriptor), true),
    );
}

$nameMismatch = new OperationDescriptor(
    name: 'test.thing.other',
    executionMode: OperationExecutionMode::Sync,
    accessScope: $declaration->accessScope,
    auditEvent: $declaration->auditEvent,
    riskClass: $declaration->riskClass,
    reversible: true,
);
assert($policy->firstMismatch($declaration, $nameMismatch) === 'name');

// And registration refuses the pair, naming the field.
$registry = new DeclaredOperationRegistry($policy);
try {
    $registry->register($declaration, larena_test_descriptor_for($declaration, ['auditEvent' => 'test.thing.other']), 'core.handler.test');
    throw new RuntimeException('a disagreeing descriptor must be rejected');
} catch (OperationRegistrationRejected $rejection) {
    assert($rejection->reasonCode === 'descriptor_declaration_mismatch');
    assert(str_contains($rejection->getMessage(), 'audit_event'), 'the rejection must name the field');
}

// An unsafe handler reference is rejected before anything else is stored.
try {
    $registry->register($declaration, larena_test_descriptor_for($declaration), '../../etc/passwd');
    throw new RuntimeException('an unsafe handler reference must be rejected');
} catch (OperationRegistrationRejected $rejection) {
    assert($rejection->reasonCode === 'handler_reference_unsafe');
}
assert(!$registry->has('test.thing.change'), 'a rejected registration must leave nothing behind');

echo "Descriptor v2 policy passed.\n";
