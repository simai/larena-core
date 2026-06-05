# Smoke

Run from `/Users/rim/Documents/GitHub/larena` after updating `larena/core` to
`3e57adb`:

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

Run from `/Users/rim/Documents/GitHub/larena-workspace`:

```bash
./scripts/test-runtime-security.sh
./scripts/seed-package-registry.sh --json
```

Expected:

- Default command output is readable for developers.
- `--json` output remains parseable by workspace scripts.
- `--full` contains the developer summary and full JSON.
- `larena:install --json` exits with code `1` as an expected guard.
- Runtime/security workspace smoke reports `Status: PASS`.

