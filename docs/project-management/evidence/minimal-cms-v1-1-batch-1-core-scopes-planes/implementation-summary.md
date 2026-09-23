# Batch 1 — Core Scopes, Planes and Membership

Cluster: `minimal_cms_v1_1_alignment`
Target: `larena.target.minimal_cms_v1_1`, digest `sha256:cbbfa628327f11f1f2c958c9b24cc10334fba965b9cce232e40884a6f37a1d3f`
Launch record: `docs/project-management/launch-records/minimal-cms-v1-1-batch-1-core-scopes-planes.json`
Specs revision: `simai/larena-specs` `2aeb08da`
Package base commit: `47123f2`
Branch: `feature/minimal-cms-v1-1-batch-1-core-scopes-planes`

## What was implemented

`larena/core` now owns scopes, planes, plane nodes and membership as
fixed-shape system records, exactly as frozen in
`specs/implementation-planning/minimal-cms-v1-1-batch-1-pre-codegen-freeze.json`.

- Four migrations: `larena_core_scopes`, `larena_core_planes`,
  `larena_core_plane_nodes`, `larena_core_memberships`.
- `ScopeRef` (`<kind>:<identifier>`, kinds `site` and `organization`) and
  `NodePath` (materialized path, maximum depth 16).
- `DatabaseScopeRegistry` (also the `ScopeRefResolver`), `DatabasePlaneRegistry`
  with adjacency plus materialized path and a transactional subtree move,
  `DatabaseMembershipResolver` with descendant resolution and ancestry.
- Sixteen declared operations (`core.scope.*`, `core.plane.*`) with access
  scope, audit event, idempotency key, transaction boundary and risk class,
  routed through the existing operation runtime.
- `ScopeBaselineInstaller`: idempotent creation of `site:main`, the flat plane
  `groups` and the node `administrators`.
- `access.yaml` and `audit.yaml` declare the five access operations and nine
  audit events.
- `OperationDescriptor` gained additive risk, reversibility and schema fields.

The root (`simai/larena`, branch of the same name) records the first
administrator in `site:main/groups#administrators` through
`core.plane.membership.assign`.

## What it deliberately does not do

No consumer wiring: Access grants, Storage roles, transport, environment
profile, solution descriptor, admin surfaces and REST/MCP exposure are later
batches. No authorization outcome changes; the membership row is inert until
Batch 2 resolves grants on plane nodes. The first-run contributor list is
untouched.

## Design decisions worth noting

1. **Adjacency plus materialized path** for plane node trees: descendant reads
   are one indexed prefix query, so SQLite and MySQL 5.7+ behave identically
   without recursive CTE on ordinary hosting.
2. **An idempotent installer instead of a first-run contributor.**
   `FirstRunCoordinator::REQUIRED_CONTRIBUTORS` is the exact list
   `['auth','setting','content']` and throws on any other composition, so
   adding a core contributor would break that check and its tests. The
   contributor composition changes in Batch 6, where Content leaves first-run.
3. **The descriptor risk class defaults to null, not to an enum case.** Core's
   older tests load contract files without the autoloader, so a default enum
   value would break them. `effectiveRiskClass()` resolves null fail-safe to
   `change`.
4. **Explicit index names.** The generated name
   `larena_core_plane_nodes_plane_id_parent_node_id_order_index_index` is 65
   characters and exceeds the MySQL 64-character identifier limit. Found by the
   MySQL smoke, not by SQLite.

## Gates

`composer lint`, `composer analyse` (PHPStan level 5), `composer test` (38
entries), `composer scope:check`, `composer larena:metadata:check`,
`composer validate:larena` all pass. See `tests.md` and `smoke.md`.
