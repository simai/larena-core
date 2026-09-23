# Batch 1 smoke evidence

Both databases are throwaway: a temporary SQLite file and a MySQL database
created and dropped for this run. The working `larena` database, `larena.test`
and every other live environment were untouched.

## SQLite

```
DB_CONNECTION=sqlite DB_DATABASE=<scratch>/smoke.sqlite php artisan migrate --force
2026_09_24_000001_create_larena_core_scopes_table ......... DONE
2026_09_24_000002_create_larena_core_planes_table ......... DONE
2026_09_24_000003_create_larena_core_plane_nodes_table .... DONE
2026_09_24_000004_create_larena_core_memberships_table .... DONE
```

Baseline apply through the container-resolved installer:

```
{"apply":{"status":"applied","created":["site:main","site:main/groups","site:main/groups#administrators"]}}
{"second_apply":{"status":"already_applied","created":[]}}
```

Readback in the same process and again in a **fresh process** (restart
readback) returns the same records:

```
scope  site:main            kind site   parent null   status active
plane  site:main/groups     kind flat                 status active
node   site:main/groups#administrators  path administrators  depth 0  order 0
members                     ["user:admin_identity:1"]
members_with_descendants    ["user:admin_identity:1"]
```

Row counts after two applies: 1 scope, 1 plane, 1 node, 1 membership.

## MySQL 8.2.0

Database `larena_v11_batch1_smoke_1790131073` (created for this run, dropped afterwards).

The first attempt **failed** and found a real defect:

```
SQLSTATE[42000]: 1059 Identifier name
'larena_core_plane_nodes_plane_id_parent_node_id_order_index_index'
is too long
```

The generated index name is 65 characters and MySQL allows 64; SQLite accepted
it silently. Fixed by naming every new index explicitly
(`core_plane_nodes_parent_order_idx` and siblings, all within the limit).

After the fix:

```
2026_09_24_000001_create_larena_core_scopes_table ......... DONE
2026_09_24_000002_create_larena_core_planes_table ......... DONE
2026_09_24_000003_create_larena_core_plane_nodes_table .... DONE
2026_09_24_000004_create_larena_core_memberships_table .... DONE

{"apply":{"status":"applied","created":["site:main","site:main/groups","site:main/groups#administrators"]}}
{"second_apply":{"status":"already_applied","created":[]}}
```

Restart readback in a fresh process returns the same scope, plane, node and
membership.

## Rollback

```
php artisan migrate:rollback --step=1 --force
2026_09_24_000004_create_larena_core_memberships_table .... DONE
php artisan migrate --force
2026_09_24_000004_create_larena_core_memberships_table .... DONE
```

The migrations are additive and reversible; rollback and re-apply both succeed
on MySQL.

## Root suite comparison

The root suite was run on both repositories at `main` and again on the batch
branches:

| Run | Tests | Passed | Failed |
| --- | --- | --- | --- |
| baseline (`main` + `main`) | 1257 | 958 | 208 |
| batch branches, first attempt | 1260 | 960 | 209 |
| batch branches, after the fix | 1260 | 961 | 208 |

The comparison showed exactly **one** new failure and no fixed ones:

```
Tests\Feature\InstallerFoundationDiagnosticsTest::test_guarded_installer_db_schema_apply_runs_package_migrations_and_can_rollback
```

Cause: the guarded installer applies and rolls back every migration in
`database/migrations` of the core package as its bootstrap schema, and the test
rolls back with `--step 2` on that path. Four extra files there made the
rollback remove the wrong migrations, leaving `larena_package_registry` in
place.

Fix: platform schema that is not installer bootstrap moved to
`database/migrations/platform/` with its own `loadMigrationsFrom` registration.
The installer path again holds exactly its two bootstrap migrations. After the
fix the test passes and a fresh `php artisan migrate` still applies all four
platform migrations.

After the fix the branch failure set is **identical to the baseline**: 208
failures, zero new, zero fixed, and three more passing tests (the new root
membership tests). The 208 are pre-existing on `main` and unrelated to this
batch (auth identity lifecycle, composition workspace, admin diagnostics and
other areas).

Commands:

```
git -C larena-workspace/packages/core checkout main && git -C larena checkout main
php vendor/bin/phpunit            # baseline: 1257 tests, 958 passed, 208 failed
git checkout feature/minimal-cms-v1-1-batch-1-core-scopes-planes  # both repos
php vendor/bin/phpunit            # branch:   1260 tests, 961 passed, 208 failed
```

## Not covered by this batch

No HTTP surface exists for scopes or planes, so there is no direct-HTTP
prohibition matrix yet; it arrives with the REST/MCP projection batch.
