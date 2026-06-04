# Runtime/Security Cluster Smoke Evidence

This evidence package covers the first read-only cluster smoke command for the
Larena runtime/security foundation.

## Scope

- Add `larena:cluster-smoke`.
- Aggregate required package availability for `larena/core`, `larena/access`,
  `larena/audit` and `larena/licensing`.
- Include runtime security smoke evidence.
- Include read-only package registry diagnostics.
- Confirm the install guard remains fail-closed without a launch record.

## Non-Goals

- No install apply mutation.
- No migrations.
- No admin UI.
- No update server behavior.
- No direct canonical graph/spec updates.
