# Tests

Run from `/Users/rim/Documents/GitHub/larena-workspace/packages/core` with
ServBay PHP first in `PATH`:

```bash
export PATH="/Applications/ServBay/package/php/8.4/8.4.20/bin:/Applications/ServBay/package/bin:$PATH"
composer validate --strict
composer dump-autoload
composer run quality:gate
```

Expected:

- Composer metadata is valid.
- Lint passes.
- PHPStan passes with explicit memory limit.
- All package unit tests pass, including `CommandReportPresenterTest`.
- Evidence and scope checks pass.

Entry app smoke after updating `larena/core`:

```bash
php artisan larena:cluster-smoke
php artisan larena:cluster-smoke --json
php artisan larena:cluster-smoke --full
php artisan larena:packages
php artisan larena:doctor
php artisan larena:install --dry-run
php artisan larena:install --json
php artisan larena:runtime-security-smoke
php artisan test
```

Expected:

- Default command output is readable without inspecting raw JSON.
- `--json` emits JSON only.
- `--full` includes both the summary and full JSON.
- `larena:install` without launch record is clearly reported as an expected
  guard.

