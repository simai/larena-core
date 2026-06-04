# Tests

Run from `/Users/rim/Documents/GitHub/larena-workspace/packages/core`:

```bash
composer validate --strict
composer dump-autoload
composer run quality:gate
```

Entry app smoke after updating `larena/core`:

```bash
php artisan larena:cluster-smoke
php artisan larena:packages
php artisan larena:doctor
php artisan larena:install --dry-run
php artisan larena:install
php artisan larena:runtime-security-smoke
php artisan test
curl -k -I https://larena.test
```

Expected:

- `larena:cluster-smoke` reports `passed` on the prepared entry app.
- The command includes runtime security smoke and package registry diagnostics.
- The command does not apply installation changes.
- `larena:install` without a launch record remains blocked.
