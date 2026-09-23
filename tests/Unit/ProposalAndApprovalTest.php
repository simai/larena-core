<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/operation-registry-fixtures.php';

use Larena\Core\Contracts\OperationApproval;
use Larena\Core\Enums\OperationDecisionStatus;
use Larena\Core\Enums\OperationRiskClass;
use Larena\Core\Registry\DeclaredOperationRegistry;
use Larena\Core\Runtime\ProposalDigest;
use Larena\Core\Runtime\RegistryOperationRuntime;

// An irreversible operation: its risk class makes confirmation mandatory.
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

// The proposal writes nothing and hands back the digest of this exact change.
$proposal = $runtime->propose('test.thing.delete', $context);
assert($proposal->decision->status === OperationDecisionStatus::Allowed);
assert($handler->writeCount() === 0, 'a proposal must not write');
assert($handler->proposed('test.thing.delete'), 'the proposal must reach the handler');

$receipt = $proposal->payload['receipt'];
assert($receipt['operation'] === 'test.thing.delete');
assert($receipt['invocation_mode'] === 'propose');
assert($receipt['confirmation_required'] === true);
assert($receipt['reversible'] === false);
assert($receipt['risk_class'] === 'irreversible');
assert($receipt['intended_change'] === ['change' => 'would change thing-7']);
assert(preg_match('/^sha256:[0-9a-f]{64}$/', $receipt['proposal_digest']) === 1);

// The approved write goes through the same path and reaches the handler.
$approval = new OperationApproval('test.thing.delete', $receipt['proposal_digest'], 'approver-1');
$executed = $runtime->execute('test.thing.delete', $context, $approval);
assert($executed->decision->status === OperationDecisionStatus::Allowed, 'an approved write must execute');
assert($handler->wrote('test.thing.delete'), 'the approved write must reach the handler');
assert($handler->writeCount() === 1, 'the approved write must run exactly once');

// The digest depends only on the operation and the input, so the same change
// proposed twice — in a different key order — approves the same way.
$reordered = larena_test_context('thing-7', ['extra' => 'value']);
$digestA = ProposalDigest::forInput('test.thing.delete', ['id' => 'thing-7', 'extra' => 'value']);
$digestB = ProposalDigest::forInput('test.thing.delete', ['extra' => 'value', 'id' => 'thing-7']);
assert($digestA === $digestB, 'key order must not change the digest');
assert(ProposalDigest::forContext('test.thing.delete', $reordered) === $digestA);
assert(ProposalDigest::forInput('test.thing.other', ['id' => 'thing-7']) !== ProposalDigest::forInput('test.thing.delete', ['id' => 'thing-7']));

// A reversible change of ordinary risk executes with no approval at all.
$reversible = larena_test_declaration(['name' => 'test.thing.rename']);
$registry->register($reversible, larena_test_descriptor_for($reversible), 'core.handler.test');
$renamed = $runtime->execute('test.thing.rename', larena_test_context('thing-8'));
assert($renamed->decision->status === OperationDecisionStatus::Allowed);

// A read proposes itself: there is nothing to preview.
$read = larena_test_declaration([
    'name' => 'test.thing.read',
    'riskClass' => OperationRiskClass::Read,
    'auditEvent' => null,
    'receiptSchema' => null,
]);
$registry->register($read, larena_test_descriptor_for($read), 'core.handler.test');
$readProposal = $runtime->propose('test.thing.read', $context);
assert($readProposal->payload['receipt']['confirmation_required'] === false);
assert($readProposal->payload['receipt']['intended_change'] === ['kind' => 'read', 'reads' => 'test.thing.read']);

// describe() carries the schemas, the risk and the confirmation answer together.
$described = $runtime->describe('test.thing.delete');
assert($described['risk'] === 'irreversible');
assert($described['confirmation_required'] === true);
assert($described['input_schema_ref'] === 'larena/core:operations.yaml#test.thing.delete.input');
assert($described['handler_ref'] === 'core.handler.test');

echo "Proposal and approved write through one path passed.\n";
