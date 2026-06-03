# Tests

Commands required for this batch:

```bash
composer validate --strict
composer run validate:larena
composer run lint
composer run analyse
composer run test
composer run evidence:check
```

Expected result: all pass on PHP `^8.3`.

PHPStan findings handled during implementation:

- removed redundant enum `instanceof` assertion;
- replaced literal invalid enum check with a non-literal helper value;
- checked normalized error arrays before accessing the `code` offset.
- corrected `evidence:check` so it validates the current launch context
  evidence path.
