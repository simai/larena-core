# Tests

Runtime:

- PHP: `/opt/homebrew/opt/php@8.3/bin/php`
- Composer: `/Applications/ServBay/package/bin/composer`

Commands:

```bash
PATH=/opt/homebrew/opt/php@8.3/bin:$PATH /Applications/ServBay/package/bin/composer validate --strict
PATH=/opt/homebrew/opt/php@8.3/bin:$PATH /Applications/ServBay/package/bin/composer run quality:gate
PATH=/opt/homebrew/opt/php@8.3/bin:$PATH /Applications/ServBay/package/bin/composer install --no-interaction --prefer-dist
PATH=/opt/homebrew/opt/php@8.3/bin:$PATH /Applications/ServBay/package/bin/composer run quality:gate
```

Results:

- `composer validate --strict`: passed.
- `composer run validate:larena`: passed.
- `composer run lint`: passed, 16 PHP files checked across `config`, `scripts`, `src`, `tests` and `tools`.
- `composer run analyse`: passed after `composer install` installed `phpstan/phpstan` from the lock file.
- `composer run test`: passed.
- `composer run evidence:check`: passed.
- `composer run scope:check`: passed, 24 changed files stayed inside launch scope or evidence path.

Unit coverage:

- `OperationRuntimeContractTest.php`
- `OperationRuntimeFailsClosedTest.php`

Acceptance covered:

- governed operation context can be represented;
- access decision slot exists without owning access;
- capability decision slot exists without owning licensing;
- audit correlation slot exists without owning audit;
- sync, queued, scheduled and denied execution modes are represented;
- unknown or unsafe operation mode fails closed at contract boundary.
- contradictory operation decision states are rejected by constructor invariants.
