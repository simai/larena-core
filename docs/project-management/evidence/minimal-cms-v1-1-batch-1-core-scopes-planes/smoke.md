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

## Not covered by this batch

No HTTP surface exists for scopes or planes, so there is no direct-HTTP
prohibition matrix yet; it arrives with the REST/MCP projection batch.
