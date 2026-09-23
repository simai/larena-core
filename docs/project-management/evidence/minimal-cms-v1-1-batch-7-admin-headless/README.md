# Batch 7 wave D1 — larena/core

Registry operations had no runtime outside REST. Core now serves every registered operation by the handler its declaration names (OperationHandlerCatalog + CatalogOperationHandler), wraps transactional operations in its own ConnectionOperationTransactionBoundary, denies any required capability through UndeclaredCapabilityGate, and plane operations describe their change before making it, so a bulk node move can be proposed and approved.

Plan: `docs/implementation-planning/minimal-cms-v1-1-batch-7-wave-d-full-scope.md` in simai/larena-specs (owner decision: full scope, 2026-09-23).
