# Smoke

## Package Smoke

The package-local quality gate passes with ServBay PHP:

```bash
PATH=/Applications/ServBay/script/alias:/Applications/ServBay/package/bin:$PATH composer run quality:gate
```

## Entry App Smoke

Entry app smoke is part of the next batch after the entry app Composer package
set is updated:

- `php artisan larena:cluster-smoke`
- `php artisan larena:packages`
- `php artisan larena:doctor`
- `php artisan test`
- `/Users/rim/Documents/GitHub/larena-workspace/scripts/test-runtime-security.sh`

The expected result is `PASS` for runtime/security checks with foundation
package registry diagnostics no worse than an explicit guarded apply state.

