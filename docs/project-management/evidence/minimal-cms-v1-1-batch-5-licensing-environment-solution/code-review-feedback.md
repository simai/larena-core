# Batch 5 wave B — review feedback

No independent review yet.

1. **Nothing persists a declared profile.** `core.environment.declare` validates and
   returns; where a declaration lives is still open. A reviewer should decide whether
   core needs a table, a config file or a first-run contributor, because until then the
   default profile is the only one an installation actually has.
2. **Nothing calls `verify()` at install or activation.** The feature asks for a
   package that needs a missing capability to fail closed at install time. The
   verification exists and answers correctly; wiring it into the installer is a
   separate decision because it can stop an upgrade.
3. **"Every core package runs on ordinary hosting" is asserted for `larena/core` only.**
   A reviewer should decide whether the claim needs a test per package or a single
   cross-package check.
4. **The availability rule hides a real case.** A capability declared `present` and
   detected `unknown` *is* reported, but a capability declared `unknown` and detected
   `absent` is not. Both are unavailable, so neither changes a decision — but the second
   is a small confirmation an operator might want. `differs()` exposes it; nothing
   surfaces it.
5. **The fingerprint has no version.** If the capability key set ever grows, every
   fingerprint changes and every admitted node stops matching. A reviewer should decide
   whether the fingerprint needs its own version, separate from the profile's.

---

## Wave C — solution descriptor

No independent review. The wave was implemented and verified in one line, the same
gap recorded for waves A and B and for batches 1 through 4.

Two things a reviewer should look at first:

1. **The cluster threshold.** "More than two nodes" is my line, not the freeze's.
   If a two-machine split should already require `distributed.cluster`, one
   comparison changes and three assertions move with it.
2. **The installed set.** A plain map keyed by solution id is enough to report
   conflicts, but the day something owns an installed-solution registry, the
   planner's signature is where that shows up.
