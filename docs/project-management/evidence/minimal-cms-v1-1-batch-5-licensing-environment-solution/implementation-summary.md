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
