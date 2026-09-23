# Batch 1 tests

Package suite: `composer test` — 38 entries, all passing, exit 0.
Static analysis: `composer analyse` — PHPStan level 5, `[OK] No errors`.
Lint: `composer lint` — 133 files, no syntax errors.

## New tests

| Test | Proves |
| --- | --- |
| `tests/Unit/ScopeRefTest.php` | `<kind>:<identifier>` grammar; rejects unknown kind, empty, uppercase, leading dash and a 64-character identifier; accepts the 63-character maximum |
| `tests/Unit/ScopeRegistryTest.php` | a site without an organization parent; an organization owning two sites; resolution by raw reference; archive keeps reads and hides the scope from the active listing |
| `tests/Unit/ScopeRegistryFailsClosedTest.php` | duplicate identity, reuse after archive, write bound to an archived scope, site parented to a site, organization with a parent, unknown parent, unknown scope on archive, writable-scope guard |
| `tests/Unit/PlaneRegistryTest.php` | flat and tree planes in one scope; path and depth; deterministic sibling order per parent; archive keeps the node readable |
| `tests/Unit/PlaneNodeMoveTest.php` | a subtree move rewrites path and depth for every descendant; a move to the root; order index recomputed against the new parent |
| `tests/Unit/PlaneNodeMoveFailsClosedTest.php` | move into self and into own subtree, cross-plane parent, nesting in a flat plane on create and on move, unknown node and plane, duplicate node, archived node/plane/scope writes, the depth-16 guard |
| `tests/Unit/MembershipResolverTest.php` | opaque subject references (user and AI actor); idempotent assign; revoke and re-activate; one subject in nodes of two planes; ancestry root first; unknown node, unknown membership and empty subject fail closed |
| `tests/Unit/MembershipResolverDescendantsTest.php` | members with and without descendants, de-duplicated and deterministically ordered; a subtree move changes resolution without touching membership |
| `tests/Unit/ScopeBaselineInstallerIdempotencyTest.php` | first apply creates three records; second apply is a no-op; the baseline plane is flat; a missing schema reports instead of throwing |
| `tests/Unit/ScopePlaneAuditEventTest.php` | every declared audit event is in the package catalogs; actor and correlation id reach the record; missing input and a foreign operation name fail closed |
| `tests/Unit/OperationDescriptorRiskClassDefaultTest.php` | descriptors without the new fields stay valid and resolve fail-safe; all sixteen Batch 1 operations declare an access scope, and every mutation declares an audit event, a transaction boundary and an idempotency key |

`tests/support/scope-plane-schema.php` builds the four tables on an in-memory
SQLite connection, mirroring the frozen migration shapes.

## Root tests

`tests/Unit/MinimalCmsFirstAdministratorCreatorTest.php` (pre-existing) passes
unchanged: the new dependencies are optional.
`tests/Unit/MinimalCmsFirstAdministratorPlaneMembershipTest.php` (new, 3 tests)
proves the membership row, idempotency on a second run, the unchanged
storage-page scope behaviour, and inert behaviour both without the core scope
schema and without the core services.
