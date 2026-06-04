# Smoke

Smoke expectations:

- A permitted in-memory operation executes exactly once.
- Access denial prevents handler execution.
- Capability denial prevents handler execution.
- Unsupported non-sync execution mode fails closed.
- Audit decision/result metadata is emitted through the audit port.

Result: passed.

Evidence:

- `SyncOperationRuntimeTest` proves a permitted operation executes exactly once.
- `SyncOperationRuntimeFailsClosedTest` proves access denial prevents handler
  execution.
- `SyncOperationRuntimeFailsClosedTest` proves capability denial prevents
  handler execution.
- `SyncOperationRuntimeFailsClosedTest` proves unsupported non-sync mode fails
  closed.
- Runtime tests verify decision/result audit metadata via `OperationAuditRecorder`.
