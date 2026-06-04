# Implementation Summary

## Changed

- Added `Larena\Core\Starter\PackageRegistryDiagnostics`.
- Added `Larena\Core\Console\Commands\PackageRegistryCommand`.
- Registered `larena:packages`.
- Added package registry diagnostics to `larena:doctor`.
- Added `PackageRegistryDiagnosticsTest`.

## Safety Boundaries

- Diagnostics never write runtime state.
- Missing registry does not block first install planning.
- Invalid registry schema reports `invalid`.
- No new install mutations, migrations, routes, admin UI or update-server
  behavior were added.
