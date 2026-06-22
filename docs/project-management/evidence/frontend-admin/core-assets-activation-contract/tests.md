# Tests

Planned command:

```bash
composer run quality:gate
```

Targeted test:

```bash
php tests/Unit/CoreAssetActivationContractTest.php
```

Expected outcome: descriptor-only activation passes, unsafe physical/runtime drift is rejected.

