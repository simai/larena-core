# Solution descriptor

A solution is a document: which packages it installs, which scopes, planes and
structure roles it claims, how it may be spread across nodes, what it needs from
its host, and — optionally — which editions it sells. The schema is
`larena.solution_manifest.v1`.

One schema serves all three distributions. A SIMAI product, a partner's
marketplace solution and a customer's local customization differ in `author` and
`distribution` and in nothing else. The moment a first-party solution gets a
private extension, no partner can build what SIMAI builds.

## The rules that carry it

**Validation and planning are offline.** Validating a manifest, planning an
install, planning an upgrade and checking a topology reach no service, need no
entitlement and touch no database. A customer has to be able to evaluate a
solution before buying it, and an air-gapped installation has to be able to check
its own. The four package tests are the proof: they run as plain PHP, with no
container, no database and no network.

**Nothing here installs.** All five operations are reads. They carry no audit
event and open no transaction, and the operation that would actually install
something does not exist yet. A read that installed would be the worst kind of
surprise.

**An edition names a capability set and nothing else.** An edition body may say
`capabilities`, and any other key is refused with
`edition_changes_more_than_capabilities`. An edition that changes code is a fork
wearing a price tag: two editions of one solution would no longer be the same
software, and an upgrade could not be reasoned about.

**Every conflict is reported in one pass, before any mutation.** Scopes, planes,
structure roles and packages, each naming the key and the solution that holds it.
Stopping at the first collision makes an operator resolve conflicts one install
attempt at a time.

**Seed data enters through SitePack.** A manifest that declares SQL, statements or
table writes is refused with `raw_seed_forbidden`. A manifest that writes rows
itself bypasses every boundary the platform has.

**An assistant profile may only narrow.** A profile naming an operation the
installation does not have, or raising the risk ceiling, is refused with
`assistant_profile_broadens`. A restriction that grants is a privilege escalation
with a friendly name.

**The top-level key set is closed.** A manifest carrying a key this version does
not know would be silently half-understood, and "the installer ignored my section"
is the worst way to learn that.

## Topology

A node declares `node_id`, `packages`, `roles` and `headless`. The headless marker
is declared, never inferred: a node that happens to carry no HTTP surface today
would silently become non-headless the moment someone adds one, and an operator
would learn about the new public surface from the internet.

One node needs nothing at all — the local transport is free, and a single-machine
installation never consults an entitlement service. Two nodes need
`distributed.remote_operations`. More than two is a cluster and needs
`distributed.cluster` on top; a pair is the ordinary web-and-worker split, while
three or more is a topology somebody has to operate.

Core binds no entitlement resolver, so the default composition refuses a
multi-node topology until something outside core says the installation holds the
capability. And a topology whose requirement the environment profile does not meet
is refused naming the capability — where `unknown` is `absent`, exactly as in the
profile itself.

## Upgrades

A manifest may declare a base solution and a version range. An upgrade that would
move the base outside that range is refused with `base_compatibility_broken` —
that is exactly the upgrade that breaks a customization nobody is watching. A
downgrade is refused, an absent solution cannot be upgraded, and an install over
something already installed says `solution_already_installed` rather than quietly
replacing a running solution.

## Operations

| Operation | Risk | What it does |
| --- | --- | --- |
| `core.solution.validate` | read | accepts a manifest or names the reason it cannot |
| `core.solution.plan_install` | read | the steps, and every conflict |
| `core.solution.plan_upgrade` | read | the same, plus base compatibility |
| `core.solution.validate_topology` | read | entitlement and environment verdict |
| `core.solution.explain` | read | the contract, stated by the implementation |

All five sit behind `core.solution.read`.
