# Batch 3 wave A — tests

Command: `composer test` (plain PHP with `zend.assertions=1`, the package's
existing style). Eleven new test files, all passing; the 38 Batch 0–1 tests
continue to pass unchanged.

| Test | What it proves |
| --- | --- |
| `OperationDeclarationLoaderTest` | Core declares all 22 operations with their real gates, risk and schemas; `core.transport.invoke_remote` is absent while no network transport exists |
| `OperationDeclarationLoaderFailsClosedTest` | 24 rejection cases: unknown top-level key, unknown operation key, a scalar where a mapping belongs, duplicate name, wrong schema, wrong package, empty operations, every invalid field value, every missing required field, unknown schema field, a list document, a missing file |
| `DeclaredOperationRegistryTest` | Registration, duplicate rejection, stable ordering, filtering, fail-closed accessors; the whole core catalogue registers from its declaration file |
| `DescriptorPolicyTest` | Every v2 rule with its own reason code; a mismatch on each of the eight shared fields is detected and named; a rejected registration leaves nothing behind |
| `ConfirmationPolicyTest` | The seven risk/reversibility combinations and their reason codes, then applied to the real core catalogue: every read passes unconfirmed, the subtree move always asks |
| `ProposalAndApprovalTest` | A proposal writes nothing and returns a receipt; the approved write reaches the handler exactly once through the same path; the digest ignores key order and depends on the operation; a read proposes itself; `describe` carries schemas, risk and the confirmation answer |
| `ProposalFailsClosedTest` | Unregistered operation, missing approval, mismatched digest, wrong-operation approval, a handler without proposal support, a denied access decision, and three malformed approvals — every one refused with its own reason code, nothing written |
| `TransportResolutionTest` | Local with no binding and with a binding on this node; the free local transport executes with no entitlement gate bound; an unregistered operation and a local-only operation bound elsewhere are refused; a failed resolution never executes locally |
| `TransportGateMatrixTest` | All seven incomplete gate combinations: the exact missing-gate list in the frozen order and the reason code from the first; unbound gates equal failing gates; the complete row resolves to the network transport without sending anything |
| `PackageDescriptorFileValidatorTest` | The Batch 2 defect written exactly as it happened is caught, both misplaced entries reported and the misplaced codes shown to be undeclared; six further malformed shapes |
| `OperationCoverageReportTest` | Core covers its own declarations; a scheduled gap passes and carries its batch; an unscheduled gap passes and is named; a malformed descriptor fails; an unversioned descriptor is a notice; an operation registered against an undeclared code fails |

## Two notes on how the tests are written

The test doubles expose impure counter methods rather than public arrays. A
static analyser cannot see that a call through the handler interface mutates the
double, so reading the arrays directly makes PHPStan "prove" that every assertion
about them is constant — and a test whose assertions are constants proves
nothing. `@phpstan-impure` accessors state the truth instead.

"Must throw" cases use `throw new RuntimeException(...)` rather than
`assert(false, ...)`, matching the Batch 1 convention, for the same reason: a
literal false assertion is a constant condition to the analyser.

## PHPStan

Level 5, `[OK] No errors`. The analyser is pointed at `../../app/vendor/symfony/yaml`
because composer cannot resolve this workspace's dev path repositories, so the
package vendor tree has no `symfony/yaml` even though `composer.json` now requires
it. Recorded in `deviations.json`.
