# Batch 3 wave A — review feedback

No independent review yet. This file records what a reviewer should attack first.

1. **The two-source design.** `operations.yaml` declares the contract and the
   handler classes build the descriptors; the registry checks them against each
   other. A reviewer should decide whether the mismatch check is worth the
   duplication, or whether descriptors should be built from the file at boot. The
   reasoning for keeping both is in `CoreOperationProvider`: the handlers already
   dispatch on the descriptor in PHP, and building descriptors from a file would
   make a running operation depend on a file parse.
2. **The stateless approval.** The digest covers the operation name and the
   canonical input, and nothing else — no time, no nonce, no actor. So an approval
   does not expire and any holder of the digest can execute that exact change.
   A reviewer should confirm this is the right trade for Batch 3, where the
   approval never leaves the process, and say when a persisted proposal with an
   expiry becomes necessary.
3. **The gap policy.** The coverage report does not fail on a declared operation
   code with no registered operation. Read the reasoning in
   `OperationCoverageReport` and decide whether an unscheduled gap should fail
   once the plan schedules every package.
4. **The 23 unscheduled gaps.** `auth`, `file-manager`, `search` and `audit`
   declare access operation codes that the v1.1 cluster plan never schedules for
   registration. This wave reports them; it does not fix the plan.
5. **`larena/file-manager`'s unversioned `access.yaml`.** No schema, no package
   key. Reported as a notice. A reviewer should decide which batch fixes it.
