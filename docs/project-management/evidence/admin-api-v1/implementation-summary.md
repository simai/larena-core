# Implementation summary

- Added an optional `OperationTransactionBoundary` contract.
- Added a backward-compatible `transactional=false` descriptor flag.
- Transactional operations now roll back on decision/result Audit failure and
  on handler exceptions, including partial writes made before an exception.
- After a confirmed rollback, the runtime records one separate rollback result
  outside the closed transaction and reports only that post-rollback event.
- A transactional handler exception skips the in-transaction result Audit and
  proceeds directly to the boundary abort. This prevents a MySQL deadlock that
  already rolled back the server transaction from turning that result Audit
  into an accidental autocommit event.
- Transaction boundaries must own the outermost transaction, reject ambient
  transactions, and enlist domain writes plus Audit in the same durable
  resource; the Rest composition enforces this fail-closed.
- Handler and Audit exception details are replaced by fixed safe codes in the
  runtime result; raw exception messages and classes are not exposed.
- Existing constructor calls, stable error codes and operation ordering remain
  compatible. Raw handler/Audit diagnostics were deliberately removed from
  returned runtime data to satisfy the sanitization boundary.
