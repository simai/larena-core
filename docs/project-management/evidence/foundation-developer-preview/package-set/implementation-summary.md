# Implementation Summary

## Changed Behavior

- `StarterScenario::plan()` now plans package registry seed for the foundation
  developer preview package set.
- `StarterScenario::doctor()` keeps runtime/security required package checks
  separate from foundation preview diagnostics.
- `RuntimeSecurityClusterSmoke::run()` still fails only on missing
  runtime/security packages, while package registry diagnostics inspect the
  full foundation preview set.
- `PackageRegistrySeedTest` now verifies that guarded registry seed can write
  additional foundation packages and remains idempotent for the same package
  set.

## Reason

The runtime/security baseline is already developer-testable. The next preview
step needs the entry app to understand the first data/content package set
without pretending those packages already provide production runtime behavior.

## Boundary

This batch adds diagnostics and guarded registry awareness only. It does not
implement data/content package runtime, migrations, routes, UI, or storage
behavior.

