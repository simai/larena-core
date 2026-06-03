# Tests

Runtime:

- PHP: `/opt/homebrew/opt/php@8.3/bin/php`
- Composer: `/Applications/ServBay/package/bin/composer`

Commands:

```bash
composer validate --strict
composer run validate:larena
composer run lint
composer run analyse
composer run test
composer run evidence:check
```

Results:

- `composer validate --strict`: passed.
- `composer run validate:larena`: passed.
- `composer run lint`: passed.
- `composer run analyse`: passed, PHPStan is deferred until dev tooling is added.
- `composer run test`: passed.
- `composer run evidence:check`: passed.

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
