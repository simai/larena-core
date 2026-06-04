# Smoke Evidence

Smoke checks:

- Package metadata exists in `composer.json`.
- Package metadata exists in `module.yaml`.
- Contract files load through direct PHP test requires.
- Config file returns supported execution mode and decision status values.
- No forbidden runtime paths were added:
  - `database/migrations/`
  - `resources/views/`
  - `routes/`
  - `src/Http/`
  - `src/Console/`
  - `src/Providers/ProductionRuntimeServiceProvider.php`

Result: passed.
