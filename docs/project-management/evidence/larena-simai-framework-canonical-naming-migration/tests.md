# Tests

- Source provenance: PASS. Input HEAD was
  `2ee83f37fd46a13df51b08a85fef2bcb6defb42b`; tracked-diff SHA-256 was
  `638e5bb7b8304018f9e6c5209abb419db1a61fbc49fdaf94d21daef6319c497a`.
- Dependency install from `composer.lock`: PASS under ServBay PHP 8.4.20.
  Larena path dependencies were supplied from disposable exact-SHA clones
  matching all five lock references.
- `composer validate --strict --no-check-publish`: PASS.
- `composer dump-autoload --no-interaction`: PASS.
- JSON parsing for the launch context and three JSON evidence files: PASS.
- YAML parsing for `module.yaml`: PASS.
- `composer run quality:gate`: PASS.
  - package launch validator: PASS;
  - PHP lint: 70 files;
  - PHPStan: no errors;
  - all 21 Core test scripts: PASS;
  - metadata sync: PASS;
  - evidence contract: PASS;
  - scope enforcement: PASS, exactly 10 changed files.
- Case-insensitive scan for the retired `sf5` generation label in active
  `src/`, `config/`, and `tests/`: PASS.
- Credential/private-key format and assignment scan over all 10 allowed files:
  PASS.
- `git diff --check`: PASS.

The first sandboxed quality-gate attempt reached PHPStan but could not open its
ephemeral loopback socket (`EPERM`). The same complete gate passed outside
that sandbox restriction. Composer emitted dependency-level PHP 8.4 deprecation
notices; they did not affect validation or package checks.
