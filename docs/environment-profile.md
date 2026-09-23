# Environment profile

A profile is a **declared** description of what a host can do:

```php
$profile = DeclaredEnvironmentProfile::ordinaryHosting();
$profile->provides(EnvironmentCapability::QueueWorker);   // false
$profile->presence(EnvironmentCapability::Opcache);       // unknown
```

Eleven capabilities, each `present`, `absent` or `unknown`. The set is closed: a
profile describing something the platform has not agreed to describe is refused
rather than half-understood.

## Unknown is absent

A host capability nobody could detect is **not available**. Assuming otherwise fails
at the first moment something needs it, so `provides()` returns false for `unknown`
and `verify()` treats a required `unknown` capability exactly as a missing one.

The two values stay distinguishable, because a profile has to be able to say "nobody
could tell" honestly.

## Ordinary hosting is first class

No queue worker, no scheduler, no Redis, no search engine, no object storage, no
process control. It is core's default binding, because that is the profile this
platform targets. An installation with more declares its own profile rather than the
platform assuming capabilities it cannot see.

## Detection reports; it never rewrites

```php
$report = $detector->report($profile);
$report['declaration_changed'];  // always false
$report['mismatches'];           // capability, declared, detected
```

A probe that cannot answer returns `unknown`, never `absent`. A capability with no
probe is `unknown` too, so a missing probe never reads as a missing capability.

A **mismatch** is a difference in availability: a capability declared present the
host does not offer, or one declared absent that it does. Absent versus unknown is
not reported — both mean the capability is not there, and reporting it produced ten
findings on an ordinary machine, which is how an operator learns to ignore the
section. `differs()` still exposes the raw difference.

`larena:doctor` carries the profile, the fingerprint and the detection report. A
mismatch does not fail the doctor: a declaration that disagrees with a probe is a
question for a human, not a broken installation.

## Verification

```php
$profile->verify('larena/queue', [EnvironmentCapability::QueueWorker])->missing;  // ['queue_worker']
```

Every missing capability is named, not the first, so an operator learns everything
in one pass.

## The fingerprint

```php
EnvironmentFingerprint::of($profile);  // env-sha256:…
```

Computed from the profile alone — capability keys and presence values in a fixed
order — and from nothing about the machine. It has to be reproducible so node
admission can compare it, and it must carry no host name, path or address, because
it travels inside an entitlement snapshot other people handle.
