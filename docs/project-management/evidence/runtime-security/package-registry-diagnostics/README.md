# Package Registry Diagnostics Evidence

This evidence package covers the read-only package registry diagnostics batch.

## Scope

- Add `larena:packages`.
- Add package registry status to `larena:doctor`.
- Keep diagnostics read-only.
- Treat missing registry as a diagnostic state, not as a blocker for first
  install planning.
- Treat invalid registry schema as fail-closed diagnostic failure.
