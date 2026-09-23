# Batch 5 wave B — Environment profile

## What exists now

An environment profile is a declared document: a profile id, a version, and each
of eleven host capabilities marked `present`, `absent` or `unknown`. The capability
set is closed, so a profile describing something the platform has not agreed to
describe is refused rather than half-understood.

**`unknown` is `absent`.** A host capability nobody could detect is not available,
and assuming otherwise fails at the first moment something needs it. The test
asserts this for every capability rather than for one example, and the verification
test asserts that a required capability declared `unknown` fails *identically* to
one declared `absent` — which is where the rule stops being a definition and starts
being a behaviour something depends on.

**Detection reports; it never rewrites.** `HostEnvironmentDetector` compares what
it can observe against the declaration and returns diagnostics. An installation
that reconfigured itself on a detection would be impossible to reason about the day
a probe returns the wrong answer, and probes do. The report carries
`declaration_changed: false` as a statement, not a hope.

A probe that cannot answer returns `unknown`, never `absent`: "I could not tell"
and "it is not there" are different facts, and only the profile turns the first
into the second. A capability with no probe at all is `unknown` too, so a missing
probe never reads as a missing capability.

**Verification names every missing capability**, not the first, so an operator
fixing a host learns everything in one pass.

**The fingerprint is computed from the profile alone** — capability keys and
presence values in the enum's fixed order — and from nothing about the machine.
That is deliberate twice: it has to be reproducible so node admission can compare
it, and it must carry no host name, path or address, because it travels inside an
entitlement snapshot other people handle. A test asserts it contains no OS family,
hostname or slash.

**Ordinary hosting is first class.** No queue worker, no scheduler, no Redis, no
search engine, no object storage, no process control. It is the default binding in
core, because that is the profile this platform targets — an installation with more
says so by declaring its own, rather than the platform assuming capabilities it
cannot see.

## The doctor finding that changed the rule

Wiring the profile into `larena:doctor` produced **ten** findings on an ordinary
development machine, and most were of the form "declared absent, detected unknown".
That is not a disagreement worth an operator's attention: both values mean the
capability is not there, and every decision the platform makes reads them the same
way. Ten findings is how an operator learns to ignore the environment section,
which is worse than having none.

A mismatch is now a difference in **availability** — a capability declared present
that the host does not offer, or one declared absent that the host does. The raw
difference is still readable through `differs()` for anyone who wants it. The same
machine now reports five findings, and every one of them is a real difference
between what was declared and what is there.

This was found by running the command, not by reading the code.

## Gates

`composer quality:gate` passes: lint, PHPStan level 5, 57 package tests including
four new ones, metadata sync, evidence contract, scope check. The four environment
operations register in the core registry, bringing it to 26.

---

## Wave C — solution descriptor

Five read operations behind `core.solution.read`: `validate`, `plan_install`,
`plan_upgrade`, `validate_topology` and `explain`. Core now declares 31
operations.

`SolutionManifestValidator` turns a document into a `SolutionManifest` or refuses
it with a reason code. The top-level key set is closed, and so are the bodies of
`editions`, `seed`, `assistant_profile`, `base_solution` and each topology node —
each one closed for the same reason: a key this version does not understand would
be silently ignored, and an installer that ignores a section is worse than one
that refuses it.

`SolutionPlanner` plans and validates. It writes nothing, reaches nothing, and
reports every conflict of all four classes in one pass, each naming the key and the
incumbent that holds it. A plan carries `planning_only: true` in its own output.

Three decisions the freeze left to the implementation, all now explicit:

- **More than two nodes is a cluster.** A pair is the web-and-worker split and
  needs `distributed.remote_operations`; three or more is a topology somebody has
  to operate, which is what `distributed.cluster` is for.
- **A node whose environment requirement is unmet is refused** with
  `environment_requirement_unmet`, listing the missing capabilities. The freeze
  required the behaviour and named no code for it.
- **The installed set is a plain map keyed by solution id.** Nothing owns an
  installed-solution registry in this batch, and inventing a contract for one would
  have been a table in disguise.

Core binds no entitlement resolver, so the default composition refuses a
multi-node topology — the same fail-closed posture Batch 3 gave the network
transport. Planning itself stays free: a single-node plan never asks.

The Docara and Tracker manifests ship as fixtures. Docara is the single-node
product with editions and a SitePack seed; Tracker is the two-node case with a base
solution and a version range. Neither is installed.
