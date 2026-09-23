<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/operation-registry-fixtures.php';

use Larena\Core\Contracts\OperationApproval;
use Larena\Core\Enums\OperationDecisionStatus;
use Larena\Core\Enums\OperationRiskClass;
use Larena\Core\Registry\DeclaredOperationRegistry;
use Larena\Core\Runtime\RegistryOperationRuntime;

$declaration = larena_test_declaration([
    'name' => 'test.thing.delete',
    'riskClass' => OperationRiskClass::Irreversible,
    'reversible' => false,
    'auditEvent' => 'test.thing.deleted',
]);

$registry = new DeclaredOperationRegistry();
$registry->register($declaration, larena_test_descriptor_for($declaration), 'core.handler.test');

$handler = new LarenaTestProposalHandler();
$runtime = new RegistryOperationRuntime($registry, larena_test_runtime($handler), $handler);
$context = larena_test_context('thing-7');
$digest = $runtime->propose('test.thing.delete', $context)->payload['receipt']['proposal_digest'];
$handler->forget();

/**
 * @param callable(): \Larena\Core\Contracts\OperationResult $call
 */
function larena_fails_closed(callable $call, string $expectedReason, LarenaTestProposalHandler $handler): void
{
    $result = $call();
    assert($result->decision->status !== OperationDecisionStatus::Allowed, 'expected a denial: ' . $expectedReason);
    assert(
        $result->decision->reasonCode === $expectedReason,
        'expected ' . $expectedReason . ', got ' . $result->decision->reasonCode,
    );
    assert($handler->writeCount() === 0, 'a denied operation must not have written');
}

// An unregistered operation cannot be proposed or executed.
larena_fails_closed(
    static fn () => $runtime->propose('test.thing.absent', $context),
    'operation_not_registered',
    $handler,
);
larena_fails_closed(
    static fn () => $runtime->execute('test.thing.absent', $context),
    'operation_not_registered',
    $handler,
);

// Confirmation is required, so a bare execution is refused.
larena_fails_closed(
    static fn () => $runtime->execute('test.thing.delete', $context),
    'approval_required',
    $handler,
);

// An approval for a different change is refused even though it is well formed.
larena_fails_closed(
    static fn () => $runtime->execute(
        'test.thing.delete',
        larena_test_context('thing-9'),
        new OperationApproval('test.thing.delete', $digest, 'approver-1'),
    ),
    'approval_mismatch',
    $handler,
);

// An approval granted for another operation is refused.
larena_fails_closed(
    static fn () => $runtime->execute(
        'test.thing.delete',
        $context,
        new OperationApproval('test.thing.other', $digest, 'approver-1'),
    ),
    'approval_operation_mismatch',
    $handler,
);

// A mutation whose handler cannot describe a change is refused in proposal mode
// rather than quietly writing.
$writeOnly = new LarenaTestWriteOnlyHandler();
$writeOnlyRuntime = new RegistryOperationRuntime($registry, larena_test_runtime($writeOnly), $writeOnly);
$refused = $writeOnlyRuntime->propose('test.thing.delete', $context);
assert($refused->decision->reasonCode === 'proposal_unsupported');
assert($writeOnly->writeCount() === 0, 'an unsupported proposal must not write');

// A denied access decision stops the proposal before the handler is consulted.
$deniedHandler = new LarenaTestProposalHandler();
$deniedRuntime = new RegistryOperationRuntime($registry, larena_test_runtime($deniedHandler, false), $deniedHandler);
$denied = $deniedRuntime->propose('test.thing.delete', $context);
assert($denied->decision->status === OperationDecisionStatus::Denied);
assert($deniedHandler->proposalCount() === 0, 'a denied proposal must not reach the handler');
assert($deniedHandler->writeCount() === 0);

// A malformed approval cannot even be constructed: there is no path that
// reaches the runtime with a digest that is not a digest.
$rejectedApprovals = 0;
foreach ([['', 'sha256:' . str_repeat('a', 64), 'approver'], ['op', 'not-a-digest', 'approver'], ['op', 'sha256:' . str_repeat('a', 64), '']] as [$operation, $badDigest, $approver]) {
    try {
        new OperationApproval($operation, $badDigest, $approver);
    } catch (InvalidArgumentException) {
        ++$rejectedApprovals;
    }
}
assert($rejectedApprovals === 3, 'every malformed approval must be rejected');

echo "Proposal and approval fail-closed behaviour passed.\n";
