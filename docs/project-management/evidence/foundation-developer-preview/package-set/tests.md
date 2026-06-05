# Tests

Commands run from `/Users/rim/Documents/GitHub/larena-workspace/packages/core`.

## Passed

```bash
/Applications/ServBay/script/alias/php /Applications/ServBay/package/bin/composer validate --strict
PATH=/Applications/ServBay/script/alias:/Applications/ServBay/package/bin:$PATH composer run validate:larena
PATH=/Applications/ServBay/script/alias:/Applications/ServBay/package/bin:$PATH composer run quality:gate
git diff --check
```

## Covered Assertions

- `FoundationPackageSetTest` verifies that the runtime/security package list is
  a subset of the foundation preview package list and that the data/content
  package set is present.
- `PackageRegistrySeedTest` verifies guarded seed output includes
  `larena/storage` and remains idempotent when repeated with the same required
  package set.
- `RuntimeSecurityClusterSmokeTest` verifies package registry diagnostics
  inspect the expanded foundation set while runtime/security smoke remains
  passed.

## Runtime Note

ServBay PHP was used because the local Homebrew PHP binary references a missing
ICU library. This is an environment issue, not a package failure.

