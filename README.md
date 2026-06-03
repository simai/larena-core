# Larena Core

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
