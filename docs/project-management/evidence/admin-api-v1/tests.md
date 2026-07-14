# Tests

Current focused evidence:

- `SyncOperationRuntimeTest.php`: PASS.
- `SyncOperationRuntimeFailsClosedTest.php`: PASS.
- `SyncOperationRuntimeTransactionBoundaryTest.php`: PASS.
- `RuntimeSecurityCompositionTest.php`: PASS.
- `RuntimeSecurityCompositionFailsClosedTest.php`: PASS.
- complete `composer run quality:gate`: PASS (package validator, lint,
  PHPStan, full tests, metadata/evidence and scope checks).
- `composer validate --strict`: PASS after repairing the historical runtime/dev
  lock placement for Access, Audit and Licensing.
- `composer check-platform-reqs --lock`: PASS under ServBay PHP 8.4.20.
- `composer prohibits php 8.3.0 --locked`: PASS; the lock has no dependency
  incompatible with the package's declared PHP 8.3 baseline.

The strict lock failure was first reproduced during independent review and was
then repaired by declaring the already required transitive UI/Dataview path
repositories and regenerating the lock. This history is retained explicitly;
the failed intermediate state is not presented as green evidence.

Root SQLite HTTP integration, the independent-process restart proof and the
isolated MySQL/concurrency acceptance pass. The MySQL run includes a real
InnoDB deadlock and verifies one winner, one typed loser, no duplicate handler
or domain mutation, no orphan idempotency row and exactly one post-rollback
Audit event for the losing correlation.
