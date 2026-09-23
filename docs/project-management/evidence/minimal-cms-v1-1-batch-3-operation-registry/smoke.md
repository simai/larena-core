# Batch 3 wave A — smoke

## Coverage command against the root

Root: `simai/larena` at `10e1df67`, branch
`feature/minimal-cms-v1-1-batch-1-core-scopes-planes`, consuming the package by
Composer path symlink.

```
php artisan core:operations:coverage --json
```

| Run | Status | Exit | Registered | Covered | Scheduled gaps | Unscheduled gaps | Descriptor violations | Notices |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| MySQL 8.2 (`larena`) | passed | 0 | 22 | 8 | 64 | 23 | 0 | 1 |
| SQLite (file-backed) | passed | 0 | 22 | 8 | 64 | 23 | 0 | 1 |

The two reports are byte-identical except for `generated_for_revision`. That is
expected and worth stating plainly: the registry is composed from declaration
files at boot and touches no database, so driver parity here proves the command
is driver-independent rather than proving anything about schema.

## Restart readback

Two separate `php artisan` processes produced identical JSON. For a registry
composed at boot this *is* the readback test: there is no stored state to reload,
so identical composition across processes is the whole claim.

## Transport gate matrix

Captured from `TransportGateMatrixTest`. Rows are the gates that are bound.

| Entitlement | Node trust | Implementation | Missing gates reported | Reason code |
| --- | --- | --- | --- | --- |
| — | — | — | entitlement, node trust, implementation | `entitlement_missing` |
| bound | — | — | node trust, implementation | `node_trust_failed` |
| — | bound | — | entitlement, implementation | `entitlement_missing` |
| bound | bound | — | implementation | `transport_not_implemented` |
| — | — | bound | entitlement, node trust | `entitlement_missing` |
| bound | — | bound | node trust | `node_trust_failed` |
| — | bound | bound | entitlement | `entitlement_missing` |
| bound | bound | bound | — | `network_transport_resolved` |

Nothing bound at all gives the same answer as every gate explicitly failing. The
last row is reachable only with a test double standing in for the network
transport package; the open core binds none, and no row ever executed locally.

## Root test suite

```
php -d memory_limit=1024M vendor/bin/phpunit --colors=never --no-progress --do-not-cache-result
```

| Run | Tests | Passed | Failed |
| --- | --- | --- | --- |
| baseline, all repositories on `main` | 1257 | 958 | 208 |
| Batch 1 branches | 1260 | 961 | 208 |
| Batch 1 + Batch 2 branches | 1260 | 961 | 208 |
| Batch 1 + Batch 2 + Batch 3 wave A branches | 1260 | 961 | 208 |

Zero new failures and zero fixed ones. Wave A adds no root-visible behaviour: the
registry is only reachable through the new console command, which the suite does
not exercise.

## Not claimed

No push, release or deployment. No network call was made or could be made. No
independent review of Batch 1, Batch 2 or this wave yet.
