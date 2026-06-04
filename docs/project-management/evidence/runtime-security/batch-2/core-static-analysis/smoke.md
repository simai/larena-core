# Smoke

Smoke expectations:

- `vendor/bin/phpstan` exists after Composer install/update.
- `composer run analyse` executes PHPStan and does not silently skip.
- Static analysis covers `src`, `scripts`, `tests` and `tools`.
- Batch 1 runtime contracts remain unchanged.

Result: passed.

Evidence:

- `vendor/bin/phpstan` exists and is executed by `composer run analyse`.
- `scripts/analyse.php` fails closed when PHPStan is unavailable.
- PHPStan now checks `src`, `scripts`, `tests` and `tools`.
- No runtime contract file in `src` was changed in this batch.
