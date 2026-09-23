# Batch 3 wave A — Core operation registry, descriptor v2 and local transport

Repository: `simai/larena-core`
Branch: `feature/minimal-cms-v1-1-batch-3-operation-registry` (from the Batch 1 branch at `5796103`)
Launch record: `docs/project-management/launch-records/minimal-cms-v1-1-batch-3-wave-a-core-operation-registry.json`
Pre-codegen freeze: `larena-specs` → `specs/implementation-planning/minimal-cms-v1-1-batch-3-pre-codegen-freeze.json`
Target: `larena.target.minimal_cms_v1_1`, digest `sha256:cbbfa628327f11f1f2c958c9b24cc10334fba965b9cce232e40884a6f37a1d3f`

## What exists now

Every governed operation core owns is declared once in `operations.yaml` — its
gates, risk class, reversibility and its input, output and receipt schema — and
registered in one transport-neutral registry. Twenty-two operations: the sixteen
scope and plane operations Batch 1 shipped, plus two registry reads and four
transport operations.

The registry is where completeness is enforced. `OperationDescriptor` keeps its
permissive constructor, because sixteen existing call sites and several package
tests build descriptors without an autoloader and an undeclared risk class
already resolves fail-safe. Registration is a later moment, and there an
operation must declare its risk class, an audit event if it mutates, an
idempotency key if it is transactional, and all three schema references. A
descriptor that disagrees with its declaration on any shared field is refused,
and the message names the field.

A proposal and its approved write travel one path. `RegistryOperationRuntime`
looks the operation up, runs the same access, capability and confirmation gates,
and only the last step differs: proposal mode asks the handler to describe the
change, execution mode performs it. The approval carries the sha256 digest of the
operation and its canonical input; the runtime recomputes that digest from the
input it is actually handed, so an approval cannot be replayed against a
different change. Nothing is persisted — there is no proposal table, nothing to
expire, and nothing to lose on restart.

Confirmation is decided by risk class, per the owner decision of 2026-09-23: a
read never asks, bulk, irreversible and external always ask, and an ordinary
reversible change does not. The policy reads the operation, never the actor: an
AI acts with the rights of the user it acts for.

The transport resolves local when no topology binding exists and when the binding
names this node, so a single-node installation pays nothing for a distributed
mode it does not use. A network resolution evaluates all three gates —
`distributed_entitlement`, `node_trust`, `network_transport_implementation` —
reports every missing one in that fixed order and takes its reason code from the
first. An unbound gate counts as an absence, not as permission. A failed network
resolution is a denial and never a local execution: a silent fallback would run
the call against the wrong node's data, which is the one outcome worse than an
error.

## What it deliberately does not do

No network transport, no cluster runtime, no topology discovery. No table and no
migration. No public HTTP, REST or MCP surface. No change to
`OperationDescriptor`, to `SyncOperationRuntime` or to any other package.
`core.transport.invoke_remote` is declared in the feature specification but is
not registered: there is nothing to invoke, and registering an operation that can
only fail would report coverage that does not exist.

## What the coverage report found

`core:operations:coverage` also validates the structure of every installed
package's `access.yaml` and `audit.yaml`, which is the direct answer to the Batch
2 defect: two operation codes were appended after the wrong key, YAML read them
as members of the neighbouring list, and every gate in three repositories stayed
green because nothing checked that file's shape.

Running it against the root found real drift on the first attempt, and two
findings changed the design rather than the packages:

1. Six packages use descriptor keys the first closed key set did not include —
   `nonclaims`, `allowed_payload_fields`, `forbidden_payload_fields`. These are
   legitimate existing keys; the contract was too narrow and was widened.
2. `larena/file-manager` ships an `access.yaml` with no `schema` and no `package`
   key at all. That is genuine drift, but it is not something this batch may fix,
   so it is reported as a notice rather than a failure, and only its list shapes
   are checked.

The report fails on what someone can fix today: a malformed descriptor file, or
an operation registered against an access code no package declares. A declared
code with no registered operation is a gap, and a gap does not fail the gate —
red for as long as the plan says the gap exists teaches everyone to ignore it.
Each gap instead carries the batch that will close it, and a gap the plan has not
scheduled says so.

Current state on the root: 22 operations registered, 8 access codes covered, 64
scheduled gaps (access, storage, docara, content, admin) and **23 unscheduled
gaps** — `auth` (9), `file-manager` (8), `search` (5) and `audit` (1). Those four
packages are not scheduled for registration anywhere in the v1.1 cluster plan.
That is a finding about the plan, not about this code, and it is recorded here
rather than papered over by inventing batches.

## Gates

`composer quality:gate` passes: `validate:larena`, `lint`, PHPStan level 5,
49 package tests, `larena:metadata:check`, `evidence:check`, `scope:check`.
See `tests.md` and `smoke.md`.
