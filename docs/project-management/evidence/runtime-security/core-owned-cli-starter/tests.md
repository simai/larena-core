# Test Evidence

## Package Checks

Target commands:

```bash
composer validate --strict
composer dump-autoload
composer run quality:gate
```

Expected result:

- Composer metadata is valid.
- PHP syntax lint passes.
- PHPStan analysis passes.
- Existing unit tests pass.
- Evidence and scope checks pass.

## Entry App Checks

Target commands:

```bash
composer dump-autoload
php artisan package:discover
php artisan larena:doctor
php artisan larena:install --dry-run
php artisan larena:runtime-security-smoke
php artisan test
```

Expected result:

- Entry app discovers the package-owned `larena/core` service provider.
- Starter commands run without app-local command closures.
- Runtime/security smoke remains passed.
