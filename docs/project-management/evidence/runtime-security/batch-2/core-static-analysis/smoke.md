# Smoke

Smoke checks:

- `vendor/bin/phpstan` exists after Composer installs dev dependencies.
- `composer run analyse` runs PHPStan through `phpstan.neon.dist`.
- `composer run analyse` no longer silently passes when PHPStan is missing.
- Batch 1 runtime contracts remain unchanged.

