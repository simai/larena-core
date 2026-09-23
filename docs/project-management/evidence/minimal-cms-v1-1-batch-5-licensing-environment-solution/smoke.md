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
