# Foundation Package Set Evidence

This evidence package covers the `larena/core` batch that expands starter
diagnostics from the runtime/security package set to the first foundation
developer preview package set.

## Scope

- Keep runtime/security smoke requirements limited to `larena/core`,
  `larena/access`, `larena/audit`, and `larena/licensing`.
- Add a single `FoundationPackageSet` contract for package-set diagnostics.
- Make package registry diagnostics and guarded registry seed use the expanded
  foundation preview package set.
- Keep missing data/content packages as degraded/action-required diagnostics,
  not as runtime/security failures.

## Non-Goals

- No storage runtime.
- No filesystem upload runtime.
- No search indexing runtime.
- No backup archive runtime.
- No file-manager UI.
- No routes, migrations, controllers, or production persistence behavior.
- No direct canonical graph/spec updates from the package repo.

## Package Sets

Runtime/security required packages:

- `larena/core`
- `larena/access`
- `larena/audit`
- `larena/licensing`

Foundation developer preview packages:

- `larena/core`
- `larena/access`
- `larena/audit`
- `larena/licensing`
- `larena/storage`
- `larena/filesystem`
- `larena/lang`
- `larena/search`
- `larena/link`
- `larena/backup`
- `larena/file-manager`

