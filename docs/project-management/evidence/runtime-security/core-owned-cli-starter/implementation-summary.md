# Implementation Summary

## Changed

- Added a Laravel service provider in `Larena\Core\Providers\CoreServiceProvider`.
- Added package-owned Artisan command classes for Larena starter diagnostics.
- Moved starter scenario and runtime/security smoke implementation into
  `Larena\Core`.
- Added Laravel console/support component dependencies to `larena/core`.
- Added dev-only path dependencies for runtime/security smoke type analysis.

## Boundary

`larena/core` owns command registration and starter diagnostics. It does not own
production access, audit or licensing policy. Those remain package boundaries of
`larena/access`, `larena/audit` and `larena/licensing`.

## Non-Goals

- No production installer mutations.
- No admin bootstrap.
- No persistence migrations.
- No direct canonical spec update.
