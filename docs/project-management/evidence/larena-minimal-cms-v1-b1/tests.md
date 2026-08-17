# Tests

- `composer validate --no-check-publish --no-interaction`: PASS on PHP 8.4.20.
- `composer quality:gate`: PASS on PHP 8.4.20, including lint, static analysis, package tests, metadata, evidence and scope checks.
- `tests/Unit/MinimalCmsDependencyContractTest.php`: PASS; mandatory Larena dependency set is empty.
- Workspace dependency report: Core conformant; whole graph intentionally remains nonconformant for B2/B3.
