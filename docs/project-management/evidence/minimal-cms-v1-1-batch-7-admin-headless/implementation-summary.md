# Implementation summary

| File | Change |
| --- | --- |
| `src/Runtime/OperationHandlerCatalog.php` | new: handler reference to lazily built handler |
| `src/Runtime/CatalogOperationHandler.php` | new: dispatches by the registry's handler reference; proposals too |
| `src/Runtime/ConnectionOperationTransactionBoundary.php` | new: one transaction per transactional operation, no ambient one |
| `src/Runtime/UndeclaredCapabilityGate.php` | new: fails closed on a required capability |
| `src/Exceptions/OperationProposalUnsupported.php` | new |
| `src/Runtime/RegistryOperationRuntime.php` | an unsupported proposal is a proposal_unsupported rejection, not an exception |
| `src/Runtime/PlaneOperationHandlers.php` | implements OperationProposalHandler for node create, move, archive and membership |
| `src/Providers/CoreServiceProvider.php` | binds the catalog with core's scope, plane, operation-registry, environment and solution handlers, and CatalogOperationHandler |
| `tests/Unit/CatalogOperationExecutionTest.php` | new |
