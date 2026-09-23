# Batch 1 code review feedback

Reviewer: implementation author self-review plus the package quality gate.
Status: `review_pending_independent_reviewer`.

## Findings addressed during implementation

1. **MySQL identifier limit.** The generated index name for
   `(plane_id, parent_node_id, order_index)` is 65 characters. MySQL rejects it;
   SQLite accepted it. All new indexes are now named explicitly. This is the
   reason the acceptance criteria demand MySQL parity and not only SQLite.
2. **Legacy test loading.** Adding an enum-typed default to
   `OperationDescriptor` broke six pre-existing core tests that load contract
   files without the autoloader. Resolved by a nullable field plus
   `effectiveRiskClass()` instead of editing tests outside the write boundary.
3. **Fail-closed reason codes.** `NodePath` threw `InvalidArgumentException` for
   a depth overflow, which is not a stable diagnostic. The registry now
   converts path failures into `ScopeBoundaryViolation` with the reason codes
   `depth_exceeded` and `invalid_node_key`.
4. **PHPStan purity.** The database registries were treated as pure, so the
   analyser "remembered" return values and reported impossible comparisons in
   the tests. The database-touching methods are now marked `@phpstan-impure`,
   which is also honest documentation.
5. **First-run composition.** `FirstRunCoordinator::REQUIRED_CONTRIBUTORS` is a
   hard-coded exact list including `content`. A core contributor would break it,
   so the baseline is an idempotent install step instead.

## Open review questions for an independent reviewer

1. Should `membersOf(includeDescendants: true)` push the descendant filter into
   SQL (one prefix query) instead of filtering the loaded node list? The current
   form is correct and bounded by plane size; the SQL form is faster on large
   planes and is a Batch 2 optimisation candidate once Access reads it on every
   request.
2. `ancestryOf()` loads the whole plane to map node keys to records. Fine for
   planes of realistic size, but a direct `whereIn(node_key)` query is cheaper.
3. The root already carries an ad-hoc scope notion in
   `larena_storage_page_scopes` (`scope_ref = 'scope:minimal-cms'`). It should be
   absorbed by the core scope model; that belongs to the Storage batch, not
   here, and is recorded as a plan follow-up.
