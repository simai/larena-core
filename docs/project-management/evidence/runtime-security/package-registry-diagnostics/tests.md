# Tests

Run from `/Users/rim/Documents/GitHub/larena-workspace/packages/core`:

```bash
composer validate --strict
composer dump-autoload
composer run quality:gate
```

Entry app smoke after updating `larena/core`:

```bash
php artisan larena:packages
php artisan larena:doctor
php artisan larena:install --dry-run
php artisan larena:install
php artisan larena:runtime-security-smoke
php artisan test
curl -k -I https://larena.test
```

Expected:

- `larena:packages` reports package registry state and never mutates state.
- `larena:doctor` includes `package_registry`.
- `larena:install` without launch record remains blocked.
