# Batch 5 wave B — smoke

## The doctor command

```
php artisan larena:doctor --json
```

Root: `simai/larena`, consuming the package by Composer path symlink.

| Field | Value |
| --- | --- |
| `environment.declared` | true |
| `environment.profile.profile_id` | `ordinary_hosting` v1 |
| `environment.fingerprint` | `env-sha256:3cb04f71…` |
| `environment.detection.declaration_changed` | **false** |
| `environment.detection.mismatch_count` | 5 |
| doctor `status` | passed |

The five findings on this machine, each a genuine difference in availability:

| Capability | Declared | Detected |
| --- | --- | --- |
| `redis` | absent | present |
| `image_processing` | unknown | present |
| `mail_transport` | present | unknown |
| `outbound_http` | unknown | present |
| `process_control` | absent | present |

A mismatch does not fail the doctor. A declaration that disagrees with a probe is a
question for a human, not a broken installation.

## What running it changed

The first run produced **ten** findings, five of which were "declared absent,
detected unknown" — not a disagreement at all, since both mean the capability is not
there. The rule now reports only differences in availability, and the count fell to
five real ones. Recorded as a deviation; it was found by running the command.

## Operation coverage

| Measure | Before wave B | After wave B |
| --- | --- | --- |
| Core operations registered | 22 | 26 |
| Declared access codes covered | — | `core.environment.read`, `core.environment.declare` added |

## Not claimed

No push, release or deployment. The profile describes a host; it configures
nothing. The fingerprint is what node admission compares, and nothing in this wave
admits a node.

---

## Wave C — solution descriptor

Both drivers, through the composed root application.

    php artisan tinker --execute='require ".../solution-smoke.php";'

| | mysql | sqlite |
| --- | --- | --- |
| core operations registered | 31 | 31 |
| docara validated, plan actionable | yes, 6 steps | yes, 6 steps |
| docara topology allowed | yes (one node, no entitlement asked) | yes |
| tracker validated, nodes | yes, 2 | yes, 2 |
| tracker topology | refused: `multi_node_without_entitlement`, `environment_requirement_unmet` (`queue_worker`) | identical |
| conflicts against an incumbent | `scope:site`, `plane:org_chart`, `structure_role:doc_page`, `package:larena/storage` | identical |
| conflicted plan actionable | no | no |
| base outside the declared range | `base_compatibility_broken` | identical |

Both drivers agree on every line, which is expected: nothing in this wave touches
a database. That is the point of the row — a planning surface that differed by
driver would be reading something it should not.

`php artisan core:operations:coverage --json` → `passed`, 58 registered operations,
all five `core.solution.*` codes covered.

Root suite: `1267 tests, 968 passed, 208 failed` — identical to the pre-wave
baseline, after updating the asserted core operation count from 26 to 31.

One thing this does not prove: a boot with no database at all. Attempting it fails
in another package's boot-time table check, well before any solution code runs. The
package tests carry the offline claim instead, and they carry it more strictly —
they have no application at all.
