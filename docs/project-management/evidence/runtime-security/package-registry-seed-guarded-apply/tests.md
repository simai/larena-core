# Tests

Run from `/Users/rim/Documents/GitHub/larena-workspace/packages/core`:

```bash
composer validate --strict
composer dump-autoload
composer run quality:gate
```

Entry app smoke after updating `larena/core`:

```bash
php artisan larena:install --dry-run
php artisan larena:install
php artisan larena:install --launch-record=docs/project-management/launch-records/package-registry-seed-guarded-apply.json --confirm=package_registry_seed
php artisan larena:doctor
php artisan larena:runtime-security-smoke
php artisan test
curl -k -I https://larena.test
```

Expected:

- Dry-run passes and remains read-only.
- Install without launch record is blocked.
- Guarded apply passes only with a valid launch record and explicit
  confirmation.
- Re-running guarded apply is idempotent.
