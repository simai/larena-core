# Test Evidence

Target package checks:

```bash
composer validate --strict
composer run quality:gate
```

Target entry app checks:

```bash
composer update larena/core --with-dependencies
php artisan larena:install --dry-run
php artisan larena:install
php artisan larena:doctor
php artisan larena:runtime-security-smoke
php artisan test
curl -k -I https://larena.test
```

Expected result:

- Dry-run emits `install_readiness_contract`.
- Non-dry-run remains blocked.
- Runtime/security smoke remains passed.
- Laravel web smoke remains HTTP 200.
