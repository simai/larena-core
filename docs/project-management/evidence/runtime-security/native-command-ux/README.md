# Native Command UX Evidence

This evidence package covers the `larena/core` native command UX batch for the
runtime/security developer milestone.

## Scope

- Add a shared command report presenter.
- Make Larena starter commands human-readable by default.
- Preserve machine-readable command output through `--json`.
- Expose combined human and JSON diagnostics through `--full`.
- Keep install apply blocked without a launch record and label that state as an
  expected guard.
- Keep workspace smoke scripts machine-readable by calling native commands with
  `--json`.

## Non-Goals

- No registry seed mutation in smoke.
- No broad package behavior changes.
- No persistence model changes.
- No admin UI.
- No direct canonical graph/spec updates.

