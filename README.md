# Larena Core

The package owns the fail-closed ordinary-hosting web-install state machine.
It keeps the one-time capability, signed private checkpoints, bounded MySQL
preflight and migration rollback/resume boundary separate from Admin
presentation and Root bundle composition.

Larena Core is the package foundation for platform contracts, operation runtime
boundaries and shared infrastructure contracts.

Current branch status:

- runtime-security Batch 1;
- contract skeletons only;
- no persistence;
- no migrations;
- no routes;
- no admin UI;
- no production operation executor.

## Implemented Contract Skeletons

Batch 1 covers `core.operation_runtime`:

- `OperationRuntime` interface;
- operation descriptor/context/decision/result DTOs;
- execution mode and decision status enums;
- fail-closed behavior at the contract boundary for unknown or unsafe modes.

The package intentionally exposes decision slots for access, capability,
audit/correlation and queue/deferred context without owning those packages.

`OperationTransactionAborted` may retain the original failure as its internal
previous cause solely so a composition-layer transaction boundary can classify
real database concurrency failures. That cause is never part of the normalized
`OperationResult` or any public output.
