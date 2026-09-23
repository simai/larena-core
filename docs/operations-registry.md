# Operations, the registry and the transport

## Declaring an operation

Every governed operation this package owns is declared in `operations.yaml`:

```yaml
schema: larena.operations.contract.v1
package: larena/core
operations:
  - name: 'core.scope.create'
    execution_mode: 'sync'
    risk: 'change'
    reversible: true
    access_scope: 'core.scope.manage'
    audit_event: 'core.scope.created'
    idempotency_key: 'scope_ref'
    transactional: true
    transports: ['local']
    input: { type: 'object', properties: { ... }, required: [...] }
    output: { type: 'object', properties: { ... } }
    receipt: { type: 'object', properties: { ... } }
```

Every key is closed. An unknown key, a scalar where a mapping belongs or a
duplicate name is rejected when the file is read, which is the point: a
descriptor entry appended after the wrong key once passed every gate in three
repositories because nothing checked the file's shape.

A read declares no `audit_event` and no `receipt`. Everything else declares both,
and a transactional operation also declares the input field that makes a retry
safe.

## Registering it

`CoreOperationProvider` pairs each declaration with the descriptor its handler
class builds, and `DeclaredOperationRegistry` refuses the pair if they disagree
on any shared field — the message names the field. It also refuses a duplicate
name, an unsafe handler reference, an undeclared risk class, a mutation without an
audit event, a transactional operation without an idempotency key and any missing
schema reference.

`OperationDescriptor` itself stays permissive. Completeness is required of a
*registered* operation, which is a later moment than construction.

## Proposing and approving

```php
$result = $runtime->propose('core.plane.node.move', $context);
$digest = $result->payload['receipt']['proposal_digest'];

$runtime->execute(
    'core.plane.node.move',
    $context,
    new OperationApproval('core.plane.node.move', $digest, $approverId),
);
```

The proposal runs the same gates and writes nothing. The digest covers the
operation name and the canonical input, so the approval cannot be replayed
against a different change; the runtime recomputes it from the input it is handed.
Nothing is stored.

Whether an approval is required comes from the risk class: a read never asks,
bulk, irreversible and external always ask, an ordinary reversible change does
not. The answer depends on the operation, never on whether the actor is a person
or an AI.

## Transports

`TransportResolver` answers local when no topology binding exists and when the
binding names this node. A network resolution needs three gates —
`distributed_entitlement`, `node_trust`, `network_transport_implementation` — and
an unbound gate counts as an absence, not as permission. The diagnostic lists
every missing gate in that order and takes its reason code from the first.

A failed network resolution throws. It never falls back to the local transport,
because that would run the call against the wrong node's data.

The open core contains the `NetworkTransport` interface and no implementation.

## Checking coverage

```
php artisan core:operations:coverage --json
```

Reports which declared access operation codes have a registered operation, and
validates the structure of every installed package's `access.yaml` and
`audit.yaml`. It fails on what someone can fix today: a malformed descriptor
file, or an operation registered against an access code no package declares. A
declared code with no registered operation is a gap, carries the batch that will
close it, and does not fail the gate.
