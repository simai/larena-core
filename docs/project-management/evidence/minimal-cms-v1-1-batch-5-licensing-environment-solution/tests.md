# Batch 5 wave B — tests

| Test | What it proves |
| --- | --- |
| `EnvironmentProfileTest` | **`unknown` behaves as `absent` for every capability**, while the two presence values stay distinguishable; an unmentioned capability is unknown; every capability is reported so a reader sees the whole picture; ordinary hosting is first class with the frozen presence set; the fingerprint is stable for the same profile, differs for a different one, changes when one capability changes, and **contains no OS family, hostname or slash** |
| `EnvironmentDetectionTest` | A generous host disagreeing and the declaration staying literally untouched; each mismatch naming the capability and both values; a matching host reporting nothing; **absent versus unknown not being a finding** and exactly the two declared-present capabilities being unconfirmed against a host that cannot tell; a declared-absent capability detected present being a real finding; a probe that cannot answer and a capability with no probe both reporting unknown; the real host detector answering one of three values for everything and leaving the queue worker unknown; writability detected without naming the path; and **no path, hostname, `/etc`, password, secret or token anywhere in a report** |
| `EnvironmentVerificationTest` | Core running on ordinary hosting; a package needing a worker refused with the capability named; **every** missing capability named rather than the first; a required capability declared `unknown` failing *identically* to one declared `absent`; requiring nothing satisfied; a generous host satisfying everything; and the payload shape |
| `EnvironmentFailsClosedTest` | An unknown capability key, three invalid presence values, five invalid profile ids and two invalid versions all refused with their own reason codes; an empty profile legal and providing nothing; the presence enum accepting its own instances and strings identically; and a profile unchanged by being read |

---

## Wave C — solution descriptor

Four plain-PHP tests, 0 database and 0 network — which is itself the offline proof:
the whole surface runs with no container, no connection and no entitlement.

`tests/Unit/SolutionManifestValidationTest.php`
: A minimal manifest validates. The same shape validates under all three
  distributions, differing only in author and distribution. An unknown top-level
  key is refused; every required key, removed one at a time, is refused. An edition
  naming only capabilities is accepted, and four editions that change packages,
  scopes, planes or roles are each refused with
  `edition_changes_more_than_capabilities`. Six raw-seed keys are each refused with
  `raw_seed_forbidden`. An assistant profile that narrows passes; one that adds an
  operation and one that raises the risk ceiling are both refused with
  `assistant_profile_broadens`. Both first manifests validate.

`tests/Unit/SolutionPlannerConflictTest.php`
: A clean installation plans with steps. Against an incumbent holding a scope, a
  plane, a structure role and a package, all four conflict classes are reported in
  one pass, each naming the incumbent, and the plan proposes no step. Installing
  over itself says `solution_already_installed`. An upgrade does not conflict with
  the version it replaces. A missing solution, a downgrade, an absent base and a
  base at either end outside the declared range are each refused by their own
  reason code. Both fixtures plan.

`tests/Unit/SolutionTopologyTest.php`
: One node is allowed with no entitlement resolver bound at all. Two nodes are
  refused with `multi_node_without_entitlement`, naming the capability, and
  accepted once remote operations are held. Three nodes are then refused with
  `cluster_without_entitlement`. The headless marker is read from the declaration
  while both nodes carry the same package, so nothing could have inferred it. On
  ordinary hosting a queue-worker requirement is refused naming `queue_worker`, and
  a profile declaring it `unknown` fails identically to one declaring it absent.

`tests/Unit/SolutionFailsClosedTest.php`
: Fourteen refusals by reason code, including the closed topology node key set,
  duplicate node ids, a node without packages, a package the solution does not
  install, a non-boolean headless marker and an inverted base range. Every
  descriptor is asserted to be a read with no audit event, no transaction and no
  confirmation. Each operation refuses a missing manifest rather than returning an
  empty plan. `explain` states `installs: false`.
