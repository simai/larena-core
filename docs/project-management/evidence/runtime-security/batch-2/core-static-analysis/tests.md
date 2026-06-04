# Tests

Required commands:

```bash
PATH=/opt/homebrew/opt/php@8.3/bin:$PATH /Applications/ServBay/package/bin/composer validate --strict
PATH=/opt/homebrew/opt/php@8.3/bin:$PATH /Applications/ServBay/package/bin/composer dump-autoload
PATH=/opt/homebrew/opt/php@8.3/bin:$PATH /Applications/ServBay/package/bin/composer run validate:larena
PATH=/opt/homebrew/opt/php@8.3/bin:$PATH /Applications/ServBay/package/bin/composer run lint
PATH=/opt/homebrew/opt/php@8.3/bin:$PATH /Applications/ServBay/package/bin/composer run analyse
PATH=/opt/homebrew/opt/php@8.3/bin:$PATH /Applications/ServBay/package/bin/composer run test
PATH=/opt/homebrew/opt/php@8.3/bin:$PATH /Applications/ServBay/package/bin/composer run evidence:check
PATH=/opt/homebrew/opt/php@8.3/bin:$PATH /Applications/ServBay/package/bin/composer run scope:check
PATH=/opt/homebrew/opt/php@8.3/bin:$PATH /Applications/ServBay/package/bin/composer run quality:gate
git diff --check
/bin/zsh .githooks/pre-commit
/bin/zsh .githooks/pre-push
```

Result: passed.

Evidence:

- Composer metadata validation passed.
- Composer autoload generation passed.
- Larena package validation passed.
- Lint passed for 16 PHP files.
- PHPStan passed with no errors.
- Unit tests passed:
  - `OperationRuntimeContractTest passed.`
  - `OperationRuntimeFailsClosedTest passed.`
- Evidence check passed against the current launch context.
- Scope check passed for 14 changed files.
- Quality gate passed.
- `git diff --check` passed.
- `.githooks/pre-commit` passed.
- `.githooks/pre-push` passed.
